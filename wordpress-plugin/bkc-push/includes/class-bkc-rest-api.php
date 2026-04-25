<?php
/**
 * BKC_REST_API — REST endpoint registration and handlers.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers all bkc/v1 REST API endpoints.
 */
class BKC_REST_API {

	const NAMESPACE = 'bkc/v1';

	/**
	 * Register all routes.  Called on rest_api_init.
	 */
	public static function register_routes(): void {
		// POST /subscribe.
		register_rest_route(
			self::NAMESPACE,
			'/subscribe',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_subscribe' ],
				'permission_callback' => '__return_true',
				'args'                => self::subscribe_args(),
			]
		);

		// PATCH /subscribe/{device_id}.
		register_rest_route(
			self::NAMESPACE,
			'/subscribe/(?P<device_id>[a-f0-9-]{36})',
			[
				'methods'             => 'PATCH',
				'callback'            => [ __CLASS__, 'handle_update_subscription' ],
				'permission_callback' => '__return_true',
			]
		);

		// DELETE /subscribe/{device_id}.
		register_rest_route(
			self::NAMESPACE,
			'/subscribe/(?P<device_id>[a-f0-9-]{36})',
			[
				'methods'             => 'DELETE',
				'callback'            => [ __CLASS__, 'handle_delete_subscription' ],
				'permission_callback' => '__return_true',
			]
		);

