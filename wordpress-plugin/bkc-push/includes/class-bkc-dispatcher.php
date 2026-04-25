<?php
/**
 * BKC_Dispatcher — Action Scheduler integration for campaign dispatch.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues and processes campaign dispatch jobs via Action Scheduler.
 */
class BKC_Dispatcher {

	/**
	 * Enqueue a dispatch job for the given campaign UUID, scheduled 10 seconds out.
	 *
	 * @param string $campaign_uuid Campaign UUID.
	 * @return int Action Scheduler action ID.
	 */
	public static function enqueue( string $campaign_uuid ): int {
		return (int) as_schedule_single_action(
			time() + 10,
			'bkc_dispatch_campaign',
			[ 'uuid' => $campaign_uuid ],
			'bkc-push'
		);
	}

	/**
	 * Action Scheduler handler for 'bkc_dispatch_campaign'.
	 *
	 * Loads the campaign, atomically transitions queued→sending, calls FCM,
	 * then transitions to sent (with message_id) or failed (with error).
	 *
	 * @param array $args Action arguments — expects ['uuid' => <uuid>].
	 */
	public static function dispatch_handler( array $args ): void {
		$uuid = $args['uuid'] ?? '';
		if ( empty( $uuid ) ) {
			return;
		}

		$campaign = BKC_Campaigns::find_by_uuid( $uuid );
		if ( ! $campaign ) {
			return;
		}

		// Idempotency: if already sent/cancelled/failed, do nothing.
		if ( ! in_array( $campaign->status, [ 'queued', 'sending' ], true ) ) {
			return;
		}

		// Atomic transition: queued → sending. If this fails, another process
		// already claimed it.
		$claimed = BKC_Campaigns::transition_status( $uuid, 'queued', 'sending' );
		if ( ! $claimed ) {
			// May already be in 'sending' from a previous attempt; allow re-dispatch
			// only if still in 'sending' (Action Scheduler retry scenario).
			$campaign = BKC_Campaigns::find_by_uuid( $uuid );
			if ( ! $campaign || 'sending' !== $campaign->status ) {
				return;
			}
		}

		// Reload to get fresh data.
		$campaign      = BKC_Campaigns::find_by_uuid( $uuid );
		$target_groups = json_decode( $campaign->target_groups, true ) ?? [];

		// Count targeted subscribers for stats.
		$subscribers_targeted = BKC_Subscriptions::count_targeted( $target_groups );

		// Initialise stats row with targeted count.
		global $wpdb;
		$stats_table = $wpdb->prefix . 'bkc_campaign_stats';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$stats_table}` (campaign_uuid, subscribers_targeted)
				VALUES (%s, %d)
				ON DUPLICATE KEY UPDATE subscribers_targeted = VALUES(subscribers_targeted)",
				$uuid,
				$subscribers_targeted
			)
		);

		try {
			$fcm    = new BKC_FCM_Client();
			$result = $fcm->send_to_groups(
				$campaign->title,
				$campaign->body,
				(string) $campaign->deep_link,
				$target_groups,
				$uuid
			);
		} catch ( \Exception $e ) {
			BKC_Campaigns::transition_status(
				$uuid,
				'sending',
				'failed',
				[ 'error_message' => $e->getMessage() ]
			);
			error_log( 'BKC Dispatcher: FCM exception for ' . $uuid . ': ' . $e->getMessage() );
			return;
		}

		if ( ! empty( $result['message_id'] ) ) {
			BKC_Campaigns::transition_status(
				$uuid,
				'sending',
				'sent',
				[
					'sent_at'         => current_time( 'mysql', true ),
					'fcm_message_ids' => wp_json_encode( [ 'message_id' => $result['message_id'] ] ),
					'error_message'   => '',
				]
			);
		} else {
			BKC_Campaigns::transition_status(
				$uuid,
				'sending',
				'failed',
				[ 'error_message' => $result['error'] ?? 'Unknown FCM error' ]
			);
			error_log( 'BKC Dispatcher: FCM failed for ' . $uuid . ': ' . ( $result['error'] ?? '' ) );
		}
	}

	/**
	 * Cancel a queued campaign.
	 *
	 * Unschedules the pending Action Scheduler job and transitions
	 * the campaign status from queued → cancelled.
	 *
	 * @param string $campaign_uuid Campaign UUID.
	 * @return bool True if the campaign was successfully cancelled.
	 */
	public static function cancel( string $campaign_uuid ): bool {
		as_unschedule_action(
			'bkc_dispatch_campaign',
			[ 'uuid' => $campaign_uuid ],
			'bkc-push'
		);

		return BKC_Campaigns::transition_status( $campaign_uuid, 'queued', 'cancelled' );
	}
}
