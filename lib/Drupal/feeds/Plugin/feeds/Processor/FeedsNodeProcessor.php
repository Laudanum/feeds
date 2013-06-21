<?php

/**
 * @file
 * Contains \Drupal\feeds\Plugin\feeds\Fetcher\FeedsNodeProcessor.
 */

namespace Drupal\feeds\Plugin\feeds\Processor;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;
use Drupal\feeds\Plugin\ProcessorBase;
use Drupal\feeds\Plugin\Core\Entity\Feed;
use Drupal\feeds\FeedsParserResult;
use Drupal\feeds\FeedsAccessException;

/**
 * Defines a node processor.
 *
 * Creates Nodes from feed items.
 *
 * @Plugin(
 *   id = "node",
 *   title = @Translation("Node processor"),
 *   description = @Translation("Creates nodes from feed items.")
 * )
 */
class FeedsNodeProcessor extends ProcessorBase {

  /**
   * Define entity type.
   */
  public function entityType() {
    return 'node';
  }

  /**
   * Implements parent::entityInfo().
   */
  protected function entityInfo() {
    $info = parent::entityInfo();
    $info['label plural'] = t('Nodes');
    return $info;
  }

  /**
   * Creates a new node in memory and returns it.
   */
  protected function newEntity(Feed $feed) {
    $defaults = variable_get('node_options_' . $this->bundle(), array('status', 'promote'));
    return entity_create('node', array(
      'type' => $this->bundle(),
      'changed' => REQUEST_TIME,
      'created' => REQUEST_TIME,
      'is_new' => TRUE,
      'log' => 'Created by FeedsNodeProcessor',
      'uid' => $this->config['author'],
      'promote' => (int) in_array('promote', $defaults),
      'status' => (int) in_array('status', $defaults),
    ))->getBCEntity();
  }

  /**
   * Loads an existing node.
   *
   * If the update existing method is not FEEDS_UPDATE_EXISTING, only the node
   * table will be loaded, foregoing the node_load API for better performance.
   *
   * @todo Reevaluate the use of node_object_prepare().
   */
  protected function entityLoad(Feed $feed, $nid) {
    $node = parent::entityLoad($feed, $nid)->getBCEntity();

    if ($this->config['update_existing'] != FEEDS_UPDATE_EXISTING) {
      $node->uid = $this->config['author'];
    }

    // node_object_prepare($node);

    // Workaround for issue #1247506. See #1245094 for backstory.
    if (!empty($node->menu)) {
      // If the node has a menu item(with a valid mlid) it must be flagged
      // 'enabled'.
      $node->menu['enabled'] = (int) (bool) $node->menu['mlid'];
    }

    // Populate properties that are set by node_object_prepare().
    if ($this->config['update_existing'] == FEEDS_UPDATE_EXISTING) {
      $node->log = 'Updated by FeedsNodeProcessor';
    }
    else {
      $node->log = 'Replaced by FeedsNodeProcessor';
    }

    return $node;
  }

  /**
   * Check that the user has permission to save a node.
   */
  protected function entitySaveAccess($entity) {

    // The check will be skipped for anonymous nodes.
    if ($this->config['authorize'] && !empty($entity->uid)) {

      $author = user_load($entity->uid);

      // If the uid was mapped directly, rather than by email or username, it
      // could be invalid.
      if (!$author) {
        $message = 'User %uid is not a valid user.';
        throw new FeedsAccessException(t($message, array('%uid' => $entity->uid)));
      }

      if (empty($entity->nid) || !empty($entity->is_new)) {
        $op = 'create';
        $access = node_access($op, $entity->type, $author);
      }
      else {
        $op = 'update';
        $access = node_access($op, $entity, $author);
      }

      if (!$access) {
        $message = 'User %name is not authorized to %op content type %content_type.';
        throw new FeedsAccessException(t($message, array('%name' => $author->name, '%op' => $op, '%content_type' => $entity->type)));
      }
    }
  }

  /**
   * Validates a node.
   */
  protected function entityValidate($entity) {
    if (!isset($entity->uid) || !is_numeric($entity->uid)) {
       $entity->uid = $this->config['author'];
    }
    if (drupal_strlen($entity->title) > 255) {
      $entity->title = drupal_substr($entity->title, 0, 255);
    }
  }

  /**
   * Save a node.
   */
  public function entitySave($entity) {
    $entity->save();
  }

  /**
   * Overrides parent::expiryQuery().
   */
  protected function expiryQuery(Feed $feed, $time) {
    $select = parent::expiryQuery($feed, $time);
    $data_table = $select->join('node_field_data', 'nfd', 'e.nid = nfd.nid');
    $select->condition('nfd.created', REQUEST_TIME - $time, '<');
    return $select;
  }

  /**
   * Return expiry time.
   */
  public function expiryTime() {
    return $this->config['expire'];
  }

  /**
   * Override parent::configDefaults().
   */
  public function configDefaults() {
    return array(
      'expire' => FEEDS_EXPIRE_NEVER,
      'author' => 0,
      'authorize' => TRUE,
    ) + parent::configDefaults();
  }

