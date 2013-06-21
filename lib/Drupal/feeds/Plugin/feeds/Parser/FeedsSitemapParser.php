<?php

/**
 * @file
 * Contains FeedsSitemapParser and related classes.
 */

namespace Drupal\feeds\Plugin\feeds\Parser;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;
use Drupal\feeds\Plugin\ParserBase;
use Drupal\feeds\Plugin\Core\Entity\Feed;
use Drupal\feeds\FeedsFetcherResult;
use Drupal\feeds\FeedsParserResult;
use SimpleXMLElement;

/**
 * Defines a SitemapXML feed parser.
 *
 * @Plugin(
 *   id = "sitemap",
 *   title = @Translation("Sitemap parser"),
 *   description = @Translation("Parse Sitemap XML format feeds.")
 * )
 */
class FeedsSitemapParser extends ParserBase {
  /**
   * Implements ParserBase::parse().
   */
  public function parse(Feed $feed, FeedsFetcherResult $fetcher_result) {
    // Set time zone to GMT for parsing dates with strtotime().
    $tz = date_default_timezone_get();
    date_default_timezone_set('GMT');
    // Yes, using a DOM parser is a bit inefficient, but will do for now
    $xml = new SimpleXMLElement($fetcher_result->getRaw());
    $result = new FeedsParserResult();
    foreach ($xml->url as $url) {
      $item = array('url' => (string) $url->loc);
      if ($url->lastmod) {
        $item['lastmod'] = strtotime($url->lastmod);
      }
      if ($url->changefreq) {
        $item['changefreq'] = (string) $url->changefreq;
      }
      if ($url->priority) {
        $item['priority'] = (string) $url->priority;
      }
      $result->items[] = $item;
    }
    date_default_timezone_set($tz);
    return $result;
  }

  /**
   * Implements ParserBase::getMappingSources().
   */
  public function getMappingSources() {
    return array(
      'url' => array(
        'name' => t('Item URL (link)'),
        'description' => t('URL of the feed item.'),
      ),
      'lastmod' => array(
        'name' => t('Last modification date'),
        'description' => t('Last modified date as UNIX time GMT of the feed item.'),
      ),
      'changefreq' => array(
        'name' => t('Change frequency'),
        'description' => t('How frequently the page is likely to change.'),
      ),
      'priority' => array(
        'name' => t('Priority'),
        'description' => t('The priority of this URL relative to other URLs on the site.'),
      ),
    ) + parent::getMappingSources();
  }
}