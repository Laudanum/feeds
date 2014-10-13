<?php

/**
 * @file
 * Contains \Drupal\feeds\Feeds\Target\Uri.
 */

namespace Drupal\feeds\Feeds\Target;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\feeds\FieldTargetDefinition;

/**
 * Defines a string field mapper.
 *
 * @Plugin(
 *   id = "uri",
 *   field_types = {"uri"}
 * )
 */
class Uri extends String {

  /**
   * {@inheritdoc}
   */
  protected static function prepareTarget(FieldDefinitionInterface $field_definition) {
    return FieldTargetDefinition::createFromFieldDefinition($field_definition)
      ->addProperty('value')
      ->markPropertyUnique('value');
  }

}