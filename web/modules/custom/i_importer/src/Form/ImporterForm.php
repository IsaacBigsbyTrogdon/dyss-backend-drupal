<?php

namespace Drupal\i_importer\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\i_importer\Service\ImporterService;

/**
 * Class ImporterForm.
 */
class ImporterForm extends FormBase {

  /**
   * Drupal\i_importer\Service\ImporterService definition.
   *
   * @var \Drupal\i_importer\Service\ImporterService
   */
  protected $util;

  /**
   * @param \Drupal\i_importer\Service\ImporterService $i_importer_utility
   */
  public function __construct(
    ImporterService $i_importer_utility
  ) {
    $this->util = $i_importer_utility;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('i_importer.utility')
    );
  }


  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'importer_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['channel'] = [
      '#type' => 'select',
      '#options' => $this->util->channelOptions(),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $tid = $form_state->getValue('channel');
    if ($tid) {
      $this->util->importChannel($tid);
      $name = $form['channel']['#options'][$tid];
      $this->util->messenger->addMessage($name . ' is being imported');
    }
  }

}
