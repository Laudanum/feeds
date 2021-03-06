<?php

/**
 * @file
 * Contains \Drupal\feeds\SubscriptionInterface.
 */

namespace Drupal\feeds;

use Drupal\Core\Entity\ContentEntityInterface;

interface SubscriptionInterface extends ContentEntityInterface {

  public function subscribe();

  public function unsubscribe();

  public function getTopic();

  public function getSecret();

  public function getHub();

  public function getState();

  public function setState($state);

  public function getLease();

  public function setLease($lease);

  public function getExpire();

  public function checkSignature($sha1, $data);

}
