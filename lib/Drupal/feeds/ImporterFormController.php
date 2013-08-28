<?php

/**
 * @file
 * Definition of \Drupal\feeds\ImporterFormController.
 */

namespace Drupal\feeds;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityFormController;
use Drupal\Core\Entity\EntityStorageControllerInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\feeds\AdvancedFormPluginInterface;
use Drupal\feeds\Ajax\SetHashCommand;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the importer edit forms.
 */
class ImporterFormController extends EntityFormController {

  /**
   * The importer storage controller.
   *
   * @var \Drupal\Core\Entity\EntityStorageControllerInterface
   */
  protected $importerStorage;

  /**
   * The plugngs that provide configuration forms.
   *
   * @var array
   */
  protected $configurablePlugins = array();

  /**
   * Constructs an ImporterFormController object.
   *
   * @param \Drupal\Core\Entity\EntityStorageControllerInterface $importer_storage
   *   The importer storage controller.
   */
  public function __construct(EntityStorageControllerInterface $importer_storage) {
    $this->importerStorage = $importer_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('entity.manager')->getStorageController('feeds_importer'));
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, array &$form_state) {
    $form['#tree'] = TRUE;

    $form['basics'] = array(
      '#title' => t('Basic settings'),
      '#type' => 'details',
      '#tree' => FALSE,
      '#collapsed' => !$this->entity->isNew(),
    );

    $form['basics']['label'] = array(
      '#type' => 'textfield',
      '#title' => t('Label'),
      '#default_value' => $this->entity->label(),
      '#maxlength' => '255',
      '#description' => t('A unique label for this importer. This label will be displayed in the interface.'),
    );

    $form['basics']['id'] = array(
      '#type' => 'machine_name',
      '#title' => t('Machine name'),
      '#default_value' => $this->entity->id(),
      '#disabled' => !$this->entity->isNew(),
      '#maxlength' => 64,
      '#description' => t('A unique name for this importer. It must only contain lowercase letters, numbers and underscores.'),
      '#machine_name' => array(
        'exists' => array($this, 'exists'),
        'source' => array('basics', 'label'),
      ),
    );
    $form['basics']['description'] = array(
      '#type' => 'textfield',
      '#title' => t('Description'),
      '#description' => t('A description of this importer.'),
      '#default_value' => $this->entity->description,
    );
    $form['import_on_create'] = array(
      '#type' => 'checkbox',
      '#title' => t('Import on submission'),
      '#description' => t('Check if import should be started at the moment a standalone form or node form is submitted.'),
      '#default_value' => $this->entity->import_on_create,
    );
    $cron_required = ' ' . l(t('Requires cron to be configured.'), 'http://drupal.org/cron', array('attributes' => array('target' => '_new')));
    $form['process_in_background'] = array(
      '#type' => 'checkbox',
      '#title' => t('Process in background'),
      '#description' => t('For very large imports. If checked, import and delete tasks started from the web UI will be handled by a cron task in the background rather than by the browser. This does not affect periodic imports, they are handled by a cron task in any case.') . $cron_required,
      '#default_value' => $this->entity->process_in_background,
    );

    $form['plugin_settings'] = array(
      '#type' => 'vertical_tabs',
      '#weight' => 99,
    );

    $form['plugin_settings']['#prefix'] = '<div id="feeds-ajax-form-wrapper">';
    $form['plugin_settings']['#suffix'] = '</div>';

    // If this is an ajax requst, updating the plugins on the importer will give
    // us the updated form.
    if (isset($form_state['values'])) {
      foreach ($this->entity->getPluginTypes() as $type) {
        $this->entity->setPlugin($type, $form_state['values'][$type]['id']);
      }
    }

    foreach ($this->entity->getPlugins() as $type => $plugin) {

      $options = $this->entity->getPluginOptionsList($type);

      if (count($options) === 1) {
        $form[$type]['id'] = array(
          '#type' => 'value',
          '#value' => $plugin->getPluginId(),
          '#plugin_type' => $type,
        );
      }
      else {
        $form[$type]['id'] = array(
          '#type' => 'select',
          '#title' => t('@type', array('@type' => ucfirst($type))),
          '#options' => $options,
          '#default_value' => $plugin->getPluginId(),
          '#ajax' => array(
            'callback' => array($this, 'ajaxCallback'),
            'wrapper' => 'feeds-ajax-form-wrapper',
            'effect' => 'none',
            'progress' => 'none',
          ),
          '#attached' => array(
            'library' => array(array('feeds', 'feeds')),
          ),
          '#plugin_type' => $type,
        );
      }

      // This is the small form that appears under the select box.
      if ($plugin instanceof AdvancedFormPluginInterface) {
        $form[$type]['advanced'] = $plugin->buildAdvancedForm(array(), $form_state);
        $this->configurablePlugins[$type] = $plugin;
      }

      $form[$type]['advanced']['#prefix'] = '<div id="feeds-plugin-' . $type . '-advanced">';
      $form[$type]['advanced']['#suffix'] = '</div>';

      if ($plugin instanceof PluginFormInterface) {
        $this->configurablePlugins[$type] = $plugin;

        if ($plugin_form = $plugin->buildConfigurationForm(array(), $form_state)) {
          $form[$type . '_configuration'] = array(
            '#type' => 'details',
            '#group' => 'plugin_settings',
            '#title' => t('@type settings', array('@type' => ucfirst($type))),
            '#parents' => array($type, 'configuration'),
          );
          // $form[$type . '_configuration']['#prefix'] = '<span id="feeds-' . $type . '-details">';
          // $form[$type . '_configuration']['#suffix'] = '</span>';

          $form[$type . '_configuration'] += $plugin_form;
        }
      }
    }

    return parent::form($form, $form_state);
  }

  /**
   * Determines if the importer already exists.
   *
   * @param string $id
   *   The importer ID.
   *
   * @return bool
   *   True if the importer exists, false otherwise.
   */
  public function exists($id) {
    return (bool) $this->importerStorage->load($id);
  }

  /**
   * {@inheritdoc}
   */
  public function validate(array $form, array &$form_state) {

    // Moved advanced settings to regular settings.
    foreach ($this->entity->getPluginTypes() as $type) {
      if (isset($form_state['values'][$type]['advanced'])) {
        if (!isset($form_state['values'][$type]['configuration'])) {
          $form_state['values'][$type]['configuration'] = array();
        }
        $form_state['values'][$type]['configuration'] += $form_state['values'][$type]['advanced'];
        unset($form_state['values'][$type]['advanced']);
      }
    }

    foreach ($this->configurablePlugins as $plugin) {
      $plugin->validateConfigurationForm($form, $form_state);
    }

    // Build the importer object from the submitted values.
    parent::validate($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submit(array $form, array &$form_state) {

    foreach ($this->configurablePlugins as $plugin) {
      $plugin->submitConfigurationForm($form, $form_state);
    }

    // Build the importer object from the submitted values.
    return parent::submit($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, array &$form_state) {
    $importer = $this->entity;
    unset($importer->plugin_settings);
    unset($importer->actions);
    $importer->save();
    drupal_set_message(t('Your changes have been saved.'));
  }

  /**
   * Sends an ajax response.
   */
  public function ajaxCallback(array $form, array &$form_state) {
    $type = $form_state['triggering_element']['#plugin_type'];
    $response = new AjaxResponse();

    if (isset($form[$type . '_configuration']['#id'])) {
      $hash = ltrim($form[$type . '_configuration']['#id'], '#');
      $response->addCommand(new SetHashCommand($hash));
    }
    $response->addCommand(new ReplaceCommand('#feeds-ajax-form-wrapper', drupal_render($form['plugin_settings'])));
    $response->addCommand(new ReplaceCommand('#feeds-plugin-' . $type . '-advanced', drupal_render($form[$type]['advanced'])));

    return $response;
  }

}
