<?php

namespace Drupal\seo_audit\Service;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;
use Drupal\metatag\MetatagManagerInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\node\NodeInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service to perform SEO audits on nodes.
 */
class AuditService {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * ConfigFactory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * HTTP client for link checking.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Request stack for current request context.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * MetatagManager service.
   *
   * @var \Drupal\metatag\MetatagManagerInterface|null
   */
  protected $metatagManager;

  /**
   * PathValidator service.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\Time
   */
  protected $timeService;

  /**
   * Constructs a new AuditService object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    ClientInterface $http_client,
    Connection $database,
    RequestStack $request_stack,
    PathValidatorInterface $path_validator,
    Time $time_service,
    ?MetatagManagerInterface $metatag_manager = NULL,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('seo_audit');
    $this->httpClient = $http_client;
    $this->database = $database;
    $this->requestStack = $request_stack;
    $this->pathValidator = $path_validator;
    $this->timeService = $time_service;
    $this->metatagManager = $metatag_manager;
  }

  /**
   * Return a batch of node IDs that should be audited for this run.
   *
   * @return array
   *   Array of node IDs.
   */
  public function getNodesForAudit(): array {
    $config = $this->configFactory->get('seo_audit.settings');
    $max_nodes = (int) ($config->get('max_nodes_per_run') ?? 50);

    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query->condition('status', 1)
      ->accessCheck(FALSE)
      ->range(0, $max_nodes);

    return $query->execute();
  }

  /**
   * Audit a node for SEO issues.
   *
   * @param int $nid
   *   The node ID to audit.
   *
   * @return array|false
   *   Audit results array or FALSE if node not found.
   */
  public function auditNode(int $nid): array|false {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      return FALSE;
    }

    $issues = [];
    $meta_title = '';
    $meta_description = '';

