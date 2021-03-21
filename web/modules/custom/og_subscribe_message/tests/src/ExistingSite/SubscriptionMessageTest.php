<?php

namespace Drupal\Tests\og_subscribe_message\ExistingSite;

use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * A test case to test subscription message.
 */
class SubscriptionMessageTest extends ExistingSiteBase {

  /**
   * Test new group subscription message.
   */
  public function testSubscriptionMessage() {
    $author = $this->createUser();
    $node = $this->createNode([
      'title' => 'New group',
      'type' => 'group',
      'uid' => $author->id(),
    ]);
    $this->assertEquals($author->id(), $node->getOwnerId());

    // We can browse pages.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    $registeredUser = $this->createUser();
    $this->drupalLogin($registeredUser);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    // Test new group subscription message.
    $message = "Hi {$registeredUser->getUsername()}, click here if you would to subscribe to this group called {$node->getTitle()}";
    $href = "/group/node/{$node->id()}/subscribe";
    $this->assertSession()->pageTextContainsOnce($message);
    $this->assertSession()->linkExistsExact($message);
    $this->assertSession()->linkByHrefExists($href);
  }

}
