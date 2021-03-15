<?php

namespace Drupal\impact_stack_auth;

/**
 * Test the auth app API-client.
 */
class AuthAppClientTest extends \DrupalUnitTestCase {

  /**
   * Reset cache.
   */
  public function tearDown() : void {
    cache_clear_all(AuthAppClient::TOKEN_CID, 'cache');
  }

  /**
   * Create an instrumented client object that doesn’t actually send requests.
   */
  protected function instrumentedApi() {
    $api = $this->getMockBuilder(AuthAppClient::class)
      ->setConstructorArgs([
        'http://mock',
        ['public_key' => 'pk_', 'secret_key' => 'sk_'],
      ])
      ->setMethods(['send'])
      ->getMock();
    return $api;
  }

  /**
   * Loading the token twice from two client objects.
   */
  public function testRequestingTokenTwiceOnTwoObjects() {
    $api = $this->instrumentedApi();
    $api->expects($this->once())
      ->method('send')
      ->will($this->returnValue([
        'token' => 'test token',
      ]));
    $token = $api->getToken();
    $this->assertEqual('test token', $token);
    $this->assertEqual($token, $api->getToken());

    $api2 = $this->instrumentedApi();
    $api2->expects($this->never())->method('send');
    $token2 = $api2->getToken();
    $this->assertEqual($token, $token2);
  }

}
