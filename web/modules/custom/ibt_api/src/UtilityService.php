<?php

namespace Drupal\ibt_api;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\pathauto\AliasCleanerInterface;

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
        $entity = $this->get($type, 'name', $value);
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
          if (!empty($data->pictures->extra_large)) {
            $this->createMedia('image', ['url' => $data->pictures->extra_large, 'name' => $data->name]);
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
        $name = $data['name'] ?? 'Unknown-name';
        $name_clean = $this->aliasCleaner->cleanString($name);
        $name_clean = ucfirst($name_clean) . '.jpg';
        /** @var  $file */
        if ($file = $this->entityExists('file', NULL, $name_clean)) {
          $file->delete();
        }
        $uri = 'public://images';
        $destination = implode('/', [$uri, $name_clean]);
        if (!$this->pathValidator->isValid($uri)) {
          $this->fileSystem->mkdir($uri);
        }
        if ($file = file_save_data($filedata, $destination,  FileSystemInterface::EXISTS_REPLACE)) {
          $this->messenger->addStatus(t('File successively created with FID: @fid', ['@fid' => $file->id()]));

          // Create file entity.
          $image_media = \Drupal\media\Entity\Media::create([
            'bundle' => 'image',
            'uid' => \Drupal::currentUser()->id(),
            'langcode' => $this->languageManager->getDefaultLanguage()->getId(),
            'status' => 1,
            'field_image' => [
              'target_id' => $file->id(),
              'alt' => t('Placeholder image'),
              'title' => t('Placeholder image'),
            ],
          ]);
          $t=1;
          $image_media->save();
        }
        break;
    }
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
