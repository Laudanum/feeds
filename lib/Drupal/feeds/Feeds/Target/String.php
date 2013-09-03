<?php

/**
 * @file
 * Contains \Drupal\feeds\Feeds\Target\String.
 */

namespace Drupal\feeds\Feeds\Target;

use Drupal\Component\Annotation\Plugin;
use Drupal\feeds\Plugin\Type\Target\FieldTargetBase;

/**
 * Defines a string field mapper.
 *
 * @Plugin(
 *   id = "string",
 *   field_types = {"string_field"}
 * )
 */
class String extends FieldTargetBase {

  /**
   * {@inheritdoc}
   */
  protected function prepareValue($delta, array &$values) {
    $values['value'] = (string) $values['value'];
  }

}