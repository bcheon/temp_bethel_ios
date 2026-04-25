<?php
/**
 * BKC_Campaigns — CRUD for wp_bkc_campaigns.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages campaign records.
 */
class BKC_Campaigns {

	/**
	 * Create a new campaign in 'queued' status.
	 *
	 * @param string $title         Push notification title.
	 * @param string $body          Push notification body.
	 * @param string $deep_link     Optional deep link URL.
	 * @param array  $target_groups Group identifiers.
	 * @param int    $created_by    WP user ID of the author.
	 * @return array{uuid: string, id: int} On success.
	 * @throws \RuntimeException On DB insert failure.
	 */
	public static function create(
		string $title,
		string $body,
		string $deep_link,
		array $target_groups,
		int $created_by
	): array {
		global $wpdb;

		$uuid  = wp_generate_uuid4();
		$table = $wpdb->prefix . 'bkc_campaigns';

		$inserted = $wpdb->insert(
			$table,
			[
				'uuid'          => $uuid,
				'title'         => $title,
				'body'          => $body,
				'deep_link'     => $deep_link,
				'target_groups' => wp_json_encode( array_values( $target_groups ) ),
				'status'        => 'queued',
				'created_by'    => $created_by,
				'created_at'    => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);

		if ( ! $inserted ) {
			throw new \RuntimeException( 'Failed to create campaign: ' . $wpdb->last_error );
		}

		return [
			'uuid' => $uuid,
			'id'   => (int) $wpdb->insert_id,
		];
	}

	/**
	 * Atomically transition a campaign's status.
	 *
	 * Uses a WHERE status = $from clause so concurrent requests cannot
	 * double-transition.
	 *
	 * @param string $uuid  Campaign UUID.
	 * @param string $from  Expected current status.
	 * @param string $to    Target status.
	 * @param array  $extra Additional columns to update (key => value pairs).
	 * @return bool True if exactly one row was updated.
	 */
	public static function transition_status(
		string $uuid,
		string $from,
		string $to,
		array $extra = []
	): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'bkc_campaigns';

		// Build SET clause from $extra plus the status field.
		$set_parts  = [ 'status = %s' ];
		$set_values = [ $to ];

		foreach ( $extra as $col => $val ) {
			$set_parts[]  = esc_sql( $col ) . ' = %s';
			$set_values[] = $val;
		}

		$set_clause  = implode( ', ', $set_parts );
		$set_values[] = $uuid;
		$set_values[] = $from;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET {$set_clause} WHERE uuid = %s AND status = %s",
				...$set_values
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Find a campaign by its UUID.
	 *
	 * @param string $uuid Campaign UUID.
	 * @return object|null DB row object or null if not found.
	 */
	public static function find_by_uuid( string $uuid ): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'bkc_campaigns';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE uuid = %s LIMIT 1",
				$uuid
			)
		);

		return $row ?: null;
	}

	/**
	 * List campaigns, optionally filtered by status.
	 *
	 * @param int         $limit         Maximum rows to return.
	 * @param int         $offset        Row offset for pagination.
	 * @param string|null $status_filter Only return campaigns with this status.
	 * @return array Array of DB row objects.
	 */
	public static function list( int $limit = 20, int $offset = 0, ?string $status_filter = null ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'bkc_campaigns';

		if ( null !== $status_filter ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$status_filter,
					$limit,
					$offset
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$limit,
					$offset
				)
			);
		}

		return $rows ?: [];
	}
}
