<?php

/**
 * @file
 * Contains \Drupal\feeds\Plugin\Type\Parser\ParserInterface.
 */

namespace Drupal\feeds\Plugin\Type\Parser;

use Drupal\feeds\FeedInterface;
use Drupal\feeds\Plugin\Type\FeedsPluginInterface;
use Drupal\feeds\Result\FetcherResultInterface;

/**
 * The interface Feeds parser must implement.
 */
interface ParserInterface extends FeedsPluginInterface {

  /**
   * Parses content returned by fetcher.
   *
   * @param \Drupal\feeds\FeedInterface $feed
   *   The feed we are parsing for.
   * @param \Drupal\feeds\Result\FetcherResultInterface $fetcher_result
   *   The result returned by the fetcher.
   *
   * @return \Drupal\feeds\Result\ParserResultInterface
   *   The parser result object.
   *
   * @todo This needs more documentation.
   */
  public function parse(FeedInterface $feed, FetcherResultInterface $fetcher_result);

  /**
   * Declare the possible mapping sources that this parser produces.
   *
   * @return array|false
   *   An array of mapping sources, or false if the sources can be defined by
   *   typing a value in a text field.
   *
   * @todo Get rid of the false return here and create a configurable source
   *   solution for parsers.
   * @todo Add type data here for automatic mappings.
   * @todo Provide code example.
   */
  public function getMappingSources();

}
