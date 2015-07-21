<?php

/**
 * @file
 * Contains \Drupal\feeds\Plugin\Type\Parser\DynamicMapperBase.
 */

namespace Drupal\feeds\Plugin\Type\Parser;

use Drupal\Core\Form\FormStateInterface;
use Drupal\feeds\Plugin\Type\ConfigurablePluginBase;

/**
 * A base helper class for dynamic mappers.
 *
 * @todo This could easily be a trait.
 */
abstract class DynamicMapperBase extends ConfigurablePluginBase implements DynamicMapperInterface {

  /**
   * {@inheritdoc}
   */
  public function getMappingSources() {
    return $this->configuration['sources'];
  }

  /**
   * {@inheritdoc}
   */
  public function addMappingSource(array $source) {
    $this->configuration['sources'][$source['machine_name']] = $source;
  }

  /**
   * {@inheritdoc}
   */
  public function removeMappingSource($source) {
    unset($this->configuration['sources'][$source]);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['sources' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceTableHeader() {
    return [
      'label' => $this->t('Name'),
      'machine_name' => $this->t('Machine name'),
      'value' => $this->t('Value'),
      'remove' => $this->t('Remove'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceColumnForm($column, $machine_name, FormStateInterface $form_state) {
    $config = isset($this->configuration['sources'][$machine_name]) ? $this->configuration['sources'][$machine_name] : ['label' => '', 'machine_name' => '', 'value' => ''];

    switch ($column) {
      case 'label':
        return [
          '#type' => 'textfield',
          '#default_value' => $config[$column],
          '#size' => 20,
        ];

      case 'machine_name':
        return [
          '#type' => 'machine_name',
          '#machine_name' => [
            'exists' => get_class($this) . '::sourceExists',
            'source' => ['dynamic_sources', 'table', $machine_name, 'label'],
            'standalone' => TRUE,
            'label' => '',
          ],
          '#default_value' => $config[$column],
          '#required' => FALSE,
          '#disabled' => $config[$column],
        ];

      case 'value':
        return [
          '#type' => 'textfield',
          '#default_value' => $config[$column],
          '#maxlength' => 1024,
        ];

      case 'remove':
        if ($machine_name === '__add') {
          return ['#markup' => ''];
        }
        return [
          '#type' => 'checkbox',
          '#ajax' => [
            'callback' => '::dynamicSourcesCallback',
            'wrapper' => 'feeds-dynamic-sources-form-wrapper',
          ],
          '#parents' => ['dynamic_sources', 'remove', $machine_name],
        ];
    }
  }

  /**
   * Callback for existing sources.
   */
  public static function sourceExists($machine_name, array $element, FormStateInterface $form_state) {
    $feed_type = $form_state->getFormObject()->getEntity();
    $sources = $feed_type->getParser()->getMappingSources();
    return isset($sources[$machine_name]);
  }

}
