<?php

/**
 * @file
 * Contains \Drupal\aggregator\ItemInterface.
 */

namespace Drupal\feeds\Feeds\Item;

use Drupal\feeds\Result\ParserResultInterface;

/**
 * The interface for a single feed item.
 */
interface ItemInterface {

  /**
   * Returns the value for a target field.
   *
   * @param string $field
   *   The name of the field.
   *
   * @return mixed|null
   *   The value that corresponds to this field, or null if it does not exist.
   */
  public function get($field);

  /**
   * Sets a value for a field.
   *
   * @param string $field
   *   The name of the field.
   * @param mixed $value
   *   The value for the field.
   *
   * @return $this
   */
  public function set($field, $value);

  /**
   * Sets the parser result this item belongs to.
   *
   * The parser is added so that items can look up values in the result object
   * itself. Storing global, or feed-wide value on the result saves us from
   * having to duplicate them on every item.
   *
   * @param \Drupal\feeds\Result\ParserResultInterface $result
   *   The parser result.
   */
  public function setResult(ParserResultInterface $result);

}