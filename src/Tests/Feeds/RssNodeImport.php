<?php

/**
 * @file
 * Contains \Drupal\feeds\Tests\Feeds\RssNodeImport.
 */

namespace Drupal\feeds\Tests\Feeds;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\feeds\Entity\Feed;
use Drupal\feeds\ImporterInterface;
use Drupal\feeds\Plugin\Type\Processor\ProcessorInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\simpletest\WebTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Integration test that imports nodes from an RSS feed.
 *
 * @group Feeds
 */
class RssNodeImport extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['taxonomy', 'node', 'feeds'];

  protected function setUp() {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'type' => 'taxonomy_term_reference',
      'settings' => [
        'allowed_values' => [
          ['vocabulary' => 'tags', 'parent' => 0],
        ],
      ],
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();

    FieldConfig::create([
      'label' => 'Tags',
      'description' => '',
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'bundle' => 'article',
    ])->save();

    $web_user = $this->drupalCreateUser(['administer feeds', 'bypass node access']);
    $this->drupalLogin($web_user);

    $this->importer = entity_create('feeds_importer', [
      'id' => Unicode::strtolower($this->randomMachineName()),
      'mappings' => [
        [
          'target' => 'title',
          'map' => ['value' => 'title'],
        ],
        [
          'target' => 'body',
          'map' => ['value' => 'description'],
        ],
        [
          'target' => 'feeds_item',
          'map' => ['guid' => 'guid', 'url' => 'url'],
          'unique' => ['guid' => TRUE],
        ],
        [
          'target' => 'created',
          'map' => ['value' => 'timestamp'],
        ],
        [
          'target' => 'field_tags',
          'map' => ['target_id' => 'tags'],
          'settings' => ['autocreate' => TRUE],
        ],
      ],
      'processor' => 'entity:node',
      'processor_configuration' => [
        'values' => [
          'type' => 'article',
        ],
      ],
      'import_period' => ImporterInterface::SCHEDULE_NEVER,
    ]);
    $this->importer->save();
  }

  public function testHttpImport() {
    $filepath = drupal_get_path('module', 'feeds') . '/tests/resources/googlenewstz.rss2';

    $feed = entity_create('feeds_feed', [
      'title' => $this->randomString(),
      'source' => file_create_url($filepath),
      'importer' => $this->importer->id(),
    ]);
    $feed->save();
    $this->drupalGet('feed/' . $feed->id());
    $this->clickLink(t('Import'));
    $this->drupalPostForm(NULL, [], t('Import'));
    $this->assertText('Created 6');
    $this->assertEqual(db_query("SELECT COUNT(*) FROM {node}")->fetchField(), 6);

    $xml = new \SimpleXMLElement($filepath, 0, TRUE);

    foreach (range(1, 6) as $nid) {
      $item = $xml->channel->item[$nid - 1];
      $node = node_load($nid);
      $this->assertEqual($node->title->value, (string) $item->title);
      $this->assertEqual($node->body->value, (string) $item->description);
      $this->assertEqual($node->feeds_item->guid, (string) $item->guid);
      $this->assertEqual($node->feeds_item->url, (string) $item->link);
      $this->assertEqual($node->created->value, strtotime((string) $item->pubDate));

      $terms = [];
      foreach ($node->field_tags as $value) {
        // $terms[] = Term::load([$value['target_id']])->label();
      }
    }

    // Test cache.
    $this->drupalPostForm('feed/' . $feed->id() . '/import', [], t('Import'));
    $this->assertText('The feed has not been updated.');

    // Import again.
    \Drupal::cache('feeds_download')->deleteAll();
    $this->drupalPostForm('feed/' . $feed->id() . '/import', [], t('Import'));
    $this->assertText('There are no new');

    // Test force-import.
    \Drupal::cache('feeds_download')->deleteAll();
    $configuration = $this->importer->getProcessor()->getConfiguration();
    $configuration['skip_hash_check'] = TRUE;
    $configuration['update_existing'] = ProcessorInterface::UPDATE_EXISTING;
    $this->importer->getProcessor()->setConfiguration($configuration);
    $this->importer->save();
    $this->drupalPostForm('feed/' . $feed->id() . '/import', [], t('Import'));
    $this->assertEqual(db_query("SELECT COUNT(*) FROM {node}")->fetchField(), 6);
    $this->assertText('Updated 6');

    // Delete items.
    $this->clickLink(t('Delete items'));
    $this->drupalPostForm(NULL, [], t('Delete items'));
    $this->assertEqual(db_query("SELECT COUNT(*) FROM {node}")->fetchField(), 0);
    $this->assertText('Deleted 6');
  }

  public function testCron() {
    $this->importer->setImportPeriod(3600);
    $mappings = $this->importer->getMappings();
    unset($mappings[2]['unique']);
    $this->importer->setMappings($mappings);
    $this->importer->save();

    $filepath = drupal_get_path('module', 'feeds') . '/tests/resources/googlenewstz.rss2';

    $feed = Feed::create([
      'title' => $this->randomString(),
      'source' => file_create_url($filepath),
      'importer' => $this->importer->id(),
    ]);
    $feed->save();

    $this->cronRun();
    $this->assertEqual(db_query("SELECT COUNT(*) FROM {node}")->fetchField(), 6);

    $this->cronRun();
    \Drupal::cache('feeds_download')->deleteAll();
    $this->assertEqual(db_query("SELECT COUNT(*) FROM {node}")->fetchField(), 6);

    // Check that items import normally.
    \Drupal::cache('feeds_download')->deleteAll();
    $this->drupalPostForm('feed/' . $feed->id() . '/import', [], t('Import'));
    $this->assertEqual(db_query("SELECT COUNT(*) FROM {node}")->fetchField(), 12);
  }

}
