<?php

namespace Drupal\seo_audit\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\seo_audit\Service\AuditService;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a form for viewing and filtering SEO audit reports.
 */
final class SeoAuditForm extends FormBase {

  /**
   * The audit service.
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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new SeoAuditForm instance.
   *
   * @param \Drupal\seo_audit\Service\AuditService $audit_service
   *   The SEO audit service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   */
  public function __construct(
    AuditService $audit_service,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    DateFormatterInterface $date_formatter,
    LoggerChannelFactoryInterface $logger_factory,
    RequestStack $request_stack,
  ) {
    $this->auditService = $audit_service;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->logger = $logger_factory->get('seo_audit');
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
      $container->get('date.formatter'),
      $container->get('logger.factory'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'seo_audit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->requestStack->getCurrentRequest();

    // Retrieve filter query parameters.
    $filters = [
      'content_name' => $request->query->get('content_name', ''),
      'content_type' => $request->query->get('content_type', ''),
      'status' => $request->query->get('status', ''),
    ];

    $form['#title'] = $this->t('SEO Audit Report');

    // === Filter section ===
    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter Options'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['seo-audit-filters']],
    ];

    $form['filters']['content_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Content Name'),
      '#default_value' => $filters['content_name'],
      '#description' => $this->t('Enter part of a content title to filter results.'),
      '#attributes' => ['placeholder' => $this->t('Enter title...')],
    ];

    $form['filters']['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#options' => ['' => $this->t('- All -')] + node_type_get_names(),
      '#default_value' => $filters['content_type'],
    ];

