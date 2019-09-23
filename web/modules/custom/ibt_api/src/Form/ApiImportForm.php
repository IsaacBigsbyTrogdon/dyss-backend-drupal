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

  const DB_STAGING = 'ibt_api_staging';

  const DB_IMPORTED = 'ibt_api_imported';

  const FORM_WRAPPER_ID = 'api-import-form-wrapper';

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
  //        if (!isset($endpoint['uri'])) return FALSE;
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
        'wrapper' => self::FORM_WRAPPER_ID
      ],
    ];
    $elements['import_count'] = $enabled ? [
      '#type'  => 'textfield',
      '#title' => t('Items Found'),
      '#value' => $data->cloudcast_count ?? NULL,
      '#disabled' => TRUE,
    ] : [];

    $elements[$this->util::STORE_KEY_IMPORT_CHANNEL_ENDPOINT] = $enabled ? [
      '#type'  => 'textfield',
      '#title' => t('Endpoint'),
      '#default_value' => $endpoint ?? NULL,
      '#disabled' => TRUE,
    ] : [];


    $limits = $this->getLimitsOptions();
    $elements['limits'] = !$enabled ? [] : [
      'download_limit' => [
        '#type'          => 'select',
        '#title'         => t('API Download Throttle'),
        '#options'       => $limits,
        '#default_value' => 200,
        '#description'   => t('This is the number of items the API should return each call as the operation pages through the data.'),
      ],
      'process_limit' => [
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
//      '#prefix' => '<div id="' . self::FORM_WRAPPER_ID . '">',
//      '#suffix' => '</div>',
      '#type' => 'container',
      '#attributes' => [
        'id' => self::FORM_WRAPPER_ID,
      ],
    ];

  }

  private function getLimitsOptions() {
    $nums = [5, 10, 25, 50, 75, 100, 150, 200, 250, 300, 400, 500, 600, 700, 800, 900,];
    return array_combine($nums, $nums);
  }



  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $queue      = \Drupal::queue('ibt_api_import_worker');
    $class      = 'Drupal\ibt_api\Form\ApiImportForm';
    $batch      = [
      'title'      => t('Downloading & Processing Api Data'),
      'operations' => [
        [
          [$class, 'downloadChannelData'],
          [
            $this->api,
            $form_state->getValue($this->util::STORE_KEY_IMPORT_CHANNEL_ENDPOINT),
            $form_state->getValue($this->util::STORE_KEY_IMPORT_CHANNEL),
            $form_state->getValue('count', 0),
            $form_state->getValue('download_limit', 0),
          ],
        ],
//        [
//          [$class, 'processItems'],
//          [
//            $this->api,
//            $this->db,
//            $form_state->getValue('process_limit', 0),
//          ],
//        ],
//        [
//          [$class, 'downloadPageData'],
//          [
//            $form_state->getValue('count', 0),
//          ],
//        ],

      ],
      'finished' => [$class, 'finishedBatch'],
    ];
    batch_set($batch);
    // Lock cron out of processing while these batch operations are being
    // processed
    \Drupal::state()->set('ibt_api.import_semaphore', TRUE);
    // Delete existing queue
    while ($worker = $queue->claimItem()) {
      $queue->deleteItem($worker);
    }
    // Clear out the staging table.
    $this->db->truncate($this::DB_STAGING)->execute();
  }

  /**
   * Batch operation to download all of the Items data from Api and store
   * it in the ibt_api_staging database table.
   *
   * @param \Drupal\ibt_api\Connection\ApiConnection $api
   * @param string $endpoint
   * @param integer $channel_id
   * @param integer $api_count
   * @param integer $limit
   * @param $context
   *
   * @throws \Exception
   */
  public static function downloadChannelData($api, $endpoint, $channel_id, $api_count, $limit, &$context) {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox'] = [
        'progress' => 0,
        'limit'    => $limit,
        'max'      => $api_count,
      ];
      $context['results']['downloaded'] = 0;
    }
    $sandbox = &$context['sandbox'];
    $data   = $api->queryEndpoint($endpoint . '/cloudcasts', [
      'limit'     => $sandbox['limit'],
      'url_query' => [
        'offset' => (string) $sandbox['progress'],
        'sort'   => 'gid asc',
      ],
    ]);
    if (empty($data->data)) {
      # no results.
    }
    else {
      $database = Database::getConnection();
      foreach ($data->data as $data) {
        if (empty($data->slug)) {
          $msg = t('Empty Slug at progress @p for the data:', [
            '@p' => $sandbox['progress'],
          ]);
          $msg .= '<br /><pre>' . print_r($data, TRUE) . '</pre>';
          \Drupal::logger('ibt_api')->warning($msg);
          $sandbox['progress']++;
          continue;
        }
        // Store the data
        $database->merge(self::DB_STAGING)
          ->key('slug')
          ->insertFields([
            'slug'  => $data->slug,
            'data' => serialize($data),
            'channel_id' => $channel_id,
          ])
          ->updateFields(['data' => serialize($data)])
          ->execute()
        ;
        $context['results']['downloaded']++;
        $sandbox['progress']++;
        $context['message'] = '<h2>' . t('Downloading API data...') . '</h2>';
        $context['message'] .= t('Queried @c of @t entries.', [
          '@c' => $sandbox['progress'],
          '@t' => $sandbox['max'],
        ]);
      }

    }

    if ($sandbox['max']) {
      $context['finished'] = $sandbox['progress'] / $sandbox['max'];
    }
    // If completely done downloading, set the last time it was done, so that
    // cron can keep the data up to date with smaller queries
