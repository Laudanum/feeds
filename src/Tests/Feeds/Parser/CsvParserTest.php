<?php

/**
 * @file
 * Contains \Drupal\feeds\Tests\Fetcher\CsvParserTest.
 */

namespace Drupal\feeds\Tests\Fetcher;

use Drupal\Core\Form\FormState;
use Drupal\feeds\Feeds\Parser\CsvParser;
use Drupal\feeds\Result\FetcherResult;
use Drupal\feeds\State;
use Drupal\feeds\StateInterface;
use Drupal\feeds\Tests\FeedsUnitTestCase;
use org\bovigo\vfs\vfsStream;

/**
 * @covers \Drupal\feeds\Feeds\Parser\CsvParser
 * @group Feeds
 */
class CsvParserTest extends FeedsUnitTestCase {

  protected $parser;
  protected $importer;
  protected $feed;
  protected $state;

  public function setUp() {
    parent::setUp();

    $this->importer = $this->getMock('Drupal\feeds\ImporterInterface');
    $configuration = ['importer' => $this->importer];
    $this->parser = new CsvParser($configuration, 'csv', []);
    $this->parser->setStringTranslation($this->getStringTranslationStub());

    $this->state = new State();

    $this->feed = $this->getMock('Drupal\feeds\FeedInterface');
    $this->feed->expects($this->any())
      ->method('getState')
      ->with(StateInterface::PARSE)
      ->will($this->returnValue($this->state));
    $this->feed->expects($this->any())
      ->method('getImporter')
      ->will($this->returnValue($this->importer));
  }

  public function testFetch() {
    $this->importer->expects($this->any())
      ->method('getLimit')
      ->will($this->returnValue(3));
    $this->feed->expects($this->any())
      ->method('getConfigurationFor')
      ->with($this->parser)
      ->will($this->returnValue($this->parser->sourceDefaults()));

    $file = dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/tests/resources/csv-example.xml';
    $fetcher_result = new FetcherResult($file);

    $result = $this->parser->parse($this->feed, $fetcher_result);
    $this->assertSame(count($result), 3);
    $this->assertSame($result[0]->get('Header A'), '"1"');

    // Parse again. Tests batching.
    $result = $this->parser->parse($this->feed, $fetcher_result);
    $this->assertSame(count($result), 2);
    $this->assertSame($result[0]->get('Header B'), "new\r\nline 2");
  }

  /**
   * @expectedException \Drupal\feeds\Exception\EmptyFeedException
   */
  public function testEmptyFeed() {
    vfsStream::setup('feeds');
    touch('vfs://feeds/empty_file');
    $result = new FetcherResult('vfs://feeds/empty_file');
    $this->parser->parse($this->feed, $result);
  }

  public function testGetMappingSources() {
    // Not really much to test here.
    $this->assertFalse($this->parser->getMappingSources());
  }

  public function testFeedForm() {
    $form_state = new FormState();
    $form = $this->parser->buildFeedForm([], $form_state, $this->feed);
    $this->assertSame(count($form), 1);
  }

  public function testConfigurationForm() {
    $form_state = new FormState();
    $form = $this->parser->buildConfigurationForm([], $form_state);
    $this->assertSame(count($form), 2);
  }

}