		// GET /campaigns.
		register_rest_route(
			self::NAMESPACE,
			'/campaigns',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'handle_list_campaigns' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'since' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'limit' => [
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// POST /events.
		register_rest_route(
			self::NAMESPACE,
			'/events',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_events' ],
				'permission_callback' => '__return_true',
			]
		);

		// GET /stats/campaign/{uuid} — admin only.
		register_rest_route(
			self::NAMESPACE,
			'/stats/campaign/(?P<uuid>[a-f0-9-]{36})',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'handle_campaign_stats' ],
				'permission_callback' => [ __CLASS__, 'require_admin' ],
			]
		);

		// GET /stats/subscribers — admin only.
		register_rest_route(
			self::NAMESPACE,
			'/stats/subscribers',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'handle_subscriber_stats' ],
				'permission_callback' => [ __CLASS__, 'require_admin' ],
			]
		);

		// POST /campaigns/{uuid}/cancel — admin only.
		register_rest_route(
			self::NAMESPACE,
			'/campaigns/(?P<uuid>[a-f0-9-]{36})/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_cancel_campaign' ],
				'permission_callback' => [ __CLASS__, 'require_admin' ],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks.
	// -------------------------------------------------------------------------

	/**
	 * Check that the current user has manage_options capability (admin).
	 *
	 * @return bool|\WP_Error
	 */
	public static function require_admin(): bool|\WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error( 'rest_forbidden', 'Insufficient permissions.', [ 'status' => 403 ] );
	}

	// -------------------------------------------------------------------------
	// Public endpoint handlers.
	// -------------------------------------------------------------------------

	/**
	 * POST /subscribe
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_subscribe( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ip = BKC_Rate_Limiter::get_client_ip();
		if ( ! BKC_Rate_Limiter::check( 'subscribe', $ip, 10, 60 ) ) {
			return new \WP_Error( 'rate_limited', 'Too many requests.', [ 'status' => 429 ] );
		}

		$token      = sanitize_text_field( $request->get_param( 'fcm_token' ) ?? '' );
		$device_id  = sanitize_text_field( $request->get_param( 'device_id' ) ?? '' );
		$platform   = sanitize_text_field( $request->get_param( 'platform' ) ?? 'ios' );
		$app_version = sanitize_text_field( $request->get_param( 'app_version' ) ?? '' );
		$groups     = $request->get_param( 'groups' );

		// Token validation.
		if ( ! preg_match( '/^[a-zA-Z0-9_:-]+$/', $token ) ) {
			return new \WP_Error( 'invalid_token', 'Invalid FCM token format.', [ 'status' => 400 ] );
		}
		$token_len = strlen( $token );
		if ( $token_len < 100 || $token_len > 300 ) {
			return new \WP_Error( 'invalid_token', 'FCM token must be 100-300 characters.', [ 'status' => 400 ] );
		}

		// Device ID validation.
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $device_id ) ) {
			return new \WP_Error( 'invalid_device_id', 'Invalid device_id format.', [ 'status' => 400 ] );
		}

		// Groups validation.
		if ( ! is_array( $groups ) || empty( $groups ) ) {
			$groups = [ 'all' ];
		}
		$groups = array_map( 'sanitize_text_field', $groups );
		if ( ! BKC_Groups::validate_array( $groups ) ) {
			return new \WP_Error( 'invalid_groups', 'One or more unknown group identifiers.', [ 'status' => 400 ] );
		}
		// Auto-include 'all'.
		if ( ! in_array( 'all', $groups, true ) ) {
			$groups[] = 'all';
		}

		$platform = in_array( $platform, [ 'ios', 'android' ], true ) ? $platform : 'ios';

		$result = BKC_Subscriptions::upsert( $token, $device_id, $platform, $app_version, $groups );
		if ( false === $result ) {
			return new \WP_Error( 'db_error', 'Failed to save subscription.', [ 'status' => 500 ] );
		}

		return rest_ensure_response( [ 'status' => 'ok', 'groups' => $groups ] );
	}

	/**
	 * PATCH /subscribe/{device_id}
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_update_subscription( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ip = BKC_Rate_Limiter::get_client_ip();
		if ( ! BKC_Rate_Limiter::check( 'subscribe_patch', $ip, 10, 60 ) ) {
			return new \WP_Error( 'rate_limited', 'Too many requests.', [ 'status' => 429 ] );
		}

		$device_id = sanitize_text_field( $request->get_param( 'device_id' ) );
		$groups    = $request->get_param( 'groups' );

		if ( ! is_array( $groups ) || empty( $groups ) ) {
			return new \WP_Error( 'invalid_groups', 'groups is required.', [ 'status' => 400 ] );
		}
		$groups = array_map( 'sanitize_text_field', $groups );
		if ( ! BKC_Groups::validate_array( $groups ) ) {
			return new \WP_Error( 'invalid_groups', 'One or more unknown group identifiers.', [ 'status' => 400 ] );
		}
		// Force-include 'all'.
		if ( ! in_array( 'all', $groups, true ) ) {
			$groups[] = 'all';
		}

		$updated = BKC_Subscriptions::update_groups( $device_id, $groups );
		if ( ! $updated ) {
			return new \WP_Error( 'not_found', 'Subscription not found.', [ 'status' => 404 ] );
		}

		return rest_ensure_response( [ 'status' => 'ok', 'groups' => $groups ] );
	}

	/**
	 * DELETE /subscribe/{device_id}
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_delete_subscription( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$device_id = sanitize_text_field( $request->get_param( 'device_id' ) );
		$deleted   = BKC_Subscriptions::delete( $device_id );
		if ( ! $deleted ) {
			return new \WP_Error( 'not_found', 'Subscription not found.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( [ 'status' => 'ok' ] );
	}

	/**
	 * GET /campaigns
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public static function handle_list_campaigns( \WP_REST_Request $request ): \WP_REST_Response {
		$since = sanitize_text_field( $request->get_param( 'since' ) ?? '' );
		$limit = (int) ( $request->get_param( 'limit' ) ?? 20 );
		$limit = max( 1, min( 50, $limit ) );

		$campaigns = BKC_Campaigns::list( $limit, 0, 'sent' );

		$data = [];
		foreach ( $campaigns as $campaign ) {
			if ( ! empty( $since ) ) {
				$since_ts    = strtotime( $since );
				$sent_at_ts  = strtotime( $campaign->sent_at );
				if ( false !== $since_ts && false !== $sent_at_ts && $sent_at_ts <= $since_ts ) {
					continue;
				}
			}

			$data[] = [
				'uuid'      => $campaign->uuid,
				'title'     => $campaign->title,
				'body'      => $campaign->body,
				'deep_link' => $campaign->deep_link,
				'sent_at'   => $campaign->sent_at,
			];
		}

		return rest_ensure_response( $data );
	}

	/**
	 * POST /events
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_events( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ip = BKC_Rate_Limiter::get_client_ip();
		if ( ! BKC_Rate_Limiter::check( 'events', $ip, 10, 60 ) ) {
			return new \WP_Error( 'rate_limited', 'Too many requests.', [ 'status' => 429 ] );
		}

		$body   = $request->get_json_params();
		$events = $body['events'] ?? [];

		if ( ! is_array( $events ) ) {
			return new \WP_Error( 'invalid_payload', 'events must be an array.', [ 'status' => 400 ] );
		}

		if ( count( $events ) > 100 ) {
			return new \WP_Error( 'too_many_events', 'Maximum 100 events per batch.', [ 'status' => 400 ] );
		}

		$accepted = BKC_Events::record_batch( $events );

		return rest_ensure_response( [ 'accepted' => $accepted ] );
	}

	// -------------------------------------------------------------------------
	// Admin-only endpoint handlers.
	// -------------------------------------------------------------------------

	/**
	 * GET /stats/campaign/{uuid}
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_campaign_stats( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$uuid = sanitize_text_field( $request->get_param( 'uuid' ) );
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bkc_campaign_stats` WHERE campaign_uuid = %s",
				$uuid
			)
		);

		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'No stats found for this campaign.', [ 'status' => 404 ] );
		}

		$targeted   = max( 1, (int) $row->subscribers_targeted );
		$delivered  = (int) $row->delivered_count;
		$opened     = (int) $row->opened_count;
		$deeplinked = (int) $row->deeplinked_count;

		return rest_ensure_response( [
			'campaign_uuid'        => $row->campaign_uuid,
			'subscribers_targeted' => $targeted,
			'delivered_count'      => $delivered,
			'opened_count'         => $opened,
			'deeplinked_count'     => $deeplinked,
			'delivery_rate'        => $delivered / $targeted,
			'open_rate'            => $delivered > 0 ? $opened / $delivered : 0,
			'deeplink_ctr'         => $opened > 0 ? $deeplinked / $opened : 0,
			'last_rolled_up'       => $row->last_rolled_up,
		] );
	}

	/**
	 * GET /stats/subscribers
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public static function handle_subscriber_stats( \WP_REST_Request $request ): \WP_REST_Response {
		$group_counts = [];
		foreach ( BKC_Groups::WHITELIST as $group ) {
			$group_counts[ $group ] = BKC_Subscriptions::count_targeted( [ $group ] );
		}

		// 7-day trend: count new subscriptions per day over the last 7 days.
		global $wpdb;
		$table = $wpdb->prefix . 'bkc_subscriptions';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$trend = $wpdb->get_results(
			"SELECT DATE(last_seen) AS day, COUNT(*) AS cnt
			FROM `{$table}`
			WHERE last_seen >= NOW() - INTERVAL 7 DAY
			GROUP BY DATE(last_seen)
			ORDER BY day ASC"
		);

		return rest_ensure_response( [
			'group_counts' => $group_counts,
			'trend_7d'     => $trend,
		] );
	}

	/**
	 * POST /campaigns/{uuid}/cancel
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_cancel_campaign( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$uuid     = sanitize_text_field( $request->get_param( 'uuid' ) );
		$campaign = BKC_Campaigns::find_by_uuid( $uuid );

		if ( ! $campaign ) {
			return new \WP_Error( 'not_found', 'Campaign not found.', [ 'status' => 404 ] );
		}

		if ( ! in_array( $campaign->status, [ 'queued' ], true ) ) {
			return new \WP_Error(
				'cannot_cancel',
				'Only queued campaigns can be cancelled.',
				[ 'status' => 409 ]
			);
		}

		$cancelled = BKC_Dispatcher::cancel( $uuid );
		if ( ! $cancelled ) {
			return new \WP_Error( 'cancel_failed', 'Failed to cancel campaign.', [ 'status' => 500 ] );
		}

		return rest_ensure_response( [ 'status' => 'cancelled' ] );
	}

	// -------------------------------------------------------------------------
	// Argument definitions.
	// -------------------------------------------------------------------------

	/**
	 * Argument schema for POST /subscribe.
	 *
	 * @return array
	 */
	private static function subscribe_args(): array {
		return [
			'fcm_token'   => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'device_id'   => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'platform'    => [
				'type'              => 'string',
				'default'           => 'ios',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'app_version' => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'groups'      => [
				'type'    => 'array',
				'default' => [ 'all' ],
				'items'   => [ 'type' => 'string' ],
			],
		];
	}
}
