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
    $form['check_external_links'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check external links'),
      '#default_value' => $config->get('check_external_links') ?? TRUE,
    ];
    $form['max_nodes_per_run'] = [
      '#type' => 'number',
      '#title' => $this->t('Max nodes per batch run'),
      '#default_value' => $config->get('max_nodes_per_run') ?? 50,
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
      '#default_value' => $config->get('field_types_to_check') ?? ['text_with_summary', 'text_long', 'link'],
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
      ->save();

    parent::submitForm($form, $form_state);
  }

}
