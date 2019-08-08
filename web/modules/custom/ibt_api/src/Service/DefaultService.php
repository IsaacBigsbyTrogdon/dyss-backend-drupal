<?php

namespace Drupal\ibt_api;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Class DefaultService.
 */
class DefaultService implements DefaultServiceInterface {

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var \Drupal\Core\Database\Driver\mysql\Connection
   */
  protected $database;
  /**
   * Drupal\Core\Routing\CurrentRouteMatch definition.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $currentRouteMatch;
  /**
   * Drupal\Core\Session\AccountProxyInterface definition.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;
  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;
  /**
   * Drupal\Component\Datetime\TimeInterface definition.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $datetimeTime;
  /**
   * @param \Drupal\Core\Database\Driver\mysql\Connection
   * @param \Drupal\Core\Routing\CurrentRouteMatch
   * @param \Drupal\Core\Session\AccountProxyInterface
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface
   * @param \Drupal\Component\Datetime\TimeInterface
   */
  public function __construct(Connection $database, CurrentRouteMatch $current_route_match, AccountProxyInterface $current_user, EntityTypeManagerInterface $entity_type_manager, TimeInterface $datetime_time) {
    $this->database = $database;
    $this->currentRouteMatch = $current_route_match;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->datetimeTime = $datetime_time;
  }

}
