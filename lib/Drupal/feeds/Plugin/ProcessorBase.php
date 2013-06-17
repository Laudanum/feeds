<?php

/**
 * @file
 * Contains Drupal\feeds\Plugin\ProcessorBase.
 */

namespace Drupal\feeds\Plugin;

use Drupal\feeds\Plugin\Core\Entity\Feed;
use Drupal\feeds\FeedsParserResult;
use Drupal\feeds\FeedsAccessException;
use Exception;

// Update mode for existing items.
define('FEEDS_SKIP_EXISTING', 0);
define('FEEDS_REPLACE_EXISTING', 1);
define('FEEDS_UPDATE_EXISTING', 2);

// Default limit for creating items on a page load, not respected by all
// processors.
define('FEEDS_PROCESS_LIMIT', 50);

/**
 * Abstract class, defines interface for processors.
 */
abstract class ProcessorBase extends FeedsPlugin {

  /**
   * Implements FeedsPlugin::pluginType().
   */
  public function pluginType() {
    return 'processor';
  }

  /**
   * Entity type this processor operates on.
   */
  public abstract function entityType();

  /**
   * Bundle type this processor operates on.
   *
   * Defaults to the entity type for entities that do not define bundles.
   *
   * @return string|NULL
   *   The bundle type this processor operates on, or NULL if it is undefined.
   */
  public function bundle() {
    return $this->config['bundle'];
  }

  /**
   * Provides a list of bundle options for use in select lists.
   *
   * @return array
   *   A keyed array of bundle => label.
   */
  public function bundleOptions() {
    $options = array();
    foreach (entity_get_bundles($this->entityType()) as $bundle => $info) {
      if (!empty($info['label'])) {
        $options[$bundle] = $info['label'];
      }
      else {
        $options[$bundle] = $bundle;
      }
    }
    return $options;
  }

  /**
   * Create a new entity.
   *
   * @param $source
   *   The feeds source that spawns this entity.
   *
   * @return
   *   A new entity object.
   */
  protected abstract function newEntity(Feed $source);

  /**
   * Load an existing entity.
   *
   * @param $feed
   *   The feeds source that spawns this entity.
   * @param $entity_id
   *   The unique id of the entity that should be loaded.
   *
   * @return
   *   A new entity object.
   *
   * @todo We should be able to batch load these, if we found all of the
   *   existing ids first.
   */
  protected function entityLoad(Feed $feed, $entity_id) {
    if ($this->config['update_existing'] == FEEDS_UPDATE_EXISTING) {
      return entity_load($this->entityType(), $entity_id);
    }

    $info = $this->entityInfo();

    $args = array(':entity_id' => $entity_id);

    $table = db_escape_table($info['base_table']);
    $key = db_escape_field($info['entity_keys']['id']);
    $values = db_query("SELECT * FROM {" . $table . "} WHERE $key = :entity_id", $args)->fetchObject();

    $entity = $this->newEntity($feed);
    foreach ($values as $key => $value) {
      $entity->$key = $value;
    }

    return $entity;
  }

  /**
   * Validate an entity.
   *
   * @throws FeedsValidationException $e
   *   If validation fails.
   */
  protected function entityValidate($entity) {}

  /**
   * Access check for saving an enity.
   *
   * @param $entity
   *   Entity to be saved.
   *
   * @throws FeedsAccessException $e
   *   If the access check fails.
   */
  protected function entitySaveAccess($entity) {}

  /**
   * Save an entity.
   *
   * @param $entity
   *   Entity to be saved.
   */
  protected abstract function entitySave($entity);

  /**
   * Delete a series of entities.
   *
   * @param $entity_ids
   *   Array of unique identity ids to be deleted.
   */
  protected function entityDeleteMultiple($entity_ids) {
    entity_delete_multiple($this->entityType(), $entity_ids);
  }

  /**
   * Wrap entity_get_info() into a method so that extending classes can override
   * it and more entity information. Allowed additional keys:
   *
   * 'label plural' ... the plural label of an entity type.
   */
  protected function entityInfo() {
    return entity_get_info($this->entityType());
  }

