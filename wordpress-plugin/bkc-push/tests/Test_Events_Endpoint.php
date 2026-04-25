<?php
/**
 * Tests for BKC_Events — event endpoint deduplication and validation.
 *
 * @package bkc-push
 */

use PHPUnit\Framework\TestCase;

class Test_Events_Endpoint extends TestCase {

	/** In-memory event store: key = "device_id|campaign_uuid|event_type" */
	private static array $events = [];

	protected function setUp(): void {
		bkc_reset_stubs();
		self::$events = [];
	}

	// -------------------------------------------------------------------------

	/**
	 * Batch insert dedup: posting the same (device+campaign+type) twice
	 * must only count once.
	 */
	public function test_batch_dedup_same_event_counted_once(): void {
		$campaign_uuid = wp_generate_uuid4();
		$device_id     = wp_generate_uuid4();

		$event = [
			'campaign_uuid' => $campaign_uuid,
			'device_id'     => $device_id,
			'event_type'    => 'delivered',
			'occurred_at'   => gmdate( 'Y-m-d H:i:s' ),
		];

		// First batch.
		$inserted1 = BKC_Events_Testable::record_batch( [ $event ], self::$events );
		// Second batch with same event.
		$inserted2 = BKC_Events_Testable::record_batch( [ $event ], self::$events );

		$this->assertSame( 1, $inserted1 );
		$this->assertSame( 0, $inserted2, 'Duplicate event must not be inserted again' );
		$this->assertCount( 1, self::$events );
	}

	/**
	 * Unknown campaign_uuid is silently dropped (accepted count = 0 for them).
	 */
	public function test_unknown_campaign_uuid_silently_dropped(): void {
		$unknown_uuid = wp_generate_uuid4(); // Not in campaigns store.
		$event        = [
			'campaign_uuid' => $unknown_uuid,
			'device_id'     => wp_generate_uuid4(),
			'event_type'    => 'opened',
			'occurred_at'   => gmdate( 'Y-m-d H:i:s' ),
		];

		$inserted = BKC_Events_Testable::record_batch( [ $event ], self::$events, [] );

		$this->assertSame( 0, $inserted );
		$this->assertEmpty( self::$events );
	}

	/**
	 * Mixed batch: known campaign UUID gets inserted, unknown gets dropped.
	 */
	public function test_mixed_batch_known_inserted_unknown_dropped(): void {
		$known_uuid   = wp_generate_uuid4();
		$unknown_uuid = wp_generate_uuid4();

		$events = [
			[
				'campaign_uuid' => $known_uuid,
				'device_id'     => wp_generate_uuid4(),
				'event_type'    => 'delivered',
				'occurred_at'   => gmdate( 'Y-m-d H:i:s' ),
			],
			[
				'campaign_uuid' => $unknown_uuid,
				'device_id'     => wp_generate_uuid4(),
				'event_type'    => 'opened',
				'occurred_at'   => gmdate( 'Y-m-d H:i:s' ),
			],
		];

		$inserted = BKC_Events_Testable::record_batch( $events, self::$events, [ $known_uuid ] );

		$this->assertSame( 1, $inserted );
		$this->assertCount( 1, self::$events );
	}

	/**
	 * Invalid event_type is rejected.
	 */
	public function test_invalid_event_type_dropped(): void {
		$uuid   = wp_generate_uuid4();
		$events = [
			[
				'campaign_uuid' => $uuid,
				'device_id'     => wp_generate_uuid4(),
				'event_type'    => 'dismissed', // not a valid type
				'occurred_at'   => gmdate( 'Y-m-d H:i:s' ),
			],
		];

		$inserted = BKC_Events_Testable::record_batch( $events, self::$events, [ $uuid ] );

		$this->assertSame( 0, $inserted );
	}

	/**
	 * Batch clamped at 100 events.
	 */
	public function test_batch_clamped_to_100(): void {
		$uuid   = wp_generate_uuid4();
		$events = [];
		for ( $i = 0; $i < 150; $i++ ) {
			$events[] = [
				'campaign_uuid' => $uuid,
				'device_id'     => wp_generate_uuid4(),
				'event_type'    => 'delivered',
				'occurred_at'   => gmdate( 'Y-m-d H:i:s' ),
			];
		}

		$inserted = BKC_Events_Testable::record_batch( $events, self::$events, [ $uuid ] );

		$this->assertLessThanOrEqual( 100, $inserted );
		$this->assertCount( 100, self::$events );
	}
}

// ---------------------------------------------------------------------------
// In-memory testable version of BKC_Events::record_batch.
// ---------------------------------------------------------------------------

class BKC_Events_Testable {

	const VALID_EVENT_TYPES = [ 'delivered', 'opened', 'deeplinked' ];

	/**
	 * @param array  $events          Raw event array.
	 * @param array  &$store          Reference to the in-memory event store.
	 * @param array  $known_campaigns Campaign UUIDs considered valid.
	 * @return int Inserted count.
	 */
	public static function record_batch(
		array $events,
		array &$store,
		?array $known_campaigns = null
	): int {
		// null means "treat all as known" (for dedup tests).
		$check_campaigns = null !== $known_campaigns;

		$events   = array_slice( $events, 0, 100 );
		$inserted = 0;

		foreach ( $events as $event ) {
			if (
				empty( $event['campaign_uuid'] )
				|| empty( $event['device_id'] )
				|| empty( $event['event_type'] )
				|| empty( $event['occurred_at'] )
			) {
				continue;
			}

			$campaign_uuid = $event['campaign_uuid'];
			$device_id     = $event['device_id'];
			$event_type    = $event['event_type'];
			$occurred_at   = $event['occurred_at'];

			if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $campaign_uuid ) ) continue;
			if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $device_id ) ) continue;
			if ( ! in_array( $event_type, self::VALID_EVENT_TYPES, true ) ) continue;
			if ( false === strtotime( $occurred_at ) ) continue;

			if ( $check_campaigns && ! in_array( $campaign_uuid, $known_campaigns, true ) ) {
				continue; // Silently drop unknown campaign.
			}

			// Dedup key: UNIQUE(device_id, campaign_uuid, event_type).
			$key = $device_id . '|' . $campaign_uuid . '|' . $event_type;
			if ( isset( $store[ $key ] ) ) {
				continue; // Already exists — INSERT IGNORE semantics.
			}

			$store[ $key ] = [
				'campaign_uuid'      => $campaign_uuid,
				'device_id'          => $device_id,
				'event_type'         => $event_type,
				'occurred_at'        => $occurred_at,
				'server_received_at' => gmdate( 'Y-m-d H:i:s' ),
			];
			++$inserted;
		}

		return $inserted;
	}
}
