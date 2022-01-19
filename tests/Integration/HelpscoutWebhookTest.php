<?php

namespace TrustedLogin\Vendor\Tests;

use TrustedLogin\Vendor\SettingsApi;

use TrustedLogin\Vendor\Webhooks\Helpscout;

class HelpscoutWebhookTest extends \WP_UnitTestCase
{
	use MocksTLApi;

	const ACCOUNT_ID = 'test-tl-service';
	const ACCESS_KEY = 'a218';
	public function setUp()
	{
		$this->setTlApiMock();
		SettingsApi::from_saved()->reset()->save();
		$settings = new SettingsApi([
			[
				'account_id'       => self::ACCOUNT_ID,
				'private_key'      => 'a217',
				'api_key'       	=> self::ACCESS_KEY,
			],
			[
				'account_id'       => '1226',
				'private_key'      => 'b227',
				'api_key'       	=> 'b228',
			]
		]);

		$settings->save();
		parent::setUp();
	}


	public function tearDown()
	{
		SettingsApi::from_saved()->reset()->save();
		//Always reset API sender
		$this->resetTlApiMock();
		parent::tearDown();
	}

	/**
	 * @covers TrustedLogin\Vendor\Webhooks\Helpscout::verify_request()
	 * @covers TrustedLogin\Vendor\Webhooks\Helpscout::verify_request()
	 */
	public function testVerifyRequest()
	{
		$data = ['hi' => 'roy'];
		$helpscout = new Helpscout( 'secret' );
		$signature = $helpscout->make_signature( json_encode( $data ) );
		$this->assertTrue(
			$helpscout->verify_request( $data, $signature )

		);
		$this->assertFalse(
			$helpscout->verify_request( $data, $signature . 1 )

		);
		$this->assertFalse(
			$helpscout->verify_request( ['a'], $signature )
		);
	}

	/**
	 * @covers TrustedLogin\Vendor\Webhooks\Helpscout::build_action_url()
	 */
	public function testBuildActionUrl()
	{
		$this->assertTrue(
			is_string( filter_var(
				Helpscout::actionUrl( 'id' ),
				FILTER_VALIDATE_URL
			) )
		);

	}

	/**
	 * Test webhook endpoint returns correct arrya
	 * @covers TrustedLogin\Vendor\Webhooks\Helpscout::webhook_endpoint()
	 * @covers TrustedLogin\Vendor\Webhooks\Helpscout::get_widget_response()
	 */
	public function testWebhookEndpoint(){
		//Make webhook class
		$helpscout = new Helpscout( 'secret' );
		//Mock data object
		$data = (object)[
			'customer' => [
				'emails' => [
					'spaceperson@rocks.space'
				],
			],
			'account_id' => self::ACCOUNT_ID
		];
		//Encode that data.
		$encodedData = json_encode( $data );
		//Sign that data.
		$signature = $helpscout->make_signature(
			$encodedData
		);
		//Verify signature
		$this->assertTrue(
			$helpscout->verify_request(
				$encodedData,
				$signature
			)
		);

		//Mock request signature
		$_SERVER['X-HELPSCOUT-SIGNATURE']= $signature;
		//Process request
		$r = $helpscout->webhook_endpoint( $encodedData );
		$this->assertArrayHasKey( 'html', $r );
		$this->assertArrayHasKey( 'status', $r );
		$this->assertEquals( 200, $r['status'] );

		//Test with invalid signature
		$_SERVER['X-HELPSCOUT-SIGNATURE']= md5($encodedData);
		$r = $helpscout->webhook_endpoint( $encodedData );
		$this->assertEquals( 403, $r['status'] );


	}


}
