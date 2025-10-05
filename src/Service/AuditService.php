<?php

namespace Drupal\seo_audit\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\metatag\MetatagManagerInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\node\NodeInterface;

/**
 * Service to perform SEO audits on nodes.
 *
 * Responsibilities:
 *  - Read meta tags (via Metatag module when available).
 *  - Check images for missing alt attributes.
 *  - Check fields for broken links (configurable).
 *  - Persist per-node audit results into `seo_audit_report`.
 *  - Provide convenience methods: getNodesForAudit(), getOverallScore(),
 *    markDuplicateMetaInReports().
 */
class AuditService {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * HTTP client for link checking.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Request stack for current request context.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Metatag manager, if metatag module is enabled. Null otherwise.
   *
   * @var \Drupal\metatag\MetatagManagerInterface|null
   */
  protected ?MetatagManagerInterface $metatagManager = NULL;

  /**
   * AuditService constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service for link checking.
   * @param \Drupal\Core\Database\Connection $database
   *   The Database connection serivce.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    LoggerChannelFactoryInterface $logger_factory,
    ClientInterface $http_client,
    Connection $database,
    RequestStack $request_stack,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->logger = $logger_factory->get('seo_audit');
    $this->httpClient = $http_client;
    $this->database = $database;
    $this->requestStack = $request_stack;

    // Use MetatagManager only if module is present (avoid hard dependency).
    if ($this->moduleHandler->moduleExists('metatag')) {
      // Use the service dynamically so container compilation doesn't fail
      // for sites without metatag module.
      if (\Drupal::getContainer()->has('metatag.manager')) {
        $this->metatagManager = \Drupal::service('metatag.manager');
      }
    }
  }

  /**
   * Return a batch of node IDs that should be audited for this run.
   *
   * Uses configuration `seo_audit.settings.max_nodes_per_run`.
   *
   * @return int[]
   *   Array of node IDs.
   */
  public function getNodesForAudit(): array {
    $config = \Drupal::config('seo_audit.settings');
    $max_nodes = (int) ($config->get('max_nodes_per_run') ?? 50);

    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    // Only published nodes by default; adjust as required.
    $query->condition('status', 1);
    $query->range(0, $max_nodes);

    return $query->execute();
  }

  /**
   * Run an audit for a single node.
   *
   * @param int $nid
   *   Node ID.
   *
   * @return array|false
   *   Audit details array on success, FALSE when node not found.
   */
  public function auditNode(int $nid): array|false {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      return FALSE;
    }

    $issues = [];
    $meta_title = '';
    $meta_description = '';

    if ($this->metatagManager) {
      // tagsFromEntityWithDefaults returns render array structure for tags.
      $tags = $this->metatagManager->tagsFromEntityWithDefaults($node);

      // Try several keys that metatag plugins may use ('#value', '#plain_text', '#markup').
      $meta_title = $this->extractMetatagValue($tags, 'title');
      $meta_description = $this->extractMetatagValue($tags, 'description');

      if (empty(trim($meta_title))) {
        $issues[] = 'Missing meta title';
      }
      if (empty(trim($meta_description))) {
        $issues[] = 'Missing meta description';
      }
    }
    else {
      // Fallback: node title + body summary heuristic.
      if (empty($node->getTitle())) {
        $issues[] = 'Missing node title';
      }
      if ($node->hasField('body')) {
        $summary = $node->get('body')->summary;
        if (empty(trim($summary))) {
          $issues[] = 'Missing body summary (used as meta description)';
        }
      }
    }