//    if ($context['finished'] >= 1) {
//      $last_time = \Drupal::time()->getRequestTime();
//      \Drupal::state()->set('iguana.tea_import_last', $last_time);
//    }

  }

  /**
   * Batch operation to extra data from the ibt_api_staging table and
   * save it to a new node or one found via GID.
   *
   * @param \Drupal\ibt_api\Connection\ApiConnection $api
   * @param \Drupal\Core\Database\Connection $database
   * @param string $endpoint
   * @param $limit
   * @param $context
   *
   * @throws \Exception
   */
  public static function processItems($api, $database, $endpoint, $limit, &$context) {
    $connection = Database::getConnection();
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox'] = [
        'progress' => 0,
        'limit'    => $limit,
        'max'      => (int) $connection->select(self::DB_STAGING, 'its')
          ->countQuery()->execute()->fetchField(),
      ];
      $context['results']['items'] = 0;
      $context['results']['nodes']  = 0;
      // Count new versus existing
      $context['results']['nodes_inserted'] = 0;
      $context['results']['nodes_updated']  = 0;
    }
    $sandbox = &$context['sandbox'];

    $query = $connection->select(self::DB_STAGING, 's')
      ->fields('s', ['slug', 'data'])
      ->range(0, $sandbox['limit'])
    ;
    /** @var \Drupal\ibt_api\UtilityService $utility */
    $utility = \Drupal::service('ibt_api.utility');
//    $channel = $utility->get('taxonomy_term', 'name', 'Dyssembler Radio on Mixcloud');
    foreach ($query->execute()->fetchAllKeyed() as $slug => $row) {
      $data   = unserialize($row);
      $node_saved = $utility->processApiData('node', 'audio', $data);
      $connection->merge(self::DB_IMPORTED)
        ->key('slug')
        ->insertFields([
          'slug'  => $slug,
        ])->execute();
      $query = $connection->delete(self::DB_STAGING);
//      $query->condition('slug', $slug);
//      $query->execute();

      $sandbox['progress']++;
      $context['results']['items']++;
      // Tally only the nodes saved
      if ($node_saved) {
        $context['results']['nodes']++;
        $context['results']['nodes_saved']++;
      }

      // Build a message so this isn't entirely boring for admins
      $msg = '<h2>' . t('Processing API data to site content...') . '</h2>';
      $msg .= t('Processed @p of @t Teas, @n new & @u updated', [
        '@p' => $sandbox['progress'],
        '@t' => $sandbox['max'],
        '@n' => $context['results']['nodes_inserted'],
        '@u' => $context['results']['nodes_updated'],
      ]);
      $msg .= '<br />';
      $msg .= t('Last item: %t %n', [
        '%t' => $node_saved->getTitle(),
        '%n' => '(nid:' . $node_saved->id() . ')',
      ]);
      $context['message'] = $msg;
    }

    if ($sandbox['max']) {
      $context['finished'] = $sandbox['progress'] / $sandbox['max'];
    }
  }


  /**
   * @param \Drupal\ibt_api\Connection\ApiConnection $api
   * @param \Drupal\Core\Database\Connection $database
   * @param string $endpoint
   * @param $api_count
   * @param $context
   *
   * @throws \Exception
   */
