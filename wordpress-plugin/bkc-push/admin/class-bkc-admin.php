<?php
/**
 * BKC_Admin — WordPress admin menu and compose form handler.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers admin menus and handles the compose form POST.
 */
class BKC_Admin {

	/**
	 * Register the top-level admin menu and submenus.
	 * Hooked on admin_menu.
	 */
	public static function add_menu(): void {
		add_menu_page(
			__( '푸쉬 공지', 'bkc-push' ),
			__( '푸쉬 공지', 'bkc-push' ),
			'manage_options',
			'bkc-push',
			[ __CLASS__, 'render_dashboard' ],
			'dashicons-megaphone',
			30
		);

		add_submenu_page(
			'bkc-push',
			__( '대시보드', 'bkc-push' ),
			__( '대시보드', 'bkc-push' ),
			'manage_options',
			'bkc-push',
			[ __CLASS__, 'render_dashboard' ]
		);

		add_submenu_page(
			'bkc-push',
			__( '새 공지 작성', 'bkc-push' ),
			__( '새 공지 작성', 'bkc-push' ),
			'manage_options',
			'bkc-push-compose',
			[ __CLASS__, 'render_compose' ]
		);

		add_submenu_page(
			'bkc-push',
			__( '발송 이력', 'bkc-push' ),
			__( '발송 이력', 'bkc-push' ),
			'manage_options',
			'bkc-push-campaigns',
			[ __CLASS__, 'render_campaigns' ]
		);
	}

	/**
	 * Enqueue admin JS on plugin pages only.
	 * Hooked on admin_enqueue_scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_scripts( string $hook ): void {
		$plugin_pages = [
			'toplevel_page_bkc-push',
			'푸쉬-공지_page_bkc-push-compose',
			'푸쉬-공지_page_bkc-push-campaigns',
			// Fallback for ASCII slugs.
			'push-_page_bkc-push-compose',
			'push-_page_bkc-push-campaigns',
		];

		// Accept any hook containing bkc-push.
		if ( ! str_contains( $hook, 'bkc-push' ) ) {
			return;
		}

		wp_enqueue_script(
			'bkc-admin',
			BKC_PUSH_URL . 'assets/admin.js',
			[],
			BKC_PUSH_VERSION,
			true
		);

		wp_localize_script(
			'bkc-admin',
			'bkcAdmin',
			[
				'restUrl' => esc_url_raw( rest_url( 'bkc/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Page renderers.
	// -------------------------------------------------------------------------

	/**
	 * Render the dashboard page.
	 */
	public static function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'bkc-push' ) );
		}
		include BKC_PUSH_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Render the compose page (and handle POST).
	 */
	public static function render_compose(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'bkc-push' ) );
		}

		$message = '';
		$error   = '';

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$result = self::handle_compose_post();
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$message = sprintf(
					/* translators: %s: campaign UUID */
					__( '발송 큐에 등록되었습니다. UUID: %s', 'bkc-push' ),
					esc_html( $result['uuid'] )
				);
			}
		}

		// Generate a fresh idempotency token for the form.
		$idempotency_token = wp_generate_uuid4();

		include BKC_PUSH_DIR . 'admin/views/compose.php';
	}

	/**
	 * Render the campaign list page.
	 */
	public static function render_campaigns(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'bkc-push' ) );
		}
		include BKC_PUSH_DIR . 'admin/views/campaign-list.php';
	}

	/**
	 * Render campaign stats page (linked from campaign list).
	 */
	public static function render_campaign_stats(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'bkc-push' ) );
		}
		include BKC_PUSH_DIR . 'admin/views/campaign-stats.php';
	}

	// -------------------------------------------------------------------------
	// Form processing.
	// -------------------------------------------------------------------------

	/**
	 * Process the compose form POST.
	 *
	 * @return array|\WP_Error ['uuid' => string, 'id' => int] or WP_Error.
	 */
	private static function handle_compose_post(): array|\WP_Error {
		// Nonce verification.
		if (
			empty( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bkc_compose' )
		) {
			return new \WP_Error( 'bad_nonce', __( '보안 검사 실패. 다시 시도하세요.', 'bkc-push' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', __( '권한이 없습니다.', 'bkc-push' ) );
		}

		// Idempotency: if this UUID was already used, return the existing campaign.
		$idempotency_token = sanitize_text_field( wp_unslash( $_POST['idempotency_token'] ?? '' ) );
		if ( ! empty( $idempotency_token ) ) {
			$existing = BKC_Campaigns::find_by_uuid( $idempotency_token );
			if ( $existing ) {
				return [ 'uuid' => $existing->uuid, 'id' => (int) $existing->id ];
			}
		}

		$title     = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$body      = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );
		$deep_link = esc_url_raw( wp_unslash( $_POST['deep_link'] ?? '' ) );
		$groups    = isset( $_POST['groups'] ) && is_array( $_POST['groups'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) )
			: [ 'all' ];

		if ( empty( $title ) ) {
			return new \WP_Error( 'missing_title', __( '제목을 입력하세요.', 'bkc-push' ) );
		}
		if ( empty( $body ) ) {
			return new \WP_Error( 'missing_body', __( '내용을 입력하세요.', 'bkc-push' ) );
		}
		if ( ! BKC_Groups::validate_array( $groups ) ) {
			return new \WP_Error( 'invalid_groups', __( '올바르지 않은 그룹입니다.', 'bkc-push' ) );
		}
		if ( ! in_array( 'all', $groups, true ) ) {
			$groups[] = 'all';
		}

		try {
			// Use idempotency_token as UUID if provided and valid UUID format.
			$uuid = ( ! empty( $idempotency_token )
				&& preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $idempotency_token ) )
				? $idempotency_token
				: wp_generate_uuid4();

			// Use direct insert to use the form's idempotency token as UUID.
			global $wpdb;
			$table    = $wpdb->prefix . 'bkc_campaigns';
			$inserted = $wpdb->insert(
				$table,
				[
					'uuid'          => $uuid,
					'title'         => $title,
					'body'          => $body,
					'deep_link'     => $deep_link,
					'target_groups' => wp_json_encode( array_values( $groups ) ),
					'status'        => 'queued',
					'created_by'    => get_current_user_id(),
					'created_at'    => current_time( 'mysql', true ),
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
			);

			if ( ! $inserted ) {
				return new \WP_Error( 'db_error', __( 'DB 저장 실패: ', 'bkc-push' ) . $wpdb->last_error );
			}

			$id = (int) $wpdb->insert_id;
			BKC_Dispatcher::enqueue( $uuid );

			return [ 'uuid' => $uuid, 'id' => $id ];
		} catch ( \Exception $e ) {
			return new \WP_Error( 'exception', $e->getMessage() );
		}
	}
}
