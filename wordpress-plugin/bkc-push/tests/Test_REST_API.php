<?php
/**
 * Tests for BKC_REST_API.
 *
 * @package bkc-push
 */

use PHPUnit\Framework\TestCase;

class Test_REST_API extends TestCase {

	protected function setUp(): void {
		bkc_reset_stubs();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_subscribe_request( array $params ): WP_REST_Request {
		$req = new WP_REST_Request();
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function valid_token(): string {
		// 150 chars, matching ^[a-zA-Z0-9_:-]+$
		return str_repeat( 'aB1_:-', 25 );
	}

	private function valid_device_id(): string {
		return 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
	}

	// -------------------------------------------------------------------------

	public function test_subscribe_validates_token_regex(): void {
		$req = $this->make_subscribe_request( [
			'fcm_token' => str_repeat( 'x', 150 ) . '!@#', // invalid chars
			'device_id' => $this->valid_device_id(),
			'groups'    => [ 'all' ],
		] );

		$result = BKC_REST_API::handle_subscribe( $req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	public function test_subscribe_rejects_unknown_group(): void {
		$req = $this->make_subscribe_request( [
			'fcm_token' => $this->valid_token(),
			'device_id' => $this->valid_device_id(),
			'groups'    => [ 'all', 'unknown_group_xyz' ],
		] );

		$result = BKC_REST_API::handle_subscribe( $req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_groups', $result->get_error_code() );
	}

	public function test_subscribe_rate_limit_enforced(): void {
		$ip = '1.2.3.4';
		// Simulate 10 prior requests filling the bucket.
		for ( $i = 0; $i < 10; $i++ ) {
			BKC_Rate_Limiter::check( 'subscribe', $ip, 10, 60 );
		}

		// 11th request should be blocked. Since handle_subscribe uses
		// BKC_Rate_Limiter::get_client_ip() (reads $_SERVER), we call the
		// rate limiter directly to verify the state then test the API response
		// by overriding the server var.
		$_SERVER['REMOTE_ADDR'] = $ip;

		$req = $this->make_subscribe_request( [
			'fcm_token' => $this->valid_token(),
			'device_id' => $this->valid_device_id(),
			'groups'    => [ 'all' ],
		] );

		$result = BKC_REST_API::handle_subscribe( $req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_events_endpoint_accepts_batch_under_100(): void {
		$events = [];
		$uuid   = wp_generate_uuid4();

		// We need a campaign in the DB. Since we are using stubs (no real DB),
		// we verify the endpoint validation accepts the payload shape.
		// The record_batch will get 0 inserts (no DB), but should not error.
		for ( $i = 0; $i < 50; $i++ ) {
			$events[] = [
				'campaign_uuid' => $uuid,
				'device_id'     => wp_generate_uuid4(),
				'event_type'    => 'delivered',
				'occurred_at'   => gmdate( 'Y-m-d H:i:s' ),
			];
		}

		$req = new WP_REST_Request();
		$req->set_json_params( [ 'events' => $events ] );
		$_SERVER['REMOTE_ADDR'] = '5.6.7.8';

		$result = BKC_REST_API::handle_events( $req );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'accepted', $data );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_events_endpoint_rejects_over_100(): void {
		$events = array_fill( 0, 101, [
			'campaign_uuid' => wp_generate_uuid4(),
			'device_id'     => wp_generate_uuid4(),
			'event_type'    => 'opened',
			'occurred_at'   => gmdate( 'Y-m-d H:i:s' ),
		] );

		$req = new WP_REST_Request();
		$req->set_json_params( [ 'events' => $events ] );
		$_SERVER['REMOTE_ADDR'] = '5.6.7.9';

		$result = BKC_REST_API::handle_events( $req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'too_many_events', $result->get_error_code() );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_stats_endpoint_requires_admin(): void {
		$GLOBALS['_bkc_current_user_can']['manage_options'] = false;

		$result = BKC_REST_API::require_admin();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_stats_endpoint_accessible_by_admin(): void {
		$GLOBALS['_bkc_current_user_can']['manage_options'] = true;

		$result = BKC_REST_API::require_admin();

		$this->assertTrue( $result );
	}
}
