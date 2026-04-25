<?php
/**
 * Tests for stats endpoint permission guards.
 *
 * @package bkc-push
 */

use PHPUnit\Framework\TestCase;

class Test_Stats_Permissions extends TestCase {

	protected function setUp(): void {
		bkc_reset_stubs();
	}

	// -------------------------------------------------------------------------

	public function test_non_admin_gets_403_from_stats_campaign(): void {
		$GLOBALS['_bkc_current_user_can']['manage_options'] = false;

		$result = BKC_REST_API::require_admin();

		$this->assertInstanceOf( WP_Error::class, $result );

		$data = $result->get_error_data();
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( 403, $data['status'] );
	}

	public function test_non_admin_gets_403_from_stats_subscribers(): void {
		$GLOBALS['_bkc_current_user_can']['manage_options'] = false;

		$result = BKC_REST_API::require_admin();

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] );
	}

	public function test_admin_gets_200_from_stats_campaign(): void {
		$GLOBALS['_bkc_current_user_can']['manage_options'] = true;

		$result = BKC_REST_API::require_admin();

		$this->assertTrue( $result );
	}

	public function test_admin_gets_200_from_stats_subscribers(): void {
		$GLOBALS['_bkc_current_user_can']['manage_options'] = true;

		$result = BKC_REST_API::require_admin();

		$this->assertTrue( $result );
	}

	public function test_non_admin_cannot_cancel_campaign(): void {
		$GLOBALS['_bkc_current_user_can']['manage_options'] = false;

		$result = BKC_REST_API::require_admin();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_permission_error_code_is_rest_forbidden(): void {
		$GLOBALS['_bkc_current_user_can']['manage_options'] = false;

		$result = BKC_REST_API::require_admin();

		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}
}
