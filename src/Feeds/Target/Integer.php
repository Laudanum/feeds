<?php

/**
 * @file
 * Contains \Drupal\feeds\Feeds\Target\Integer.
 */

namespace Drupal\feeds\Feeds\Target;

/**
 * Defines an integer field mapper.
 *
 * @Plugin(
 *   id = "integer",
 *   field_types = {
 *     "integer",
 *     "list_integer",
 *     "created",
 *     "timestamp"
 *   }
 * )
 */
class Integer extends Number {

  /**
   * {@inheritdoc}
   */
  protected function prepareValue($delta, array &$values) {
    $value = trim($values['value']);
    $values['value'] = is_numeric($value) ? (int) $value : '';
  }

}
