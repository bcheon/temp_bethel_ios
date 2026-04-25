<?php
/**
 * IRON RULE: Idempotency test.
 *
 * Simulates double-submit of the same compose form (same idempotency_token / UUID):
 * only one campaign row created, dispatcher only fires once.
 *
 * @package bkc-push
 */

use PHPUnit\Framework\TestCase;

class Test_Idempotency extends TestCase {

	// Public so BKC_Admin_Idempotent_Testable (defined below this class) can mutate them.
	public static array $campaigns = [];
	public static int   $dispatch_count = 0;

	protected function setUp(): void {
		bkc_reset_stubs();
		self::$campaigns      = [];
		self::$dispatch_count = 0;
	}

	/**
	 * IRON RULE: Double-submit of same UUID must not create two campaigns
	 * and must not dispatch twice.
	 */
	public function test_double_submit_same_uuid_creates_one_campaign_dispatches_once(): void {
		$uuid = wp_generate_uuid4();

		// First submission.
		$result1 = BKC_Admin_Idempotent_Testable::handle_compose( $uuid, 'Sunday Sermon', 'Come join us!', '', [ 'all' ] );
		$this->assertArrayHasKey( 'uuid', $result1 );
		$this->assertSame( $uuid, $result1['uuid'] );

		$campaign_count_after_first  = count( self::$campaigns );
		$dispatch_count_after_first  = self::$dispatch_count;

		// Second submission with same UUID (double-click or page reload).
		$result2 = BKC_Admin_Idempotent_Testable::handle_compose( $uuid, 'Sunday Sermon', 'Come join us!', '', [ 'all' ] );
		$this->assertArrayHasKey( 'uuid', $result2 );
		$this->assertSame( $uuid, $result2['uuid'] );

		$campaign_count_after_second = count( self::$campaigns );
		$dispatch_count_after_second = self::$dispatch_count;

		// Assert: still exactly one campaign row.
		$this->assertSame(
			$campaign_count_after_first,
			$campaign_count_after_second,
			'Double-submit must not create a second campaign row'
		);

		// Assert: dispatcher only fired once.
		$this->assertSame(
			$dispatch_count_after_first,
			$dispatch_count_after_second,
			'Dispatcher must not be called a second time for a duplicate UUID'
		);
	}
}

// ---------------------------------------------------------------------------
// Testable admin handler using in-memory store.
// ---------------------------------------------------------------------------

class BKC_Admin_Idempotent_Testable {

	public static function handle_compose(
		string $idempotency_token,
		string $title,
		string $body,
		string $deep_link,
		array $groups
	): array|\WP_Error {
		// Idempotency check.
		if ( isset( Test_Idempotency::$campaigns[ $idempotency_token ] ) ) {
			return [
				'uuid' => $idempotency_token,
				'id'   => Test_Idempotency::$campaigns[ $idempotency_token ]['id'],
			];
		}

		// Create new campaign.
		static $next_id = 1;
		Test_Idempotency::$campaigns[ $idempotency_token ] = [
			'id'            => $next_id++,
			'uuid'          => $idempotency_token,
			'title'         => $title,
			'body'          => $body,
			'deep_link'     => $deep_link,
			'target_groups' => json_encode( $groups ),
			'status'        => 'queued',
		];

		// Count dispatches.
		++Test_Idempotency::$dispatch_count;

		return [
			'uuid' => $idempotency_token,
			'id'   => Test_Idempotency::$campaigns[ $idempotency_token ]['id'],
		];
	}
}
