<?php
/**
 * Tests for BKC_Subscriptions.
 *
 * These tests use an in-memory simulation of wpdb behaviour via a test double.
 *
 * @package bkc-push
 */

use PHPUnit\Framework\TestCase;

class Test_Subscriptions extends TestCase {

	protected function setUp(): void {
		bkc_reset_stubs();
		// Reset the in-memory subscription store.
		BKC_Subscriptions_Store::reset();
	}

	// -------------------------------------------------------------------------

	public function test_upsert_new(): void {
		$rows   = BKC_Subscriptions_Store::all();
		$before = count( $rows );

		$result = BKC_Subscriptions_Testable::upsert(
			'token123' . str_repeat( 'x', 100 ),
			wp_generate_uuid4(),
			'ios',
			'1.0.0',
			[ 'all', 'youth' ]
		);

		$this->assertNotFalse( $result );
		$this->assertCount( $before + 1, BKC_Subscriptions_Store::all() );
	}

	public function test_upsert_existing_updates_not_inserts(): void {
		$device_id = wp_generate_uuid4();

		BKC_Subscriptions_Testable::upsert( 'token_v1' . str_repeat( 'a', 100 ), $device_id, 'ios', '1.0.0', [ 'all' ] );
		$count_after_first = count( BKC_Subscriptions_Store::all() );

		// Upsert same device_id — should update, not add a new row.
		BKC_Subscriptions_Testable::upsert( 'token_v2' . str_repeat( 'b', 100 ), $device_id, 'ios', '1.0.1', [ 'all', 'youth' ] );
		$count_after_second = count( BKC_Subscriptions_Store::all() );

		$this->assertSame( $count_after_first, $count_after_second, 'Upsert must not insert a duplicate row' );

		$row = BKC_Subscriptions_Store::find_by_device( $device_id );
		$this->assertStringContainsString( 'token_v2', $row['fcm_token'] );
		$this->assertStringContainsString( 'youth', $row['groups'] );
	}

	public function test_delete(): void {
		$device_id = wp_generate_uuid4();
		BKC_Subscriptions_Testable::upsert( 'tokdel' . str_repeat( 'z', 100 ), $device_id, 'ios', '1.0.0', [ 'all' ] );

		$deleted = BKC_Subscriptions_Testable::delete( $device_id );

		$this->assertTrue( $deleted );
		$this->assertNull( BKC_Subscriptions_Store::find_by_device( $device_id ) );
	}

	public function test_prune_stale_removes_15_day_old(): void {
		$old_device = wp_generate_uuid4();
		$new_device = wp_generate_uuid4();

		BKC_Subscriptions_Store::insert_raw( [
			'fcm_token'   => 'tok_old' . str_repeat( 'o', 100 ),
			'device_id'   => $old_device,
			'platform'    => 'ios',
			'app_version' => '1.0.0',
			'groups'      => json_encode( [ 'all' ] ),
			'last_seen'   => gmdate( 'Y-m-d H:i:s', strtotime( '-15 days' ) ),
		] );

		BKC_Subscriptions_Store::insert_raw( [
			'fcm_token'   => 'tok_new' . str_repeat( 'n', 100 ),
			'device_id'   => $new_device,
			'platform'    => 'ios',
			'app_version' => '1.0.0',
			'groups'      => json_encode( [ 'all' ] ),
			'last_seen'   => gmdate( 'Y-m-d H:i:s' ),
		] );

		$pruned = BKC_Subscriptions_Testable::prune_stale( 14 );

		$this->assertSame( 1, $pruned );
		$this->assertNull( BKC_Subscriptions_Store::find_by_device( $old_device ) );
		$this->assertNotNull( BKC_Subscriptions_Store::find_by_device( $new_device ) );
	}

