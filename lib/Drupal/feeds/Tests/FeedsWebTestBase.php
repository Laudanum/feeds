<?php

/**
 * @file
 * Common functionality for all Feeds tests.
 */

namespace Drupal\feeds\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\feeds\Plugin\FeedsPlugin;

/**
 * Test basic Data API functionality.
 */
class FeedsWebTestBase extends WebTestBase {

  /**
   * The profile to install as a basis for testing.
   *
   * @var string
   */
  protected $profile = 'testing';

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array(
    'taxonomy',
    'image',
    'file',
    'field',
    'field_ui',
    // 'feeds_tests',
    'job_scheduler',
    'feeds_ui',
    'views',
  );

  public function setUp() {
    parent::setUp();

    // Create text format.
    $filtered_html_format = entity_create('filter_format', array(
      'format' => 'filtered_html',
      'name' => 'Filtered HTML',
      'weight' => 0,
      'filters' => array(
        // URL filter.
        'filter_url' => array(
          'weight' => 0,
          'status' => 1,
        ),
        // HTML filter.
        'filter_html' => array(
          'weight' => 1,
          'status' => 1,
        ),
        // Line break filter.
        'filter_autop' => array(
          'weight' => 2,
          'status' => 1,
        ),
        // HTML corrector filter.
        'filter_htmlcorrector' => array(
          'weight' => 10,
          'status' => 1,
        ),
      ),
    ));
    $filtered_html_format->save();

    $permissions = array();
    $permissions[] = 'access content';
    $permissions[] = 'administer site configuration';
    $permissions[] = 'administer content types';
    $permissions[] = 'administer nodes';
    $permissions[] = 'bypass node access';
    $permissions[] = 'administer taxonomy';
    $permissions[] = 'administer users';
    $permissions[] = 'administer feeds';
    $permissions[] = 'administer node fields';
    $permissions[] = 'administer node display';

    // Create an admin user and log in.
    $this->admin_user = $this->drupalCreateUser($permissions);
    $this->drupalLogin($this->admin_user);

    $types = array(
      array(
        'type' => 'page',
        'name' => 'Basic page',
      ),
      array(
        'type' => 'article',
        'name' => 'Article',
      ),
    );
    foreach ($types as $type) {
      $this->drupalCreateContentType($type);
      $edit = array(
        'node_options[status]' => 1,
        'node_options[promote]' => 1,
      );
      $this->drupalPost('admin/structure/types/manage/' . $type['type'], $edit, 'Save content type');
    }

    $display = config('views.view.frontpage')->get('display');
    $display['default']['display_options']['pager']['options']['items_per_page'] = 500;
    config('views.view.frontpage')
      ->set('display', $display)
      ->save();
  }

  /**
   * Absolute path to Drupal root.
   */
  public function absolute() {
    return realpath(getcwd());
  }

  /**
   * Get the absolute directory path of the feeds module.
   */
  public function absolutePath() {
    return  $this->absolute() . '/' . drupal_get_path('module', 'feeds');
  }

  /**
   * Generate an OPML test feed.
   *
   * The purpose of this function is to create a dynamic OPML feed that points
   * to feeds included in this test.
   */
  public function generateOPML() {
    $path = $GLOBALS['base_url'] . '/' . drupal_get_path('module', 'feeds') . '/tests/feeds/';

  $output =
'<?xml version="1.0" encoding="utf-8"?>
<opml version="1.1">
<head>
    <title>Feeds test OPML</title>
    <dateCreated>Fri, 16 Oct 2009 02:53:17 GMT</dateCreated>
    <ownerName></ownerName>
</head>
<body>
  <outline text="Feeds test group" >
    <outline title="Development Seed - Technological Solutions for Progressive Organizations" text="" xmlUrl="' . $path . 'developmentseed.rss2" type="rss" />
    <outline title="Magyar Nemzet Online - H\'rek" text="" xmlUrl="' . $path . 'feed_without_guid.rss2" type="rss" />
    <outline title="Drupal planet" text="" type="rss" xmlUrl="' . $path . 'drupalplanet.rss2" />
  </outline>
</body>
</opml>';

    // UTF 8 encode output string and write it to disk
    $output = utf8_encode($output);
    $filename = file_default_scheme() . '://test-opml-' . $this->randomName() . '.opml';

    $filename = file_unmanaged_save_data($output, $filename);
    return $filename;
  }

