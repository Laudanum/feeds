<?php

/**
 * @file
 * Contains \Drupal\feeds\Plugin\feeds\fetcher\FeedsHTTPFetcher.
 */

namespace Drupal\feeds\Plugin\feeds\fetcher;

use Drupal\feeds\Plugin\FeedsFetcher;
use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;
use Drupal\feeds\FeedsSource;
use Drupal\feeds\PuSHSubscriber;
use Drupal\feeds\PuSHEnvironment;
use Drupal\feeds\FeedsHTTPFetcherResult;


/**
 * Defines an HTTP fetcher.
 *
 * Uses http_request_get() to download a feed.
 *
 * @Plugin(
 *   id = "http",
 *   title = @Translation("HTTP fetcher"),
 *   description = @Translation("Downloads data from a URL using Drupal's HTTP request handler.")
 * )
 */
class FeedsHTTPFetcher extends FeedsFetcher {

  /**
   * Implements FeedsFetcher::fetch().
   */
  public function fetch(FeedsSource $source) {
    $source_config = $source->getConfigFor($this);
    if ($this->config['use_pubsubhubbub'] && ($raw = $this->subscriber($source->feed_nid)->receive())) {
      return new FeedsFetcherResult($raw);
    }
    $fetcher_result = new FeedsHTTPFetcherResult($source_config['source']);
    // When request_timeout is empty, the global value is used.
    $fetcher_result->setTimeout($this->config['request_timeout']);
    return $fetcher_result;
  }

  /**
   * Clear caches.
   */
  public function clear(FeedsSource $source) {
    $source_config = $source->getConfigFor($this);
    $url = $source_config['source'];
    feeds_include_library('http_request.inc', 'http_request');
    http_request_clear_cache($url);
  }

  /**
   * Implements FeedsFetcher::request().
   */
  public function request($feed_nid = 0) {
    feeds_dbg($_GET);
    @feeds_dbg(file_get_contents('php://input'));
    // A subscription verification has been sent, verify.
    if (isset($_GET['hub_challenge'])) {
      $this->subscriber($feed_nid)->verifyRequest();
    }
    // No subscription notification has ben sent, we are being notified.
    else {
      try {
        feeds_source($this->id, $feed_nid)->existing()->import();
      }
      catch (Exception $e) {
        // In case of an error, respond with a 503 Service (temporary) unavailable.
        header('HTTP/1.1 503 "Not Found"', NULL, 503);
        drupal_exit();
      }
    }
    // Will generate the default 200 response.
    header('HTTP/1.1 200 "OK"', NULL, 200);
    drupal_exit();
  }

  /**
   * Override parent::configDefaults().
   */
  public function configDefaults() {
    return array(
      'auto_detect_feeds' => FALSE,
      'use_pubsubhubbub' => FALSE,
      'designated_hub' => '',
      'request_timeout' => NULL,
    );
  }

  /**
   * Override parent::configForm().
   */
  public function configForm(&$form_state) {
    $form = array();
    $form['auto_detect_feeds'] = array(
      '#type' => 'checkbox',
      '#title' => t('Auto detect feeds'),
      '#description' => t('If the supplied URL does not point to a feed but an HTML document, attempt to extract a feed URL from the document.'),
      '#default_value' => $this->config['auto_detect_feeds'],
    );
    $form['use_pubsubhubbub'] = array(
      '#type' => 'checkbox',
      '#title' => t('Use PubSubHubbub'),
      '#description' => t('Attempt to use a <a href="http://en.wikipedia.org/wiki/PubSubHubbub">PubSubHubbub</a> subscription if available.'),
      '#default_value' => $this->config['use_pubsubhubbub'],
    );
    $form['designated_hub'] = array(
      '#type' => 'textfield',
      '#title' => t('Designated hub'),
      '#description' => t('Enter the URL of a designated PubSubHubbub hub (e. g. superfeedr.com). If given, this hub will be used instead of the hub specified in the actual feed.'),
      '#default_value' => $this->config['designated_hub'],
      '#dependency' => array(
        'edit-use-pubsubhubbub' => array(1),
      ),
    );
   // Per importer override of global http request timeout setting.
   $form['request_timeout'] = array(
     '#type' => 'textfield',
     '#title' => t('Request timeout'),
     '#description' => t('Timeout in seconds to wait for an HTTP get request to finish.</br>' .
                         '<b>Note:</b> this setting will override the global setting.</br>' .
                         'When left empty, the global value is used.'),
     '#default_value' => $this->config['request_timeout'],
     '#element_validate' => array('element_validate_integer_positive'),
     '#maxlength' => 3,
     '#size'=> 30,
   );
    return $form;
  }