//  public static function downloadPageData($api, $database, $endpoint, $api_count, &$context) {
//    if (!isset($context['sandbox']['progress'])) {
//      $context['sandbox'] = [
//        'progress' => 0,
//        'max'      => $api_count,
//      ];
//      $context['results']['downloaded'] = 0;
//    }
//    $sandbox = &$context['sandbox'];
//    $data   = $api->queryEndpoint($endpoint, [
//      'limit'     => $sandbox['limit'],
//      'url_query' => [
//        'offset' => (string) $sandbox['progress'],
//        'sort'   => 'gid asc',
//      ],
//    ]);
//    if (empty($data->data)) {
//      # no results.
//    }
//    else {
//      foreach ($data->data as $data) {
//        if (empty($data->slug)) {
//          $msg = t('Empty Slug at progress @p for the data:', [
//            '@p' => $sandbox['progress'],
//          ]);
//          $msg .= '<br /><pre>' . print_r($data, TRUE) . '</pre>';
//          \Drupal::logger('ibt_api')->warning($msg);
//          $sandbox['progress']++;
//          continue;
//        }
//        // Store the staging data.
//        $database->merge(self::DB_STAGING)
//          ->key('slug')
//          ->insertFields([
//            'slug'  => $data->slug,
//            'data' => serialize($data),
//          ])
//          ->updateFields(['data' => serialize($data)])
//          ->execute()
//        ;
//        $context['results']['downloaded']++;
//        $sandbox['progress']++;
//        $context['message'] = '<h2>' . t('Downloading API data...') . '</h2>';
//        $context['message'] .= t('Queried @c of @t entries.', [
//          '@c' => $sandbox['progress'],
//          '@t' => $sandbox['max'],
//        ]);
//      }
//
//    }
//
//    if ($sandbox['max']) {
//      $context['finished'] = $sandbox['progress'] / $sandbox['max'];
//    }
//    // If completely done downloading, set the last time it was done, so that
//    // cron can keep the data up to date with smaller queries
//    //    if ($context['finished'] >= 1) {
//    //      $last_time = \Drupal::time()->getRequestTime();
//    //      \Drupal::state()->set('iguana.tea_import_last', $last_time);
//    //    }
//
//  }


  /**
   * Reports the results of the Tea import operations.
   *
   * @param bool  $success
   * @param array $results
   * @param array $operations
   */
  public static function finishedBatch($success, $results, $operations) {
    // Unlock to allow cron to update the data later
    \Drupal::state()->set('ibt_api.import_semaphore', FALSE);
    // The 'success' parameter means no fatal PHP errors were detected. All
    // other error management should be handled using 'results'.
    $downloaded = t('Finished with an error.');
    $processed  = FALSE;
    $saved      = FALSE;
    $inserted   = FALSE;
    $updated    = FALSE;
    if ($success) {
      $downloaded = \Drupal::translation()->formatPlural(
        $results['downloaded'],
        'One tea downloaded.',
        '@count items downloaded.'
      );
//      $processed  = \Drupal::translation()->formatPlural(
//        $results['items'],
//        'One tea processed.',
//        '@count items processed.'
//      );
//      $saved      = \Drupal::translation()->formatPlural(
//        $results['nodes'],
//        'One node saved.',
//        '@count nodes saved.'
//      );
//      $inserted   = \Drupal::translation()->formatPlural(
//        $results['nodes_inserted'],
//        'One was created.',
//        '@count were created.'
//      );
//      $updated    = \Drupal::translation()->formatPlural(
//        $results['nodes_updated'],
//        'One was updated.',
//        '@count were updated.'
//      );
    }
    drupal_set_message($downloaded);
    if ($processed) {
      drupal_set_message($processed);
    };
    if ($saved) {
      drupal_set_message($saved);
    };
    if ($inserted) {
      drupal_set_message($inserted);
    };
    if ($updated) {
      drupal_set_message($updated);
    };
  }

}

