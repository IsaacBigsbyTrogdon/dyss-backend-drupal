<?php

namespace Drupal\ibt_api\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormBase;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ibt_api\Connection\ApiConnection;
use Drupal\ibt_api\UtilityService;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Class ApiImportForm
 *
 * @package Drupal\ibt_api\Form
 */
class DataProcessForm extends FormBase {

  /**
   * @var \Drupal\ibt_api\UtilityService
   */
  protected $util;

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $db;

  /**
   * @var \Drupal\ibt_api\Connection\ApiConnection
   */
  protected $api;

  /**
   * ApiImportForm constructor.
   *
   * @param \Drupal\ibt_api\UtilityService $ibt_api_utility
   * @param \Drupal\Core\Database\Connection $db
   * @param \Drupal\ibt_api\Connection\ApiConnection $api
   */
  public function __construct(
    UtilityService $ibt_api_utility,
    Connection $db,
    ApiConnection $api
  ) {
    $this->util = $ibt_api_utility;
    $this->db = $db;
    $this->api = $api;
    $this->queue = \Drupal::queue('ibt_api_import_worker');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ibt_api.utility'),
      Database::getConnection(),
      new ApiConnection()
    );
  }

  const FORM_WRAPPER_ID = 'api-import-form-wrapper';

  const FIELD_LABEL_IMPORT_COUNT = 'import_count';

  const FIELD_LABEL_API_LIMIT = 'download_limit';

  const FIELD_LABEL_API_ITEM_LIMIT = 'download_item_limit';

  const FIELD_LABEL_PROCESS_LIMIT = 'process_limit';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'api_process_data_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    return $this->formElements($form, $form_state, $request);
  }

  /**
   * @inheritDoc
   */
  public function formElements(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $elements['process_name'] = [
      '#type' => 'select2',
      '#title' => t('Select a method to process data.'),
      '#options' => [
        0 => t('Extract sub-headline')
      ],
      '#default_value' => 0,
      '#attached' => [
        'library' => [
          'select2/select2.min',
        ],
      ],
    ];
    $elements['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type'     => 'submit',
        '#value'    => ('Start process'),
      ],
    ];
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $helperClass = 'Drupal\ibt_api\Helper\ApiDataHelper';
    $batch = [
      'title'      => t('Downloading & Processing Api Data'),
      'operations' => [
        [
          [$helperClass, 'deleteItems'], [],
        ],
        [
          [$helperClass, 'processData'],
          [
            $form_state->getValue('process_name'),
          ],
        ],
      ],
      'finished' => [$helperClass, 'finishedBatch'],
    ];
    batch_set($batch);
  }
}

