<?php
/**
 * BKC_Events — telemetry event collection and management.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles raw campaign event records.
 */
class BKC_Events {

	/**
	 * Valid event types.
	 */
	const VALID_EVENT_TYPES = [ 'delivered', 'opened', 'deeplinked' ];

	/**
	 * Batch-insert events with server-side deduplication.
	 *
	 * Accepts up to 100 events. Silently drops events with unknown
	 * campaign_uuids. Uses INSERT IGNORE so UNIQUE constraint on
	 * (device_id, campaign_uuid, event_type) handles dedup.
	 *
	 * @param array $events Array of event associative arrays, each with keys:
	 *                      campaign_uuid, device_id, event_type, occurred_at.
	 * @return int Number of newly inserted rows.
	 */
	public static function record_batch( array $events ): int {
		global $wpdb;

		if ( empty( $events ) ) {
			return 0;
		}

		// Clamp to 100.
		$events = array_slice( $events, 0, 100 );

		$table  = $wpdb->prefix . 'bkc_campaign_events';
		$now    = current_time( 'mysql', true );
		$inserted = 0;

		foreach ( $events as $event ) {
			// Validate required keys.
			if (
				empty( $event['campaign_uuid'] )
				|| empty( $event['device_id'] )
				|| empty( $event['event_type'] )
				|| empty( $event['occurred_at'] )
			) {
				continue;
			}

			$campaign_uuid = sanitize_text_field( $event['campaign_uuid'] );
			$device_id     = sanitize_text_field( $event['device_id'] );
			$event_type    = sanitize_text_field( $event['event_type'] );
			$occurred_at   = sanitize_text_field( $event['occurred_at'] );

			// Validate UUID format (simple regex).
			if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $campaign_uuid ) ) {
				continue;
			}

			if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $device_id ) ) {
				continue;
			}

			// Validate event_type.
			if ( ! in_array( $event_type, self::VALID_EVENT_TYPES, true ) ) {
				continue;
			}

			// Validate occurred_at is parseable as a date.
			$ts = strtotime( $occurred_at );
			if ( false === $ts || $ts <= 0 ) {
				continue;
			}
			$occurred_at_mysql = gmdate( 'Y-m-d H:i:s', $ts );

			// Silently skip unknown campaign_uuids.
			$campaign_exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$wpdb->prefix}bkc_campaigns` WHERE uuid = %s",
					$campaign_uuid
				)
			);
			if ( ! $campaign_exists ) {
				continue;
			}

			// INSERT IGNORE handles dedup via UNIQUE KEY.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$table}`
						(campaign_uuid, device_id, event_type, occurred_at, server_received_at)
					VALUES
						(%s, %s, %s, %s, %s)",
					$campaign_uuid,
					$device_id,
					$event_type,
					$occurred_at_mysql,
					$now
				)
			);

			if ( 1 === (int) $result ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Delete events older than the given number of months.
	 *
	 * @param int $months Events older than this many months are removed.
	 * @return int Deleted row count.
	 */
	public static function prune_older_than( int $months = 6 ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'bkc_campaign_events';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE server_received_at < NOW() - INTERVAL %d MONTH",
				$months
			)
		);

		return (int) $deleted;
	}
}
