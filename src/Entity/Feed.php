<?php

/**
 * @file
 * Contains \Drupal\feeds\Entity\Feed.
 */

namespace Drupal\feeds\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\feeds\Event\DeleteFeedsEvent;
use Drupal\feeds\Event\EventDispatcherTrait;
use Drupal\feeds\Event\FeedsEvents;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Plugin\Type\FeedsPluginInterface;
use Drupal\feeds\Result\FetcherResultInterface;
use Drupal\feeds\State;
use Drupal\feeds\StateInterface;
use Drupal\user\UserInterface;

/**
 * Defines the feed entity class.
 *
 * @ContentEntityType(
 *   id = "feeds_feed",
 *   label = @Translation("Feed"),
 *   bundle_label = @Translation("Importer"),
 *   module = "feeds",
 *   handlers = {
 *     "storage" = "Drupal\feeds\FeedStorageController",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "access" = "Drupal\feeds\FeedAccessController",
 *     "form" = {
 *       "create" = "Drupal\feeds\FeedFormController",
 *       "update" = "Drupal\feeds\FeedFormController",
 *       "delete" = "Drupal\feeds\Form\FeedDeleteForm",
 *       "import" = "Drupal\feeds\Form\FeedImportForm",
 *       "clear" = "Drupal\feeds\Form\FeedClearForm",
 *       "unlock" = "Drupal\feeds\Form\FeedUnlockForm",
 *       "default" = "Drupal\feeds\FeedFormController"
 *     },
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "feed_import" = "Drupal\feeds\FeedImportHandler",
 *     "feed_clear" = "Drupal\feeds\FeedClearHandler",
 *     "feed_expire" = "Drupal\feeds\FeedExpireHandler"
 *   },
 *   base_table = "feeds_feed",
 *   uri_callback = "feeds_feed_uri",
 *   fieldable = TRUE,
 *   entity_keys = {
 *     "id" = "fid",
 *     "bundle" = "importer",
 *     "label" = "title",
 *     "uuid" = "uuid"
 *   },
 *   permission_granularity = "bundle",
 *   bundle_entity_type = "feeds_importer",
 *   field_ui_base_route = "feeds.importer_edit",
 *   links = {
 *     "canonical" = "feeds.view",
 *     "delete-form" = "feeds.delete",
 *     "edit-form" = "feeds.edit",
 *     "admin-form" = "feeds.importer_edit"
 *   }
 * )
 */
class Feed extends ContentEntityBase implements FeedInterface {
  use EventDispatcherTrait;

