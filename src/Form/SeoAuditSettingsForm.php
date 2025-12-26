<?php

namespace Drupal\seo_audit\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure SEO Audit settings for this site.
 */
class SeoAuditSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'seo_audit_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['seo_audit.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('seo_audit.settings');
    $nodes_types = node_type_get_names();

    $form['max_nodes_per_run'] = [
      '#type' => 'number',
      '#title' => $this->t('Max nodes per batch run'),
      '#description' => $this->t('The maximum number of nodes to process in a single batch run. Adjust based on your server capabilities.'),
      '#default_value' => $config->get('max_nodes_per_run') ?? 50,
    ];
    $form['check_external_links'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check external links'),
      '#description' => $this->t('If enabled, the SEO audit will check if external links are reachable. This may slow down the audit process.'),
      '#default_value' => $config->get('check_external_links') ?? TRUE,
    ];
    $form['node_types_to_audit'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types to audit'),
      '#description' => $this->t('Select which content types should be included in the SEO audit.'),
      '#options' => $nodes_types,
      '#default_value' => $config->get('node_types_to_audit'),
    ];
    $options = [
      'text_with_summary' => $this->t('Text (formatted, with summary)'),
      'text_long' => $this->t('Text (formatted, long)'),
      'string' => $this->t('Text (plain)'),
      'link' => $this->t('Link'),
      'entity_reference' => $this->t('Entity reference'),
    ];
    $form['field_types_to_check'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Field types to check for links'),
      '#description' => $this->t('Select which field types should be scanned for links during SEO audits.'),
      '#options' => $options,
      '#default_value' => $config->get('field_types_to_check'),
    ];
    return parent::buildForm($form, $form_state) + $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('seo_audit.settings')
      ->set('check_external_links', $form_state->getValue('check_external_links'))
      ->set('max_nodes_per_run', $form_state->getValue('max_nodes_per_run'))
      ->set('field_types_to_check', $form_state->getValue('field_types_to_check'))
      ->set('node_types_to_audit', array_filter($form_state->getValue('node_types_to_audit')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
