<?php
/**
 * Minimal WordPress function stubs for offline unit tests.
 *
 * Only the functions actually used by the plugin classes are stubbed here.
 *
 * @package bkc-push
 */

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		if ( is_string( $value ) ) {
			return stripslashes( $value );
		}
		return $value;
	}
}

// Minimal $wpdb stub: returns canned values per call type. Tests that need
// realistic DB behavior should inject their own Testable variant instead.
if ( ! class_exists( 'BKC_WPDB_Stub' ) ) {
	class BKC_WPDB_Stub {
		public string $prefix = 'wp_';
		public function prepare( string $sql, ...$args ): string {
			return vsprintf( str_replace( [ '%s', '%d', '%f' ], "'%s'", $sql ), $args );
		}
		public function get_var( $query = null ) { return 1; } // pretend the row exists
		public function query( $query = null ): int { return 1; } // pretend insert succeeded
		public function get_results( $query = null, $output = OBJECT ): array { return []; }
		public function get_row( $query = null, $output = OBJECT, $y = 0 ) { return null; }
	}
}
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new BKC_WPDB_Stub();
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return strip_tags( trim( $str ) );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $data ): string {
		return $data;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( string $sql ): string {
		return addslashes( $sql );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['_bkc_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		$GLOBALS['_bkc_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['_bkc_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		return $GLOBALS['_bkc_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value, $autoload = null ): bool {
		$GLOBALS['_bkc_options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = [] ) {
		return $GLOBALS['_bkc_http_mock']( 'POST', $url, $args );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return (int) ( $response['response']['code'] ?? 200 );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( int $timestamp, string $hook, array $args = [], string $group = '' ): int {
		$GLOBALS['_bkc_scheduled_actions'][] = compact( 'timestamp', 'hook', 'args', 'group' );
		return count( $GLOBALS['_bkc_scheduled_actions'] );
	}
}

if ( ! function_exists( 'as_unschedule_action' ) ) {
	function as_unschedule_action( string $hook, array $args = [], string $group = '' ): void {
		$GLOBALS['_bkc_unscheduled_actions'][] = compact( 'hook', 'args', 'group' );
	}
}

if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	function as_schedule_recurring_action( int $timestamp, int $interval_s, string $hook, array $args = [], string $group = '' ): int {
		$GLOBALS['_bkc_scheduled_actions'][] = compact( 'timestamp', 'interval_s', 'hook', 'args', 'group' );
		return count( $GLOBALS['_bkc_scheduled_actions'] );
	}
}

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action( string $hook, array $args = [], string $group = '' ): bool {
		foreach ( $GLOBALS['_bkc_scheduled_actions'] ?? [] as $action ) {
			if ( $action['hook'] === $hook && $action['group'] === $group ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( string $hook, array $args = [], string $group = '' ): void {
		// stub — no-op in tests
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return $GLOBALS['_bkc_current_user_can'][ $capability ] ?? false;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return $GLOBALS['_bkc_current_user_id'] ?? 1;
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $data ) {
		return new WP_REST_Response( $data );
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = [] ): bool {
		$GLOBALS['_bkc_rest_routes'][] = compact( 'namespace', 'route', 'args' );
		return true;
	}
}

// ---------------------------------------------------------------------------
// Minimal class stubs
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private array  $data;

		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = (array) $data;
		}

		public function get_error_code(): string    { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array     { return $this->data; }
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public mixed $data;
		public int   $status;

		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data(): mixed  { return $this->data; }
		public function get_status(): int  { return $this->status; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = [];
		private array $json   = [];

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function set_json_params( array $json ): void {
			$this->json = $json;
		}

		public function get_json_params(): array {
			return $this->json;
		}
	}
}

// ---------------------------------------------------------------------------
// Global state reset helper (called by test setUp)
// ---------------------------------------------------------------------------
function bkc_reset_stubs(): void {
	$GLOBALS['_bkc_transients']          = [];
	$GLOBALS['_bkc_options']             = [];
	$GLOBALS['_bkc_scheduled_actions']   = [];
	$GLOBALS['_bkc_unscheduled_actions'] = [];
	$GLOBALS['_bkc_rest_routes']         = [];
	$GLOBALS['_bkc_current_user_can']    = [];
	$GLOBALS['_bkc_current_user_id']     = 1;
	$GLOBALS['_bkc_http_mock']           = static function ( string $method, string $url, array $args ): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => '{}',
		];
	};
	// Restore the default $wpdb stub in case a test swapped in its own mock.
	$GLOBALS['wpdb'] = new BKC_WPDB_Stub();
}

bkc_reset_stubs();