  /**
   * {@inheritdoc}
   */
  public function id() {
    return $this->get('fid')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function label($langcode = NULL) {
    return $this->get('title')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSource() {
    return $this->get('source')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', (int) $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getChangedTime() {
    return $this->get('changed')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getImportedTime() {
    return $this->get('imported')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getImporter() {
    return $this->get('importer')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    return $this->get('uid')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    return $this->get('uid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    $this->set('uid', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account) {
    $this->set('uid', $account->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isActive() {
    return (bool) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setActive($active) {
    $this->set('status', $active ? self::ACTIVE : self::INACTIVE);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function preview() {
    $result = $this->getImporter()->getFetcher()->fetch($this);
    $result = $this->getImporter()->getParser()->parse($this, $result);
    \Drupal::moduleHandler()->invokeAll('feeds_after_parse', array($this, $result));
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function startImport() {
    \Drupal::moduleHandler()->invokeAll('feeds_before_import', array($this));
    $this->getImporter()->getPlugin('manager')->startImport($this);
  }

  /**
   * {@inheritdoc}
   */
  public function startClear() {
    \Drupal::moduleHandler()->invokeAll('feeds_before_clear', array($this));
    $this->getImporter()->getPlugin('manager')->startClear($this);
  }

  /**
   * {@inheritdoc}
   */
  public function import() {
    return $this->entityManager()
      ->getHandler('feeds_feed', 'feed_import')
      ->import($this);
  }

  /**
   * {@inheritdoc}
   */
  public function importRaw($raw) {
    return $this->entityManager()
      ->getHandler('feeds_feed', 'feed_import')
      ->pushImport($this, $raw);
  }

  /**
   * {@inheritdoc}
   */
  public function clear() {
    return $this->entityManager()
      ->getHandler('feeds_feed', 'feed_clear')
      ->clear($this);
  }

  /**
   * {@inheritdoc}
   */
  public function expire() {
    return $this->entityManager()
      ->getHandler('feeds_feed', 'feed_expire')
      ->expire($this);
  }

  /**
   * Cleans up after an import.
   */
  public function cleanUp() {
    $processor_state = $this->getState(StateInterface::PROCESS);
    $this->getImporter()->getProcessor()->setMessages($this, $processor_state);
    $this->imported = time();

    $this->log('import', 'Imported in !s s', array('!s' => $this->getImportedTime() - $this->getState(StateInterface::START), WATCHDOG_INFO));

    // Unset.
    $this->clearFetcherResult();
    $this->clearState();
  }

  /**
   * {@inheritdoc}
   */
  public function schedule() {
    $this->scheduleImport();
    $this->scheduleExpire();
  }

  /**
   * {@inheritdoc}
   */
  public function scheduleImport() {
    $this->getImporter()->getPlugin('scheduler')->scheduleImport($this);
  }

  /**
   * {@inheritdoc}
   */
  public function scheduleExpire() {
    $this->getImporter()->getPlugin('scheduler')->scheduleExpire($this);
  }

  /**
   * {@inheritdoc}
   *
   * @todo Convert to proper field item.
   */
  public function getState($stage) {
    $state = $this->get('state')->$stage;
    if (!$state) {
      $state = new State();
      $this->get('state')->$stage = $state;
    }
    return $state;
  }

  /**
   * {@inheritdoc}
   */
  public function setState($stage, $state) {
    $this->get('state')->$stage = $state;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function clearState() {
    $this->get('state')->setValue(NULL);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFetcherResult() {
    return $this->get('fetcher_result')->result;
  }

  /**
   * {@inheritdoc}
   */
  public function setFetcherResult(FetcherResultInterface $result) {
    $this->get('fetcher_result')->result = $result;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function clearFetcherResult() {
    $this->get('fetcher_result')->setValue(NULL);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function progressParsing() {
    return $this->getState(StateInterface::PARSE)->progress;
  }

  /**
   * {@inheritdoc}
   */
  public function progressImporting() {
    $fetcher = $this->getState(StateInterface::FETCH);
    $parser = $this->getState(StateInterface::PARSE);

    if ($fetcher->progress == StateInterface::BATCH_COMPLETE && $parser->progress == StateInterface::BATCH_COMPLETE) {
      return StateInterface::BATCH_COMPLETE;
    }
    // Fetching envelops parsing.
    // @todo: this assumes all fetchers neatly use total. May not be the case.
    $fetcher_fraction = $fetcher->total ? 1.0 / $fetcher->total : 1.0;
    $parser_progress = $parser->progress * $fetcher_fraction;
    $result = $fetcher->progress - $fetcher_fraction + $parser_progress;

    if ($result >= StateInterface::BATCH_COMPLETE) {
      return 0.99;
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function progressClearing() {
    return $this->getState(StateInterface::CLEAR)->progress;
  }

  /**
   * {@inheritdoc}
   */
  public function progressExpiring() {
    return $this->getState(StateInterface::EXPIRE)->progress;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemCount() {
    return $this->getImporter()->getProcessor()->getItemCount($this);
  }

  /**
   * {@inheritdoc}
   *
   * @todo Perform some validation.
   */
  public function existing() {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function unlock() {
    $this->entityManager()->getStorage($this->entityType)->unlockFeed($this);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigurationFor(FeedsPluginInterface $client) {
    $type = $client->pluginType();
    $configuration = $this->get('config')->$type;
    return array_intersect_key($configuration, $client->sourceDefaults()) + $client->sourceDefaults();
  }

  /**
   * {@inheritdoc}
   */
  public function setConfigurationFor(FeedsPluginInterface $client, array $configuration) {
    $type = $client->pluginType();
    $this->get('config')->$type = array_intersect_key($configuration, $client->sourceDefaults()) + $client->sourceDefaults();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function log($type, $message, $variables = array(), $severity = WATCHDOG_NOTICE) {
    if ($severity < WATCHDOG_NOTICE) {
      $error = &drupal_static('feeds_log_error', FALSE);
      $error = TRUE;
    }
    db_insert('feeds_log')
      ->fields(array(
        'fid' => $this->id(),
        'log_time' => time(),
        'request_time' => REQUEST_TIME,
        'type' => $type,
        'message' => $message,
        'variables' => serialize($variables),
        'severity' => $severity,
      ))
      ->execute();

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage_controller) {
    // Before saving the feed, set changed time.
    $this->set('changed', REQUEST_TIME);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage_controller, $update = TRUE) {
    // Alert implementers of FeedInterface to the fact that we're saving.
    foreach ($this->getImporter()->getPlugins() as $plugin) {
      $plugin->onFeedSave($this, $update);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage_controller, array $feeds) {
    // Delete values from other tables also referencing these feeds.
    $ids = array_keys($feeds);

    // @todo Create a log controller or some sort of log handler that D8 uses.
    db_delete('feeds_log')
      ->condition('fid', $ids)
      ->execute();

    // Group feeds by imporer.
    $grouped = array();
    foreach ($feeds as $fid => $feed) {
      $grouped[$feed->bundle()][$fid] = $feed;
    }

    // Alert plugins that we are deleting.
    foreach ($grouped as $group) {
      // Grab the first feed to get its importer.
      $feed = reset($group);
      foreach ($feed->getImporter()->getPlugins() as $plugin) {
        $plugin->onFeedDeleteMultiple($group);
      }
    }

    $this->dispatchEvent(FeedsEvents::FEEDS_DELETE, new DeleteFeedsEvent($this));
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = array();

    $fields['fid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Feed ID'))
      ->setDescription(t('The feed ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The feed UUID.'))
      ->setReadOnly(TRUE);

    $fields['importer'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Importer'))
      ->setDescription(t('The feed importer.'))
      ->setSetting('target_type', 'feeds_importer')
      ->setReadOnly(TRUE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The title of this feed, always treated as non-markup plain text.'))
      ->setRequired(TRUE)
      ->setDefaultValue('')
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', array(
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ))
      ->setDisplayOptions('form', array(
        'type' => 'string_textfield',
        'weight' => -5,
      ))
      ->setDisplayConfigurable('form', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Authored by'))
      ->setDescription(t('The user ID of the feed author.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDefaultValueCallback(array('Drupal\feeds\Entity\Feed', 'getCurrentUserId'))
      ->setDisplayOptions('view', array(
        'label' => 'hidden',
        'type' => 'author',
        'weight' => 0,
      ))
      ->setDisplayOptions('form', array(
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
        'settings' => array(
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ),
      ))
      ->setDisplayConfigurable('form', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Importing status'))
      ->setDescription(t('A boolean indicating whether the feed is active.'))
      ->setDefaultValue(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the feed was created.'))
      ->setDisplayOptions('view', array(
        'label' => 'hidden',
        'type' => 'timestamp',
        'weight' => 0,
      ))
      ->setDisplayOptions('form', array(
        'type' => 'datetime_timestamp',
        'weight' => 10,
      ))
      ->setDisplayConfigurable('form', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the feed was last edited.'));

    $fields['imported'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Imported'))
      ->setDescription(t('The time that the feed was imported.'));

    $fields['source'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Source'))
      ->setDescription(t('The source of the feed.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', array(
        'type' => 'uri',
        'weight' => -3,
      ));

    $fields['config'] = BaseFieldDefinition::create('feeds_serialized')
      ->setLabel(t('Config'))
      ->setDescription(t('The config of the feed.'));

    $fields['fetcher_result'] = BaseFieldDefinition::create('feeds_serialized')
      ->setLabel(t('Fetcher result'))
      ->setDescription(t('The source of the feed.'));

    $fields['state'] = BaseFieldDefinition::create('feeds_serialized')
      ->setLabel(t('State'))
      ->setDescription(t('The source of the feed.'))
      ->setSettings(array('default_value' => array()));

    return $fields;
  }

  /**
   * Default value callback for 'uid' base field definition.
   *
   * @see ::baseFieldDefinitions()
   *
   * @return array
   *   An array of default values.
   */
  public static function getCurrentUserId() {
    return array(\Drupal::currentUser()->id());
  }

}