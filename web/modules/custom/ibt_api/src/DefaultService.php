<?php

namespace Drupal\ibt_api;
use Drupal\Core\Path\AliasManagerInterface;

/**
 * Class DefaultService.
 */
class DefaultService {

  /**
   * Drupal\Core\Path\AliasManagerInterface definition.
   *
   * @var \Drupal\Core\Path\AliasManagerInterface
   */
  protected $pathAliasManager;

  /**
   * Constructs a new DefaultService object.
   */
  public function __construct(AliasManagerInterface $path_alias_manager) {
    $this->pathAliasManager = $path_alias_manager;
  }

}
