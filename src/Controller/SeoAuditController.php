<?php

namespace Drupal\seo_audit\Controller;

use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for SEO Audit.
 */
class SeoAuditController extends ControllerBase {

  /**
   * Calling the Audit Service.
   *
   * @var \Drupal\seo_audit\Service\AuditService
   */
  protected $auditService;

  /**
   * Constructor.
   */
  public function __construct($audit_service) {
    $this->auditService = $audit_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('seo_audit.audit_service'));
  }

  /**
   * Display SEO Audit report.
   */
  public function report() {
    $header = [
      'title' => $this->t('Title'),
      'score' => $this->t('Score'),
      'last_checked' => $this->t('Last checked'),
      'issues' => $this->t('Issues'),
    ];

    $rows = [];
    $connection = \Drupal::database();

    // // Calculate overall average score
    $sql = "SELECT AVG(score) as avg_score FROM {seo_audit_report}";
    $result = $connection->query($sql)->fetchField();

    $average_score = round((float) $result, 2);

    // Table data with pager.
    $query = $connection->select('seo_audit_report', 's')
      ->fields('s', ['nid', 'issues', 'score', 'last_checked'])
      ->extend(PagerSelectExtender::class)
      ->limit(50);

    $result = $query->execute();

    foreach ($result as $record) {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($record->nid);
      $title = $node ? $node->toLink()->toString() : $this->t('Node @nid', ['@nid' => $record->nid]);
      $issues = json_decode($record->issues, TRUE) ?: [];
      if (empty($issues)) {
        $issues[] = $this->t('No issues found');
      }

      $rows[] = [
        'data' => [
          'title' => ['data' => ['#markup' => $title]],
          'score' => $record->score,
          'last_checked' => date('Y-m-d H:i:s', $record->last_checked),
          'issues' => ['data' => ['#markup' => implode('<br/>', $issues)]],
        ],
      ];
    }

    // Actions: Run full audit + overall score.
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['seo-audit-actions'],
        'style' => ['display: flex;', 'justify-content: space-between;', 'align-items: center;'],
      ],
      'button' => [
        '#type' => 'link',
        '#title' => $this->t('Run full audit'),
        '#url' => Url::fromRoute('seo_audit.run_batch'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'overall_score' => [
        '#markup' => $this->t('Overall SEO score: @score%', ['@score' => $average_score]),
        '#prefix' => '<span class="overall-score" style="margin-left:20px;font-weight:bold;">',
        '#suffix' => '</span>',
      ],
    ];

    // Table.
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No audit data yet. Run the audit.'),
    ];

    // Pager.
    $build['pager'] = ['#type' => 'pager'];

    return $build;
  }

  /**
   * Run SEO Audit as a batch process.
   *
   * @return array
   *   A render array.
   */
  public function runAudit() {
    // Build batch for all nodes.
    $nids = \Drupal::entityQuery('node')->accessCheck()->execute();
    $operations = [];
    foreach ($nids as $nid) {
      $operations[] = ['\Drupal\\seo_audit\\Service\\AuditService::batchAuditCallback', [$nid]];
    }
    $batch = [
      'title' => $this->t('Running SEO Audit'),
      'operations' => $operations,
      'finished' => 'seo_audit_batch_finished',
    ];

    batch_set($batch);
    return batch_process('admin/config/seo-audit');
  }

}
