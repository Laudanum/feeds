<?php

/**
 * @file
 * Contains \Drupal\feeds\Plugin\feeds\Fetcher\HTTPFetcher.
 */

namespace Drupal\feeds\Plugin\feeds\Fetcher;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Form\FormInterface;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\FeedNotModifiedException;
use Drupal\feeds\FeedPluginFormInterface;
use Drupal\feeds\FetcherResult;
use Drupal\feeds\HTTPRequest;
use Drupal\feeds\Plugin\FetcherBase;
use Drupal\feeds\RawFetcherResult;
use Drupal\job_scheduler\JobScheduler;

/**
 * Defines an HTTP fetcher.
 *
 * @todo Make a new subscriber interface.
 *
 * @Plugin(
 *   id = "http",
 *   title = @Translation("HTTP fetcher"),
 *   description = @Translation("Downloads data from a URL using Drupal's HTTP request handler.")
 * )
 */
class HTTPFetcher extends FetcherBase implements FeedPluginFormInterface, FormInterface {

  /**
   * {@inheritdoc}
   */
  public function fetch(FeedInterface $feed, $raw = NULL) {

    // Handle pubsubhubbub.
    if ($this->config['use_pubsubhubbub'] && $raw !== NULL) {
      return new RawFetcherResult($raw);
    }

    $feed_config = $feed->getConfigFor($this);

    $http = new HTTPRequest($feed_config['source'], array('timeout' => $this->config['request_timeout']));
    $result = $http->get();
    if (!in_array($result->code, array(200, 201, 202, 203, 204, 205, 206))) {
      throw new \Exception(t('Download of @url failed with code !code.', array('@url' => $feed_config['source'], '!code' => $result->code)));
    }
    // Update source if there was a permanent redirect.
    if ($result->redirect) {
      $feed_config['source'] = $result->redirect;
      $feed->setConfigFor($this, $feed_config);
    }
    if ($result->code == 304) {
      throw new FeedNotModifiedException();
    }
    return new FetcherResult($result->file);
  }

  /**
   * {@inheritdoc}
   */
  public function clear(FeedInterface $feed) {
    $feed_config = $feed->getConfigFor($this);
    $url = $feed_config['source'];
    cache()->delete('feeds_http_download_' . md5($url));
  }

  /**
   * {@inheritdoc}
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
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state) {
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
      '#type' => 'number',
      '#title' => t('Request timeout'),
      '#description' => t('Timeout in seconds to wait for an HTTP get request to finish.</br>
                         <b>Note:</b> this setting will override the global setting.</br>
                         When left empty, the global value is used.'),
      '#default_value' => $this->config['request_timeout'],
      '#min' => 0,
      '#maxlength' => 3,
      '#size' => 30,
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function feedForm(array $form, array &$form_state, FeedInterface $feed) {
    $feed_config = $feed->getConfigFor($this);

    $form['fetcher']['#tree'] = TRUE;
    $form['fetcher']['source'] = array(
      '#type' => 'textfield',
      '#title' => t('URL'),
      '#description' => t('Enter a feed URL.'),
      '#default_value' => isset($feed_config['source']) ? $feed_config['source'] : '',
      '#maxlength' => NULL,
      '#required' => TRUE,
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function feedFormValidate(array $form, array &$form_state, FeedInterface $feed) {
    $values =& $form_state['values']['fetcher'];
    $values['source'] = trim($values['source']);

    if (!feeds_valid_url($values['source'], TRUE)) {
      $form_key = 'feeds][' . get_class($this) . '][source';
      form_set_error($form_key, t('The URL %source is invalid.', array('%source' => $values['source'])));
    }
    elseif ($this->config['auto_detect_feeds']) {
      $http = new HTTPRequest($values['source']);
      if ($url = $http->getCommonSyndication()) {
        $values['source'] = $url;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function sourceSave(FeedInterface $feed) {
    if (!$this->config['use_pubsubhubbub']) {
      return;
    }

    $feed_config = $feed->getConfigFor($this);

    $job = array(
      'type' => $this->getPluginId(),
      'id' => $feed->id(),
    );

    // Subscription does not exist yet.
    if (!$subscription = \Drupal::service('feeds.subscription.crud')->getSubscription($feed->id())) {
      $sub = array(
        'id' => $feed->id(),
        'state' => 'unsubscribed',
        'hub' => '',
        'topic' => $feed_config['source'],
      );

      \Drupal::service('feeds.subscription.crud')->setSubscription($sub);

      // Subscribe to new topic.
      \Drupal::queue('feeds_push_subscribe')->createItem($job);
    }

    // Source has changed.
    elseif ($subscription['topic'] !== $feed_config['source']) {
      // Subscribe to new topic.
      \Drupal::queue('feeds_push_subscribe')->createItem($job);

      // Unsubscribe from old topic.
      $job['data'] = $subscription['topic'];
      \Drupal::queue('feeds_push_unsubscribe')->createItem($job);

      // Save new topic to subscription.
      $subscription['topic'] = $feed_config['source'];
      \Drupal::service('feeds.subscription.crud')->setSubscription($subscription);
    }

    // Hub exists, but we aren't subscribed.
    // @todo Is this the best way to handle this?
    // @todo Periodically check for new hubs... Always check for new hubs...
    // Maintain a retry count so that we don't keep trying indefinitely.
    elseif ($subscription['hub']) {
      switch ($subscription['state']) {
        case 'subscribe':
        case 'subscribed':
          break;

        default:
          \Drupal::queue('feeds_push_subscribe')->createItem($job);
          break;
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @todo Call sourceDelete() when changing plugins.
   * @todo Clear cache when deleting.
   */
  public function sourceDelete(FeedInterface $feed) {
    if ($this->config['use_pubsubhubbub']) {
      $job = array(
        'type' => $this->getPluginId(),
        'id' => $feed->id(),
        'period' => 0,
        'periodic' => FALSE,
      );

      // Remove any existing subscribe jobs.
      JobScheduler::get('feeds_push_subscribe')->remove($job);
      JobScheduler::get('feeds_push_unsubscribe')->set($job);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function importPeriod(FeedInterface $feed) {
    $sub = \Drupal::service('feeds.subscription.crud')->getSubscription($feed->id());
    if ($sub && $sub['state'] == 'subscribed') {
      // Delay for three days if there is a successful subscription.
      return 259200;
    }
  }

}
