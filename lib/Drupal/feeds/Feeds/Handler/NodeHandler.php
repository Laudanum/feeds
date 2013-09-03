<?php

/**
 * @file
 * Contains \Drupal\feeds\Feeds\Handler\NodeHandler.
 */

namespace Drupal\feeds\Feeds\Handler;

use Drupal\Component\Annotation\Plugin;
use Drupal\feeds\Exception\EntityAccessException;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Plugin\Type\PluginBase;
use Drupal\feeds\Plugin\Type\Processor\ProcessorInterface;
use Drupal\feeds\Plugin\Type\Scheduler\SchedulerInterface;
use Drupal\feeds\Result\ParserResultInterface;

/**
 * Handles special node entity operations.
 *
 * @Plugin(
 *   id = "node"
 * )
 */
class NodeHandler extends PluginBase {

  /**
   * Crea
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configuration += $this->getDefaultConfiguration();
  }

  public function getConfiguration() {
    return $this->configuration;
  }

  public static function applies($processor) {
    return $processor->entityType() == 'node';
  }

  /**
   * Creates a new user account in memory and returns it.
   */
  public function newEntityValues(FeedInterface $feed, $values) {
    $node_settings = entity_load('node_type', $this->importer->getProcessor()->bundle())->getModuleSettings('node');

    // Ensure default settings.
    $node_settings += array(
      'options' => array('status', 'promote'),
      'preview' => DRUPAL_OPTIONAL,
      'submitted' => TRUE,
    );

    $values['uid'] = $this->configuration['author'];
    $values['status'] = (int) in_array('status', $node_settings['options']);
    $values['log'] = 'Created by FeedsNodeProcessor';
    $values['promote'] = (int) in_array('promote', $node_settings['options']);

    return $values;
  }

  /**
   * Implements parent::entityInfo().
   */
  public function entityInfo(array &$info) {
    $info['label_plural'] = $this->t('Nodes');
  }

  /**
   * Override parent::getDefaultConfiguration().
   */
  public function getDefaultConfiguration() {
    $defaults = array();
    $defaults['author'] = 0;
    $defaults['authorize'] = TRUE;
    $defaults['expire'] = SchedulerInterface::EXPIRE_NEVER;
    $defaults['status'] = 1;

    return $defaults;
  }

  public function buildConfigurationForm(array &$form, array &$form_state) {
    $author = user_load($this->configuration['author']);

    $form['author'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Author'),
      '#description' => $this->t('Select the author of the nodes to be created - leave empty to assign "anonymous".'),
      '#autocomplete_path' => 'user/autocomplete',
      '#default_value' => check_plain($author->getUsername()),
    );

    $period = drupal_map_assoc(array(SchedulerInterface::EXPIRE_NEVER, 3600, 10800, 21600, 43200, 86400, 259200, 604800, 2592000, 2592000 * 3, 2592000 * 6, 31536000), array($this->importer->getProcessor(), 'formatExpire'));

    $form['expire'] = array(
      '#type' => 'select',
      '#title' => $this->t('Expire nodes'),
      '#options' => $period,
      '#description' => $this->t('Select after how much time nodes should be deleted. The node\'s published date will be used for determining the node\'s age, see Mapping settings.'),
      '#default_value' => $this->configuration['expire'],
    );
  }

  public function validateConfigurationForm(array &$form, array &$form_state) {
    $values =& $form_state['values']['processor']['configuration'];
    if ($author = user_load_by_name($values['author'])) {
      $values['author'] = $author->id();
    }
    else {
      $values['author'] = 0;
    }
  }

  public function submitConfigurationForm(array &$form, array &$form_state) {
    $values =& $form_state['values']['processor']['configuration'];
    if ($this->configuration['expire'] != $values['expire']) {
      $this->importer->reschedule($this->importer->id());
    }
  }

  /**
   * Loads an existing user.
   */
  public function entityPrepare(FeedInterface $feed, $node) {
    $update_existing = $this->importer->getProcessor()->getConfiguration('update_existing');

    if ($update_existing != ProcessorInterface::UPDATE_EXISTING) {
      $node->uid = $this->configuration['author'];
    }

    // node_object_prepare($node);

    // Workaround for issue #1247506. See #1245094 for backstory.
    if (!empty($node->menu)) {
      // If the node has a menu item(with a valid mlid) it must be flagged
      // 'enabled'.
      $node->menu['enabled'] = (int) (bool) $node->menu['mlid'];
    }

    // Populate properties that are set by node_object_prepare().
    if ($update_existing == ProcessorInterface::UPDATE_EXISTING) {
      $node->log = 'Updated by FeedsNodeProcessor';
    }
    else {
      $node->log = 'Replaced by FeedsNodeProcessor';
    }
  }

  public function preSave($entity) {
    if (!isset($entity->uid) || !is_numeric($entity->uid)) {
       $entity->uid = $this->configuration['author'];
    }
    if (drupal_strlen($entity->title) > 255) {
      $entity->title = drupal_substr($entity->title, 0, 255);
    }
  }

  /**
   * Override setTargetElement to operate on a target item that is a node.
   */
  public function setTargetElement(FeedInterface $feed, $node, $target_element, $value) {
    switch ($target_element) {
      case 'user_name':
        if ($user = user_load_by_name($value)) {
          $node->uid = $user->uid;
        }
        break;

      case 'user_mail':
        if ($user = user_load_by_mail($value)) {
          $node->uid = $user->uid;
        }
        break;
    }
  }

  /**
   * Return available mapping targets.
   */
  public function getMappingTargets(array &$targets) {
    $targets['title']['optional_unique'] = TRUE;
    $targets['user_name'] = array(
      'name' => $this->t('Username'),
      'description' => $this->t('The Drupal username of the node author.'),
    );
    $targets['user_mail'] = array(
      'name' => $this->t('User email'),
      'description' => $this->t('The email address of the node author.'),
    );
  }

  /**
   * Overrides parent::expiryQuery().
   */
  public function expiryQuery(FeedInterface $feed, $select, $time) {
    $data_table = $select->join('node_field_data', 'nfd', 'e.nid = nfd.nid');
    $select->condition('nfd.created', REQUEST_TIME - $time, '<');
    return $select;
  }

  /**
   * Get nid of an existing feed item node if available.
   */
  public function existingEntityId(FeedInterface $feed, array $item) {
    $nid = FALSE;
    // Iterate through all unique targets and test whether they do already
    // exist in the database.
    foreach ($this->importer->getProcessor()->uniqueTargets($feed, $item) as $target => $value) {

      switch ($target) {
        case 'nid':
          $nid = db_query("SELECT nid FROM {node} WHERE nid = :nid", array(':nid' => $value))->fetchField();
          break;

        case 'title':
          $nid = db_query("SELECT nid FROM {node_field_data} WHERE title = :title AND type = :type", array(':title' => $value, ':type' => $this->importer->getProcessor()->bundle()))->fetchField();
          break;
      }
      if ($nid) {
        // Return with the first nid found.
        return $nid;
      }
    }
    return 0;
  }

}