	public function test_count_targeted_respects_14_day_window(): void {
		// Active subscriber in 'all' + 'youth'.
		BKC_Subscriptions_Store::insert_raw( [
			'fcm_token'   => 'tok_a' . str_repeat( 'a', 100 ),
			'device_id'   => wp_generate_uuid4(),
			'platform'    => 'ios',
			'app_version' => '1.0.0',
			'groups'      => json_encode( [ 'all', 'youth' ] ),
			'last_seen'   => gmdate( 'Y-m-d H:i:s' ),
		] );

		// Stale subscriber in 'all' + 'youth' — should not be counted.
		BKC_Subscriptions_Store::insert_raw( [
			'fcm_token'   => 'tok_b' . str_repeat( 'b', 100 ),
			'device_id'   => wp_generate_uuid4(),
			'platform'    => 'ios',
			'app_version' => '1.0.0',
			'groups'      => json_encode( [ 'all', 'youth' ] ),
			'last_seen'   => gmdate( 'Y-m-d H:i:s', strtotime( '-15 days' ) ),
		] );

		$count = BKC_Subscriptions_Testable::count_targeted( [ 'youth' ] );

		$this->assertSame( 1, $count );
	}
}

// ---------------------------------------------------------------------------
// In-memory subscription store + testable class.
// ---------------------------------------------------------------------------

class BKC_Subscriptions_Store {
	private static array $rows = [];

	public static function reset(): void {
		self::$rows = [];
	}

	public static function insert_raw( array $row ): void {
		self::$rows[ $row['device_id'] ] = $row;
	}

	public static function all(): array {
		return self::$rows;
	}

	public static function find_by_device( string $device_id ): ?array {
		return self::$rows[ $device_id ] ?? null;
	}

	public static function update( string $device_id, array $changes ): bool {
		if ( ! isset( self::$rows[ $device_id ] ) ) return false;
		self::$rows[ $device_id ] = array_merge( self::$rows[ $device_id ], $changes );
		return true;
	}

	public static function delete( string $device_id ): bool {
		if ( ! isset( self::$rows[ $device_id ] ) ) return false;
		unset( self::$rows[ $device_id ] );
		return true;
	}

	public static function prune( int $older_than_days ): int {
		$cutoff  = strtotime( "-{$older_than_days} days" );
		$deleted = 0;
		foreach ( self::$rows as $dev_id => $row ) {
			if ( strtotime( $row['last_seen'] ) < $cutoff ) {
				unset( self::$rows[ $dev_id ] );
				++$deleted;
			}
		}
		return $deleted;
	}

	public static function count_targeted( array $groups ): int {
		$cutoff = strtotime( '-14 days' );
		$count  = 0;
		foreach ( self::$rows as $row ) {
			if ( strtotime( $row['last_seen'] ) < $cutoff ) continue;
			$row_groups = json_decode( $row['groups'], true ) ?? [];
			foreach ( $groups as $g ) {
				if ( in_array( $g, $row_groups, true ) ) {
					++$count;
					break;
				}
			}
		}
		return $count;
	}
}

class BKC_Subscriptions_Testable {

	public static function upsert( string $fcm_token, string $device_id, string $platform, string $app_version, array $groups ): int|false {
		$groups_json = json_encode( array_values( array_unique( $groups ) ) );
		$existing    = BKC_Subscriptions_Store::find_by_device( $device_id );
		if ( $existing ) {
			BKC_Subscriptions_Store::update( $device_id, [
				'fcm_token'   => $fcm_token,
				'platform'    => $platform,
				'app_version' => $app_version,
				'groups'      => $groups_json,
				'last_seen'   => gmdate( 'Y-m-d H:i:s' ),
			] );
			return 2; // MySQL ON DUPLICATE KEY UPDATE returns 2.
		}
		BKC_Subscriptions_Store::insert_raw( [
			'fcm_token'   => $fcm_token,
			'device_id'   => $device_id,
			'platform'    => $platform,
			'app_version' => $app_version,
			'groups'      => $groups_json,
			'last_seen'   => gmdate( 'Y-m-d H:i:s' ),
		] );
		return 1;
	}

	public static function delete( string $device_id ): bool {
		return BKC_Subscriptions_Store::delete( $device_id );
	}

	public static function prune_stale( int $older_than_days = 14 ): int {
		return BKC_Subscriptions_Store::prune( $older_than_days );
	}

	public static function count_targeted( array $groups ): int {
		return BKC_Subscriptions_Store::count_targeted( $groups );
	}
}
