<?php

namespace Drupal\seo_audit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\seo_audit\Service\AuditService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles SEO Audit batch operations.
 *
 * This controller provides endpoints for running SEO audits in batch mode,
 * either for all nodes of specified types or for a specific node.
 */
final class SeoAuditController extends ControllerBase {

  /**
   * The audit service used for performing SEO checks.
   *
   * @var \Drupal\seo_audit\Service\AuditService
   */
  protected $auditService;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new SeoAuditController.
   *
   * @param \Drupal\seo_audit\Service\AuditService $audit_service
   *   The audit service instance.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   */
  public function __construct(
    AuditService $audit_service,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $request_stack,
  ) {
    $this->auditService = $audit_service;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
    $container->get('seo_audit.audit_service'),
    $container->get('database'),
    $container->get('entity_type.manager'),
     $container->get('request_stack')
    );
  }

  /**
   * Runs a full SEO audit for all published nodes of selected content types.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the SEO Audit report page after batch starts.
   */
  public function runAudit(): RedirectResponse {
    $nids = $this->getAuditableNodeIds();

    if (empty($nids)) {
      $this->messenger()->addWarning($this->t('No nodes found for SEO audit.'));
      return $this->redirect('seo_audit_form.report');
    }

    $batch = $this->buildBatch($nids, $this->t('Running SEO Audit'));
    batch_set($batch);

    return batch_process(Url::fromRoute('seo_audit_form.report')->toString());
  }

  /**
   * Runs a SEO audit for a single node ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the SEO Audit report page after batch starts.
   */
  public function runSelectedAudit(): RedirectResponse {
    // Normalize input to an array.
    $request = $this->requestStack->getCurrentRequest();
    $nids = $request->query->get('nids', '');

    $nids = is_array($nids) && !empty($nids) ? $nids : [$nids];
    $node_storage = $this->entityTypeManager->getStorage('node');

    $valid_nids = [];
    $auditable_types = $this->getConfiguredNodeTypes();

    foreach ($nids as $nid) {
      $node = $node_storage->load($nid);

      if (!$node || !$node->isPublished()) {
        $this->messenger()->addError($this->t('Invalid or unpublished node (ID: @nid).', ['@nid' => $nid]));
        continue;
      }

      if (!in_array($node->bundle(), $auditable_types, TRUE)) {
        $this->messenger()->addWarning($this->t('The node type "@type" is not included in the audit configuration for node ID @nid.', [
          '@type' => $node->bundle(),
          '@nid' => $nid,
        ]));
        continue;
      }

      $valid_nids[] = $nid;
    }

    if (empty($valid_nids)) {
      $this->messenger()->addError($this->t('No valid nodes selected for audit.'));
      return $this->redirect('seo_audit_form.report');
    }

    // Build and run batch for all valid nodes.
    $batch = $this->buildBatch(
        $valid_nids,
        $this->t('Running SEO Audit for @count node(s).', ['@count' => count($valid_nids)])
    );
    batch_set($batch);

    return batch_process(Url::fromRoute('seo_audit_form.report')->toString());
  }

  /**
   * Retrieves node IDs for all published nodes of configured content types.
   *
   * @return int[]
   *   An array of node IDs that should be audited.
   */
  protected function getAuditableNodeIds(): array {
    $node_types = $this->getConfiguredNodeTypes();

    if (empty($node_types)) {
      return [];
    }

    return $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $node_types, 'IN')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->execute();
  }

  /**
   * Gets the list of configured content types to audit.
   *
   * @return string[]
   *   An array of node type machine names.
   */
  protected function getConfiguredNodeTypes(): array {
    $config = $this->config('seo_audit.settings');
    $node_types = $config->get('node_types_to_audit');

    // Convert comma-separated string into an array if needed.
    if (is_string($node_types)) {
      $node_types = array_filter(array_map('trim', explode(',', $node_types)));
    }

    return is_array($node_types) ? $node_types : [];
  }

  /**
   * Builds a Drupal batch definition for SEO audit processing.
   *
   * @param int[] $nids
   *   Array of node IDs to process.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The title for the batch process.
   *
   * @return array
   *   The batch definition.
   */
  protected function buildBatch(array $nids, $title): array {
    $operations = array_map(static function ($nid) {
      return ['\Drupal\seo_audit\Service\AuditService::batchAuditCallback', [$nid]];
    }, $nids);

    return [
      'title' => $title,
      'operations' => $operations,
      'finished' => 'seo_audit_batch_finished',
      'progress_message' => t('Processed @current of @total nodes.'),
    ];
  }

}
