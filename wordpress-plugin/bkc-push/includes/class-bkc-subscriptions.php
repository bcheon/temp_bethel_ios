<?php
/**
 * BKC_Subscriptions — CRUD for wp_bkc_subscriptions.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages device subscription records.
 */
class BKC_Subscriptions {

	/**
	 * Insert or update a subscription record.
	 *
	 * Uses an INSERT … ON DUPLICATE KEY UPDATE pattern so that re-registrations
	 * (token refresh, re-install) update the existing row keyed by device_id.
	 *
	 * @param string $fcm_token   FCM registration token.
	 * @param string $device_id   App-generated UUID (stable across token refreshes).
	 * @param string $platform    'ios' or 'android'.
	 * @param string $app_version Semver string from the app.
	 * @param array  $groups      Validated group identifiers including 'all'.
	 * @return int|false Affected rows count or false on failure.
	 */
	public static function upsert(
		string $fcm_token,
		string $device_id,
		string $platform,
		string $app_version,
		array $groups
	): int|false {
		global $wpdb;

		$groups_json = wp_json_encode( array_values( array_unique( $groups ) ) );
		$table       = $wpdb->prefix . 'bkc_subscriptions';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"INSERT INTO `{$table}`
				(fcm_token, device_id, platform, app_version, groups, last_seen)
			VALUES
				(%s, %s, %s, %s, %s, NOW())
			ON DUPLICATE KEY UPDATE
				fcm_token   = VALUES(fcm_token),
				platform    = VALUES(platform),
				app_version = VALUES(app_version),
				groups      = VALUES(groups),
				last_seen   = NOW()",
			$fcm_token,
			$device_id,
			$platform,
			$app_version,
			$groups_json
		);
		// phpcs:enable

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $result;
	}

	/**
	 * Update the groups for an existing subscription.
	 *
	 * @param string $device_id App-generated device UUID.
	 * @param array  $groups    New group list (must include 'all').
	 * @return bool True on success, false if device not found or DB error.
	 */
	public static function update_groups( string $device_id, array $groups ): bool {
		global $wpdb;

		$groups_json = wp_json_encode( array_values( array_unique( $groups ) ) );
		$table       = $wpdb->prefix . 'bkc_subscriptions';

		$updated = $wpdb->update(
			$table,
			[
				'groups'    => $groups_json,
				'last_seen' => current_time( 'mysql', true ),
			],
			[ 'device_id' => $device_id ],
			[ '%s', '%s' ],
			[ '%s' ]
		);

		return $updated !== false && $updated > 0;
	}

	/**
	 * Delete a subscription by device_id.
	 *
	 * @param string $device_id App-generated device UUID.
	 * @return bool True when a row was deleted.
	 */
	public static function delete( string $device_id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete(
			$wpdb->prefix . 'bkc_subscriptions',
			[ 'device_id' => $device_id ],
			[ '%s' ]
		);

		return $deleted !== false && $deleted > 0;
	}

	/**
	 * Remove subscriptions whose last_seen is older than the given number of days.
	 *
	 * @param int $older_than_days Rows with last_seen older than this many days will be deleted.
	 * @return int Number of deleted rows.
	 */
	public static function prune_stale( int $older_than_days = 14 ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'bkc_subscriptions';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE last_seen < NOW() - INTERVAL %d DAY",
				$older_than_days
			)
		);

		return (int) $deleted;
	}

	/**
	 * Count active subscribers for any of the given groups, active within 14 days.
	 *
	 * A subscriber is counted if their groups JSON contains ANY of the supplied
	 * group identifiers AND they have been seen within the last 14 days.
	 *
	 * @param array $groups Group identifiers to match against.
	 * @return int
	 */
	public static function count_targeted( array $groups ): int {
		global $wpdb;

		if ( empty( $groups ) ) {
			return 0;
		}

		$table = $wpdb->prefix . 'bkc_subscriptions';

		// Build a JSON_CONTAINS check for each group joined with OR.
		$conditions = [];
		$values     = [];
		foreach ( $groups as $group ) {
			$conditions[] = 'JSON_CONTAINS(groups, %s)';
			$values[]     = wp_json_encode( $group );
		}

		$where = '(' . implode( ' OR ', $conditions ) . ')';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE last_seen >= NOW() - INTERVAL 14 DAY AND {$where}",
				...$values
			)
		);

		return (int) $count;
	}
}
