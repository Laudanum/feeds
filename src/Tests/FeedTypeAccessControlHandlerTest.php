<?php

/**
 * @file
 * Contains \Drupal\feeds\Tests\FeedTypeAccessControlHandlerTest.
 */

namespace Drupal\feeds\Tests;

/**
 * @covers \Drupal\feeds\FeedTypeAccessControlHandler
 * @group Feeds
 */
class FeedTypeAccessControlHandlerTest extends FeedsUnitTestCase {

  public function setUp() {
    parent::setUp();

    $this->entity = $this->getMock('Drupal\Core\Entity\EntityInterface');
    $this->account = $this->getMock('Drupal\Core\Session\AccountInterface');
    $this->account->expects($this->once())
      ->method('hasPermission')
      ->with('administer feeds')
      ->will($this->returnValue(TRUE));
    $this->controller = $this->getMock('Drupal\feeds\FeedTypeAccessControlHandler', NULL, [], '', FALSE);
  }

  public function testCheckAccessTrue() {
    $method = $this->getMethod('Drupal\feeds\FeedTypeAccessControlHandler', 'checkAccess');
    $result = $method->invokeArgs($this->controller, [$this->entity, '', '', $this->account]);
    $this->assertTrue($result->isAllowed());
  }

}