  /**
   * Create an importer configuration.
   *
   * @param $name
   *   The natural name of the feed.
   * @param $id
   *   The persistent id of the feed.
   * @param $edit
   *   Optional array that defines the basic settings for the feed in a format
   *   that can be posted to the feed's basic settings form.
   */
  public function createImporterConfiguration($name = 'Syndication', $id = 'syndication') {
    // Create new feed configuration.
    $this->drupalGet('admin/structure/feeds');
    $this->clickLink('Add importer');
    $edit = array(
      'name' => $name,
      'id' => $id,
    );
    $this->drupalPost('admin/structure/feeds/create', $edit, 'Create');

    // Assert message and presence of default plugins.
    $this->assertText('Your configuration has been created with default settings.');
    $this->assertPlugins($id, 'http', 'syndication', 'node');
    // Per default attach to page content type.
    $this->setSettings($id, NULL, array('content_type' => 'page'));
    // Per default attached to article content type.
    $this->setSettings($id, 'node', array('bundle' => 'article'));
  }

  /**
   * Choose a plugin for a importer configuration and assert it.
   *
   * @param $id
   *   The importer configuration's id.
   * @param $plugin_key
   *   The key string of the plugin to choose (one of the keys defined in
   *   feeds_feeds_plugins()).
   */
  public function setPlugin($id, $plugin_key) {
    if ($type = FeedsPlugin::typeOf($plugin_key)) {
      $edit = array(
        'plugin_key' => $plugin_key,
      );
      $this->drupalPost("admin/structure/feeds/$id/$type", $edit, 'Save');

      // Assert actual configuration.
      $config = config('feeds.importer.' . $id)->get('config');
      $this->assertEqual($config[$type]['plugin_key'], $plugin_key, 'Verified correct ' . $type . ' (' . $plugin_key . ').');
    }
  }

  /**
   * Set importer or plugin settings.
   *
   * @param $id
   *   The importer configuration's id.
   * @param $plugin
   *   The plugin (class) name, or NULL to set importer's settings
   * @param $settings
   *   The settings to set.
   */
  public function setSettings($id, $plugin, $settings) {
    $this->drupalPost('admin/structure/feeds/' . $id . '/settings/' . $plugin, $settings, 'Save');
    $this->assertText('Your changes have been saved.');
  }

  /**
   * Create a test feed node. Test user has to have sufficient permissions:
   *
   * * create [type] content
   * * use feeds
   *
   * Assumes that page content type has been configured with
   * createImporterConfiguration() as a feed content type.
   *
   * @return
   *   The node id of the node created.
   */
  public function createFeedNode($id = 'syndication', $feed_url = NULL, $title = '', $content_type = NULL) {
    if (empty($feed_url)) {
      $feed_url = $GLOBALS['base_url'] . '/' . drupal_get_path('module', 'feeds') . '/tests/feeds/developmentseed.rss2';
    }

    // If content type not given, retrieve it.
    if (!$content_type) {
      $config = config('feeds.importer.' . $id)->get('config');
      $content_type = $config['content_type'];
      $this->assertFalse(empty($content_type), 'Valid content type found: ' . $content_type);
    }

    // Create a feed node.
    $edit = array(
      'title' => $title,
      'feeds[Drupal\feeds\Plugin\feeds\fetcher\FeedsHTTPFetcher][source]' => $feed_url,
    );
    $this->drupalPost('node/add/' . str_replace('_', '-', $content_type), $edit, 'Save and publish');
    $this->assertText('has been created.');

    // Get the node id from URL.
    $nid = $this->getNid($this->getUrl());

    // Check whether feed got recorded in feeds_source table.
    $query = db_select('feeds_source', 's')
      ->condition('s.id', $id, '=')
      ->condition('s.feed_nid', $nid, '=');
    $query->addExpression("COUNT(*)");
    $result = $query->execute()->fetchField();
    $this->assertEqual(1, $result);

    $source = db_select('feeds_source', 's')
      ->condition('s.id', $id, '=')
      ->condition('s.feed_nid', $nid, '=')
      ->fields('s', array('config'))
      ->execute()->fetchObject();
    $config = unserialize($source->config);
    $this->assertEqual($config['Drupal\feeds\Plugin\feeds\fetcher\FeedsHTTPFetcher']['source'], $feed_url, t('URL in DB correct.'));
    return $nid;
  }

