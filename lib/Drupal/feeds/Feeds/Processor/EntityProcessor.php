<?php

/**
 * @file
 * Contains \Drupal\feeds\Feeds\Processor\EntityProcessor.
 */

namespace Drupal\feeds\Feeds\Processor;

use Drupal\Component\Annotation\Plugin;
use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageControllerInterface;
use Drupal\Core\Entity\Field\FieldItemInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\feeds\Plugin\Type\AdvancedFormPluginInterface;
use Drupal\feeds\Exception\EntityAccessException;
use Drupal\feeds\Exception\ValidationException;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Result\ParserResultInterface;
use Drupal\feeds\Plugin\Type\Processor\ProcessorBase;
use Drupal\feeds\Plugin\Type\Processor\ProcessorInterface;
use Drupal\feeds\Plugin\Type\Scheduler\SchedulerInterface;
use Drupal\feeds\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines an entity processor.
 *
 * Creates entities from feed items.
 *
 * @Plugin(
 *   id = "entity",
 *   title = @Translation("Entity processor"),
 *   description = @Translation("Creates entities from feed items."),
 *   derivative = "\Drupal\feeds\Plugin\Derivative\EntityProcessor"
 * )
 */
class EntityProcessor extends ProcessorBase implements ProcessorInterface, AdvancedFormPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The entity storage controller for the entity type being processed.
   *
   * @var \Drupal\Core\Entity\EntityStorageControllerInterface
   */
  protected $storageController;

  /**
   * The entity query factory object.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $queryFactory;

  /**
   * The entity info for the selected entity type.
   *
   * @var array
   */
  protected $entityInfo;

  /**
   * The targets for this processor.
   *
   * @var array
   */
  protected $targets = array();

  /**
   * The extenders that apply to this entity type.
   *
   * @var array
   */
  protected $handlers = array();

  /**
   * Whether or not we should continue processing existing items.
   *
   * @var bool
   */
  protected $skipExisting;

  /**
   * Constructs an EntityProcessor object.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityStorageControllerInterface $storage_controller
   *   The storage controller for this processor.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, array $entity_info, EntityStorageControllerInterface $storage_controller, QueryFactory $query_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    // $entityInfo has to be assinged before $this->loadHandlers() is called.
    $this->entityInfo = $entity_info;
    $this->storageController = $storage_controller;
    $this->skipExisting = $this->configuration['update_existing'] == ProcessorInterface::SKIP_EXISTING;
    $this->queryFactory = $query_factory;

    $this->loadHandlers();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, array $plugin_definition) {
    $entity_manager = $container->get('entity.manager');
    $entity_info = $entity_manager->getDefinition($plugin_definition['entity type']);
    $storage_controller = $entity_manager->getStorageController($plugin_definition['entity type']);

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_info,
      $storage_controller,
      $container->get('entity.query')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @todo Bulk load existing entity ids/entities.
   */
  public function process(FeedInterface $feed, StateInterface $state, ParserResultInterface $parser_result) {
    while ($item = $parser_result->shiftItem()) {
      $this->processItem($feed, $state, $item);
    }
  }

  /**
   * Processes a single item.
   *
   * @param \Drupal\feeds\FeedInterface $feed
   *   The feed being processed.
   * @param \Drupal\feeds\StateInterface $state
   *   The state object.
   * @param array $item
   *   The item being processed.
   */
  protected function processItem(FeedInterface $feed, StateInterface $state, array $item) {
    // Check if this item already exists.
    $entity_id = $this->existingEntityId($feed, $item);

    // If it exists, and we are not updating, pass onto the next item.
    if ($entity_id && $this->skipExisting) {
      return;
    }

    $old_hash = FALSE;
    if ($entity_id) {
      $entity = $this->entityLoad($entity_id);
      $old_hash = $entity->get('feeds_item')->hash;
    }

    $hash = $this->hash($item);
    $changed = $old_hash && $old_hash === $hash;

    // Do not proceed if the item exists, has not changed, and we're not
    // forcing the update.
    if ($entity_id && !$changed && !$this->configuration['skip_hash_check']) {
      return;
    }

    try {
      // Build a new entity.
      if (!$entity_id) {
        $entity = $this->newEntity($feed);
      }

      // Set property and field values.
      $this->map($feed, $item, $entity);
      $this->entityValidate($entity);

      // This will throw an exception on failure.
      $this->entitySaveAccess($entity);

      // Set the values that we absolutely need for this field.
      $entity->get('feeds_item')->fid = $feed->id();
      $entity->get('feeds_item')->hash = $hash;
      $entity->get('feeds_item')->imported = REQUEST_TIME;

      // And... Save! We made it.
      $entity->save();

      // Track progress.
      $entity_id ? $state->updated++ : $state->created++;
    }

    // Something bad happened, log it.
    catch (\Exception $e) {
      $state->failed++;
      drupal_set_message($e->getMessage(), 'warning');
      $message = $this->createLogMessage($e, $entity, $item);
      $feed->log('import', $message, array(), WATCHDOG_ERROR);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clear(FeedInterface $feed) {
    $state = $feed->state(StateInterface::CLEAR);

    // Build base select statement.
    $query = $this->queryFactory->get(($this->entityType()))
      ->condition('feeds_item.fid', $feed->id());

    // If there is no total, query it.
    if (!$state->total) {
      $count_query = clone $query;
      $state->total = $count_query->count()->execute();
    }

    // Delete a batch of entities.
    $entity_ids = $query->range(0, $this->getLimit())->execute();

    if ($entity_ids) {
      $this->entityDeleteMultiple($entity_ids);
      $state->deleted += count($entity_ids);
      $state->progress($state->total, $state->deleted);
    }
    else {
      $state->progress($state->total, $state->total);
    }

    // Report results when done.
    if ($feed->progressClearing() == StateInterface::BATCH_COMPLETE) {
      if ($state->deleted) {
        $message = format_plural(
          $state->deleted,
          'Deleted @number @entity from %title.',
          'Deleted @number @entities from %title.',
          array(
            '@number' => $state->deleted,
            '@entity' => Unicode::strtolower($this->label()),
            '@entities' => Unicode::strtolower($this->labelPlural()),
            '%title' => $feed->label(),
          )
        );
        $feed->log('clear', $message, array(), WATCHDOG_INFO);
        drupal_set_message($message);
      }
      else {
        drupal_set_message($this->t('There are no @entities to be deleted.', array('@entities' => Unicode::strtolower($this->labelPlural()))));
      }
    }
  }

  /**
   * Called after processing all items to display messages.
   *
   * @param \Drupal\feeds\FeedInterface $feed
   *   The feed being processed.
   */
  public function setMessages(FeedInterface $feed) {
    $state = $feed->state(StateInterface::PROCESS);

    $tokens = array(
      '@entity' => Unicode::strtolower($this->label()),
      '@entities' => Unicode::strtolower($this->labelPlural()),
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
        'message' => $this->t('There are no new @entities.', array('@entities' => Unicode::strtolower($this->labelPlural()))),
      );
    }
    foreach ($messages as $message) {
      drupal_set_message($message['message']);
      $feed->log('import', $message['message'], array(), isset($message['level']) ? $message['level'] : WATCHDOG_INFO);
    }
  }

  /**
   * Loads the handlers that apply to this processor.
   *
   * @todo Move this to PluginBase.
   */
  protected function loadHandlers() {
    $configuration = $this->configuration + array('importer' => $this->importer);
    $definitions = \Drupal::service('plugin.manager.feeds.handler')->getDefinitions();

    foreach ($definitions as $definition) {
      $class = $definition['class'];
      if ($class::applies($this)) {
        $this->handlers[] = \Drupal::service('plugin.manager.feeds.handler')->createInstance($definition['id'], $configuration);
      }
    }
  }

  /**
   * Applies a function to listeners.
   *
   * @todo Move to PluginBase.
   *
   * @todo Events?
   */
  public function apply($action, &$arg1 = NULL, &$arg2 = NULL, &$arg3 = NULL, &$arg4 = NULL) {
    $return = array();

    foreach ($this->handlers as $handler) {
      if (method_exists($handler, $action)) {
        $callable = array($handler, $action);
        $result = $callable($arg1, $arg2, $arg3, $arg4);
        if (is_array($result)) {
          $return = array_merge($return, $result);
        }
        else {
          $return[] = $result;
        }
      }
    }

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function entityType() {
    return $this->pluginDefinition['entity type'];
  }

  /**
   * {@inheritdoc}
   */
  protected function entityInfo() {
    $this->apply(__FUNCTION__, $this->entityInfo);
    return $this->entityInfo;
  }

  /**
   * Bundle type this processor operates on.
   *
   * Defaults to the entity type for entities that do not define bundles.
   *
   * @return string|null
   *   The bundle type this processor operates on, or null if it is undefined.
   */
  protected function bundleKey() {
    $info = $this->entityInfo();
    if (!empty($info['entity_keys']['bundle'])) {
      return $info['entity_keys']['bundle'];
    }
  }

  /**
   * Bundle type this processor operates on.
   *
   * Defaults to the entity type for entities that do not define bundles.
   *
   * @return string|null
   *   The bundle type this processor operates on, or null if it is undefined.
   */
  public function bundle() {
    if ($bundle_key = $this->bundleKey()) {
      if (isset($this->configuration['values'][$bundle_key])) {
        return $this->configuration['values'][$bundle_key];
      }
      return;
    }

    return $this->entityType();
  }

  /**
   * Returns the bundle label for the entity being processed.
   *
   * @return string
   *   The bundle label.
   */
  protected function bundleLabel() {
    $info = $this->entityInfo;
    if (!empty($info['bundle_label'])) {
      return $info['bundle_label'];
    }

    return $this->t('Bundle');
  }

  /**
   * Provides a list of bundle options for use in select lists.
   *
   * @return array
   *   A keyed array of bundle => label.
   */
  protected function bundleOptions() {
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

  protected function label() {
    $info = $this->entityInfo();
    return $info['label'];
  }

  protected function labelPlural() {
    $info = $this->entityInfo();
    return isset($info['label_plural']) ? $info['label_plural'] : $info['label'];
  }

  /**
   * {@inheritdoc}
   */
  protected function newEntity(FeedInterface $feed) {
    $values = $this->configuration['values'];
    $values = $this->apply('newEntityValues', $feed, $values);
    return $this->storageController->create($values)->getNGEntity();
  }

  /**
   * {@inheritdoc}
   */
  protected function entityLoad(FeedInterface $feed, $entity_id) {
    $entity = $this->storageController->load($entity_id)->getNGEntity();
    $this->apply('entityPrepare', $feed, $entity);

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function entityValidate(EntityInterface $entity) {
    $this->apply(__FUNCTION__, $entity);

    $violations = $entity->validate();
    if (count($violations)) {
      $info = $this->entityInfo();
      $args = array(
        '@entity' => Unicode::strtolower($info['label']),
        '%label' => $entity->label(),
        '@url' => $this->url('feeds_importer.mapping', array('feeds_importer' => $this->importer->id())),
      );
      throw new ValidationException(String::format('The @entity %label failed to validate. Please check your <a href="@url">mappings</a>.', $args));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function entitySaveAccess(EntityInterface $entity) {
    if ($this->configuration['authorize'] && !empty($entity->uid->value)) {

      // If the uid was mapped directly, rather than by email or username, it
      // could be invalid.
      if (!($account = $entity->uid->entity)) {
        $message = 'User %uid is not a valid user.';
        throw new EntityAccessException(String::format($message, array('%uid' => $entity->uid->value)));
      }

      $op = $entity->isNew() ? 'create' : 'update';

      if (!$entity->access($op, $account)) {
        $args = array(
          '%name' => $account->getUsername(),
          '%op' => $op,
          '@bundle' => Unicode::strtolower($this->bundleLabel()),
          '%bundle' => $entity->bundle(),
        );
        throw new EntityAccessException(String::format('User %name is not authorized to %op @bundle %bundle.', $args));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function entityDeleteMultiple(array $entity_ids) {
    $entities = $this->storageController->loadMultiple($entity_ids);
    $this->storageController->delete($entities);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration($key = NULL) {
    $this->configuration + $this->apply('getConfiguration');
    return parent::getConfiguration($key);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultConfiguration() {
    $defaults = array(
      'values' => array(
        $this->bundleKey() => NULL,
      ),
      'authorize' => TRUE,
      'expire' => SchedulerInterface::EXPIRE_NEVER,
    ) + parent::getDefaultConfiguration();

    $defaults += $this->apply(__FUNCTION__);

    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, array &$form_state) {
    $info = $this->entityInfo();
    $tokens = array('@entities' => Unicode::strtolower($this->labelPlural()));

    $form['update_existing'] = array(
      '#type' => 'radios',
      '#title' => $this->t('Update existing @entities', $tokens),
      '#description' => $this->t('Existing @entities will be determined using mappings that are a "unique target".', $tokens),
      '#options' => array(
        ProcessorInterface::SKIP_EXISTING => $this->t('Do not update existing @entities', $tokens),
        ProcessorInterface::REPLACE_EXISTING => $this->t('Replace existing @entities', $tokens),
        ProcessorInterface::UPDATE_EXISTING => $this->t('Update existing @entities', $tokens),
      ),
      '#default_value' => $this->configuration['update_existing'],
    );
    $form['authorize'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Authorize'),
      '#description' => $this->t('Check that the author has permission to create the node.'),
      '#default_value' => $this->configuration['authorize'],
    );

    $form = parent::buildConfigurationForm($form, $form_state);

    $this->apply(__FUNCTION__, $form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, array &$form_state) {
    $this->apply(__FUNCTION__, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, array &$form_state) {
    $this->apply(__FUNCTION__, $form, $form_state);
    parent::submitConfigurationForm($form, $form_state);

    // Make sure our field exists.
    $entity_type = $this->entityType();
    $bundle = $this->bundle();
    $field = array(
      'name' => 'feeds_item',
      'entity_type' => $entity_type,
      'type' => 'feeds_item',
      'translatable' => FALSE,
    );
    $instance = array(
      'field_name' => 'feeds_item',
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => 'Feeds item',
    );

    // Create the field and instance.
    $field_storage = \Drupal::entityManager()->getStorageController('field_entity');
    $instance_storage = \Drupal::entityManager()->getStorageController('field_instance');

    if (!$field_storage->load("$entity_type.feeds_item")) {
      $field_storage->create($field)->save();
    }
    if (!$instance_storage->load("$entity_type.$bundle.feeds_item")) {
      $instance_storage->create($instance)->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMappingTargets() {
    if (!$this->targets) {
      // Let other modules expose mapping targets.
      $definitions = \Drupal::service('plugin.manager.feeds.target')->getDefinitions();

      foreach ($definitions as $definition) {
        $class = $definition['class'];
        $class::targets($this->targets, $this->importer, $definition);
      }
    }

    return $this->targets;
  }

  /**
   * {@inheritdoc}
   */
  public function setTargetElement(FeedInterface $feed, $entity, $field_name, $values, $mapping) {
    $entity->get($field_name)->setValue($values);
  }

  /**
   * Return expiry time.
   */
  public function expiryTime() {
    return $this->configuration['expire'];
  }

  /**
   * {@inheritdoc}
   */
  public function expire(FeedInterface $feed, $time = NULL) {
    $state = $feed->state(StateInterface::EXPIRE);

    if ($time === NULL) {
      $time = $this->expiryTime();
    }
    if ($time == SchedulerInterface::EXPIRE_NEVER) {
      return;
    }

    $query = $this->queryFactory->get(($this->entityType()))
      ->condition('feeds_item.fid', $feed->fid())
      ->condition('feeds_item.imported', REQUEST_TIME - $time, '<');

    // If there is no total, query it.
    if (!$state->total) {
      $count_query = clone $query;
      $state->total = $count_query->count()->execute();
    }

    // Delete a batch of entities.
    if ($entity_ids = $query->range(0, $this->getLimit())->execute()) {
      $this->entityDeleteMultiple($entity_ids);
      $state->deleted += count($entity_ids);
      $state->progress($state->total, $state->deleted);
    }
    else {
      $state->progress($state->total, $state->total);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getItemCount(FeedInterface $feed) {
    return $this->queryFactory->get(($this->entityType()))
      ->condition('feeds_item.fid', $feed->id())
      ->count()
      ->execute();
  }

  protected function existingEntityId(FeedInterface $feed, array $item) {
    $query = $this->queryFactory->get(($this->entityType()))
      ->condition('feeds_item.fid', $feed->id())
      ->range(0, 1);

    // Iterate through all unique targets and test whether they do already
    // exist in the database.
    foreach ($this->uniqueTargets($feed, $item) as $target => $value) {
      switch ($target) {
        case 'url':
          $entity_id = $query->condition('feeds_item.url', $value)->execute();
          break;

        case 'guid':
          $entity_id = $query->condition('feeds_item.guid', $value)->execute();
          break;
      }

      if (isset($entity_id)) {
        return key($entity_id);
      }

      $query = clone $query;
    }

    $ids = array_filter($this->apply('existingEntityId', $feed, $item));

    if ($ids) {
      return reset($ids);
    }

    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function buildAdvancedForm(array $form, array &$form_state) {
    $form['values']['#tree'] = TRUE;

    if ($bundle_key = $this->bundleKey()) {
      $form['values'][$bundle_key] = array(
        '#type' => 'select',
        '#options' => $this->bundleOptions(),
        '#title' => $this->bundleLabel(),
        '#required' => TRUE,
        '#default_value' => $this->bundle(),
      );
    }

    return $form;
  }

}