    // Meta tags check.
    if ($this->metatagManager) {
      $tags = $this->metatagManager->tagsFromEntityWithDefaults($node);
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
      if (empty(trim($node->getTitle()))) {
        $issues[] = 'Missing node title';
      }
      if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
        $body = $node->get('body')->value ?? '';

        if (empty(trim($body))) {
          $issues[] = 'Missing body (used as meta description)';
        }
      }
    }

    // Image alt text check.
    $missing_alt_count = 0;
    foreach ($node->getFields() as $field_name => $field_item_list) {
      $field_definition = $field_item_list->getFieldDefinition();
      if ($field_definition->getType() === 'image' && !$field_item_list->isEmpty()) {
        foreach ($field_item_list as $item) {
          if (empty($item->alt)) {
            $missing_alt_count++;
          }
        }
      }
    }
    if ($missing_alt_count > 0) {
      $issues[] = "Images missing alt text: {$missing_alt_count}";
    }

    // Broken link & image checks.
    $config = $this->configFactory->get('seo_audit.settings');
    $field_types_to_check = (array) ($config->get('field_types_to_check') ?? ['text_with_summary', 'text_long', 'text']);
    $check_external = (bool) ($config->get('check_external_links') ?? TRUE);

    $broken_links = $broken_images = $empty_links = $empty_images  = [];

    foreach ($node->getFields() as $field_name => $field_item_list) {
      $field_definition = $field_item_list->getFieldDefinition();
      $type = $field_definition->getType();

      // Check HTML fields (body, text areas, etc.)
      if (in_array($type, $field_types_to_check, TRUE) && !$field_item_list->isEmpty()) {
        foreach ($field_item_list as $delta => $item) {
          $html = '';
          if (isset($item->value)) {
            $html = (string) $item->value;
          }
          elseif (isset($item->summary)) {
            $html = (string) $item->summary;
          }

          if ($html === '') {
            continue;
          }

          // Extract ALL links including empty ones.
          $links_data = $this->extractLinksFromHtml($html);
          foreach ($links_data as $link_data) {
            $href = $link_data['href'];
            $is_empty = $link_data['empty'];

            if ($is_empty) {
              $empty_links[] = $href;
              continue;
            }

            // Skip external links if configured.
            if (!$check_external && $this->isExternalUrl($href)) {
              continue;
            }

            if (!$this->checkLink($href)) {
              $broken_links[] = $href;
            }
          }

          // Extract ALL images including empty ones.
          $images_data = $this->extractImagesFromHtml($html);
          foreach ($images_data as $img_data) {
            $src = $img_data['src'];
            $is_empty = $img_data['empty'];
            $has_alt = $img_data['has_alt'];

            if ($is_empty || !$has_alt) {
              $empty_images[] = $src;
            }

            // Skip external images if configured.
            if (!$check_external && $this->isExternalUrl($src)) {
              continue;
            }

            if (!$is_empty && !$this->checkLink($src)) {
              $broken_images[] = $src;
            }
          }
        }
      }

      // Link field entity type (field type 'link')
      if ($type === 'link' && !$field_item_list->isEmpty()) {
        foreach ($field_item_list as $item) {
          $uri = $item->uri ?? '';
          if ($uri === '' || $uri === 'route:<nolink>') {
            $empty_links[] = $uri ?: '(empty)';
            continue;
          }

          if (!$check_external && $this->isExternalUrl($uri)) {
            continue;
          }

          if (!$this->checkLink($uri)) {
            $broken_links[] = $uri;
          }
        }
      }
    }

    // Report issues.
    if (!empty($empty_links)) {
      $issues[] = 'Empty links found: ' . implode(', ', array_slice($empty_links, 0, 5));
    }
    if (!empty($empty_images)) {
      $issues[] = 'Empty image sources or alt attributes found: ' . implode(', ', array_slice($empty_images, 0, 5));
    }
    if (!empty($broken_links)) {
      $issues[] = 'Broken links found: ' . implode(', ', array_slice($broken_links, 0, 5));
    }
    if (!empty($broken_images)) {
      $issues[] = 'Broken images found: ' . implode(', ', array_slice($broken_images, 0, 5));
    }

    $status = empty($issues) ? 'passed' : 'issues';

    $this->database->merge('seo_audit_report')
      ->key('nid', $nid)
      ->fields([
        'nid' => $nid,
        'issues' => json_encode(array_values($issues)),
        'status' => $status,
        'last_checked' => $this->timeService->getRequestTime(),
        'meta_title' => $meta_title,
        'meta_description' => $meta_description,
      ])
      ->execute();

    return [
      'nid' => $nid,
      'issues' => $issues,
      'status' => $status,
      'last_checked' => $this->timeService->getRequestTime(),
      'meta_title' => $meta_title,
      'meta_description' => $meta_description,
    ];
  }

  /**
   * Extracts a meta tag value out of the array returned by metatag.manager.
   *
   * @param array $tags
   *   The meta tags array.
   * @param string $key
   *   The meta tag key to extract.
   *
   * @return string
   *   The extracted meta tag value.
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
   * @param string $html
   *   The HTML content to parse.
   *
   * @return array
   *   Array of image URLs.
   */
  protected function extractImagesFromHtml(string $html): array {
    $images = [];

    if (trim($html) === '') {
      return $images;
    }

    libxml_use_internal_errors(TRUE);
    $html = mb_convert_encoding($html, 'UTF-8', 'auto');
    $doc = new \DOMDocument('1.0', 'UTF-8');
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    foreach ($doc->getElementsByTagName('img') as $img) {
      if ($img instanceof \DOMElement) {
        $src = trim($img->getAttribute('src'));
        $alt = trim($img->getAttribute('alt'));
        $is_empty = $src === '' || $src === '#' || preg_match('/^\s*$/', $src);

        $images[] = [
          'src' => $src ?: '(empty)',
          'empty' => $is_empty,
          'has_alt' => !empty($alt),
        ];
      }
    }

    libxml_clear_errors();
    return $images;
  }

  /**
   * Extracts all links from the provided HTML content.
   *
   * @param string $html
   *   The HTML content to parse.
   *
   * @return array
   *   Array of link URLs.
   */
  protected function extractLinksFromHtml(string $html): array {
    $links = [];

    if (trim($html) === '') {
      return $links;
    }

    libxml_use_internal_errors(TRUE);
    $html = mb_convert_encoding($html, 'UTF-8', 'auto');
    $doc = new \DOMDocument('1.0', 'UTF-8');
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    foreach ($doc->getElementsByTagName('a') as $a) {
      if ($a instanceof \DOMElement) {
        $href = trim($a->getAttribute('href'));
        $is_empty = $href === '' || $href === '#' || preg_match('/^\s*$/', $href);

        $links[] = [
          'href' => $href ?: '(empty)',
          'empty' => $is_empty,
          'text' => trim($a->textContent),
        ];
      }
    }

    libxml_clear_errors();
    return $links;
  }

  /**
   * Check if a URL is external.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is external, FALSE otherwise.
   */
  protected function isExternalUrl(string $url): bool {
    if (empty($url) || $url === '(empty)') {
      return FALSE;
    }
    return preg_match('/^https?:\/\//', $url) && !$this->pathValidator->isValid($url);
  }

  /**
   * Validate a link (HEAD request fallback to GET).
   *
   * Returns TRUE for valid (SEO-safe) links, FALSE otherwise.
   *
   * @param string $url
   *   The URL to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function checkLink(string $url): bool {
    if (empty($url)) {
      return FALSE;
    }
    // Basic length check.
    if (strlen($url) < 10) {
      $this->logger->warning('URL too short: @url', ['@url' => $url]);
      return FALSE;
    }

    // Handle internal paths.
    if (!$this->isExternalUrl($url)) {
      // For internal paths, convert to absolute URL for checking.
      try {
        if (str_starts_with($url, '/')) {
          $url = Url::fromUserInput($url)->setAbsolute()->toString();
        }
        else {
          $url = Url::fromUri($url)->setAbsolute()->toString();
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Invalid internal URL @url: @error', [
          '@url' => $url,
          '@error' => $e->getMessage(),
        ]);
        return FALSE;
      }
    }

    // Enhanced URL validation.
    if (!$this->isValidUrl($url)) {
      $this->logger->warning('Invalid URL format: @url', ['@url' => $url]);
      return FALSE;
    }

    try {
      $response = $this->httpClient->request('HEAD', $url, [
        'timeout' => 5,
        'allow_redirects' => TRUE,
        'http_errors' => FALSE,
      ]);

      $status_code = $response->getStatusCode();

      if (in_array($status_code, [405, 403], TRUE)) {
        $response = $this->httpClient->request('GET', $url, [
          'timeout' => 5,
          'allow_redirects' => TRUE,
          'http_errors' => FALSE,
        ]);
        $status_code = $response->getStatusCode();
      }

      $valid_status_codes = [200, 301, 302, 304, 307];
      return in_array($status_code, $valid_status_codes, TRUE);
    }
    catch (RequestException $e) {
      $this->logger->error('Failed to check URL @url: @error', [
        '@url' => $url,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
    catch (\InvalidArgumentException $e) {
      // Catch invalid argument exceptions from HTTP client.
      $this->logger->error('Invalid URL argument @url: @error', [
        '@url' => $url,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Enhanced URL validation.
   */
  protected function isValidUrl(string $url): bool {
    // Basic filter_var validation.
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return FALSE;
    }

    // Parse URL to check components.
    $parsed = parse_url($url);

    // Check if host exists and has reasonable length.
    if (!isset($parsed['host']) || strlen($parsed['host']) < 2) {
      return FALSE;
    }

    // Check for common TLDs or valid domain pattern.
    if (!preg_match('/\.(com|org|net|edu|gov|io|co|[a-z]{2,})$/i', $parsed['host'])) {
      // Allow localhost for testing.
      if ($parsed['host'] !== 'localhost' && !str_contains($parsed['host'], '.local')) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Compute the overall site SEO score.
   *
   * @return float
   *   The average SEO score.
   */
  public function getOverallScore(): float {
    $avg = $this->database->query('SELECT AVG(score) FROM {seo_audit_report}')->fetchField();
    return $avg === NULL ? 0.0 : round((float) $avg, 2);
  }

  /**
   * Detect duplicates and update report rows.
   */
  public function markDuplicateMetaInReports(): void {
    $duplicate_titles = $this->database->query(
      "SELECT meta_title FROM {seo_audit_report} WHERE meta_title <> '' GROUP BY meta_title HAVING COUNT(*) > 1"
    )->fetchCol();

    foreach ($duplicate_titles as $title) {
      $rows = $this->database->query(
        'SELECT nid, issues FROM {seo_audit_report} WHERE meta_title = :title',
        [':title' => $title]
      )->fetchAllKeyed(0, 1);

      foreach ($rows as $nid => $issues_json) {
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

    $duplicate_descs = $this->database->query(
      "SELECT meta_description FROM {seo_audit_report} WHERE meta_description <> '' GROUP BY meta_description HAVING COUNT(*) > 1"
    )->fetchCol();

    foreach ($duplicate_descs as $desc) {
      $rows = $this->database->query(
        'SELECT nid, issues FROM {seo_audit_report} WHERE meta_description = :desc',
        [':desc' => $desc]
      )->fetchAllKeyed(0, 1);

      foreach ($rows as $nid => $issues_json) {
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
   * Batch callback helper.
   *
   * @param int $nid
   *   The node ID to audit.
   * @param array $context
   *   The batch context.
   */
  public static function batchAuditCallback($nid, array &$context): void {
    $service = \Drupal::service('seo_audit.audit_service');
    $result = $service->auditNode((int) $nid);
    $context['results'][] = $result;
    $context['message'] = t('Auditing node @nid', ['@nid' => $nid]);
  }

}
