<?php
/**
 * BKC_Stats_Rollup — hourly aggregation of campaign event statistics.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

/**
 * Computes and upserts aggregated statistics from raw campaign events.
 */
class BKC_Stats_Rollup {

	/**
	 * WP option key tracking the last time the event prune ran.
	 */
	const LAST_PRUNE_OPTION = 'bkc_last_event_prune';

	/**
	 * Register the hourly recurring Action Scheduler job (idempotent).
	 */
	public static function register_cron(): void {
		if ( ! as_has_scheduled_action( 'bkc_stats_rollup', [], 'bkc-push' ) ) {
			as_schedule_recurring_action(
				time(),
				HOUR_IN_SECONDS,
				'bkc_stats_rollup',
				[],
				'bkc-push'
			);
		}
	}

	/**
	 * Action Scheduler handler for 'bkc_stats_rollup'.
	 *
	 * IRON RULE — idempotent rerun: always recomputes from raw events table,
	 * never increments. Running this handler twice produces the same numbers.
	 */
	public static function rollup_handler(): void {
		global $wpdb;

		$events_table = $wpdb->prefix . 'bkc_campaign_events';
		$stats_table  = $wpdb->prefix . 'bkc_campaign_stats';

		// Find all campaign UUIDs that have events newer than their last_rolled_up.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$campaign_uuids = $wpdb->get_col(
			"SELECT DISTINCT e.campaign_uuid
			FROM `{$events_table}` e
			LEFT JOIN `{$stats_table}` s ON e.campaign_uuid = s.campaign_uuid
			WHERE s.last_rolled_up IS NULL
			   OR e.server_received_at > s.last_rolled_up"
		);

		if ( empty( $campaign_uuids ) ) {
			self::maybe_prune_events();
			return;
		}

		foreach ( $campaign_uuids as $campaign_uuid ) {
			self::rollup_single( $campaign_uuid );
		}

		self::maybe_prune_events();
	}

	/**
	 * Recompute stats for a single campaign from raw events.
	 *
	 * @param string $campaign_uuid Campaign UUID.
	 */
	private static function rollup_single( string $campaign_uuid ): void {
		global $wpdb;

		$events_table = $wpdb->prefix . 'bkc_campaign_events';
		$stats_table  = $wpdb->prefix . 'bkc_campaign_stats';

		// Count distinct devices per event_type — full recompute (idempotent).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, COUNT(DISTINCT device_id) AS cnt
				FROM `{$events_table}`
				WHERE campaign_uuid = %s
				GROUP BY event_type",
				$campaign_uuid
			),
			ARRAY_A
		);

		$delivered  = 0;
		$opened     = 0;
		$deeplinked = 0;

		foreach ( $counts as $row ) {
			switch ( $row['event_type'] ) {
				case 'delivered':
					$delivered = (int) $row['cnt'];
					break;
				case 'opened':
					$opened = (int) $row['cnt'];
					break;
				case 'deeplinked':
					$deeplinked = (int) $row['cnt'];
					break;
			}
		}

		// UPSERT into stats table — full overwrite of event counts.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$stats_table}`
					(campaign_uuid, delivered_count, opened_count, deeplinked_count, last_rolled_up)
				VALUES
					(%s, %d, %d, %d, NOW())
				ON DUPLICATE KEY UPDATE
					delivered_count  = VALUES(delivered_count),
					opened_count     = VALUES(opened_count),
					deeplinked_count = VALUES(deeplinked_count),
					last_rolled_up   = NOW()",
				$campaign_uuid,
				$delivered,
				$opened,
				$deeplinked
			)
		);
	}

	/**
	 * Run event pruning at most once per day.
	 */
	private static function maybe_prune_events(): void {
		$last_prune = (int) get_option( self::LAST_PRUNE_OPTION, 0 );

		if ( time() - $last_prune >= DAY_IN_SECONDS ) {
			BKC_Events::prune_older_than( 6 );
			update_option( self::LAST_PRUNE_OPTION, time(), false );
		}
	}
}