    $form['filters']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Audit Status'),
      '#options' => [
        '' => $this->t('- All -'),
        'passed' => $this->t('Passed'),
        'issues' => $this->t('Issues'),
      ],
      '#default_value' => $filters['status'],
    ];

    // Filter action buttons.
    $form['filters']['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['filter-actions']],
    ];

    $form['filters']['actions']['filter'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply Filters'),
      '#button_type' => 'primary',
    ];

    $form['filters']['actions']['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset Filters'),
      '#submit' => ['::resetForm'],
      '#limit_validation_errors' => [],
    ];

    // === Audit action buttons ===
    $form['audit_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['seo-audit-actions']],
    ];

    $form['audit_actions']['run_full_audit'] = [
      '#type' => 'link',
      '#title' => $this->t('Run Full Audit'),
      '#url' => Url::fromRoute('seo_audit.run_batch'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    // === Results Table ===
    $form['results_table'] = $this->buildResultsTable(
      $filters['content_name'],
      $filters['content_type'],
      $filters['status']
    );

    // Add CSS for styling.
    $form['#attached']['library'][] = 'seo_audit/audit_form';

    return $form;
  }

  /**
   * Builds the SEO audit results table.
   *
   * @param string $content_name
   *   The content name filter.
   * @param string $content_type
   *   The content type filter.
   * @param string $status
   *   The audit status filter.
   *
   * @return array
   *   A renderable table array.
   */
  protected function buildResultsTable(string $content_name, string $content_type, string $status): array {
    $header = [
      'title' => $this->t('Title'),
      'type' => $this->t('Content Type'),
      'audit_status' => $this->t('Audit Status'),
      'last_checked' => $this->t('Last Checked'),
      'issues' => $this->t('Issues'),
      'operations' => $this->t('Operations'),
    ];

    try {
      // Preload node types for performance.
      $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
      $node_type_labels = [];
      foreach ($node_types as $type) {
        $node_type_labels[$type->id()] = $type->label();
      }

      // Create query with optional filters.
      $query = $this->database->select('seo_audit_report', 's')
        ->fields('s')
        ->extend(PagerSelectExtender::class)
        ->limit(50);

      // Join with node data to access node fields and filter by published nodes.
      $query->leftJoin('node_field_data', 'n', 's.nid = n.nid');

      // Only published nodes.
      $query->condition('n.status', 1);

      // Apply filters.
      if ($content_name !== '') {
        $query->condition('n.title', '%' . $this->database->escapeLike($content_name) . '%', 'LIKE');
      }
      if ($content_type !== '') {
        $query->condition('n.type', $content_type);
      }
      if ($status !== '') {
        $query->condition('s.status', $status);
      }

      // Order by last checked date (newest first).
      $query->orderBy('s.last_checked', 'DESC');

      $results = $query->execute();
      $rows = [];

      foreach ($results as $record) {
        // Load node with access check.
        $node = $this->entityTypeManager->getStorage('node')->load($record->nid);

        // Skip if node doesn't exist or user doesn't have access.
        if (!$node || !$node->access('view')) {
          continue;
        }

        // Node title with link.
        $title = $node->toLink()->toString();

        // Node type label.
        $node_type_label = $node_type_labels[$node->bundle()] ?? $node->bundle();

        // Audit status with proper formatting.
        $audit_status = $record->status ? ucfirst($record->status) : $this->t('Not audited');

        // Format last checked date with timezone.
        $last_checked = $record->last_checked
          ? $this->dateFormatter->format($record->last_checked, 'short')
          : $this->t('Never');

        // Issues list with safe rendering.
        $issues = json_decode($record->issues, TRUE) ?: [];
        $issues_markup = $this->formatIssuesList($issues);

        // Operation links.
        $operations = $this->buildOperationLinks($record->nid);

        $rows[] = [
          'title' => ['data' => ['#markup' => $title]],
          'type' => ['data' => ['#plain_text' => $node_type_label]],
          'audit_status' => [
            'data' => ['#plain_text' => $audit_status],
            'class' => ['audit-status', 'audit-status--' . ($record->status ?: 'none')],
          ],
          'last_checked' => ['data' => ['#plain_text' => $last_checked]],
          'issues' => ['data' => $issues_markup],
          'operations' => ['data' => $operations],
        ];
      }

      // Handle empty results.
      if (empty($rows)) {
        $rows[] = [
          [
            'data' => $this->t('No audit results found matching the current filters.'),
            'colspan' => count($header),
            'class' => ['empty-message'],
          ],
        ];
      }

      return [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No audit records available. Run an audit to generate reports.'),
        '#attributes' => ['class' => ['seo-audit-results']],
        '#sticky' => TRUE,
      ];

    }
    catch (\Exception $e) {
      // Log error and display user-friendly message.
      $this->logger->error('Error building SEO audit results table: @error', [
        '@error' => $e->getMessage(),
      ]);

      return [
        '#markup' => $this->t('Unable to load audit results due to a system error. Please try again later.'),
        '#prefix' => '<div class="messages messages--error">',
        '#suffix' => '</div>',
      ];
    }
  }

  /**
   * Formats the issues list for safe display.
   *
   * @param array $issues
   *   The issues array from the audit report.
   *
   * @return array
   *   A renderable array for the issues list.
   */
  protected function formatIssuesList(array $issues): array {
    if (empty($issues)) {
      return [
        '#markup' => '<em>' . $this->t('No issues found') . '</em>',
      ];
    }

    // Create a safe item list.
    $items = [];
    foreach ($issues as $issue) {
      $items[] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $issue ?? '',
        '#attributes' => [
          'class' => ['seo-issue-item'],
        ],
      ];
    }
    // dd($items);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['seo-issues-list']],
      'items' => $items,
    ];
  }

  /**
   * Builds operation links for a node.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return array
   *   A renderable array of operation links.
   */
  protected function buildOperationLinks(int $nid): array {
    $links = [];

    // Edit link.
    $links['edit'] = [
      '#type' => 'link',
      '#title' => $this->t('Edit'),
      '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $nid]),
      '#attributes' => [
        'class' => ['button', 'button--small', 'button--edit'],
        'title' => $this->t('Edit this content'),
      ],
    ];

    // Re-run audit link.
    $links['re_run_audit'] = [
      '#type' => 'link',
      '#title' => $this->t('Re-run Audit'),
      '#url' => Url::fromRoute('seo_audit.run_selected', ['nid' => $nid]),
      '#attributes' => [
        'class' => ['button', 'button--small', 'button--danger'],
        'title' => $this->t('Re-run SEO audit for this content'),
      ],
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['operations-links']],
      'links' => $links,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Handles filter submission and redirects with query parameters.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $params = array_filter([
      'content_name' => $form_state->getValue('content_name'),
      'content_type' => $form_state->getValue('content_type'),
      'status' => $form_state->getValue('status'),
    ]);

    // Use form state redirect instead of direct response.
    $form_state->setRedirect('<current>', [], ['query' => $params]);
  }

  /**
   * Custom submit handler to reset all filters.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   */
  public function resetForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('<current>');
  }

}
