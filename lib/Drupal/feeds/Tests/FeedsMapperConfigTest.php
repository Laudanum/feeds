<?php

/**
 * @file
 * Test cases for Feeds mapping configuration form.
 */

namespace Drupal\feeds\Tests;

use Drupal\feeds\FeedsMapperTestBase;

/**
 * Class for testing basic Feeds ajax mapping configurtaion form behavior.
 */
class FeedsMapperConfigTest extends FeedsMapperTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array(
    'feeds_tests',
  );

  public static function getInfo() {
    return array(
      'name' => 'Mapper: Config',
      'description' => 'Test the mapper configuration UI.',
      'group' => 'Feeds',
    );
  }

  /**
   * Basic test of mapping configuration.
   */
  public function test() {
    // Create importer configuration.
    $this->createImporterConfiguration();
    $this->addMappings('syndication', array(
      0 => array(
        'source' => 'url',
        'target' => 'test_target',
      ),
    ));

    // Click gear to get form.
    $this->drupalPostAJAX(NULL, array(), 'mapping_settings_edit_0');

    // Set some settings.
    $edit = array(
      'config[0][settings][checkbox]' => 1,
      'config[0][settings][textfield]' => 'Some text',
      'config[0][settings][textarea]' => 'Textarea value: Didery dofffffffffffffffffffffffffffffffffffff',
      'config[0][settings][radios]' => 'option1',
      'config[0][settings][select]' => 'option4',
    );
    $this->drupalPostAJAX(NULL, $edit, 'mapping_settings_update_0');

    // Click Save.
    $this->drupalPost(NULL, array(), t('Save'));

    // Reload.
    $this->drupalGet('admin/structure/feeds/manage/syndication/mapping');

    // See if our settings were saved.
    $this->assertText('Checkbox active.');
    $this->assertText('Textfield value: Some text');
    $this->assertText('Textarea value: Didery dofffffffffffffffffffffffffffffffffffff');
    $this->assertText('Radios value: Option 1');
    $this->assertText('Select value: Another One');

    // Check that settings are in db.
    $config = config('feeds.importer.syndication')->get('config');

    $settings = $config['processor']['config']['mappings'][0];
    $this->assertEqual($settings['checkbox'], 1);
    $this->assertEqual($settings['textfield'], 'Some text');
    $this->assertEqual($settings['textarea'], 'Textarea value: Didery dofffffffffffffffffffffffffffffffffffff');
    $this->assertEqual($settings['radios'], 'option1');
    $this->assertEqual($settings['select'], 'option4');


    // Check that form validation works.
    // Click gear to get form.
    $this->drupalPostAJAX(NULL, array(), 'mapping_settings_edit_0');

    // Set some settings.
    $edit = array(
      // Required form item.
      'config[0][settings][textfield]' => '',
    );
    $this->drupalPostAJAX(NULL, $edit, 'mapping_settings_update_0');
    $this->assertText('A text field field is required.');
    $this->drupalPost(NULL, array(), t('Save'));
    // Reload.
    $this->drupalGet('admin/structure/feeds/manage/syndication/mapping');
    // Value has not changed.
    $this->assertText('Textfield value: Some text');

    // Check that multiple mappings work.
    $this->addMappings('syndication', array(
      1 => array(
        'source' => 'url',
        'target' => 'test_target',
      ),
    ));
    $this->assertText('Checkbox active.');
    $this->assertText('Checkbox inactive.');
    // Click gear to get form.
    $this->drupalPostAJAX(NULL, array(), 'mapping_settings_edit_1');
    // Set some settings.
    $edit = array(
      'config[1][settings][textfield]' => 'Second mapping text',
    );
    $this->drupalPostAJAX(NULL, $edit, 'mapping_settings_update_1');
    // Click Save.
    $this->drupalPost(NULL, array(), t('Save'));
    // Reload.
    $this->drupalGet('admin/structure/feeds/manage/syndication/mapping');
    $this->assertText('Checkbox active.');
    $this->assertText('Checkbox inactive.');
    $this->assertText('Second mapping text');
  }
}
