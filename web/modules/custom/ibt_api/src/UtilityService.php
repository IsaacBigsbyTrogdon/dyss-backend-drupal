<?php

namespace Drupal\ibt_api;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\file\FileUsage\FileUsageInterface;

/**
 * Class UtilityService.
 */
class UtilityService implements UtilityServiceInterface {

  CONST MODULE_NAME = 'ibt_api';

  /**
   * Drupal\Core\File\FileSystemInterface definition.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Drupal\Core\Messenger\MessengerInterface definition.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Drupal\Core\Session\AccountProxyInterface definition.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Drupal\language\ConfigurableLanguageManagerInterface definition.
   *
   * @var \Drupal\language\ConfigurableLanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Drupal\file\FileUsage\FileUsageInterface definition.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;

  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Drupal\Core\TempStore\PrivateTempStoreFactory definition.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempstore;

  /**
   * @param \Drupal\Core\File\FileSystemInterface
   * @param \Drupal\Core\Messenger\MessengerInterface
   * @param \Drupal\Core\Session\AccountProxyInterface
   * @param \Drupal\language\ConfigurableLanguageManagerInterface
   * @param \Drupal\file\FileUsage\FileUsageInterface
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  public function __construct(FileSystemInterface $file_system,
                              MessengerInterface $messenger,
                              AccountProxyInterface $current_user,
                              ConfigurableLanguageManagerInterface $language_manager,
                              FileUsageInterface $file_usage,
                              EntityTypeManagerInterface $entity_type_manager,
                              PrivateTempStoreFactory $tempstore_private) {
    $this->fileSystem = $file_system;
    $this->messenger = $messenger;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
    $this->fileUsage = $file_usage;
    $this->entityTypeManager = $entity_type_manager;
    $this->tempstore = $tempstore_private;
  }

  public function processApiData($type, $bundle, $data, $channel = NULL) {
    switch ($bundle) {
      case 'audio':
        if ($this->entityExists($type, $bundle, $data->slug)) {
          $this->update($type, $bundle, $data->slug);
        }
        else {
          $data->channel = $channel;
          $this->createNode($bundle, $data);
        }
        $t=1;
        break;
    }
  }

  private function entityExists($type, $bundle, $value) {
    $entityManager = $this->entityTypeManager->getListBuilder($type);
    switch ($type) {
      case 'node':
        switch ($bundle) {
          case 'audio':
            $ids = $entityManager
            ->getStorage()
            ->loadByProperties([
              'type' => $bundle,
              'field_slug' => $value,
            ]);
            $t=1;
            break;
        }
        break; // node
      case 'taxonomy_term':
        return $this->getTerm($value);
        break;
    }
    return FALSE;
  }

  private function createNode($bundle, $data) {
    switch ($bundle) {
      case 'audio':
        if (!empty($data->tags)) {
          $tags = [];
          foreach ($data->tags as $tag) {
            if (!$entity = $this->entityExists('taxonomy_term', 'channel', $tag->name)) {
              $entity = $this->createTerm('tags', $tag);
            }
            $tags[] = $entity;
          }
          if (!empty($data->pictures->large)) {
            $this->createMedia('image', ['url' => $data->pictures->large, 'name' => $data->name]);
          }
          $t=1;
        }
        break;
    }
  }

  private function createMedia($bundle, $data) {
    switch ($bundle) {
      case 'image':
        $filedata = file_get_contents($data['url']);
        $name = $this::sanitize($data['name']);
        $destination = 'public://images/' . $name;
        $file = file_save_data($filedata, $destination,  FileSystemInterface::EXISTS_REPLACE);

        $t=1;
        break;
    }
  }

  public static function sanitize($string){
    $string = ‌‌rawurlencode(str_replace('#', '', str_replace(' ', '-', $string)));
    return $string;
  }

  /**
   * @param $name
   *
   * @return \Drupal\Core\Entity\EntityInterface|mixed|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  public function getTerm($name) {
//    if ($terms = $this->getStore('terms')) {
//      if (isset($terms[$name])) {
//        return $terms[$name];
//      }
//    }
    if (!$item = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $name])) {
      return NULL;
    };
    if (is_array($item)) {
      $term = reset($item);
//      $terms[$name] = $term;
//      $this->setStore('terms', $terms);
      return $term;
    }
    else {
      return NULL;
    }
  }

  /**
   * @param $key
   *
   * @return mixed
   */
  private function getStore($key) {
    return $this->tempstore->get($this::MODULE_NAME)->get($key);
  }

  /**
   * @param $key
   * @param $value
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  private function setStore($key, $value) {
    $this->tempstore->get($this::MODULE_NAME)->set($key, $value);
  }

  private function update($type, $bundle, $entity) {

  }

  /**
   * @param $vid
   * @param $data
   *
   * @return array|\Drupal\Core\Entity\EntityInterface
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function createTerm($vid, $data) {
    switch ($vid) {
      case 'tags':
        $term = [
          'name' => $data->name,
          'vid' => $vid,
          'langcode' => \Drupal::languageManager()->getCurrentLanguage()->getId(),
          'field_url' => $data->url,
          'field_key' => $data->key,
        ];
        $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->create($term);
        $term->save();
        return $term;
        break;
    }
  }

}