  /**
   * Edit the configuration of a feed node to test update behavior.
   *
   * @param $nid
   *   The nid to edit.
   * @param $feed_url
   *   The new (absolute) feed URL to use.
   * @param $title
   *   Optional parameter to change title of feed node.
   */
  public function editFeedNode($nid, $feed_url, $title = '') {
    $edit = array(
      'title' => $title,
      'feeds[Drupal\feeds\Plugin\feeds\fetcher\FeedsHTTPFetcher][source]' => $feed_url,
    );
    // Check that the update was saved.
    $this->drupalPost('node/' . $nid . '/edit', $edit, 'Save and keep published');
    $this->assertText('has been updated.');

    // Check that the URL was updated in the feeds_source table.
    $source = db_query("SELECT * FROM {feeds_source} WHERE feed_nid = :nid", array(':nid' => $nid))->fetchObject();
    $config = unserialize($source->config);
    $this->assertEqual($config['Drupal\feeds\Plugin\feeds\fetcher\FeedsHTTPFetcher']['source'], $feed_url, t('URL in DB correct.'));
  }

  /**
   * Batch create a variable amount of feed nodes. All will have the
   * same URL configured.
   *
   * @return
   *   An array of node ids of the nodes created.
   */
  public function createFeedNodes($id = 'syndication', $num = 20, $content_type = NULL) {
    $nids = array();
    for ($i = 0; $i < $num; $i++) {
      $nids[] = $this->createFeedNode($id, NULL, $this->randomName(), $content_type);
    }
    return $nids;
  }