  /**
   * Process the result of the parsing stage.
   *
   * @param Feed $source
   *   Source information about this import.
   * @param FeedsParserResult $parser_result
   *   The result of the parsing stage.
   */
  public function process(Feed $feed, FeedsParserResult $parser_result) {
    $state = $feed->state(FEEDS_PROCESS);

    while ($item = $parser_result->shiftItem()) {

      // Check if this item already exists.
      $entity_id = $this->existingEntityId($feed, $parser_result);
      $skip_existing = $this->config['update_existing'] == FEEDS_SKIP_EXISTING;

      module_invoke_all('feeds_before_update', $feed, $item, $entity_id);

      // If it exists, and we are not updating, pass onto the next item.
      if ($entity_id && $skip_existing) {
        continue;
      }

      $hash = $this->hash($item);
      $changed = ($hash !== $this->getHash($entity_id));
      $force_update = $this->config['skip_hash_check'];

      // Do not proceed if the item exists, has not changed, and we're not
      // forcing the update.
      if ($entity_id && !$changed && !$force_update) {
        continue;
      }

      try {

        // Load an existing entity.
        if ($entity_id) {
          $entity = $this->entityLoad($feed, $entity_id);

          // The feeds_item table is always updated with the info for the most
          // recently processed entity. The only carryover is the entity_id.
          $this->newItemInfo($entity, $feed->id(), $hash);
          $entity->feeds_item->entity_id = $entity_id;
          $entity->feeds_item->is_new = FALSE;
        }

        // Build a new entity.
        else {
          $entity = $this->newEntity($feed);
          $this->newItemInfo($entity, $feed->id(), $hash);
        }

        // Set property and field values.
        $this->map($feed, $parser_result, $entity);
        $this->entityValidate($entity);

        // Allow modules to alter the entity before saving.
        module_invoke_all('feeds_presave', $feed, $entity, $item, $entity_id);
        if (module_exists('rules')) {
          rules_invoke_event('feeds_import_'. $feed->importer()->id(), $entity);
        }

        // Enable modules to skip saving at all.
        if (!empty($entity->feeds_item->skip)) {
          continue;
        }

        // This will throw an exception on failure.
        $this->entitySaveAccess($entity);
        $this->entitySave($entity);

        // Allow modules to perform operations using the saved entity data.
        // $entity contains the updated entity after saving.
        module_invoke_all('feeds_after_save', $feed, $entity, $item, $entity_id);

        // Track progress.
        if (empty($entity_id)) {
          $state->created++;
        }
        else {
          $state->updated++;
        }
      }

      // Something bad happened, log it.
      catch (Exception $e) {
        $state->failed++;
        drupal_set_message($e->getMessage(), 'warning');
        $message = $this->createLogMessage($e, $entity, $item);
        $feed->log('import', $message, array(), WATCHDOG_ERROR);
      }
    }

    // Set messages if we're done.
    if ($feed->progressImporting() != FEEDS_BATCH_COMPLETE) {
      return;
    }

    $info = $this->entityInfo();
    $tokens = array(
      '@entity' => strtolower($info['label']),
      '@entities' => strtolower($info['label plural']),
    );
    $messages = array();
    if ($state->created) {
      $messages[] = array(
       'message' => format_plural(
          $state->created,
          'Created @number @entity.',
          'Created @number @entities.',
          array('@number' => $state->created) + $tokens
        ),
      );
    }
    if ($state->updated) {
      $messages[] = array(
       'message' => format_plural(
          $state->updated,
          'Updated @number @entity.',
          'Updated @number @entities.',
          array('@number' => $state->updated) + $tokens
        ),
      );
    }
    if ($state->failed) {
      $messages[] = array(
       'message' => format_plural(
          $state->failed,
          'Failed importing @number @entity.',
          'Failed importing @number @entities.',
          array('@number' => $state->failed) + $tokens
        ),
        'level' => WATCHDOG_ERROR,
      );
    }
    if (empty($messages)) {
      $messages[] = array(
        'message' => t('There are no new @entities.', array('@entities' => strtolower($info['label plural']))),
      );
    }
    foreach ($messages as $message) {
      drupal_set_message($message['message']);
      $feed->log('import', $message['message'], array(), isset($message['level']) ? $message['level'] : WATCHDOG_INFO);
    }
  }

