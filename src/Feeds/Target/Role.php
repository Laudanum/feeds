<?php

/**
 * @file
 * Contains \Drupal\feeds\Feeds\Target\Role.
 */

namespace Drupal\feeds\Feeds\Target;

use Drupal\feeds\Plugin\Type\Target\FieldTargetBase;

/**
 * Defines a role field mapper.
 *
 * @FeedsTarget(
 *   id = "role",
 *   field_types = {
 *     "role"
 *   }
 * )
 */
class Role extends FieldTargetBase {

  /**
   * {@inheritdoc}
   */
  protected function prepareValue($delta, array &$values) {
    error_log(print_r($values, TRUE));
    $values['value'] = (bool) trim((string) $values['value']);
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    // $formats = \Drupal::entityManager()
    //   ->getStorage('filter_format')
    //   ->loadByProperties(['status' => '1', 'format' => $this->configuration['format']]);

    // if ($formats) {
    //   $format = reset($formats);
    //   return $this->t('Format: %format', ['%format' => $format->label()]);
    // }
  }

}
