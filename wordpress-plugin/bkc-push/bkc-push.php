<?php
/**
 * Plugin Name: BKC Push
 * Plugin URI:  https://bkc.org
 * Description: FCM-based push notification management for BKC Church iOS app.
 * Version:     1.0.0
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Author:      BKC Church
 * Text Domain: bkc-push
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

// Define service account path constant if not already defined.
if ( ! defined( 'BKC_FCM_SERVICE_ACCOUNT_PATH' ) ) {
	define(
		'BKC_FCM_SERVICE_ACCOUNT_PATH',
		getenv( 'BKC_FCM_SVC_ACCT' ) ?: '/var/www/bkc-secrets/fcm-service-account.json'
	);
}

define( 'BKC_PUSH_VERSION', '1.0.0' );
define( 'BKC_PUSH_DIR', plugin_dir_path( __FILE__ ) );
define( 'BKC_PUSH_URL', plugin_dir_url( __FILE__ ) );

// Include class files.
require_once BKC_PUSH_DIR . 'includes/class-bkc-groups.php';
require_once BKC_PUSH_DIR . 'includes/class-bkc-rate-limiter.php';
require_once BKC_PUSH_DIR . 'includes/class-bkc-subscriptions.php';
require_once BKC_PUSH_DIR . 'includes/class-bkc-campaigns.php';
require_once BKC_PUSH_DIR . 'includes/class-bkc-events.php';
require_once BKC_PUSH_DIR . 'includes/class-bkc-fcm-client.php';
require_once BKC_PUSH_DIR . 'includes/class-bkc-dispatcher.php';
require_once BKC_PUSH_DIR . 'includes/class-bkc-stats-rollup.php';
require_once BKC_PUSH_DIR . 'includes/class-bkc-rest-api.php';
require_once BKC_PUSH_DIR . 'admin/class-bkc-admin.php';

// Activation hook — run DB migrations.
register_activation_hook( __FILE__, 'bkc_push_activate' );

/**
 * Run DB migrations on activation.
 */
function bkc_push_activate(): void {
	bkc_push_run_migrations();
}

/**
 * Create or upgrade plugin database tables using dbDelta.
 */
function bkc_push_run_migrations(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$wpdb->prefix}bkc_campaigns (
		id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
		uuid CHAR(36) NOT NULL,
		title VARCHAR(255) NOT NULL,
		body TEXT NOT NULL,
		deep_link VARCHAR(2048) DEFAULT NULL,
		target_groups LONGTEXT NOT NULL,
		status ENUM('draft','queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
		scheduled_at DATETIME NULL DEFAULT NULL,
		sent_at DATETIME NULL DEFAULT NULL,
		fcm_message_ids LONGTEXT NULL DEFAULT NULL,
		error_message TEXT NULL DEFAULT NULL,
		created_by BIGINT UNSIGNED NOT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY uuid (uuid),
		KEY idx_status (status),
		KEY idx_created (created_at)
	) $charset_collate;";

	dbDelta( $sql );

	$sql = "CREATE TABLE {$wpdb->prefix}bkc_subscriptions (
		id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
		fcm_token VARCHAR(512) NOT NULL,
		device_id CHAR(36) NOT NULL,
		platform ENUM('ios','android') NOT NULL DEFAULT 'ios',
		app_version VARCHAR(32) DEFAULT NULL,
		groups LONGTEXT NOT NULL,
		last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY unique_device (device_id),
		KEY idx_last_seen (last_seen)
	) $charset_collate;";

	dbDelta( $sql );

	$sql = "CREATE TABLE {$wpdb->prefix}bkc_campaign_events (
		id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
		campaign_uuid CHAR(36) NOT NULL,
		device_id CHAR(36) NOT NULL,
		event_type ENUM('delivered','opened','deeplinked') NOT NULL,
		occurred_at DATETIME NOT NULL,
		server_received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		KEY idx_campaign (campaign_uuid),
		KEY idx_device_time (device_id, occurred_at),
		UNIQUE KEY unique_event (device_id, campaign_uuid, event_type)
	) $charset_collate;";

	dbDelta( $sql );

	$sql = "CREATE TABLE {$wpdb->prefix}bkc_campaign_stats (
		campaign_uuid CHAR(36) NOT NULL,
		subscribers_targeted INT NOT NULL DEFAULT 0,
		delivered_count INT NOT NULL DEFAULT 0,
		opened_count INT NOT NULL DEFAULT 0,
		deeplinked_count INT NOT NULL DEFAULT 0,
		last_rolled_up DATETIME NULL DEFAULT NULL,
		PRIMARY KEY (campaign_uuid)
	) $charset_collate;";

	dbDelta( $sql );
}

// Deactivation hook — unschedule Action Scheduler jobs.
register_deactivation_hook( __FILE__, 'bkc_push_deactivate' );

/**
 * Clean up scheduled actions on deactivation.
 */
function bkc_push_deactivate(): void {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'bkc_dispatch_campaign', [], 'bkc-push' );
		as_unschedule_all_actions( 'bkc_stats_rollup', [], 'bkc-push' );
	}
}

// WordPress hooks.
add_action( 'admin_menu', [ 'BKC_Admin', 'add_menu' ] );
add_action( 'admin_enqueue_scripts', [ 'BKC_Admin', 'enqueue_scripts' ] );
add_action( 'rest_api_init', [ 'BKC_REST_API', 'register_routes' ] );
add_action( 'init', 'bkc_push_init_action_scheduler' );

// Action Scheduler action handlers.
add_action( 'bkc_dispatch_campaign', [ 'BKC_Dispatcher', 'dispatch_handler' ] );
add_action( 'bkc_stats_rollup', [ 'BKC_Stats_Rollup', 'rollup_handler' ] );

/**
 * Initialize Action Scheduler recurring jobs on 'init'.
 */
function bkc_push_init_action_scheduler(): void {
	if ( function_exists( 'as_has_scheduled_action' ) ) {
		BKC_Stats_Rollup::register_cron();
	}
}
