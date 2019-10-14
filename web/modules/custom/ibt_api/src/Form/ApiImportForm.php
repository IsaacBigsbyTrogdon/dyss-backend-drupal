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
 * Download batch form.
 * Batch operations are included in this class as methods.
 *
 * @package Drupal\ibt_api\Form
 */
class ApiImportForm extends FormBase {

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
    return 'api_import_form';
  }

  public function ajaxChannelUpdate(array &$form, FormStateInterface $form_state) {
    if (!$id = $form_state->getValue($this->util::STORE_KEY_IMPORT_CHANNEL)) {
      $this->util->deleteStore($this->util::STORE_KEY_IMPORT_CHANNEL);
      $this->util->deleteStore($this->util::STORE_KEY_IMPORT_CHANNEL_ENDPOINT);
    }
    /** @var \Drupal\node\Entity\Node $node */
    if ($node = $this->util->getStore($this->util::STORE_KEY_IMPORT_CHANNEL)) {
      if ($id !== $node->id()) $load_channel = TRUE;
    }
    else $load_channel = TRUE;
    $node = !isset($load_channel) ? $node : $this->util->entityTypeManager->getStorage('node')->load($id);
    if ($node instanceof Node) {
      if ($node->bundle() === 'channel' && $node->hasField($this->util::FIELD_ENDPOINTS)) {
        if ($endpoints = $node->get($this->util::FIELD_ENDPOINTS)->getValue()) {
          $endpoint = reset($endpoints);
          if (isset($endpoint['uri'])) {
            if (UrlHelper::isValid($endpoint['uri'])) {
              $this->util->setStore($this->util::STORE_KEY_IMPORT_CHANNEL, $node);
              $this->util->setStore($this->util::STORE_KEY_IMPORT_CHANNEL_ENDPOINT, $endpoint['uri']);
            }
          }
        }
      }
    }
    $form_state->setRebuild(true);
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand(NULL, $form));
    return $response;
//    return $form;
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
    /** @var \Drupal\node\Entity\Node $channel */
    $channel = $this->util->getStore($this->util::STORE_KEY_IMPORT_CHANNEL);
    $enabled = isset($channel);
    $endpoint = $this->util->getStore($this->util::STORE_KEY_IMPORT_CHANNEL_ENDPOINT);
    if ($endpoint)
      $data = $this->api->queryEndpoint($endpoint, []);
    $elements[$this->util::STORE_KEY_IMPORT_CHANNEL] = [
      '#type' => 'select2',
      '#title' => t('Select a channel to import'),
      '#options' => $this->util->getChannelOptions(),
      '#default_value' => $channel ? $channel->id() : NULL,
      '#attached' => [
        'library' => [
          'select2/select2.min',
        ],
      ],
      '#ajax' => [
        'callback' => [$this, 'ajaxChannelUpdate'],
        'event' => 'change',
        'wrapper' => self::FORM_WRAPPER_ID,
        'method' => 'replace'
      ],
    ];
    $elements[self::FIELD_LABEL_IMPORT_COUNT] = $enabled ? [
      '#type'  => 'textfield',
      '#title' => t('Items Found'),
      '#value' => $data->cloudcast_count ?? NULL,
      '#disabled' => TRUE,
    ] : [];

    # @TODO - use schema to trigger different download structures.
    if ($channel && $channel->hasField($this->util::FIELD_SCHEMA)) {
      $schema = $channel->get($this->util::FIELD_SCHEMA)->getString();
      $elements[$this->util::FIELD_SCHEMA] = $enabled ? [
        '#type'  => 'textfield',
        '#title' => t('Import schema'),
        '#value' => $schema ?? NULL,
        '#disabled' => TRUE,
      ] : [];
    }

    $elements[$this->util::STORE_KEY_IMPORT_CHANNEL_ENDPOINT] = $enabled ? [
      '#type'  => 'textfield',
      '#title' => t('Endpoint'),
      '#default_value' => $endpoint ?? NULL,
      '#disabled' => TRUE,
    ] : [];


    $limits = $this->getLimitsOptions();
    $elements['limits'] = !$enabled ? [] : [
      self::FIELD_LABEL_API_LIMIT => [
        '#type'          => 'select',
        '#title'         => t('API Download Throttle'),
        '#options'       => $limits,
        '#default_value' => 100,
        '#description'   => t('This is the number of items the API should return each call as the operation pages through the data.'),
      ],
      self::FIELD_LABEL_API_ITEM_LIMIT => [
        '#type'          => 'select',
        '#title'         => t('API Download Item Throttle'),
        '#options'       => $limits,
        '#default_value' => 5,
        '#description'   => t('Single API Calls'),
      ],
      self::FIELD_LABEL_PROCESS_LIMIT => [
        '#type'          => 'select',
        '#title'         => t('Node Process Throttle'),
        '#options'       => $limits,
        '#default_value' => 50,
        '#description'   => t('This is the number of items to analyze and save to Drupal as ' .
          'the operation pages through the data. This is labor intensive so ' .
          'usually a lower number than the above throttle'),
      ],
    ];

    $elements['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type'     => 'submit',
        '#value'    => $enabled ? ('Start import') : 'x',
        '#disabled' => !$enabled,
      ],
    ];

    return $form = [
      'items' => $elements,
      '#prefix' => '<div id="' . self::FORM_WRAPPER_ID . '">',
      '#suffix' => '</div>',
      '#type' => 'container',
      '#attributes' => [
//        'id' => self::FORM_WRAPPER_ID,
      ],
    ];

  }

  /**
   * @return array|false
   */
  private function getLimitsOptions() {
    $nums = [5, 10, 25, 50, 75, 100, 150, 200, 250, 300, 400, 500, 600, 700, 800, 900,];
    return array_combine($nums, $nums);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $helperClass = 'Drupal\ibt_api\Helper\ApiImportHelper';
    $batch = [
      'title'      => t('Downloading & Processing Api Data'),
      'operations' => [
        [
          [$helperClass, 'downloadChannelData'],
          [
            $form_state->getValue($this->util::STORE_KEY_IMPORT_CHANNEL_ENDPOINT),
            $form_state->getValue($this->util::STORE_KEY_IMPORT_CHANNEL),
            $form_state->getValue(self::FIELD_LABEL_IMPORT_COUNT),
            $form_state->getValue(self::FIELD_LABEL_API_LIMIT),
          ],
        ],
        [
          [$helperClass, 'processApiChannelData'],
          [
            $form_state->getValue(self::FIELD_LABEL_PROCESS_LIMIT, 0),
          ],
        ],
        [
          [$helperClass, 'downloadPageData'],
          [
            $form_state->getValue(self::FIELD_LABEL_API_ITEM_LIMIT),
          ],
        ],

      ],
      'finished' => [$helperClass, 'finishedBatch'],
    ];
    batch_set($batch);
    // Lock cron out of processing while these batch operations are being
    // processed
    \Drupal::state()->set('ibt_api.import_semaphore', TRUE);
    // Delete existing queue
//    while ($worker = $this->queue->claimItem()) {
//      $this->queue->deleteItem($worker);
//    }
    // Clear out the staging table.
    $this->db->truncate($this->util::DB_STAGING)->execute();
  }

}

