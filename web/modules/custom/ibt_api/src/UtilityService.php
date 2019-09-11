<?php

namespace Drupal\ibt_api;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
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

  CONST MODULE_NAME = 'ibt_api';

  CONST PUBLIC_URI = 'public://';

  CONST IMAGES = 'images';


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
   * The alias cleaner.
   *
   * @var \Drupal\pathauto\AliasCleanerInterface
   */
  protected $aliasCleaner;

  /**
   * Drupal\Core\Path\PathValidatorInterface definition.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var \Drupal\Core\Database\Driver\mysql\Connection
   */
  protected $database;

  /**
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   * @param \Drupal\language\ConfigurableLanguageManagerInterface $language_manager
   * @param \Drupal\file\FileUsage\FileUsageInterface $file_usage
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempstore_private
   * @param \Drupal\pathauto\AliasCleanerInterface $alias_cleaner
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   * @param \Drupal\Core\Database\Driver\mysql\Connection $database
   */
  public function __construct(FileSystemInterface $file_system,
                              MessengerInterface $messenger,
                              AccountProxyInterface $current_user,
                              ConfigurableLanguageManagerInterface $language_manager,
                              FileUsageInterface $file_usage,
                              EntityTypeManagerInterface $entity_type_manager,
                              PrivateTempStoreFactory $tempstore_private,
                              AliasCleanerInterface $alias_cleaner,
                              PathValidatorInterface $path_validator,
                              Connection $database) {
    $this->fileSystem = $file_system;
    $this->messenger = $messenger;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
    $this->fileUsage = $file_usage;
    $this->entityTypeManager = $entity_type_manager;
    $this->tempstore = $tempstore_private;
    $this->aliasCleaner = $alias_cleaner;
    $this->pathValidator = $path_validator;
    $this->database = $database;
  }

  /**
   * @param $type
   * @param $bundle
   * @param $data
   * @param null $channel
   *
   * @return \Drupal\Core\Entity\EntityInterface|\Drupal\node\Entity\Node|mixed|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processApiData($type, $bundle, $data, $channel = NULL) {
    $entity = NULL;
    switch ($bundle) {
      case 'audio':
        if ($entity = $this->entityExists($type, $bundle, $data->slug)) {
          $this->update($type, $bundle, $entity, $data);
        }
        else {
          $data->channel = $channel;
          $entity = $this->createNode($bundle, $data);
        }
        break;
    }
    return $entity;
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
            $entity = $this->entityTypeManager->getStorage($type)
              ->load($id);
            break;
        }
        break; // node
      case 'taxonomy_term':
        $entity = $this->get($type, 'name', $value);
        break;
      case 'media':
        $entity = $this->get($type, 'field_url', $value);
        break;

      case 'file':
        $query = $this->database
          ->select('file_managed', 'f');
        $query->addField('f', 'fid');
        $query->condition('f.filename', $value);
        $result = $query->execute()->fetchAllKeyed();
        $id = array_keys($result);
        $id = reset($id);
        $entity = $this->entityTypeManager->getStorage($type)
          ->load($id);
        break;
    }
    return $entity;
  }

  private function createNode($bundle, $data) {
    $node = NULL;
    switch ($bundle) {
      case 'audio':
        /** @var \Drupal\node\Entity\Node $node */
        $node = Node::create([
          'type' => $bundle,
          'title' => $data->name,
          'uid' => $this->currentUser->id(),
        ]);
        if (!empty($data->tags)) {
          foreach ($data->tags as $tag) {
            if (!$term = $this->entityExists('taxonomy_term', 'channel', $tag->name)) {
              $term = $this->createTerm('tags', $tag);
              $this->messenger->addStatus(t('Term created with tid: @tid', ['@mid' => $term->id()]));
            }
            $node->get('field_tags')->appendItem($term);
          }
          unset($data->tags);
        }
        if (!empty($data->pictures->extra_large)) {
          if (!$media_image = $this->entityExists('media', 'image', $data->pictures->extra_large)) {
            $media_image = $this->createMedia('image', ['url' => $data->pictures->extra_large, 'name' => $data->name]);
            $this->messenger->addStatus(t('Media created with mid: @mid', ['@mid' => $media_image->id()]));
          }
          $node->get('field_images')->appendItem($media_image);
          unset($data->pictures);
        }
        if (!empty($data->channel) && $data->channel instanceof Term) {
          $node->get('field_channels')->appendItem($data->channel);
          unset($data->channel);
        }
        foreach($data as $key => $value) {
          if ($node->hasField('field_' . $key)) {
            $node->set('field_' . $key, $value);
          }
        }
        $node->save();
        break;
    }
    return $node;
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
   *
   * @return \Drupal\Core\Entity\EntityInterface|mixed|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function get($type, $prop, $value) {
    $item = NULL;
    switch ($type) {
      case 'taxonomy_term':
      case 'file':
      case 'media':
        if (!$result = $this->entityTypeManager->getStorage($type)->loadByProperties([$prop => $value])) {
          return NULL;
        }
        elseif (is_array($result)) {
          $item = reset($result);
        }
        break;
    }
    return $item;
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

  /**
   * @param $type
   * @param $bundle
   * @param $entity
   * @param $data
   */
  private function update($type, $bundle, $entity, $data) {
    switch ($type) {
      case 'node':
        switch ($bundle) {
          case 'audio':
            // @todo;
            $entity->set('field_created_time', $data->created_time);
            $entity->set('created', strtotime($data->created_time));
            $entity->save();
            break;
        }

        break;
    }
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
