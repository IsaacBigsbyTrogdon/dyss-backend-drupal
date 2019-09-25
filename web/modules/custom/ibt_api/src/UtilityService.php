<?php

namespace Drupal\ibt_api;

use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\file\Entity\File;
use Drupal\ibt_api\Connection\ApiConnection;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\pathauto\AliasCleanerInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Class UtilityService.
 */
class UtilityService {

  const MODULE_NAME = 'ibt_api';

  const PUBLIC_URI = 'public://';

  const IMAGES = 'images';

  const FIELD_ENDPOINTS = 'field_endpoints';

  const STORE_KEY_IMPORT_CHANNEL = 'import_channel';

  const STORE_KEY_IMPORT_CHANNEL_ENDPOINT = 'import_channel_endpoint';

  const STORE_KEY_IMPORT_PROCESSED_ITEMS = 'import_channel_audio_ids';

  const DB_STAGING = 'ibt_api_staging';

  const DB_IMPORTED = 'ibt_api_imported';

  /**
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;
  /**
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;
  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;
  /**
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;
  /**
   * @var \Drupal\language\ConfigurableLanguageManagerInterface
   */
  protected $languageManager;
  /**
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;
  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;
  /**
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  public $tempStore;
  /**
   * @var \Drupal\pathauto\AliasCleanerInterface
   */
  protected $aliasCleaner;
  /**
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;
  /**
   * @var \Drupal\Core\Database\Driver\mysql\Connection
   */
  public $database;
//  /**
//   * @var \Drupal\Core\Database\Connection
//   */
//  protected $db;
//  /**
//   * @var \Drupal\ibt_api\Connection\ApiConnection
//   */
//  protected $api;

