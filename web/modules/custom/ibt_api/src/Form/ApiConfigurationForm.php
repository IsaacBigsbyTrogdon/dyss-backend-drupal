<?php

namespace Drupal\ibt_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines a form that configures forms module settings.
 */
class ApiConfigurationForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ibt_api_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'ibt_api.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $config = $this->config('ibt_api.settings');
    $state  = \Drupal::state();
    $form["#attributes"]["autocomplete"] = "off";
    $form['ibt_api'] = array(
      '#type'  => 'fieldset',
      '#title' => $this->t('API settings'),
    );
    $form['ibt_api']['endpoint_overview'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('API Endpoint: Overview'),
      '#default_value' => $config->get('ibt_api.endpoint.overview') ?: '',
    );
    $form['ibt_api']['endpoint_content'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('API Endpoint: Content'),
      '#default_value' => $config->get('ibt_api.endpoint.content') ?: '',
    );
//    $form['ibt_api']['username'] = array(
//      '#type'          => 'textfield',
//      '#title'         => $this->t('Username'),
//      '#default_value' => $config->get('ibt_api.username')?: '',
//    );
//    $form['ibt_api']['password'] = array(
//      '#type'          => 'textfield',
//      '#title'         => $this->t('Password'),
//      '#default_value' => '',
//      '#description'   => t('Leave blank to make no changes, use an invalid string to disable if need be.')
//    );
//    $form['ibt_api']['public_key'] = array(
//      '#type'          => 'textfield',
//      '#title'         => $this->t('Public Key'),
//      '#default_value' => $config->get('ibt_api.public_key'),
//    );
//    $form['ibt_api']['private_key'] = array(
//      '#type'          => 'textfield',
//      '#title'         => $this->t('Private Key'),
//      '#default_value' => '',
//      '#description'   => t('Leave blank to make no changes, use an invalid string to disable if need be.')
//    );
//    $form['ibt_api']['division'] = array(
//      '#type'          => 'textfield',
//      '#title'         => $this->t('Division'),
//      '#default_value' => $config->get('ibt_api.division'),
//    );
//    $form['ibt_api']['territory'] = array(
//      '#type'          => 'textfield',
//      '#title'         => $this->t('Territory'),
//      '#default_value' => $config->get('ibt_api.territory'),
//    );
    $nums   = [
      5, 10, 25, 50, 75, 100, 150, 200, 250, 300, 400, 500, 600, 700, 800, 900,
    ];
    $limits = array_combine($nums, $nums);
    $form['cron_download_limit'] = [
      '#type'          => 'select',
      '#title'         => t('Cron API Download Throttle'),
      '#options'       => $limits,
      '#default_value' => $state->get('ibt_api.cron_download_limit', 100),
    ];
    $form['cron_process_limit'] = [
      '#type'          => 'select',
      '#title'         => t('Cron Queue Node Process Throttle'),
      '#options'       => $limits,
      '#default_value' => $state->get('ibt_api.cron_process_limit', 25),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('ibt_api.settings');
    $state  = \Drupal::state();
    $config->set('ibt_api.endpoint.overview', $values['endpoint_overview']);
    $config->set('ibt_api.endpoint.content', $values['endpoint_content']);
    $config->save();
//    if (!empty($values['private_key'])) {
//      $state->set('ibt_api.private_key', $values['private_key']);
//    }
//    if (!empty($values['password'])) {
//      $state->set('ibt_api.password', $values['password']);
//    }
    $state->set('ibt_api.cron_download_limit', $values['cron_download_limit']);
    $state->set('ibt_api.cron_process_limit', $values['cron_process_limit']);
  }

}
