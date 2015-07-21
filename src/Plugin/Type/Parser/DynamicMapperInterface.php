<?php

/**
 * @file
 * Contains \Drupal\feeds\Plugin\Type\Parser\DynamicMapperInterface.
 */

namespace Drupal\feeds\Plugin\Type\Parser;

use Drupal\Core\Form\FormStateInterface;

/**
 * The interface for parsers with dynamic feed sources.
 */
interface DynamicMapperInterface {

  /**
   * Adds a mapping source.
   *
   * @param array $source
   *   The source configuration.
   */
  public function addMappingSource(array $source);

  /**
   * Removes a mapping source.
   *
   * @param string $source_id
   *   The source id.
   */
  public function removeMappingSource($source_id);

  /**
   * Reuturns the list of table headers.
   *
   * @return array
   *   A list of header names keyed by the form keys.
   */
  public function getSourceTableHeader();

  /**
   * Returns a form element for a specific column.
   *
   * @param array $values
   *   The individual source item values.
   * @param string $column
   *   The name of the column.
   * @param string $machine_name
   *   The machine name of the source.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   A single form element.
   */
  public function getSourceColumnForm($column, $machine_name, FormStateInterface $form_state);

}