    $missing_alt_count = 0;
    foreach ($node->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() === 'image' && $node->hasField($field_name)) {
        foreach ($node->get($field_name) as $item) {
          // File/image items typically expose ->alt property.
          if (empty($item->alt)) {
            $missing_alt_count++;
          }
        }
      }
    }
    if ($missing_alt_count > 0) {
      $issues[] = "Images missing alt text: {$missing_alt_count}";
    }

    $broken_links = [];
    // Field types to check are configurable (e.g. text_with_summary, text_long).
    $config = \Drupal::config('seo_audit.settings');
    $field_types_to_check = (array) ($config->get('field_types_to_check') ?? ['text_with_summary', 'text_long']);

    foreach ($node->getFieldDefinitions() as $field_name => $definition) {
      $type = $definition->getType();

      // Text-like fields where HTML anchors may be present.
      if (in_array($type, $field_types_to_check, TRUE) && $node->hasField($field_name)) {
        $value = $node->get($field_name)->value ?? '';
        if (!empty($value)) {
          $links = $this->extractLinksFromHtml($value);
          foreach ($links as $link) {
            if (!$this->checkLink($link)) {
              $broken_links[] = $link;
            }
          }
        }
      }

      // Link field type.
      if ($type === 'link' && $node->hasField($field_name)) {
        foreach ($node->get($field_name) as $item) {
          $uri = $item->uri ?? '';
          if (!empty($uri) && !$this->checkLink($uri)) {
            $broken_links[] = $uri;
          }
        }
      }
    }

    if (!empty($broken_links)) {
      $issues[] = 'Broken links found: ' . implode(', ', array_slice($broken_links, 0, 5));
    }

    $score = max(0, 100 - (count($issues) * 10));

    // Build edit link for UI convenience.
    $edit_link = Url::fromRoute('entity.node.edit_form', ['node' => $nid])->toString();

    // Persist audit row (note: ensure schema has meta_title, meta_description, edit_link).
    $this->database->merge('seo_audit_report')
      ->key('nid', $nid)
      ->fields([
        'nid' => $nid,
        'issues' => json_encode(array_values($issues)),
        'score' => $score,
        'last_checked' => time(),
        'meta_title' => $meta_title,
        'meta_description' => $meta_description,
        'edit_link' => $edit_link,
      ])
      ->execute();

    return [
      'nid' => $nid,
      'issues' => $issues,
      'score' => $score,
      'meta_title' => $meta_title,
      'meta_description' => $meta_description,
      'edit_link' => $edit_link,
    ];
  }

  /**
   * Extracts a meta tag value out of the array returned by metatag.manager.
   *
   * The metatag manager returns renderable arrays; different plugins use
   * '#value', '#plain_text' or '#markup'.
   *
   * @param array $tags
   *   The render array from metatag.manager->tagsFromEntity().
   * @param string $key
   *   The meta key to read, e.g. 'title' or 'description'.
   *
   * @return string
   *   Normalized string value (empty string if not present).
   */
  protected function extractMetatagValue(array $tags, string $key): string {
    if (!isset($tags[$key])) {
      return '';
    }
    $entry = $tags[$key];
    if (is_array($entry)) {
      if (isset($entry['#value'])) {
        return (string) $entry['#value'];
      }
      if (isset($entry['#plain_text'])) {
        return (string) $entry['#plain_text'];
      }
      if (isset($entry['#markup'])) {
        return (string) $entry['#markup'];
      }
    }
    return (string) $entry;
  }

  /**
   * Extracts all links from the provided HTML content.
   *
   * This method safely parses UTF-8 HTML and returns unique href values.
   *
   * @param string $html
   *   The HTML string to parse.
   *
   * @return array
   *   An array of unique link URLs.
   */
  protected function extractLinksFromHtml(string $html): array {
    $links = [];

    if (trim($html) === '') {
      return $links;
    }

    // Prevent warnings from malformed HTML.
    libxml_use_internal_errors(TRUE);

    // Ensure UTF-8 encoding (no HTML entity conversion — PHP 8.2 safe).
    $html = mb_convert_encoding($html, 'UTF-8', 'auto');

    // Load HTML safely into DOMDocument.
    $doc = new \DOMDocument('1.0', 'UTF-8');
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    // Extract all <a href="..."> links.
    foreach ($doc->getElementsByTagName('a') as $a) {
      if ($a instanceof \DOMElement) {
        $href = trim($a->getAttribute('href'));
        if ($href !== '') {
          $links[] = $href;
        }
      }
    }

    libxml_clear_errors();

    return array_unique($links);
  }

  /**
   * Validate a link (HEAD request fallback to GET).
   *
   * Honors the config value `seo_audit.settings.check_external_links`.
   *
   * @param string $url
   *   URL or path to validate.
   *
   * @return bool
   *   TRUE = link valid (or intentionally skipped), FALSE = broken.
   */
  protected function checkLink(string $url): bool {
    $config = \Drupal::config('seo_audit.settings');
    $check_external = (bool) ($config->get('check_external_links') ?? TRUE);

    // Skip external checks if disabled.
    if (!$check_external && preg_match('/^https?:\\/\\//', $url) && !\Drupal::service('path.validator')->isInternal($url)) {
      return TRUE;
    }

    try {
      // Normalize local relative paths to absolute.
      if (strpos($url, 'http') !== 0) {
        $base = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost();
        if (strpos($url, '/') === 0) {
          $url = $base . $url;
        }
        else {
          // Skip relative, non-root paths (fragment/anchor/JS links).
          return TRUE;
        }
      }

      $response = $this->httpClient->request('HEAD', $url, [
        'timeout' => 5,
        'allow_redirects' => TRUE,
      ]);

      return ($response->getStatusCode() < 400);
    }
    catch (\Exception $e) {
      $this->logger->warning('Link check failed for @url: @message', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Compute the overall site SEO score.
   *
   * @return float
   *   Average score across all records (rounded to 2 decimals). 0.0 if none.
   */
  public function getOverallScore(): float {
    $avg = $this->database->query('SELECT AVG(score) FROM {seo_audit_report}')->fetchField();
    return $avg === NULL ? 0.0 : round((float) $avg, 2);
  }

  /**
   * Detect duplicate meta titles and descriptions across saved reports and,
   * append a "Duplicate meta ..." issue to each impacted row.
   *
   * This is intended to be run after a full audit pass (e.g. as an extra batch
   * operation or a separate maintenance task). It relies on `meta_title` and
   * `meta_description` being stored in the report table.
   *
   * Complexity: O(n) reads/writes of the report table. For very large sites,
   * consider doing this in chunks or during the batch processing pipeline.
   */
  public function markDuplicateMetaInReports(): void {
    // 1) Find duplicate meta titles.
    $duplicate_titles = $this->database->query(
      "SELECT meta_title FROM {seo_audit_report} WHERE meta_title <> '' GROUP BY meta_title HAVING COUNT(*) > 1"
    )->fetchCol();

    foreach ($duplicate_titles as $title) {
      $nids = $this->database->query(
        'SELECT nid, issues FROM {seo_audit_report} WHERE meta_title = :title',
        [':title' => $title]
      )->fetchAllKeyed(0, 1);

      foreach ($nids as $nid => $issues_json) {
        $issues = json_decode($issues_json, TRUE) ?: [];
        if (!in_array('Duplicate meta title', $issues, TRUE)) {
          $issues[] = 'Duplicate meta title';
          $this->database->update('seo_audit_report')
            ->fields(['issues' => json_encode(array_values($issues))])
            ->condition('nid', $nid)
            ->execute();
        }
      }
    }

    // 2) Find duplicate meta descriptions.
    $duplicate_descs = $this->database->query(
      "SELECT meta_description FROM {seo_audit_report} WHERE meta_description <> '' GROUP BY meta_description HAVING COUNT(*) > 1"
    )->fetchCol();

    foreach ($duplicate_descs as $desc) {
      $nids = $this->database->query(
        'SELECT nid, issues FROM {seo_audit_report} WHERE meta_description = :desc',
        [':desc' => $desc]
      )->fetchAllKeyed(0, 1);

      foreach ($nids as $nid => $issues_json) {
        $issues = json_decode($issues_json, TRUE) ?: [];
        if (!in_array('Duplicate meta description', $issues, TRUE)) {
          $issues[] = 'Duplicate meta description';
          $this->database->update('seo_audit_report')
            ->fields(['issues' => json_encode(array_values($issues))])
            ->condition('nid', $nid)
            ->execute();
        }
      }
    }
  }

  /**
   * Batch callback helper. Kept compatible with Batch API static callback signature.
   *
   * @param int $nid
   *   Node id.
   * @param array $context
   *   Batch context.
   */
  public static function batchAuditCallback($nid, array &$context) {
    $service = \Drupal::service('seo_audit.audit_service');
    $result = $service->auditNode((int) $nid);
    $context['results'][] = $result;
    $context['message'] = t('Auditing node @nid', ['@nid' => $nid]);
  }

}