  /**
   * Import a URL through the import form. Assumes http in place.
   */
  public function importURL($id, $feed_url = NULL) {
    if (empty($feed_url)) {
      $feed_url = $GLOBALS['base_url'] . '/' . drupal_get_path('module', 'feeds') . '/tests/feeds/developmentseed.rss2';
    }
    $edit = array(
      'feeds[Drupal\feeds\Plugin\feeds\fetcher\FeedsHTTPFetcher][source]' => $feed_url,
    );
    $nid = $this->drupalPost('import/' . $id, $edit, 'Import');

    // Check whether feed got recorded in feeds_source table.
    $this->assertEqual(1, db_query("SELECT COUNT(*) FROM {feeds_source} WHERE id = :id AND feed_nid = 0", array(':id' => $id))->fetchField());
    $source = db_query("SELECT * FROM {feeds_source} WHERE id = :id AND feed_nid = 0",  array(':id' => $id))->fetchObject();
    $config = unserialize($source->config);
    $this->assertEqual($config['Drupal\feeds\Plugin\feeds\fetcher\FeedsHTTPFetcher']['source'], $feed_url, t('URL in DB correct.'));

    // Check whether feed got properly added to scheduler.
    $this->assertEqual(1, db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = :id AND id = 0 AND name = 'feeds_source_import' AND last <> 0 AND scheduled = 0", array(':id' => $id))->fetchField());

    // Check expire scheduler.
    $jobs = db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = :id AND id = 0 AND name = 'feeds_source_expire'", array(':id' => $id))->fetchField();
    if (feeds_importer($id)->processor->expiryTime() == FEEDS_EXPIRE_NEVER) {
      $this->assertEqual(0, $jobs);
    }
    else {
      $this->assertEqual(1, $jobs);
    }
  }

  /**
   * Import a file through the import form. Assumes FeedsFileFetcher in place.
   */
  public function importFile($id, $file) {

    $this->assertTrue(file_exists($file), 'Source file exists');
    $edit = array(
      'files[feeds]' => $file,
    );
    $this->drupalPost('import/' . $id, $edit, 'Import');
  }

  /**
   * Assert a feeds configuration's plugins.
   *
   * @deprecated:
   *   Use setPlugin() instead.
   *
   * @todo Refactor users of assertPlugin() and make them use setPugin() instead.
   */
  public function assertPlugins($id, $fetcher, $parser, $processor) {
    // Assert actual configuration.
    $config = config('feeds.importer.' . $id)->get('config');

    $this->assertEqual($config['fetcher']['plugin_key'], $fetcher, 'Correct fetcher');
    $this->assertEqual($config['parser']['plugin_key'], $parser, 'Correct parser');
    $this->assertEqual($config['processor']['plugin_key'], $processor, 'Correct processor');
  }

   /**
    * Adds mappings to a given configuration.
    *
    * @param string $id
    *   ID of the importer.
    * @param array $mappings
    *   An array of mapping arrays. Each mapping array must have a source and
    *   an target key and can have a unique key.
    * @param bool $test_mappings
    *   (optional) TRUE to automatically test mapping configs. Defaults to TRUE.
    */
  public function addMappings($id, $mappings, $test_mappings = TRUE) {

    $path = "admin/structure/feeds/$id/mapping";

    // Iterate through all mappings and add the mapping via the form.
    foreach ($mappings as $i => $mapping) {

      if ($test_mappings) {
        $current_mapping_key = $this->mappingExists($id, $i, $mapping['source'], $mapping['target']);
        $this->assertEqual($current_mapping_key, -1, 'Mapping does not exist before addition.');
      }

      // Get unique flag and unset it. Otherwise, drupalPost will complain that
      // Split up config and mapping.
      $config = $mapping;
      unset($config['source'], $config['target']);
      $mapping = array('source' => $mapping['source'], 'target' => $mapping['target']);

      // Add mapping.
      $this->drupalPost($path, $mapping, t('Save'));

      // If there are other configuration options, set them.
      if ($config) {
        $this->drupalPostAJAX(NULL, array(), 'mapping_settings_edit_' . $i);

        // Set some settings.
        $edit = array();
        foreach ($config as $key => $value) {
          $edit["config[$i][settings][$key]"] = $value;
        }
        $this->drupalPostAJAX(NULL, $edit, 'mapping_settings_update_' . $i);
        $this->drupalPost(NULL, array(), t('Save'));
      }

      if ($test_mappings) {
        $current_mapping_key = $this->mappingExists($id, $i, $mapping['source'], $mapping['target']);
        $this->assertTrue($current_mapping_key >= 0, 'Mapping exists after addition.');
      }
    }
  }

  /**
   * Remove mappings from a given configuration.
   *
   * @param array $mappings
   *   An array of mapping arrays. Each mapping array must have a source and
   *   a target key and can have a unique key.
   * @param bool $test_mappings
   *   (optional) TRUE to automatically test mapping configs. Defaults to TRUE.
   */
  public function removeMappings($id, $mappings, $test_mappings = TRUE) {
    $path = "admin/structure/feeds/$id/mapping";

    $current_mappings = $this->getCurrentMappings($id);

    // Iterate through all mappings and remove via the form.
    foreach ($mappings as $i => $mapping) {

      if ($test_mappings) {
        $current_mapping_key = $this->mappingExists($id, $i, $mapping['source'], $mapping['target']);
        $this->assertEqual($current_mapping_key, $i, 'Mapping exists before removal.');
      }

      $remove_mapping = array("remove_flags[$i]" => 1);

      $this->drupalPost($path, $remove_mapping, t('Save'));

      $this->assertText('Your changes have been saved.');

      if ($test_mappings) {
        $current_mapping_key = $this->mappingExists($id, $i, $mapping['source'], $mapping['target']);
        $this->assertEqual($current_mapping_key, -1, 'Mapping does not exist after removal.');
      }
    }
  }

  /**
   * Gets an array of current mappings from the feeds_importer config.
   *
   * @param string $id
   *   ID of the importer.
   *
   * @return bool|array
   *   FALSE if the importer has no mappings, or an an array of mappings.
   */
  public function getCurrentMappings($id) {
    $config = config('feeds.importer.' . $id)->get('config');

    // We are very specific here. 'mappings' can either be an array or not
    // exist.
    if (array_key_exists('mappings', $config['processor']['config'])) {
      $this->assertTrue(is_array($config['processor']['config']['mappings']), 'Mappings is an array.');

      return $config['processor']['config']['mappings'];
    }

    return FALSE;
  }

  /**
   * Determines if a mapping exists for a given importer.
   *
   * @param string $id
   *   ID of the importer.
   * @param integer $i
   *   The key of the mapping.
   * @param string $source
   *   The source field.
   * @param string $target
   *   The target field.
   *
   * @return integer
   *   -1 if the mapping doesn't exist, the key of the mapping otherwise.
   */
  public function mappingExists($id, $i, $source, $target) {

    $current_mappings = $this->getCurrentMappings($id);

    if ($current_mappings) {
      foreach ($current_mappings as $key => $mapping) {
        if ($mapping['source'] == $source && $mapping['target'] == $target && $key == $i) {
          return $key;
        }
      }
    }

    return -1;
  }

  /**
   * Helper function, retrieves node id from a URL.
   */
  public function getNid($url) {
    $matches = array();
    preg_match('/node\/(\d+?)$/', $url, $matches);
    $nid = $matches[1];

    // Test for actual integerness.
    $this->assertTrue($nid === (string) (int) $nid, 'Node id is an integer.');

    return $nid;
  }

  /**
   * Copies a directory.
   */
  public function copyDir($source, $dest) {
    $result = file_prepare_directory($dest, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);
    foreach (@scandir($source) as $file) {
      if (is_file("$source/$file")) {
        $file = file_unmanaged_copy("$source/$file", "$dest/$file");
      }
    }
  }

  /**
   * Download and extract SimplePIE.
   *
   * Sets the 'feeds_simplepie_library_dir' variable to the directory where
   * SimplePie is downloaded.
   */
  function downloadExtractSimplePie($version) {
    $url = "http://simplepie.org/downloads/simplepie_$version.mini.php";
    $filename = 'simplepie.mini.php';

    // Avoid downloading the file dozens of times
    $library_dir = DRUPAL_ROOT . '/' . $this->originalFileDirectory . '/simpletest/feeds';
    $simplepie_library_dir = $library_dir . '/simplepie';

    if (!file_exists($library_dir)) {
      drupal_mkdir($library_dir);
    }

    if (!file_exists($simplepie_library_dir)) {
      drupal_mkdir($simplepie_library_dir);
    }

    // Local file name.
    $local_file = $simplepie_library_dir . '/' . $filename;

    // Begin single threaded code.
    if (function_exists('sem_get')) {
      $semaphore = sem_get(ftok(__FILE__, 1));
      sem_acquire($semaphore);
    }

    // Download and extact the archive, but only in one thread.
    if (!file_exists($local_file)) {
      $local_file = system_retrieve_file($url, $local_file, FALSE, FILE_EXISTS_REPLACE);
    }

    if (function_exists('sem_get')) {
      sem_release($semaphore);
    }
    // End single threaded code.

    // Verify that files were successfully extracted.
    $this->assertTrue(file_exists($local_file), t('@file found.', array('@file' => $local_file)));

    // Set the simpletest library directory.
    variable_set('feeds_library_dir', $library_dir);
  }
}