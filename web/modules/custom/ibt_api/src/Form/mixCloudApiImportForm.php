<?php

namespace Drupal\ibt_api\Form;

use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ibt_api\Connection\MixcloudConnection;
//use Drupal\ibt_api\mixcloudApi;

/**
 * Defines a form that triggers batch operations to download and process Tea
 * data from the mixcloudApi API.
 * Batch operations are included in this class as methods.
 */
class mixCloudApiImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ibt_api_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $connection = new MixcloudConnection();
    $data       = $connection->queryEndpoint('MixCloudDetailsFull', [
//      'limit'     => 1,
      'url_query' => [
        'sort' => 'gid asc',
      ]
    ]);

//    if (empty($data->pagination->total_count)) {
//      $msg  = 'A total count of Teas was not returned, indicating that there';
//      $msg .= ' is a problem with the connection. See ';
//      $msg .= '<a href="/admin/config/services/i-api">the Overview page</a>';
//      $msg .= 'for more details.';
//      drupal_set_message(t($msg), 'error');
//    }
    $t=1;
    $form['count_display'] = [
      '#type'  => 'item',
      '#title' => t('Item Found'),
      'markup'  => [
        '#markup' => '$data->pagination->total_count',
//        '#markup' => $data->pagination->total_count,
      ]
    ];

    $form['count'] = [
      '#type'  => 'value',
      '#value' => '$data->pagination->total_count',
//      '#value' => $data->pagination->total_count,
    ];

    $nums   = [
      5, 10, 25, 50, 75, 100, 150, 200, 250, 300, 400, 500, 600, 700, 800, 900,
    ];
    $limits = array_combine($nums, $nums);
    $desc   = 'This is the number of Teas the API should return each call ' .
      'as the operation pages through the data.';
    $form['download_limit'] = [
      '#type'          => 'select',
      '#title'         => t('API Download Throttle'),
      '#options'       => $limits,
      '#default_value' => 200,
      '#description'   => t($desc),
    ];
    $desc = 'This is the number of items to analyze and save to Drupal as ' .
      'the operation pages through the data.<br />This is labor intensive so ' .
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
//      '#disabled' => empty($data->pagination->total_count),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $connection = Database::getConnection();
    $queue      = \Drupal::queue('ibt_api_import_worker');
    $class      = 'Drupal\ibt_api\Form\mixCloudApiImportForm';
    $batch      = [
      'title'      => t('Downloading & Processing mixcloudApi Tea Data'),
      'operations' => [
        [ // Operation to download all of the teas
          [$class, 'downloadData'], // Static method notation
          [
            $form_state->getValue('count', 0),
            $form_state->getValue('download_limit', 0),
          ],
        ],
        [ // Operation to process & save the tea data
          [$class, 'processPlaylist'], // Static method notation
          [
            $form_state->getValue('process_limit', 0),
          ],
        ],
      ],
      'finished' => [$class, 'finishedBatch'], // Static method notation
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
    $connection->truncate('ibt_api_tea_staging')->execute();
  }

  /**
   * Batch operation to download all of the Items data from mixcloudApi and store
   * it in the ibt_api_tea_staging database table.
   *
   * @param int   $api_count
   * @param array $context
   */
  public static function downloadData($api_count, $limit, &$context) {
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


    $mixcloud = new MixcloudConnection();
    $data   = $mixcloud->queryEndpoint('MixCloudDetailsFull', [
      'limit'     => $sandbox['limit'],
      'url_query' => [
        'offset' => (string) $sandbox['progress'],
        'sort'   => 'gid asc',
      ],
    ]);

    $t=1;
    foreach ($data->response_data as $tea_data) {
      // Check for empty or non-numeric GIDs

      $context['results']['downloaded']++;
      $sandbox['progress']++;
      // Build a message so this isn't entirely boring for admins
      $context['message'] = '<h2>' . t('Downloading API data...') . '</h2>';
      $context['message'] .= t('Queried @c of @t Tea entries.', [
        '@c' => $sandbox['progress'],
        '@t' => $sandbox['max'],
      ]);
    }

    if ($sandbox['max']) {
      $context['finished'] = $sandbox['progress'] / $sandbox['max'];
    }
    // If completely done downloading, set the last time it was done, so that
    // cron can keep the data up to date with smaller queries
    if ($context['finished'] >= 1) {
      $last_time = \Drupal::time()->getRequestTime();
      \Drupal::state()->set('ibt_api.import_last', $last_time);
    }
  }

  /**
   * Batch operation to extra data from the ibt_api_tea_staging table and
   * save it to a new node or one found via GID.
   *
   * @param array $context
   */
  public static function processPlaylist($limit, &$context) {
    $connection = Database::getConnection();
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox'] = [
        'progress' => 0,
        'limit'    => $limit,
        'max'      => (int)$connection->select('ibt_api_tea_staging', 'its')
          ->countQuery()->execute()->fetchField(),
      ];
      $context['results']['teas'] = 0;
      $context['results']['nodes']  = 0;
      // Count new versus existing
      $context['results']['nodes_inserted'] = 0;
      $context['results']['nodes_updated']  = 0;
    }
    $sandbox = &$context['sandbox'];

    $query = $connection->select('ibt_api_tea_staging', 'its')
      ->fields('its')
      ->range(0, $sandbox['limit'])
    ;
    $results = $query->execute();

    foreach ($results as $row) {
      $gid        = (int) $row->gid;
      $tea_data   = unserialize($row->data);
      $tea        = new mixcloudApi($tea_data);
      $node_saved = $tea->processTea(); // Custom data-to-node processing

      $connection->merge('mixcloud_previous')
        ->key(['gid' => $gid])
        ->insertFields([
          'gid'  => $gid,
          'data' => $row->data,
        ])
        ->updateFields(['data' => $row->data])
        ->execute()
      ;

      $query = $connection->delete('ibt_api_tea_staging');
      $query->condition('gid', $gid);
      $query->execute();

      $sandbox['progress']++;
      $context['results']['teas']++;
      // Tally only the nodes saved
      if ($node_saved) {
        $context['results']['nodes']++;
        $context['results']['nodes_' . $node_saved]++;
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
      $msg .= t('Last tea: %t %g %n', [
        '%t' => $tea->getTitle(),
        '%g' => '(GID:' . $gid . ')',
        '%n' => '(node:' . $tea->getNode()->id() . ')',
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
        '@count teas downloaded.'
      );
      $processed  = \Drupal::translation()->formatPlural(
        $results['teas'],
        'One tea processed.',
        '@count teas processed.'
      );
      $saved      = \Drupal::translation()->formatPlural(
        $results['nodes'],
        'One node saved.',
        '@count nodes saved.'
      );
      $inserted   = \Drupal::translation()->formatPlural(
        $results['nodes_inserted'],
        'One was created.',
        '@count were created.'
      );
      $updated    = \Drupal::translation()->formatPlural(
        $results['nodes_updated'],
        'One was updated.',
        '@count were updated.'
      );
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

