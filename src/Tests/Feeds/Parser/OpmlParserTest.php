<?php

/**
 * @file
 * Contains \Drupal\feeds\Tests\Feeds\Parser\OpmlParserTest.
 */

namespace Drupal\feeds\Tests\Feeds\Parser;

use Drupal\feeds\Feeds\Parser\OpmlParser;
use Drupal\feeds\Result\RawFetcherResult;
use Drupal\feeds\State;
use Drupal\feeds\StateInterface;
use Drupal\feeds\Tests\FeedsUnitTestCase;

/**
 * @covers \Drupal\feeds\Feeds\Parser\OpmlParser
 * @group Feeds
 */
class OpmlParserTest extends FeedsUnitTestCase {

  protected $parser;
  protected $importer;
  protected $feed;
  protected $state;

  public function setUp() {
    parent::setUp();

    $this->importer = $this->getMock('Drupal\feeds\ImporterInterface');
    $configuration = ['importer' => $this->importer];
    $this->parser = new OpmlParser($configuration, 'sitemap', []);
    $this->parser->setStringTranslation($this->getStringTranslationStub());

    $this->feed = $this->getMock('Drupal\feeds\FeedInterface');
    $this->feed->expects($this->any())
      ->method('getImporter')
      ->will($this->returnValue($this->importer));

    $this->state = $this->getMock('Drupal\feeds\StateInterface');
  }

  public function testFetch() {
    $file = dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/tests/resources/opml-example.xml';
    $fetcher_result = new RawFetcherResult(file_get_contents($file));

    $result = $this->parser->parse($this->feed, $fetcher_result, $this->state);
    $this->assertSame(count($result), 13);
    $this->assertSame($result[0]->get('title'), 'CNET News.com');
    $this->assertSame($result[3]->get('xmlurl'), 'http://rss.news.yahoo.com/rss/tech');
    $this->assertSame($result[7]->get('htmlurl'), 'http://www.fool.com');
  }

  /**
   * @expectedException \Drupal\feeds\Exception\EmptyFeedException
   */
  public function testEmptyFeed() {
    $this->parser->parse($this->feed, new RawFetcherResult(''), $this->state);
  }

  public function testGetMappingSources() {
    // Not really much to test here.
    $this->assertSame(count($this->parser->getMappingSources()), 5);
  }

}

