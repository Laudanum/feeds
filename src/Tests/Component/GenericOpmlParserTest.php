<?php

/**
 * @file
 * Contains \Drupal\feeds\Tests\Component\GenericOpmlParserTest.
 */

namespace Drupal\feeds\Tests\Component;

use Drupal\feeds\Component\GenericOpmlParser;
use Drupal\feeds\Tests\FeedsUnitTestCase;

/**
 * @covers \Drupal\feeds\Component\GenericOpmlParser
 * @group Feeds
 */
class GenericOpmlParserTest extends FeedsUnitTestCase {

  public function test() {
    $file = dirname(dirname(dirname(dirname(__FILE__)))) . '/tests/resources/opml-example.xml';
    $parser = new GenericOpmlParser(file_get_contents($file));
    $result = $parser->parse();
    $this->assertSame(count($result), 2);
    $this->assertSame(count($result['head']), 11);
    $this->assertSame(count($result['outlines']), 11);

    // Try with lowercase.
    $result = $parser->parse(TRUE);
    $this->assertSame(count($result), 2);
    $this->assertSame(count($result['head']), 11);
    $this->assertSame(count($result['outlines']), 11);
  }

}