  /**
   * Remove all stored results or stored results up to a certain time for a
   * source.
   *
   * @param Feed $feed
   *   Source information for this expiry. Implementers should only delete items
   *   pertaining to this source. The preferred way of determining whether an
   *   item pertains to a certain souce is by using $source->fid. It is the
   *   processor's responsibility to store the fid of an imported item in
   *   the processing stage.
   */
  public function clear(Feed $feed) {
    $state = $feed->state(FEEDS_PROCESS_CLEAR);

    // Build base select statement.
    $info = $this->entityInfo();
    $select = db_select($info['base_table'], 'e');
    $select->addField('e', $info['entity_keys']['id'], 'entity_id');
    $select->join(
      'feeds_item',
      'fi',
      "e.{$info['entity_keys']['id']} = fi.entity_id AND fi.entity_type = '{$this->entityType()}'");
    $select->condition('fi.fid', $feed->id());

    // If there is no total, query it.
    if (!$state->total) {
      $state->total = $select->countQuery()
        ->execute()
        ->fetchField();
    }

    // Delete a batch of entities.
    $entities = $select->range(0, $this->getLimit())->execute();
    $entity_ids = array();
    foreach ($entities as $entity) {
      $entity_ids[$entity->entity_id] = $entity->entity_id;
    }
    $this->entityDeleteMultiple($entity_ids);

    // Report progress, take into account that we may not have deleted as
    // many items as we have counted at first.
    if (count($entity_ids)) {
      $state->deleted += count($entity_ids);
      $state->progress($state->total, $state->deleted);
    }
    else {
      $state->progress($state->total, $state->total);
    }

    // Report results when done.
    if ($feed->progressClearing() == FEEDS_BATCH_COMPLETE) {
      if ($state->deleted) {
        $message = format_plural(
          $state->deleted,
          'Deleted @number @entity',
          'Deleted @number @entities',
          array(
            '@number' => $state->deleted,
            '@entity' => strtolower($info['label']),
            '@entities' => strtolower($info['label plural']),
          )
        );
        $feed->log('clear', $message, array(), WATCHDOG_INFO);
        drupal_set_message($message);
      }
      else {
        drupal_set_message(t('There are no @entities to be deleted.', array('@entities' => $info['label plural'])));
      }
    }
  }

  /*
   * Report number of items that can be processed per call.
   *
   * 0 means 'unlimited'.
   *
   * If a number other than 0 is given, Feeds parsers that support batching
   * will only deliver this limit to the processor.
   *
   * @see Feed::getLimit()
   * @see FeedsCSVParser::parse()
   */
  public function getLimit() {
    return variable_get('feeds_process_limit', FEEDS_PROCESS_LIMIT);
  }

  /**
   * Deletes feed items older than REQUEST_TIME - $time.
   *
   * Do not invoke expire on a processor directly, but use
   * Feed::expire() instead.
   *
   * @param Feed $source
   *   The source to expire entities for.
   *
   * @param $time
   *   (optional) All items produced by this configuration that are older than
   *   REQUEST_TIME - $time should be deleted. If NULL, processor should use
   *   internal configuration. Defaults to NULL.
   *
   * @return float
   *   FEEDS_BATCH_COMPLETE if all items have been processed, a float between 0
   *   and 0.99* indicating progress otherwise.
   *
   * @see Feed::expire()
   */
  public function expire(Feed $feed, $time = NULL) {
    $state = $feed->state(FEEDS_PROCESS_EXPIRE);

    if ($time === NULL) {
      $time = $this->expiryTime();
    }
    if ($time == FEEDS_EXPIRE_NEVER) {
      return;
    }

    $select = $this->expiryQuery($feed, $time);

    // If there is no total, query it.
    if (!$state->total) {
      $state->total = $select->countQuery()->execute()->fetchField();
    }

    // Delete a batch of entities.
    $entity_ids = $select->range(0, $this->getLimit())->execute()->fetchCol();
    if ($entity_ids) {
      $this->entityDeleteMultiple($entity_ids);
      $state->deleted += count($entity_ids);
      $state->progress($state->total, $state->deleted);
    }
    else {
      $state->progress($state->total, $state->total);
    }
  }

  /**
   * Returns a database query used to select entities to expire.
   *
   * Processor classes should override this method to set the age portion of the
   * query.
   *
   * @param Feed $feed
   *   The feed source.
   * @param int $time
   *   Delete entities older than this.
   *
   * @return SelectQuery
   *   A select query to execute.
   *
   * @see FeedsNodeProcessor::expiryQuery()
   */
  protected function expiryQuery(Feed $feed, $time) {
    // Build base select statement.
    $info = $this->entityInfo();
    $id_key = db_escape_field($info['entity_keys']['id']);

    $select = db_select($info['base_table'], 'e');
    $select->addField('e', $info['entity_keys']['id'], 'entity_id');
    $select->join(
      'feeds_item',
      'fi',
      "e.$id_key = fi.entity_id AND fi.entity_type = :entity_type", array(
        ':entity_type' => $this->entityType(),
    ));
    $select->condition('fi.fid', $feed->id());

    return $select;
  }

