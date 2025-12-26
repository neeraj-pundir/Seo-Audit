<?php

namespace Drupal\seo_audit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\seo_audit\Service\AuditService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Handles SEO Audit batch operations.
 *
 * This controller provides endpoints for running SEO audits in batch mode
 * for all nodes of specified types or for specific nodes.
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
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When user doesn't have permission to run audits.
   */
  public function runAudit(): RedirectResponse {
    if (!$this->currentUser()->hasPermission('administer seo audit')) {
      throw new AccessDeniedHttpException();
    }

    $nids = $this->getAuditableNodeIds();

    if (empty($nids)) {
      $this->messenger()->addWarning($this->t('No nodes found for SEO audit.'));
      return $this->redirect('seo_audit_form.report');
    }

    $batch = $this->buildBatch(
      $nids,
      $this->t('Running SEO Audit for @count nodes', ['@count' => count($nids)])
    );
    batch_set($batch);

    return batch_process(Url::fromRoute('seo_audit_form.report')->toString());
  }

  /**
   * Runs a SEO audit for specific nodes.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the SEO Audit report page after batch starts.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When user doesn't have permission to run audits.
   */
  public function runSelectedAudit(): RedirectResponse {
    if (!$this->currentUser()->hasPermission('administer seo audit')) {
      throw new AccessDeniedHttpException();
    }

    $request = $this->requestStack->getCurrentRequest();
    $nids = $request->query->get('nids', '');

    // Normalize and validate input.
    $input_nids = is_array($nids) ? $nids : [$nids];
    $valid_nids = [];

    foreach ($input_nids as $nid) {
      if (is_numeric($nid) && $nid > 0) {
        $valid_nids[] = $nid;
      }
    }

    if (empty($valid_nids)) {
      $this->messenger()->addError($this->t('No valid nodes selected for audit.'));
      return $this->redirect('seo_audit_form.report');
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $auditable_types = $this->getConfiguredNodeTypes();
    $processed_nids = [];

    foreach ($valid_nids as $nid) {
      $node = $node_storage->load($nid);

      if (!$node) {
        $this->messenger()->addError($this->t('Node not found (ID: @nid).', ['@nid' => $nid]));
        continue;
      }

      if (!$node->isPublished()) {
        $this->messenger()->addWarning($this->t('Node @nid is unpublished and was skipped.', ['@nid' => $nid]));
        continue;
      }

      if (!in_array($node->bundle(), $auditable_types, TRUE)) {
        $this->messenger()->addWarning($this->t('Node type "@type" is not included in audit configuration for node ID @nid.', [
          '@type' => $node->bundle(),
          '@nid' => $nid,
        ]));
        continue;
      }

      $processed_nids[] = $nid;
    }

    if (empty($processed_nids)) {
      $this->messenger()->addError($this->t('No valid nodes available for audit.'));
      return $this->redirect('seo_audit_form.report');
    }

    $batch = $this->buildBatch(
      $processed_nids,
      $this->t('Running SEO Audit for @count node(s)', ['@count' => count($processed_nids)])
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

    // Use accessCheck(FALSE) for administrative operations to improve
    // performance.
    return $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $node_types, 'IN')
      ->condition('status', 1)
      ->accessCheck(FALSE)
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
  protected function buildBatch(array $nids, TranslatableMarkup $title): array {
    $operations = array_map(static function ($nid) {
      return ['\Drupal\seo_audit\Service\AuditService::batchAuditCallback', [$nid]];
    }, $nids);

    return [
      'title' => $title,
      'operations' => $operations,
      'finished' => 'seo_audit_batch_finished',
      'progress_message' => $this->t('Processed @current of @total nodes.'),
      'error_message' => $this->t('An error occurred during the SEO audit process.'),
    ];
  }

}
