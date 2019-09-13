<?php

namespace Drupal\ibt_api\Form;

use Dflydev\DotAccessData\Data;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ibt_api\Connection\ApiConnection;
use Drupal\ibt_api\UtilityService;

/**
 * Class ApiImportForm
 * Download batch form.
 * Batch operations are included in this class as methods.
 *
 * @package Drupal\ibt_api\Form
 */
class ApiImportForm extends FormBase {

  /**
   * Drupal\ibt_api\UtilityService definition.
   *
   * @var \Drupal\ibt_api\UtilityService
   */
  protected $util;

  protected $connection;

  protected $api;

  /**
   * @param \Drupal\ibt_api\UtilityService $ibt_api_utility
   * @param \Drupal\Core\Database\Connection $connection
   * @param \Drupal\ibt_api\Connection\ApiConnection $api
   */
  public function __construct(UtilityService $ibt_api_utility, Connection $connection, ApiConnection $api) {
    $this->util = $ibt_api_utility;
    $this->connection = $connection;
    $this->api = $api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ibt_api.utility'), Database::getConnection(), new ApiConnection()
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ibt_api_import_form';
  }

  public function getDatabaseConnection() {
    return Database::getConnection();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $options = [
      //      'limit'     => 1,
      //      'url_query' => [
      //        'sort' => 'gid asc',
      //      ]
    ];
    $data = $this->api->queryEndpoint('endpoint.overview', $options);

    if (isset($data->owner)) {
      $label = $data->owner->username ?? t('Unknown');
      $array = ['<label>', t('Channel'), '</label>', '<h2>', $label, '</h2>'];
      $form['channel'] = [
        '#type' => 'container',
        '#title' => t('Owner'),
        'owner' => [
          '#type' => 'markup',
          '#markup' => implode(' ', $array),
        ],
      ];
    }

    $form['count_display'] = [
      '#type'  => 'item',
      '#title' => t('Items Found'),
      'markup'  => [
        '#markup' => $data->cloudcast_count ?? 'Unknown',
      ]
    ];

    $form['count'] = [
      '#type'  => 'value',
      '#value' => $data->cloudcast_count ?? 0,
    ];
    $enabled = isset($data->cloudcast_count);

    $nums   = [
      5, 10, 25, 50, 75, 100, 150, 200, 250, 300, 400, 500, 600, 700, 800, 900,
    ];
    $limits = array_combine($nums, $nums);
    $desc   = 'This is the number of items the API should return each call ' .
      'as the operation pages through the data.';
    $form['download_limit'] = [
      '#type'          => 'select',
      '#title'         => t('API Download Throttle'),
      '#options'       => $limits,
      '#default_value' => 200,
      '#description'   => t($desc),
    ];
    $desc = 'This is the number of items to analyze and save to Drupal as ' .
      'the operation pages through the data. This is labor intensive so ' .
      'usually a lower number than the above throttle';
    $form['process_limit'] = [
      '#type'          => 'select',
      '#title'         => t('Node Process Throttle'),
      '#options'       => $limits,
      '#default_value' => 50,
      '#description'   => t($desc),
    ];

    $form['actions']['#type'] = 'actions';

    $form['actions']['submit'] = [
      '#type'     => 'submit',
      '#value'    => t('Start import'),
      '#disabled' => !$enabled,
    ];

    return $form;
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
            $form_state->getValue('count', 0),
            $form_state->getValue('download_limit', 0),
          ],
        ],
        [
          [$class, 'processItems'],
          [
            $form_state->getValue('process_limit', 0),
          ],
        ],
        [
          [$class, 'downloadPageData'],
          [
            $form_state->getValue('count', 0),
          ],
        ],

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
    // Clear out the staging table for fresh, whole data
    $this->connection->truncate('ibt_api_staging')->execute();
  }

  /**
   * Batch operation to download all of the Items data from Api and store
   * it in the ibt_api_staging database table.
   *
   * @param $api_count
   * @param $limit
   * @param $context
   *
   * @throws \Exception
   */
  public static function downloadChannelData($api_count, $limit, &$context) {
    $database = Database::getConnection();
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox'] = [
        'progress' => 0,
        'limit'    => $limit,
        'max'      => $api_count,
      ];
      $context['results']['downloaded'] = 0;
    }
    $sandbox = &$context['sandbox'];

    $api = new ApiConnection();
    $data   = $api->queryEndpoint('endpoint.content', [
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
        $database->merge('ibt_api_staging')
          ->key('slug')
          ->insertFields([
            'slug'  => $data->slug,
            'data' => serialize($data),
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

  public static function downloadPageData($api_count, &$context) {
    $query = \Drupal::database()->select('node', 'n');
    $query->addField('n', 'nid');
    $query->join('node__field_slug', 's', 's.entity_id = n.nid');
    $query->join('node__field_key', 'k', 'k.entity_id = n.nid');
    $query->condition('n.type', 'blablabla');
    $results = $query->execute()->fetchAllKeyed('n.nid');
    $t=1;
//

    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox'] = [
        'progress' => 0,
        'max'      => $api_count,
      ];
      $context['results']['downloaded'] = 0;
    }
    $sandbox = &$context['sandbox'];

    $api = new ApiConnection();
    $data   = $api->queryEndpoint('endpoint.content', [
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
        $database->merge('ibt_api_staging')
          ->key('slug')
          ->insertFields([
            'slug'  => $data->slug,
            'data' => serialize($data),
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
   * @param $limit
   * @param $context
   *
   * @throws \Exception
   */
  public static function processItems($limit, &$context) {
    $connection = Database::getConnection();
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox'] = [
        'progress' => 0,
        'limit'    => $limit,
        'max'      => (int) $connection->select('ibt_api_staging', 'its')
          ->countQuery()->execute()->fetchField(),
      ];
      $context['results']['items'] = 0;
      $context['results']['nodes']  = 0;
      // Count new versus existing
      $context['results']['nodes_inserted'] = 0;
      $context['results']['nodes_updated']  = 0;
    }
    $sandbox = &$context['sandbox'];

    $query = $connection->select('ibt_api_staging', 's')
      ->fields('s', ['slug', 'data'])
      ->range(0, $sandbox['limit'])
    ;
    /** @var \Drupal\ibt_api\UtilityService $utility */
    $utility = \Drupal::service('ibt_api.utility');
//    $channel = $utility->get('taxonomy_term', 'name', 'Dyssembler Radio on Mixcloud');
    foreach ($query->execute()->fetchAllKeyed() as $slug => $row) {
      $data   = unserialize($row);
      $node_saved = $utility->processApiData('node', 'audio', $data);
      $connection->merge('ibt_api_previous')
        ->key('slug')
        ->insertFields([
          'slug'  => $slug,
        ])->execute();
      $query = $connection->delete('ibt_api_staging');
      $query->condition('slug', $slug);
      $query->execute();

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