  /**
   * Counts the number of items imported by this processor.
   */
  public function itemCount(Feed $feed) {
    return db_query("SELECT count(*) FROM {feeds_item} WHERE fid = :fid", array(':fid' => $feed->id()))->fetchField();
  }

  /**
   * Execute mapping on an item.
   *
   * This method encapsulates the central mapping functionality. When an item is
   * processed, it is passed through map() where the properties of $source_item
   * are mapped onto $target_item following the processor's mapping
   * configuration.
   *
   * For each mapping ParserBase::getSourceElement() is executed to retrieve
   * the source element, then ProcessorBase::setTargetElement() is invoked
   * to populate the target item properly. Alternatively a
   * hook_x_targets_alter() may have specified a callback for a mapping target
   * in which case the callback is asked to populate the target item instead of
   * ProcessorBase::setTargetElement().
   *
   * @ingroup mappingapi
   *
   * @see hook_feeds_parser_sources_alter()
   * @see hook_feeds_data_processor_targets_alter()
   * @see hook_feeds_node_processor_targets_alter()
   * @see hook_feeds_term_processor_targets_alter()
   * @see hook_feeds_user_processor_targets_alter()
   */
  protected function map(Feed $source, FeedsParserResult $result, $target_item = NULL) {

    // Static cache $targets as getMappingTargets() may be an expensive method.
    static $sources;
    if (!isset($sources[$this->importer->id()])) {
      $sources[$this->importer->id()] = $this->importer->parser->getMappingSources();
    }
    static $targets;
    if (!isset($targets[$this->importer->id()])) {
      $targets[$this->importer->id()] = $this->getMappingTargets();
    }
    $parser = $this->importer->parser;
    if (empty($target_item)) {
      $target_item = array();
    }

    // Many mappers add to existing fields rather than replacing them. Hence we
    // need to clear target elements of each item before mapping in case we are
    // mapping on a prepopulated item such as an existing node.
    foreach ($this->config['mappings'] as $mapping) {
      if (isset($targets[$this->importer->id()][$mapping['target']]['real_target'])) {
        unset($target_item->{$targets[$this->importer->id()][$mapping['target']]['real_target']});
      }
      elseif (isset($target_item->{$mapping['target']})) {
        unset($target_item->{$mapping['target']});
      }
    }

    /*
    This is where the actual mapping happens: For every mapping we envoke
    the parser's getSourceElement() method to retrieve the value of the source
    element and pass it to the processor's setTargetElement() to stick it
    on the right place of the target item.

    If the mapping specifies a callback method, use the callback instead of
    setTargetElement().
    */
    feeds_load_mappers();
    foreach ($this->config['mappings'] as $mapping) {
      // Retrieve source element's value from parser.
      if (isset($sources[$this->importer->id()][$mapping['source']]) &&
          is_array($sources[$this->importer->id()][$mapping['source']]) &&
          isset($sources[$this->importer->id()][$mapping['source']]['callback']) &&
          function_exists($sources[$this->importer->id()][$mapping['source']]['callback'])) {
        $callback = $sources[$this->importer->id()][$mapping['source']]['callback'];
        $value = $callback($source, $result, $mapping['source']);
      }
      else {
        $value = $parser->getSourceElement($source, $result, $mapping['source']);
      }

      // Map the source element's value to the target.
      if (isset($targets[$this->importer->id()][$mapping['target']]) &&
          is_array($targets[$this->importer->id()][$mapping['target']]) &&
          isset($targets[$this->importer->id()][$mapping['target']]['callback']) &&
          function_exists($targets[$this->importer->id()][$mapping['target']]['callback'])) {
        $callback = $targets[$this->importer->id()][$mapping['target']]['callback'];
        $callback($source, $target_item, $mapping['target'], $value, $mapping);
      }
      else {
        $this->setTargetElement($source, $target_item, $mapping['target'], $value, $mapping);
      }
    }
    return $target_item;
  }

  /**
   * Per default, don't support expiry. If processor supports expiry of imported
   * items, return the time after which items should be removed.
   */
  public function expiryTime() {
    return FEEDS_EXPIRE_NEVER;
  }

  /**
   * Declare default configuration.
   */
  public function configDefaults() {
    $info = $this->entityInfo();
    $bundle = NULL;
    if (empty($info['entity_keys']['bundle'])) {
      $bundle = $this->entityType();
    }
    return array(
      'mappings' => array(),
      'update_existing' => FEEDS_SKIP_EXISTING,
      'input_format' => NULL,
      'skip_hash_check' => FALSE,
      'bundle' => $bundle,
    );
  }

