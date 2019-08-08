<?php

namespace Drupal\ibt_api\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Database\Database;
use Drupal\ibt_api\Connection\MixcloudConnection;
//use Drupal\ibt_api\mixcloudApi;

/**
 * Updates Tea(s) from mixcloudApi API data.
 *
 * @QueueWorker(
 *   id = "ibt_api_import_worker",
 *   title = @Translation("mixcloudApi Import Worker"),
 *   cron = {"time" = 60}
 * )
 */
class mixCloudApiImportWorker extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $connection = Database::getConnection();
    $gids       = $data['gids'];

    if (empty($gids)) {
      \Drupal::logger('ibt_api')->warning(t('mixcloudApiImportWorker queue with no GPMS IDs!'));
      return;
    }

    $query = $connection->select('ibt_api_tea_staging', 'its');
    $query->fields('its');
    $query->condition('its.gid', $gids, 'IN');
    $results = $query->execute();

    foreach ($results as $row) {
      $gid      = (int) $row->gid;
      $tea_data = unserialize($row->data);

      try {
        $tea = new MixcloudConnection($tea_data);
//        $tea = new mixcloudApi($tea_data);
        $tea->processTea(); // Custom data-to-node processing

        $connection->merge('mixcloud_previous')
          ->key(['gid' => $gid])
          ->insertFields([
            'gid'  => $gid,
            'data' => $row->data,
          ])
          ->updateFields(['data' => $row->data])
          ->execute();

        $query = $connection->delete('ibt_api_tea_staging');
        $query->condition('gid', $gid);
        $query->execute();
      } catch (\Exception $e) {
        watchdog_exception('ibt_api', $e);
      }
    }
  }

}