  /**
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   * @param \Drupal\language\ConfigurableLanguageManagerInterface $language_manager
   * @param \Drupal\file\FileUsage\FileUsageInterface $file_usage
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStore
   * @param \Drupal\pathauto\AliasCleanerInterface $alias_cleaner
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   * @param \Drupal\Core\Database\Driver\mysql\Connection $database
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
                              FileSystemInterface $file_system,
                              MessengerInterface $messenger,
                              AccountProxyInterface $current_user,
                              ConfigurableLanguageManagerInterface $language_manager,
                              FileUsageInterface $file_usage,
                              EntityTypeManagerInterface $entity_type_manager,
                              PrivateTempStoreFactory $tempStore,
                              AliasCleanerInterface $alias_cleaner,
                              PathValidatorInterface $path_validator,
                              Connection $database
  ) {
    $this->loggerFactory = $logger_factory;
    $this->fileSystem = $file_system;
    $this->messenger = $messenger;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
    $this->fileUsage = $file_usage;
    $this->entityTypeManager = $entity_type_manager;
    $this->tempStore = $tempStore;
    $this->aliasCleaner = $alias_cleaner;
    $this->pathValidator = $path_validator;
    $this->database = $database;
  }

  /**
   * @param $type
   * @param $bundle
   * @param $data
   *
   * @return \Drupal\Core\Entity\EntityInterface|\Drupal\node\Entity\Node|mixed|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function processApiChannelData($type, $bundle, $data) {
    $entity = NULL;
    $create_entity = FALSE;
    switch ($bundle) {
      case 'audio':
        /** @var \Drupal\node\Entity\Node $entity */
        if (!$entity = $this->entityExists($type, $bundle, $data->slug)) {
          $create_entity = TRUE;
          $entity = Node::create([
            'type' => $bundle,
            'title' => $data->name,
            'uid' => $this->currentUser->id(),
          ]);
        }
        if (!empty($data->tags)) {
          $entity->set('field_tags', []);
          foreach ($data->tags as $tag) {
            if (!$term = $this->entityExists('taxonomy_term', 'tag', $tag->name)) {
              $term = $this->createTerm('tags', $tag);
              $this->messenger->addStatus(t('Term created with tid: @tid', ['@tid' => $term->id()]));
            }
            $entity->get('field_tags')->appendItem($term);
          }
          unset($data->tags);
        }
        if (!empty($data->pictures->extra_large)) {
          $entity->set('field_images', []);
          if (!$media_image = $this->entityExists('media', 'image', $data->pictures->extra_large)) {
            $media_image = $this->createMedia('image', ['url' => $data->pictures->extra_large, 'name' => $data->name]);
            $this->messenger->addStatus(t('Media created with mid: @mid', ['@mid' => $media_image->id()]));
          }
          $entity->get('field_images')->appendItem($media_image);
          unset($data->pictures);
        }
        if (!empty($data->channel)) {
          if ($channel = $this->getEntityBy('node', 'nid', $data->channel)) {
            $entity->set('field_channels', []);
            $entity->get('field_channels')->appendItem($channel);
          }
          unset($data->channel);
        }
        foreach($data as $key => $value) {
          if ($entity->hasField('field_' . $key)) {
            $entity->set('field_' . $key, $value);
          }
        }
        $entity->save();
        $processed = $this->getStore(self::STORE_KEY_IMPORT_PROCESSED_ITEMS);
        $processed += $entity->id();
        $this->setStore(self::STORE_KEY_IMPORT_PROCESSED_ITEMS, $processed);
        break;
    }
    return $create_entity ?: $entity;
  }

  public function getChannelOptions() : array {
    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid', 'title']);
    $query->condition('n.type', 'channel');
    return $query->execute()->fetchAllKeyed();
  }

  /**
   * @param $type
   * @param $bundle
   * @param $value
   *
   * @return \Drupal\Core\Entity\EntityInterface|mixed|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function entityExists($type, $bundle, $value) {
    $entity = NULL;
    switch ($type) {
      case 'node':
        switch ($bundle) {
          case 'audio':
            $entityManager = $this->entityTypeManager->getListBuilder($type);
            $result = $entityManager
              ->getStorage()
              ->loadByProperties([
                'type' => $bundle,
                'field_slug' => $value,
              ]);
            $id = array_keys($result);
            $id = reset($id);
            try {
              $entity = $this->entityTypeManager->getStorage($type)
                ->load($id);
            }
            catch (\Exception $error) {
              $this->loggerFactory->get($this::MODULE_NAME)->alert(t('@err', ['@err' => $error]));
              $this->messenger->addWarning(t('Unable to load entity with id ', ['@key', $id]));
            }
            break;
        }
        break; // node
      case 'taxonomy_term':
        $entity = $this->getEntityBy($type, 'name', $value);
        break;
      case 'media':
        $entity = $this->getEntityBy($type, 'field_url', $value);
        break;

      case 'file':
        $query = $this->database
          ->select('file_managed', 'f');
        $query->addField('f', 'fid');
        $query->condition('f.filename', $value);
        $result = $query->execute()->fetchAllKeyed();
        $id = array_keys($result);
        $id = reset($id);
        try {
          $entity = $this->entityTypeManager->getStorage($type)
            ->load($id);
        }
        catch (\Exception $error) {
          $this->loggerFactory->get($this::MODULE_NAME)->alert(t('@err', ['@err' => $error]));
          $this->messenger->addWarning(t('Unable to load entity with id @key', ['@key', $id]));
        }
        break;
    }
    return $entity;
  }

  private function createNode($bundle, $data, $force = NULL) {

  }

  private function createMedia($bundle, $data) {
    $media = NULL;
    switch ($bundle) {
      case 'image':
        $fileData = file_get_contents($data['url']);
        $name = $data['name'] ?? 'Unknown-name';
        $nameClean = $this->aliasCleaner->cleanString($name);
        $nameClean = ucfirst($nameClean) . '.jpg';
        /** @var  $file */
        if (!$file = $this->entityExists('file', NULL, $nameClean)) {
          $file = $this->createFile($fileData, $nameClean);
          $this->messenger->addStatus(t('File created with fid: @fid', ['@fid' => $file->id()]));
        }
        if ($file instanceof File) {
          $media = Media::create([
            'bundle' => 'image',
            'uid' => $this->currentUser->id(),
            'langcode' => $this->languageManager->getDefaultLanguage()->getId(),
            'status' => 1,
            'field_media_image' => [
              'target_id' => $file->id(),
              'alt' => $name,
              'title' => $name,
            ],
            'field_url' => $data['url'] ?: NULL,
          ]);
          $media->save();
        }
        break;
    }
    return $media;
  }

  /**
   * @param $data
   * @param $name
   *
   * @return \Drupal\file\FileInterface|false
   */
  private function createFile($data, $name) {
    $uri = $this::PUBLIC_URI . $this::IMAGES;
    if (!$this->pathValidator->isValid($uri)) {
      $this->fileSystem->mkdir($uri);
    }
    $destination = implode('/', [$uri, $name]);
    return file_save_data($data, $destination,  $this->fileSystem::EXISTS_REPLACE);
  }

  /**
   * @param $type
   * @param $prop
   * @param $value
   * @param bool $single
   *
   * @return \Drupal\Core\Entity\EntityInterface|mixed|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getEntityBy($type, $prop, $value, $single = TRUE) {
    $item = NULL;
    $result = [];
    switch ($type) {
      case 'taxonomy_term':
      case 'file':
      case 'media':
      case 'node':
        if (!$result = $this->entityTypeManager->getStorage($type)->loadByProperties([$prop => $value])) {
          return NULL;
        }
        elseif (is_array($result)) {
          $item = reset($result);
        }
        break;
    }
    return $single ? $item : $result;
  }


  /**
   * @param $key
   *
   * @return mixed
   */
  public function getStore($key) {
    try {
      return $this->tempStore->get($this::MODULE_NAME)->get($key);
    }
    catch (\Exception $error) {
      $this->loggerFactory->get($this::MODULE_NAME)->alert(t('@err', ['@err' => $error]));
      $this->messenger->addWarning(t('Unable to save @key to tempStore', ['@key', $key]));
    }
  }

  /**
   * @param $key
   * @param $value
   */
  public function setStore($key, $value) {
    try {
      $this->tempStore->get($this::MODULE_NAME)->set($key, $value);
    }
    catch (\Exception $error) {
      $this->loggerFactory->get($this::MODULE_NAME)->alert(t('@err', ['@err' => $error]));
      $this->messenger->addWarning(t('Unable to get @key from tempStore', ['@key', $key]));
    }
  }

  /**
   * @param $key
   * @param $value
   */
  public function deleteStore($key) {
    try {
      $this->tempStore->get($this::MODULE_NAME)->delete($key);
    }
    catch (\Exception $error) {
      $this->loggerFactory->get($this::MODULE_NAME)->alert(t('@err', ['@err' => $error]));
      $this->messenger->addWarning(t('Unable to delete @key from tempStore', ['@key', $key]));
    }
  }

  /**
   * @param $vid
   * @param $data
   *
   * @return array|\Drupal\Core\Entity\EntityInterface
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
        try {
          $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->create($term);
          $term->save();
        }
        catch (\Exception $error) {
          $this->loggerFactory->get($this::MODULE_NAME)->alert(t('@err', ['@err' => $error]));
          $this->messenger->addWarning(t('Save term with name @key', ['@key', $data->name]));
        }
        return $term;
        break;
    }
  }

}
