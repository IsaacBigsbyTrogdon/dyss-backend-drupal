<?php

namespace Drupal\i_importer\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\i_importer\Connector\MixcloudApiConnector;

class ImporterService {

  public $database;

  public $messenger;

  /**
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  public $account;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  private $eventApi = NULL;

  /**
   * Constructor.
   *
   *
   * @param \Drupal\Core\Database\Driver\mysql\Connection $database
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
//   * @param \Drupal\i_importer\Connector\MixcloudApiConnector $eventApi
   */
  public function __construct(
    Connection $database,
    MessengerInterface $messenger,
    AccountProxyInterface $account,
    EntityTypeManagerInterface $entityTypeManager
//    MixcloudApiConnector $eventApi
  ) {
    $this->database          = $database;
    $this->messenger         = $messenger;
    $this->account           = $account;
    $this->entityTypeManager = $entityTypeManager;
    $this->eventApi = new MixcloudApiConnector();
//    $this->eventApi = new MixcloudApiConnector($this->getAccessToken(), $this->url_xing_event_api);
    $this->eventApi->setDefaultParams([
      'headers' => [
        'Accept' => 'application/json',
      ],
      'query' => [
        'format' => 'json',
//        'version' => 1,
      ],
//      'verify' => FALSE,
    ]);
  }

  private $date_format = 'Y-m-d\TH:i:s';

  public function channelOptions(): array {
    $vid = 'channels';
    $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vid);
    foreach ($terms as $term) {
      $data[$term->tid] = $term->name;
    }
    return empty($data) ? [] : $data;
  }

  public function importChannel($tid) {
    $term =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tid);
    $endpoints = $term->get('field_endpoints')->getValue();
    $data = [];
    foreach ($endpoints as $endpoint) {
      if (!isset($endpoint['uri'])) continue;
      $ep =$endpoint['uri'];
      $offset = 0;
      $limit = 20;
      $params = ['query' => ['offset' => $offset, 'limit' => $limit]];
      $import = TRUE;
      while ($import === TRUE) {
        $result = $this->eventApi->query($ep, $params);
        if (isset($result['data'])) {
          $data = array_merge($data, $result['data']);
          $offset = $offset + $limit;
          $params['query']['offset'] = $offset;
          if (isset($result['data']) && empty($result['data'])) {
            $import = FALSE;
          }
        }
      }
    }
    if (!empty($data)) $this->process($data);
  }

  public function process($data) {
    $t=1;
  }

  /**
   * @param $array
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function loadProfiles($array): array {
    return $this->entityTypeManager->getStorage('profile')
      ->loadMultiple($array);
  }


  /**
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *
   * @return \Drupal\profile\Entity\Profile
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createTestProfile($account = NULL) {
    if (!$account) {
      $account = $this->account;
    }
    $build = [
      'type' => $this->test_account_profile_type,
      'uid' => $account->id(),
      'langcode' => $account->getPreferredLangcode(),
    ];

    /** @var \Drupal\profile\Entity\Profile $profile */
    $profile = $this->entityTypeManager->getStorage('profile')->create($build);
    $default_values = $this->getDefaultFieldValues($profile);
    if (isset($default_values['field_account_duration'][0]['value'])) {
      // Set subscription & expiration field_start based on number of days from field_account_duration.
      // Dates are stored in UTC, and formatted for timezone in output.
      $start = new DrupalDateTime('now', 'UTC');
      $date_start = $start->format($this->date_format);
      $end = new DrupalDateTime('now', 'UTC');
      $date_modifier = $this->dateStringModifier($default_values['field_account_duration'][0]['value']);
      $end->modify($date_modifier);
      $date_end = $end->format($this->date_format);
      $profile->set('field_start', $date_start);
      $profile->set('field_end', $date_end);
    }
    $profile->save();
    return $profile;
  }

  /**
   * @param $value
   *
   * @return string
   */
  private function dateStringModifier($value) {
    $day = $value > 1 ? t('Days') : t('Day');
    return implode(' ', ['+', $value, $day]);
  }


}
