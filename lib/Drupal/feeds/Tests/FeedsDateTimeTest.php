<?php

/**
 * @file
 * Tests for FeedsDateTime class.
 */

namespace Drupal\feeds\Tests;

/**
 * Test FeedsDateTime class.
 *
 * Using DrupalWebTestCase as DrupalUnitTestCase is broken in SimpleTest 2.8.
 * Not inheriting from Feeds base class as ParserCSV should be moved out of
 * Feeds at some time.
 */
class FeedsDateTimeTest extends FeedsWebTestBase {
  protected $profile = 'testing';

  public static function getInfo() {
    return array(
      'name' => 'FeedsDateTime unit tests',
      'description' => 'Unit tests for Feeds date handling.',
      'group' => 'Feeds',
    );
  }

  public function setUp() {
    parent::setUp();
    module_load_include('inc', 'feeds' , 'plugins/FeedsParser');
  }

  /**
   * Dispatch tests, only use one entry point method testX to save time.
   */
  public function test() {
    $date = new FeedsDateTime('2010-20-12');
    $this->assertTrue(is_numeric($date->format('U')));
    $date = new FeedsDateTime('created');
    $this->assertTrue(is_numeric($date->format('U')));
    $date = new FeedsDateTime('12/3/2009 20:00:10');
    $this->assertTrue(is_numeric($date->format('U')));
  }
}