  /**
   * Override parent::configForm().
   */
  public function configForm(&$form_state) {
    $form = parent::configForm($form_state);

    $author = user_load($this->config['author']);
    $form['author'] = array(
      '#type' => 'textfield',
      '#title' => t('Author'),
      '#description' => t('Select the author of the nodes to be created - leave empty to assign "anonymous".'),
      '#autocomplete_path' => 'user/autocomplete',
      '#default_value' => empty($author->name) ?  'anonymous' : check_plain($author->name),
    );
    $form['authorize'] = array(
      '#type' => 'checkbox',
      '#title' => t('Authorize'),
      '#description' => t('Check that the author has permission to create the node.'),
      '#default_value' => $this->config['authorize'],
    );
    $period = drupal_map_assoc(array(FEEDS_EXPIRE_NEVER, 3600, 10800, 21600, 43200, 86400, 259200, 604800, 2592000, 2592000 * 3, 2592000 * 6, 31536000), 'feeds_format_expire');
    $form['expire'] = array(
      '#type' => 'select',
      '#title' => t('Expire nodes'),
      '#options' => $period,
      '#description' => t('Select after how much time nodes should be deleted. The node\'s published date will be used for determining the node\'s age, see Mapping settings.'),
      '#default_value' => $this->config['expire'],
    );
    return $form;
  }

  /**
   * Override parent::configFormValidate().
   */
  public function configFormValidate(&$values) {
    if ($author = user_load_by_name($values['author'])) {
      $values['author'] = $author->uid;
    }
    else {
      $values['author'] = 0;
    }
  }

  /**
   * Reschedule if expiry time changes.
   */
  public function configFormSubmit(&$values) {
    if ($this->config['expire'] != $values['expire']) {
      feeds_reschedule($this->importer->id());
    }
    parent::configFormSubmit($values);
  }

  /**
   * Override setTargetElement to operate on a target item that is a node.
   */
  public function setTargetElement(Feed $feed, $target_node, $target_element, $value) {
    switch ($target_element) {
      case 'created':
        $target_node->created = feeds_to_unixtime($value, REQUEST_TIME);
        break;

      case 'feeds_source':
        break;

      case 'user_name':
        if ($user = user_load_by_name($value)) {
          $target_node->uid = $user->uid;
        }
        break;

      case 'user_mail':
        if ($user = user_load_by_mail($value)) {
          $target_node->uid = $user->uid;
        }
        break;

      default:
        parent::setTargetElement($feed, $target_node, $target_element, $value);
        break;
    }
  }

  /**
   * Return available mapping targets.
   */
  public function getMappingTargets() {
    $type = node_type_load($this->bundle());

    $targets = parent::getMappingTargets();
    if ($type && $type->has_title) {
      $targets['title'] = array(
        'name' => t('Title'),
        'description' => t('The title of the node.'),
        'optional_unique' => TRUE,
      );
    }
    $targets['nid'] = array(
      'name' => t('Node ID'),
      'description' => t('The nid of the node. NOTE: use this feature with care, node ids are usually assigned by Drupal.'),
      'optional_unique' => TRUE,
    );
    $targets['uid'] = array(
      'name' => t('User ID'),
      'description' => t('The Drupal user ID of the node author.'),
    );
    $targets['user_name'] = array(
      'name' => t('Username'),
      'description' => t('The Drupal username of the node author.'),
    );
    $targets['user_mail'] = array(
      'name' => t('User email'),
      'description' => t('The email address of the node author.'),
    );
    $targets['status'] = array(
      'name' => t('Published status'),
      'description' => t('Whether a node is published or not. 1 stands for published, 0 for not published.'),
    );
    $targets['created'] = array(
      'name' => t('Published date'),
      'description' => t('The UNIX time when a node has been published.'),
    );
    $targets['promote'] = array(
      'name' => t('Promoted to front page'),
      'description' => t('Boolean value, whether or not node is promoted to front page. (1 = promoted, 0 = not promoted)'),
    );
    $targets['sticky'] = array(
      'name' => t('Sticky'),
      'description' => t('Boolean value, whether or not node is sticky at top of lists. (1 = sticky, 0 = not sticky)'),
    );

    // Include language field if Locale module is enabled.
    if (module_exists('locale')) {
      $targets['language'] = array(
        'name' => t('Language'),
        'description' => t('The two-character language code of the node.'),
      );
    }

    // Include comment field if Comment module is enabled.
    if (module_exists('comment')) {
      $targets['comment'] = array(
        'name' => t('Comments'),
        'description' => t('Whether comments are allowed on this node: 0 = no, 1 = read only, 2 = read/write.'),
      );
    }

    // Let other modules expose mapping targets.
    $definitions = \Drupal::service('plugin.manager.feeds.mapper')->getDefinitions();
    foreach ($definitions as $definition) {
      $mapper = \Drupal::service('plugin.manager.feeds.mapper')->createInstance($definition['id']);
      $mapper->targets($targets, $this->entityType(), $this->bundle());
    }

    return $targets;
  }

  /**
   * Get nid of an existing feed item node if available.
   */
  protected function existingEntityId(Feed $feed, FeedsParserResult $result) {
    if ($nid = parent::existingEntityId($feed, $result)) {
      return $nid;
    }

    // Iterate through all unique targets and test whether they do already
    // exist in the database.
    foreach ($this->uniqueTargets($feed, $result) as $target => $value) {
      switch ($target) {
        case 'nid':
          $nid = db_query("SELECT nid FROM {node} WHERE nid = :nid", array(':nid' => $value))->fetchField();
          break;

        case 'title':
          $nid = db_query("SELECT nid FROM {node_field_data} WHERE title = :title AND type = :type", array(':title' => $value, ':type' => $this->bundle()))->fetchField();
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