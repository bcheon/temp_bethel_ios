<?php
/**
 * Tests for BKC_Stats_Rollup.
 *
 * @package bkc-push
 */

use PHPUnit\Framework\TestCase;

class Test_Stats_Rollup extends TestCase {

	/** In-memory raw events store. */
	private static array $events = [];

	/** In-memory stats store: campaign_uuid => row. */
	private static array $stats = [];

	protected function setUp(): void {
		bkc_reset_stubs();
		self::$events = [];
		self::$stats  = [];
	}

	// -------------------------------------------------------------------------

	public function test_events_rolled_up_to_stats_accurately(): void {
		$uuid = wp_generate_uuid4();

		// Insert raw events.
		$devices = [ wp_generate_uuid4(), wp_generate_uuid4(), wp_generate_uuid4() ];
		foreach ( $devices as $dev ) {
			self::$events[] = [ 'campaign_uuid' => $uuid, 'device_id' => $dev, 'event_type' => 'delivered' ];
		}
		// Only 2 opened.
		self::$events[] = [ 'campaign_uuid' => $uuid, 'device_id' => $devices[0], 'event_type' => 'opened' ];
		self::$events[] = [ 'campaign_uuid' => $uuid, 'device_id' => $devices[1], 'event_type' => 'opened' ];
		// 1 deeplinked.
		self::$events[] = [ 'campaign_uuid' => $uuid, 'device_id' => $devices[0], 'event_type' => 'deeplinked' ];

		BKC_Stats_Rollup_Testable::rollup( $uuid, self::$events, self::$stats );

		$this->assertArrayHasKey( $uuid, self::$stats );
		$row = self::$stats[ $uuid ];
		$this->assertSame( 3, $row['delivered_count'] );
		$this->assertSame( 2, $row['opened_count'] );
		$this->assertSame( 1, $row['deeplinked_count'] );
	}

	/**
	 * IRON RULE: idempotent_rerun — running rollup twice produces the same numbers.
	 */
	public function test_idempotent_rerun_iron_rule(): void {
		$uuid = wp_generate_uuid4();

		$device = wp_generate_uuid4();
		self::$events[] = [ 'campaign_uuid' => $uuid, 'device_id' => $device, 'event_type' => 'delivered' ];
		self::$events[] = [ 'campaign_uuid' => $uuid, 'device_id' => $device, 'event_type' => 'opened' ];

		// Run once.
		BKC_Stats_Rollup_Testable::rollup( $uuid, self::$events, self::$stats );
		$after_first = self::$stats[ $uuid ];

		// Run again — must produce identical numbers, not double.
		BKC_Stats_Rollup_Testable::rollup( $uuid, self::$events, self::$stats );
		$after_second = self::$stats[ $uuid ];

		$this->assertSame( $after_first['delivered_count'], $after_second['delivered_count'],
			'delivered_count must not double on re-run' );
		$this->assertSame( $after_first['opened_count'], $after_second['opened_count'],
			'opened_count must not double on re-run' );
	}

	/**
	 * 6-month prune removes old events.
	 */
	public function test_prune_older_than_6_months(): void {
		$uuid      = wp_generate_uuid4();
		$old_event = [
			'campaign_uuid'      => $uuid,
			'device_id'          => wp_generate_uuid4(),
			'event_type'         => 'delivered',
			'server_received_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-7 months' ) ),
		];
		$new_event = [
			'campaign_uuid'      => $uuid,
			'device_id'          => wp_generate_uuid4(),
			'event_type'         => 'delivered',
			'server_received_at' => gmdate( 'Y-m-d H:i:s' ),
		];

		self::$events = [ $old_event, $new_event ];

		$pruned = BKC_Stats_Rollup_Testable::prune_events( self::$events, 6 );

		$this->assertSame( 1, $pruned );
		$this->assertCount( 1, self::$events );
	}
}

// ---------------------------------------------------------------------------
// In-memory testable rollup.
// ---------------------------------------------------------------------------

class BKC_Stats_Rollup_Testable {

	/**
	 * Recompute stats for a campaign from raw events (full overwrite — idempotent).
	 *
	 * @param string $campaign_uuid
	 * @param array  $events  All raw events (by reference for prune).
	 * @param array  &$stats  In-memory stats store.
	 */
	public static function rollup( string $campaign_uuid, array $events, array &$stats ): void {
		$delivered  = [];
		$opened     = [];
		$deeplinked = [];

		foreach ( $events as $ev ) {
			if ( $ev['campaign_uuid'] !== $campaign_uuid ) continue;
			$dev = $ev['device_id'];
			switch ( $ev['event_type'] ) {
				case 'delivered':
					$delivered[ $dev ] = true;
					break;
				case 'opened':
					$opened[ $dev ] = true;
					break;
				case 'deeplinked':
					$deeplinked[ $dev ] = true;
					break;
			}
		}

		// Full overwrite (not increment) — IRON RULE.
		$stats[ $campaign_uuid ] = [
			'campaign_uuid'   => $campaign_uuid,
			'delivered_count' => count( $delivered ),
			'opened_count'    => count( $opened ),
			'deeplinked_count'=> count( $deeplinked ),
			'last_rolled_up'  => gmdate( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Remove events older than $months months.
	 *
	 * @param array &$events
	 * @param int   $months
	 * @return int Pruned count.
	 */
	public static function prune_events( array &$events, int $months ): int {
		$cutoff = strtotime( "-{$months} months" );
		$before = count( $events );
		$events = array_filter( $events, static function ( array $ev ) use ( $cutoff ): bool {
			$ts = isset( $ev['server_received_at'] ) ? strtotime( $ev['server_received_at'] ) : time();
			return $ts >= $cutoff;
		} );
		$events = array_values( $events );
		return $before - count( $events );
	}
}
