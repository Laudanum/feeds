<?php

/**
 * @file
 * Contains \Drupal\feeds\Feeds\Parser\SyndicationParser.
 */

namespace Drupal\feeds\Feeds\Parser;

use Drupal\feeds\Exception\EmptyFeedException;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Feeds\Item\SyndicationItem;
use Drupal\feeds\Plugin\Type\Parser\ParserInterface;
use Drupal\feeds\Plugin\Type\PluginBase;
use Drupal\feeds\Result\FetcherResultInterface;
use Drupal\feeds\Result\ParserResult;
use Drupal\feeds\StateInterface;
use Zend\Feed\Reader\Exception\ExceptionInterface;
use Zend\Feed\Reader\Reader;

/**
 * Defines an RSS and Atom feed parser.
 *
 * @FeedsParser(
 *   id = "syndication",
 *   title = @Translation("RSS/Atom"),
 *   description = @Translation("Default parser for RSS, Atom and RDF feeds.")
 * )
 */
class SyndicationParser extends PluginBase implements ParserInterface {

  /**
   * {@inheritdoc}
   */
  public function parse(FeedInterface $feed, FetcherResultInterface $fetcher_result) {
    $result = new ParserResult();
    Reader::setExtensionManager(\Drupal::service('feed.bridge.reader'));
    Reader::registerExtension('GeoRSS');

    $raw = $fetcher_result->getRaw();
    if (!strlen(trim($raw))) {
      throw new EmptyFeedException();
    }

    try {
      $channel = Reader::importString($raw);
    }
    catch (ExceptionInterface $e) {
      $args = ['%site' => $feed->label(), '%error' => trim($e->getMessage())];
      throw new \RuntimeException($this->t('The feed from %site seems to be broken because of error "%error".', $args));
    }

    foreach ($channel as $delta => $entry) {
      $item = new SyndicationItem();
      // Move the values to an array as expected by processors.
      $item
        ->set('title', $entry->getTitle())
        ->set('guid', $entry->getId())
        ->set('url', $entry->getLink())
        ->set('guid', $entry->getId())
        ->set('url', $entry->getLink())
        ->set('description', $entry->getDescription())
        ->set('tags', $entry->getCategories()->getValues())
        ->set('feed_title', $channel->getTitle())
        ->set('feed_description', $channel->getDescription())
        ->set('feed_url', $channel->getLink());

      if ($image = $channel->getImage()) {
        $item->set('feed_image_uri', $image['uri']);
      }

      if ($enclosure = $entry->getEnclosure()) {
        $item->set('enclosures', [rawurldecode($enclosure->url)]);
      }

      if ($author = $entry->getAuthor()) {
        $author += ['name' => '', 'email' => ''];
        $item->set('author_name', $author['name'])
             ->set('author_email', $author['email']);
      }
      if ($date = $entry->getDateModified()) {
        $item->set('timestamp', $date->getTimestamp());
      }

      if ($point = $entry->getGeoPoint()) {
        $item->set('georss_lat', $point['lat'])
             ->set('georss_lon', $point['lon']);
      }

      $result->addItem($item);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getMappingSources() {
    return array(
      'feed_title' => array(
        'label' => $this->t('Feed title'),
        'description' => $this->t('Title of the feed.'),
      ),
      'feed_description' => array(
        'label' => $this->t('Feed description'),
        'description' => $this->t('Description of the feed.'),
      ),
      'feed_image_uri' => array(
        'label' => $this->t('Feed image'),
        'description' => $this->t('The URL of the feed image.'),
      ),
      'feed_url' => array(
        'label' => $this->t('Feed URL (link)'),
        'description' => $this->t('URL of the feed.'),
      ),
      'title' => array(
        'label' => $this->t('Title'),
        'description' => $this->t('Title of the feed item.'),
        'suggestions' => array(
          'targets' => array('subject', 'title', 'label', 'name'),
          'types' => array(
            'field_item:text' => [],
          ),
        ),
      ),
      'description' => array(
        'label' => $this->t('Description'),
        'description' => $this->t('Description of the feed item.'),
        'suggested' => array('body'),
        'suggestions' => array(
          'targets' => array('body'),
          'types' => array(
            'field_item:text_with_summary' => [],
          ),
        ),
      ),
      'author_name' => array(
        'label' => $this->t('Author name'),
        'description' => $this->t("Name of the feed item's author."),
        'suggestions' => array(
          'types' => array(
            'entity_reference_field' => array('target_type' => 'user'),
          ),
        ),
      ),
      'author_email' => array(
        'label' => $this->t('Author email'),
        'description' => $this->t("Name of the feed item's email address."),
      ),
      'timestamp' => array(
        'label' => $this->t('Published date'),
        'description' => $this->t('Published date as UNIX time GMT of the feed item.'),
        'suggestions' => array(
          'targets' => array('created'),
        ),
      ),
      'url' => array(
        'label' => $this->t('Item URL (link)'),
        'description' => $this->t('URL of the feed item.'),
        'suggestions' => array(
          'targets' => array('url'),
        ),
      ),
      'guid' => array(
        'label' => $this->t('Item GUID'),
        'description' => $this->t('Global Unique Identifier of the feed item.'),
        'suggestions' => array(
          'targets' => array('guid'),
        ),
      ),
      'tags' => array(
        'label' => $this->t('Categories'),
        'description' => $this->t('An array of categories that have been assigned to the feed item.'),
        'suggestions' => array(
          'targets' => array('field_tags'),
          'types' => array(
            'field_item:taxonomy_term_reference' => [],
          ),
        ),
      ),
      'georss_lat' => array(
        'label' => $this->t('Item lattitude'),
        'description' => $this->t('The feed item lattitutde.'),
      ),
      'georss_lon' => array(
        'label' => $this->t('Item longitude'),
        'description' => $this->t('The feed item longitude.'),
      ),
      'enclosures' => array(
        'label' => $this->t('Enclosures'),
        'description' => $this->t('A list of enclosures attached to the feed item.'),
        'suggestions' => array(
          'types' => array(
            'field_item:file' => [],
          ),
        ),
      ),
    );
  }

}