  /**
   * Overrides parent::configForm().
   */
  public function configForm(&$form_state) {
    $info = $this->entityInfo();
    $form = array();

    if (!empty($info['entity_keys']['bundle'])) {
      $form['bundle'] = array(
        '#type' => 'select',
        '#options' => $this->bundleOptions(),
        '#title' => !empty($info['bundle_label']) ? $info['bundle_label'] : t('Bundle'),
        '#required' => TRUE,
        '#default_value' => $this->bundle(),
      );
    }
    else {
      $form['bundle'] = array(
        '#type' => 'value',
        '#value' => $this->entityType(),
      );
    }

    $tokens = array('@entities' => strtolower($info['label plural']));

    $form['update_existing'] = array(
      '#type' => 'radios',
      '#title' => t('Update existing @entities', $tokens),
      '#description' =>
        t('Existing @entities will be determined using mappings that are a "unique target".', $tokens),
      '#options' => array(
        FEEDS_SKIP_EXISTING => t('Do not update existing @entities', $tokens),
        FEEDS_REPLACE_EXISTING => t('Replace existing @entities', $tokens),
        FEEDS_UPDATE_EXISTING => t('Update existing @entities', $tokens),
      ),
      '#default_value' => $this->config['update_existing'],
    );
    global $user;
    $formats = filter_formats($user);
    foreach ($formats as $format) {
      $format_options[$format->format] = $format->name;
    }
    $form['skip_hash_check'] = array(
      '#type' => 'checkbox',
      '#title' => t('Skip hash check'),
      '#description' => t('Force update of items even if item source data did not change.'),
      '#default_value' => $this->config['skip_hash_check'],
    );
    $form['input_format'] = array(
      '#type' => 'select',
      '#title' => t('Text format'),
      '#description' => t('Select the input format for the body field of the nodes to be created.'),
      '#options' => $format_options,
      '#default_value' => isset($this->config['input_format']) ? $this->config['input_format'] : 'plain_text',
      '#required' => TRUE,
    );

    return $form;
  }

  /**
   * Get mappings.
   */
  public function getMappings() {
    return isset($this->config['mappings']) ? $this->config['mappings'] : array();
  }

  /**
   * Declare possible mapping targets that this processor exposes.
   *
   * @ingroup mappingapi
   *
   * @return
   *   An array of mapping targets. Keys are paths to targets
   *   separated by ->, values are TRUE if target can be unique,
   *   FALSE otherwise.
   */
  public function getMappingTargets() {

    // The bundle has not been selected.
    if (!$this->bundle()) {
      $info = $this->entityInfo();
      $bundle_name = !empty($info['bundle_name']) ? drupal_strtolower($info['bundle_name']) : t('bundle');
      $url = url('admin/structure/feeds/manage/' . $this->importer->id() . '/settings/processor');
      drupal_set_message(t('Please <a href="@url">select a @bundle_name</a>.', array('@url' => $url, '@bundle_name' => $bundle_name)), 'warning', FALSE);
    }

    return array(
      'url' => array(
        'name' => t('URL'),
        'description' => t('The external URL of the item. E. g. the feed item URL in the case of a syndication feed. May be unique.'),
        'optional_unique' => TRUE,
      ),
      'guid' => array(
        'name' => t('GUID'),
        'description' => t('The globally unique identifier of the item. E. g. the feed item GUID in the case of a syndication feed. May be unique.'),
        'optional_unique' => TRUE,
      ),
    );
  }

  /**
   * Set a concrete target element. Invoked from ProcessorBase::map().
   *
   * @ingroup mappingapi
   */
  public function setTargetElement(Feed $source, $target_item, $target_element, $value) {
    switch ($target_element) {
      case 'url':
      case 'guid':
        $target_item->feeds_item->$target_element = $value;
        break;
      default:
        $target_item->$target_element = $value;
        break;
    }
  }

