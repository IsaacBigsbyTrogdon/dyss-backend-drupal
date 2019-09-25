<?php

namespace Drupal\ibt_api\Helper;

use Drupal\Core\Database\Database;
use Drupal\ibt_api\Connection\ApiConnection;
use Drupal\node\Entity\Node;

class ApiImportHelper {

  /**
   * Batch operation to download all of the Items data from Api and store
   * it in the ibt_api_staging database table.
   *
   * @param \Drupal\ibt_api\UtilityService $util
   * @param string $endpoint
   * @param integer $channel_id
   * @param integer $api_count
   * @param integer $limit
   * @param $context
   *
   * @throws \Exception
   */
  public static function downloadChannelData($endpoint, $channel_id, $api_count, $limit, &$context) {
    $api = new ApiConnection;
    $util = \Drupal::service('ibt_api.utility');
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox'] = [
        'progress' => 0,
        'limit'    => $limit,
        'max'      => $api_count,
      ];
      $context['results']['downloaded'] = 0;
    }
    $sandbox = &$context['sandbox'];
    # @TODO: develop import schema pattern to deal with various import structures.
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
        $database->merge($util::DB_STAGING)
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

    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] >= $context['sandbox']['max'];
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
   * @param $limit
   * @param $context
   *
   * @throws \Exception
   */
  public static function processItems($limit, &$context) {
    /** @var \Drupal\ibt_api\UtilityService $utility */
    $utility = \Drupal::service('ibt_api.utility');
    $connection = Database::getConnection();
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox'] = [
        'progress' => 0,
        'limit'    => $limit,
        'max'      => (int) $connection->select($utility::DB_STAGING, 's')
          ->countQuery()->execute()->fetchField(),
      ];
      $context['results']['items'] = 0;
      $context['results']['nodes']  = 0;
      // Count new versus existing
      $context['results']['nodes_inserted'] = 0;
      $context['results']['nodes_updated']  = 0;
    }
    $sandbox = &$context['sandbox'];

    $query = $connection->select($utility::DB_STAGING, 's')
      ->fields('s', ['slug', 'channel_id', 'data'])
      ->range($sandbox['progress'], $sandbox['limit'])
    ;
    foreach ($query->execute()->fetchAllAssoc('slug') as $slug => $row) {
      $data = unserialize($row->data);
      $data->channel = $row->channel_id;
      if ($node_saved = $utility->processApiChannelData('node', 'audio', $data)) {
        if (!$node_saved instanceof Node) {
          $connection->merge($utility::DB_IMPORTED)
            ->key('slug')
            ->insertFields([
              'slug'  => $slug,
            ])->execute();
        }
      }

      $query = $connection->delete($utility::DB_STAGING);
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
      $msg .= t('Processed @p of @t items, @n new & @u updated', [
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
   * @param Node $channel
   * @param int $limit
   * @param $context
   *
   * @throws \Exception
   */
    public static function downloadPageData($channel, $limit, &$context) {
      /** @var \Drupal\ibt_api\UtilityService $utility */
      $utility = \Drupal::service('ibt_api.utility');
      $api = new ApiConnection;
      $connection = Database::getConnection();

      if (!isset($context['sandbox']['progress'])) {
        $context['sandbox'] = [
          'progress' => 0,
          'limit'    => $limit,
          'max' => sizeof($utility->getStore($utility::STORE_KEY_IMPORT_PROCESSED_ITEMS)),
        ];
      }
      $sandbox = &$context['sandbox'];

      $i = $sandbox['progress'];
      $y = $i + $limit;
      for ($i; $i <= $y; $i++) {
        $id = $utility->getStore($utility::STORE_KEY_IMPORT_PROCESSED_ITEMS)[$i];
        $t=1;
      }

      $data   = $api->queryEndpoint($endpoint, [
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
          // Store the staging data.
          $database->merge(self::DB_STAGING)
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
