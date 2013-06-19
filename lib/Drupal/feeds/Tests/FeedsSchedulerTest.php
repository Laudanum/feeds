<?php

/**
 * @file
 * Feeds tests.
 */

namespace Drupal\feeds\Tests;

use Drupal\feeds\FeedsWebTestBase;

/**
 * Test cron scheduling.
 */
class FeedsSchedulerTest extends FeedsWebTestBase {

  public static function getInfo() {
    return array(
      'name' => 'Scheduler',
      'description' => 'Tests for feeds scheduler.',
      'group' => 'Feeds',
    );
  }

  /**
   * Test scheduling on cron.
   */
  public function testScheduling() {
    // Create importer configuration.
    $this->createImporterConfiguration();
    $this->addMappings('syndication', array(
      0 => array(
        'source' => 'title',
        'target' => 'title',
        'unique' => FALSE,
      ),
      1 => array(
        'source' => 'description',
        'target' => 'body',
      ),
      2 => array(
        'source' => 'timestamp',
        'target' => 'created',
      ),
      3 => array(
        'source' => 'url',
        'target' => 'url',
        'unique' => TRUE,
      ),
      4 => array(
        'source' => 'guid',
        'target' => 'guid',
        'unique' => TRUE,
      ),
    ));

    // Create 10 feed nodes. Turn off import on create before doing that.
    $this->setSettings('syndication', '', array('import_on_create' => FALSE));
    $this->assertText('Do not import on submission');

    $fids = $this->createFeeds();
    // Test import_on_create.
    $count = db_query("SELECT COUNT(*) FROM {node}")->fetchField();
    $this->assertEqual($count, 0, t('@count nodes created.', array('@count' => $count)));

    // Check whether feed got properly added to scheduler.
    foreach ($fids as $fid) {
      $this->assertEqual(1, db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = 'syndication' AND id = :fid AND name = 'feeds_feed_import' AND scheduled = 0 AND period = 1800 AND periodic = 1", array(':fid' => $fid))->fetchField());
    }

    // Take time for comparisons.
    $time = time() + 1800;
    sleep(1);

    // Log out and run cron, no changes.
    $this->drupalLogout();
    $this->cronRun();
    $count = db_query("SELECT COUNT(*) FROM {job_schedule} WHERE next > :time", array(':time' => $time))->fetchField();
    $this->assertEqual($count, 0, '0 feeds refreshed on cron.');

    // Set next time to 0 to simulate updates.
    // There should be 2 x job_schedule_num (= 10) feeds updated now.
    db_query("UPDATE {job_schedule} SET next = 0");
    $this->cronRun();
    $this->cronRun();

    // There should be feeds_schedule_num X 2 (= 20) feeds updated now.
    $schedule = array();
    $rows = db_query("SELECT id, last, scheduled FROM {job_schedule} WHERE next > :time", array(':time' => $time));
    foreach ($rows as $row) {
      $schedule[$row->id] = $row;
    }
    $this->assertEqual(count($schedule), 20, '20 feeds refreshed on cron.' . $count);

    // There should be 200 article nodes in the database.
    $count = db_query("SELECT COUNT(*) FROM {node_field_data} WHERE type = 'article' AND status = 1")->fetchField();
    $this->assertEqual($count, 200, 'There are 200 article nodes aggregated.' . $count);

    // There shouldn't be any items with scheduled = 1 now, if so, this would
    // mean they are stuck.
    $count = db_query("SELECT COUNT(*) FROM {job_schedule} WHERE scheduled = 1")->fetchField();
    $this->assertEqual($count, 0, 'All items are unscheduled (schedule flag = 0).' . $count);

    // Hit cron again twice.
    $this->cronRun();
    $this->cronRun();

    // The import_period setting of the feed configuration is 1800, there
    // shouldn't be any change to the database now.
    $equal = TRUE;
    $rows = db_query("SELECT id, last, scheduled FROM {job_schedule} WHERE next > :time", array(':time' => $time));
    foreach ($rows as $row) {
      $equal = $equal && ($row->last == $schedule[$row->id]->last);
    }
    $this->assertTrue($equal, 'Schedule did not change.');

    // Log back in and set refreshing to as often as possible.
    $this->drupalLogin($this->admin_user);
    $this->setSettings('syndication', '', array('import_period' => 0));
    $this->assertText('Periodic import: as often as possible');
    $this->drupalLogout();

    // Hit cron once, this should cause Feeds to reschedule all entries.
    $this->cronRun();
    $equal = FALSE;
    $rows = db_query("SELECT id, last, scheduled FROM {job_schedule} WHERE next > :time", array(':time' => $time));
    foreach ($rows as $row) {
      $equal = $equal && ($row->last == $schedule[$row->id]->last);
      $schedule[$row->id] = $row;
    }
    $this->assertFalse($equal, 'Every feed schedule time changed.');

    // Hit cron again, 4 times now, every item should change again.
    for ($i = 0; $i < 4; $i++) {
      $this->cronRun();
    }
    $equal = FALSE;
    $rows = db_query("SELECT id, last, scheduled FROM {job_schedule} WHERE next > :time", array(':time' => $time));
    foreach ($rows as $row) {
      $equal = $equal && ($row->last == $schedule[$row->id]->last);
    }
    $this->assertFalse($equal, 'Every feed schedule time changed.');

    // There should be 200 article nodes in the database.
    $count = db_query("SELECT COUNT(*) FROM {node_field_data} WHERE type = 'article' AND status = 1")->fetchField();
    $this->assertEqual($count, 200, 'The total of 200 article nodes has not changed.');

    // Set expire settings, check rescheduling.
    $max_next = db_query("SELECT MAX(next) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_import' AND period = 0")->fetchField();
    $min_next = db_query("SELECT MIN(next) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_import' AND period = 0")->fetchField();
    $this->assertEqual(0, db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_expire'")->fetchField());
    $this->drupalLogin($this->admin_user);
    $this->setSettings('syndication', 'processor', array('expire' => 86400));
    $this->drupalLogout();
    sleep(1);
    $this->cronRun();
    // There should be 20 feeds_feed_expire jobs now.
    $this->assertEqual(count($fids), db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_expire' AND scheduled = 0 AND period = 3600")->fetchField());
    $new_max_next = db_query("SELECT MAX(next) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_import' AND period = 0")->fetchField();
    $new_min_next = db_query("SELECT MIN(next) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_import' AND period = 0")->fetchField();
    $this->assertNotEqual($new_max_next, $max_next);
    $this->assertNotEqual($new_min_next, $min_next);
    $this->assertEqual($new_max_next, $new_min_next);
    $max_next = $new_max_next;
    $min_next = $new_min_next;

    // Set import settings, check rescheduling.
    $this->drupalLogin($this->admin_user);
    $this->setSettings('syndication', '', array('import_period' => 3600));
    $this->drupalLogout();
    sleep(1);
    $this->cronRun();
    $new_max_next = db_query("SELECT MAX(next) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_import' AND period = 3600")->fetchField();
    $new_min_next = db_query("SELECT MIN(next) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_import' AND period = 3600")->fetchField();
    $this->assertNotEqual($new_max_next, $max_next);
    $this->assertNotEqual($new_min_next, $min_next);
    $this->assertEqual($new_max_next, $new_min_next);
    $this->assertEqual(0, db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_import' AND period <> 3600")->fetchField());
    $this->assertEqual(count($fids), db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_expire' AND period = 3600 AND next = :last", array(':last' => $new_min_next))->fetchField());

    // Delete source, delete importer, check schedule.
    $this->drupalLogin($this->admin_user);
    $fid = array_shift($fids);
    $this->feedDelete($fid);
    $this->assertEqual(0, db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_import' AND id = :fid", array(':fid' => $fid))->fetchField());
    $this->assertEqual(0, db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_expire' AND id = :fid", array(':fid' => $fid))->fetchField());
    $this->assertEqual(count($fids), db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_import'")->fetchField());
    $this->assertEqual(count($fids), db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_expire'")->fetchField());

    $this->drupalPost('admin/structure/feeds/manage/syndication/delete', array(), t('Delete'));
    $this->assertEqual(count($fids), db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_expire'")->fetchField());
    $this->assertEqual(count($fids), db_query("SELECT COUNT(*) FROM {job_schedule} WHERE type = 'syndication' AND name = 'feeds_feed_import'")->fetchField());
  }

  /**
   * Test batching on cron.
   *
   * @todo Figure out why cron needs to be run once before.
   */
  function testBatching() {
    $this->cronRun();
    // Set up an importer.
    $this->createImporterConfiguration('Node import', 'node');
    // Set and configure plugins and mappings.
    $this->setPlugin('node', 'fetcher', 'file');
    $this->setPlugin('node', 'parser', 'csv');
    $this->addMappings('node', array(
      0 => array(
        'source' => 'title',
        'target' => 'title',
      ),
    ));

    // Verify that there are 86 nodes total.
    $fid = $this->importFile('node', $this->absolutePath() . '/tests/feeds/many_nodes.csv');
    $this->assertText('Created 86 nodes');

    // Run batch twice with two different process limits.
    // 50 = FEEDS_PROCESS_LIMIT.
    foreach (array(10, 50) as $limit) {
      variable_set('feeds_process_limit', $limit);

      db_query("UPDATE {job_schedule} SET next = 0");
      $this->feedDeleteItems($fid);
      $this->assertEqual(0, db_query("SELECT COUNT(*) FROM {node} WHERE type = 'article'")->fetchField());

      // Hit cron (item count / limit) times, assert correct number of articles.
      for ($i = 0; $i < ceil(86 / $limit); $i++) {
        $this->cronRun();
        sleep(1);
        if ($limit * ($i + 1) < 86) {
          $count = $limit * ($i + 1);
          $period = 0; // Import should be rescheduled for ASAP.
        }
        else {
          $count = 86; // We've reached our total of 86.
          $period = 1800; // Hence we should find the Source's default period.
        }
        $this->assertEqual($count, db_query("SELECT COUNT(*) FROM {node} WHERE type = 'article'")->fetchField());
        $this->assertEqual($period, db_query("SELECT period FROM {job_schedule} WHERE type = 'node' AND id = :fid", array(':fid' => $fid))->fetchField());
      }
    }

    // Delete a couple of nodes, then hit cron again. They should not be replaced
    // as the minimum update time is 30 minutes.
    $nodes = db_query_range("SELECT nid FROM {node} WHERE type = 'article'", 0, 2);
    foreach ($nodes as $node) {
      $this->drupalPost("node/{$node->nid}/delete", array(), 'Delete');
    }
    $this->assertEqual(84, db_query("SELECT COUNT(*) FROM {node} WHERE type = 'article'")->fetchField());
    $this->cronRun();
    $this->assertEqual(84, db_query("SELECT COUNT(*) FROM {node} WHERE type = 'article'")->fetchField());
  }

}