  /**
   * Expose source form.
   */
  public function sourceForm($source_config) {
    $form = array();
    $form['source'] = array(
      '#type' => 'textfield',
      '#title' => t('URL'),
      '#description' => t('Enter a feed URL.'),
      '#default_value' => isset($source_config['source']) ? $source_config['source'] : '',
      '#maxlength' => NULL,
      '#required' => TRUE,
    );
    return $form;
  }

  /**
   * Override parent::sourceFormValidate().
   */
  public function sourceFormValidate(&$values) {
    $values['source'] = trim($values['source']);

    if (!feeds_valid_url($values['source'], TRUE)) {
      $form_key = 'feeds][' . get_class($this) . '][source';
      form_set_error($form_key, t('The URL %source is invalid.', array('%source' => $values['source'])));
    }
    elseif ($this->config['auto_detect_feeds']) {
      feeds_include_library('http_request.inc', 'http_request');
      if ($url = http_request_get_common_syndication($values['source'])) {
        $values['source'] = $url;
      }
    }
  }

  /**
   * Override sourceSave() - subscribe to hub.
   */
  public function sourceSave(FeedsSource $source) {
    if ($this->config['use_pubsubhubbub']) {
      // If this is a feeds node we want to delay the subscription to
      // feeds_exit() to avoid transaction race conditions.
      if ($source->feed_nid) {
        $job = array('fetcher' => $this, 'source' => $source);
        feeds_set_subscription_job($job);
      }
      else {
        $this->subscribe($source);
      }
    }
  }

  /**
   * Override sourceDelete() - unsubscribe from hub.
   */
  public function sourceDelete(FeedsSource $source) {
    if ($this->config['use_pubsubhubbub']) {
      // If we're in a feed node, queue the unsubscribe,
      // else process immediately.
      if ($source->feed_nid) {
        $job = array(
          'type' => $source->id,
          'id' => $source->feed_nid,
          'period' => 0,
          'periodic' => FALSE,
        );
        JobScheduler::get('feeds_push_unsubscribe')->set($job);
      }
      else {
        $this->unsubscribe($source);
      }
    }
  }

  /**
   * Implement FeedsFetcher::subscribe() - subscribe to hub.
   */
  public function subscribe(FeedsSource $source) {
    $source_config = $source->getConfigFor($this);
    $this->subscriber($source->feed_nid)->subscribe($source_config['source'], url($this->path($source->feed_nid), array('absolute' => TRUE)), valid_url($this->config['designated_hub']) ? $this->config['designated_hub'] : '');
  }

  /**
   * Implement FeedsFetcher::unsubscribe() - unsubscribe from hub.
   */
  public function unsubscribe(FeedsSource $source) {
    $source_config = $source->getConfigFor($this);
    $this->subscriber($source->feed_nid)->unsubscribe($source_config['source'], url($this->path($source->feed_nid), array('absolute' => TRUE)));
  }

  /**
   * Implement FeedsFetcher::importPeriod().
   */
  public function importPeriod(FeedsSource $source) {
    if ($this->subscriber($source->feed_nid)->subscribed()) {
      return 259200; // Delay for three days if there is a successful subscription.
    }
  }

  /**
   * Convenience method for instantiating a subscriber object.
   */
  protected function subscriber($subscriber_id) {
    return PushSubscriber::instance($this->id, $subscriber_id, 'Drupal\feeds\PuSHSubscription', PuSHEnvironment::instance());
  }
}