  /**
   * Retrieve the target entity's existing id if available. Otherwise return 0.
   *
   * @ingroup mappingapi
   *
   * @param Feed $source
   *   The source information about this import.
   * @param $result
   *   A FeedsParserResult object.
   *
   * @return
   *   The serial id of an entity if found, 0 otherwise.
   */
  protected function existingEntityId(Feed $feed, FeedsParserResult $result) {
    $query = db_select('feeds_item')
      ->fields('feeds_item', array('entity_id'))
      ->condition('fid', $feed->id())
      ->condition('entity_type', $this->entityType());

    // Iterate through all unique targets and test whether they do already
    // exist in the database.
    foreach ($this->uniqueTargets($feed, $result) as $target => $value) {
      switch ($target) {
        case 'url':
          $entity_id = $query->condition('url', $value)->execute()->fetchField();
          break;

        case 'guid':
          $entity_id = $query->condition('guid', $value)->execute()->fetchField();
          break;
      }
      if (isset($entity_id)) {
        // Return with the content id found.
        return $entity_id;
      }
    }
    return 0;
  }


  /**
   * Utility function that iterates over a target array and retrieves all
   * sources that are unique.
   *
   * @param $batch
   *   A FeedsImportBatch.
   *
   * @return
   *   An array where the keys are target field names and the values are the
   *   elements from the source item mapped to these targets.
   */
  protected function uniqueTargets(Feed $feed, FeedsParserResult $result) {
    $parser = $this->importer->parser;
    $targets = array();
    foreach ($this->config['mappings'] as $mapping) {
      if (!empty($mapping['unique'])) {
        // Invoke the parser's getSourceElement to retrieve the value for this
        // mapping's source.
        $targets[$mapping['target']] = $parser->getSourceElement($feed, $result, $mapping['source']);
      }
    }
    return $targets;
  }

  /**
   * Adds Feeds specific information on $entity->feeds_item.
   *
   * @param $entity
   *   The entity object to be populated with new item info.
   * @param $fid
   *   The feed nid of the source that produces this entity.
   * @param $hash
   *   The fingerprint of the source item.
   */
  protected function newItemInfo($entity, $fid, $hash = '') {
    $entity->feeds_item = new \stdClass();
    $entity->feeds_item->is_new = TRUE;
    $entity->feeds_item->entity_id = 0;
    $entity->feeds_item->entity_type = $this->entityType();
    $entity->feeds_item->id = $this->importer->id();
    $entity->feeds_item->fid = $fid;
    $entity->feeds_item->imported = REQUEST_TIME;
    $entity->feeds_item->hash = $hash;
    $entity->feeds_item->url = '';
    $entity->feeds_item->guid = '';
  }

  /**
   * Loads existing entity information and places it on $entity->feeds_item.
   *
   * @param $entity
   *   The entity object to load item info for. Id key must be present.
   *
   * @return
   *   TRUE if item info could be loaded, false if not.
   */
  protected function loadItemInfo($entity) {
    $entity_info = entity_get_info($this->entityType());
    $key = $entity_info['entity_keys']['id'];
    if ($item_info = feeds_item_info_load($this->entityType(), $entity->$key)) {
      $entity->feeds_item = $item_info;
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Create MD5 hash of item and mappings array.
   *
   * Include mappings as a change in mappings may have an affect on the item
   * produced.
   *
   * @return Always returns a hash, even with empty, NULL, FALSE:
   *  Empty arrays return 40cd750bba9870f18aada2478b24840a
   *  Empty/NULL/FALSE strings return d41d8cd98f00b204e9800998ecf8427e
   */
  protected function hash($item) {
    return hash('md5', serialize($item) . serialize($this->config['mappings']));
  }

  /**
   * Retrieves the MD5 hash of $entity_id from the database.
   *
   * @return string
   *   Empty string if no item is found, hash otherwise.
   */
  protected function getHash($entity_id) {

    if ($hash = db_query("SELECT hash FROM {feeds_item} WHERE entity_type = :type AND entity_id = :id", array(':type' => $this->entityType(), ':id' => $entity_id))->fetchField()) {
      // Return with the hash.
      return $hash;
    }
    return '';
  }

  /**
   * Creates a log message for when an exception occured during import.
   *
   * @param Exception $e
   *   The exception that was throwned during processing the item.
   * @param $entity
   *   The entity object.
   * @param $item
   *   The parser result for this entity.
   *
   * @return string
   *   The message to log.
   */
  protected function createLogMessage(Exception $e, $entity, $item) {
    include_once DRUPAL_ROOT . '/core/includes/utility.inc';
    $message = $e->getMessage();
    $message .= '<h3>Original item</h3>';
    $message .= '<pre>' . drupal_var_export($item). '</pre>';
    $message .= '<h3>Entity</h3>';
    $message .= '<pre>' . drupal_var_export($entity->getValue()) . '</pre>';
    return $message;
  }

}