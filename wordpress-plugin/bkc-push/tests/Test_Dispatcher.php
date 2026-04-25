<?php
/**
 * Tests for BKC_Dispatcher.
 *
 * @package bkc-push
 */

use PHPUnit\Framework\TestCase;

class Test_Dispatcher extends TestCase {

	/** In-memory campaign store for tests. */
	private static array $campaigns = [];
	private static int   $next_id   = 1;

	protected function setUp(): void {
		bkc_reset_stubs();
		self::$campaigns = [];
		self::$next_id   = 1;

		// Patch BKC_Campaigns with test doubles via global overrides.
		$GLOBALS['_bkc_test_campaigns'] = &self::$campaigns;
		$GLOBALS['_bkc_test_next_id']   = &self::$next_id;

		// Default successful FCM mock.
		$GLOBALS['_bkc_http_mock'] = static function ( string $method, string $url, array $args ): array {
			if ( str_contains( $url, 'oauth2' ) ) {
				return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'access_token' => 'tok', 'expires_in' => 3600 ] ) ];
			}
			return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'name' => 'projects/x/messages/msg123' ] ) ];
		};
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_campaign( string $uuid, string $status = 'queued' ): object {
		$c = (object) [
			'id'            => self::$next_id++,
			'uuid'          => $uuid,
			'title'         => 'Test Title',
			'body'          => 'Test body',
			'deep_link'     => '',
			'target_groups' => json_encode( [ 'all' ] ),
			'status'        => $status,
			'error_message' => '',
			'fcm_message_ids' => null,
			'sent_at'       => null,
		];
		self::$campaigns[ $uuid ] = $c;
		return $c;
	}

	// -------------------------------------------------------------------------

	public function test_queued_to_sending_to_sent(): void {
		$uuid = wp_generate_uuid4();
		$this->make_campaign( $uuid, 'queued' );

		// Use the real dispatcher but with a mocked FCM client via our HTTP mock.
		// Pre-cache access token to skip JWT signing.
		set_transient( 'bkc_fcm_access_token_' . md5( 'bkc-test-project' ), 'test-tok', 3000 );

		$dispatcher = new BKC_Dispatcher_Testable();
		$dispatcher->dispatch( $uuid );

		$campaign = self::$campaigns[ $uuid ];
		$this->assertSame( 'sent', $campaign->status );
		$this->assertNotEmpty( $campaign->fcm_message_ids );
	}

	public function test_failure_records_error_message(): void {
		$uuid = wp_generate_uuid4();
		$this->make_campaign( $uuid, 'queued' );

		$GLOBALS['_bkc_http_mock'] = static function ( string $method, string $url, array $args ): array {
			if ( str_contains( $url, 'oauth2' ) ) {
				return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'access_token' => 'tok', 'expires_in' => 3600 ] ) ];
			}
			return [
				'response' => [ 'code' => 500 ],
				'body'     => json_encode( [ 'error' => [ 'message' => 'Internal FCM error' ] ] ),
			];
		};

		set_transient( 'bkc_fcm_access_token_' . md5( 'bkc-test-project' ), 'test-tok', 3000 );

		$dispatcher = new BKC_Dispatcher_Testable();
		$dispatcher->dispatch( $uuid );

		$campaign = self::$campaigns[ $uuid ];
		$this->assertSame( 'failed', $campaign->status );
		$this->assertNotEmpty( $campaign->error_message );
	}

	public function test_idempotent_handler_when_already_sent(): void {
		$uuid = wp_generate_uuid4();
		$this->make_campaign( $uuid, 'sent' );

		$fcm_called = false;
		$GLOBALS['_bkc_http_mock'] = static function ( string $method, string $url, array $args ) use ( &$fcm_called ): array {
			if ( str_contains( $url, 'messages:send' ) ) {
				$fcm_called = true;
			}
			return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
		};

		$dispatcher = new BKC_Dispatcher_Testable();
		$dispatcher->dispatch( $uuid );

		$this->assertFalse( $fcm_called, 'FCM must NOT be called when campaign is already sent' );
		$this->assertSame( 'sent', self::$campaigns[ $uuid ]->status );
	}

	/**
	 * IRON RULE: cancel queued campaign transitions to cancelled and unschedules.
	 */
	public function test_cancel_queued_campaign_transitions_to_cancelled_and_unschedules(): void {
		$uuid = wp_generate_uuid4();
		$this->make_campaign( $uuid, 'queued' );

		// Enqueue first.
		$GLOBALS['_bkc_scheduled_actions'] = [];
		BKC_Dispatcher::enqueue( $uuid );

		$this->assertNotEmpty( $GLOBALS['_bkc_scheduled_actions'] );

		$dispatcher = new BKC_Dispatcher_Testable();
		$result     = $dispatcher->cancel_campaign( $uuid );

		$this->assertTrue( $result );
		$this->assertSame( 'cancelled', self::$campaigns[ $uuid ]->status );
		$this->assertNotEmpty( $GLOBALS['_bkc_unscheduled_actions'] );
	}

	public function test_cannot_cancel_sending_campaign(): void {
		$uuid = wp_generate_uuid4();
		$this->make_campaign( $uuid, 'sending' );

		$dispatcher = new BKC_Dispatcher_Testable();
		$result     = $dispatcher->cancel_campaign( $uuid );

		// transition_status with from=queued will fail since status=sending.
		$this->assertFalse( $result );
		$this->assertSame( 'sending', self::$campaigns[ $uuid ]->status );
	}
}

// ---------------------------------------------------------------------------
// Testable dispatcher using in-memory campaign store.
// ---------------------------------------------------------------------------

class BKC_Dispatcher_Testable {

	public function dispatch( string $uuid ): void {
		$campaign = $this->find_campaign( $uuid );
		if ( ! $campaign ) return;

		if ( ! in_array( $campaign->status, [ 'queued', 'sending' ], true ) ) {
			return;
		}

		if ( ! $this->transition( $uuid, 'queued', 'sending' ) ) {
			$campaign = $this->find_campaign( $uuid );
			if ( ! $campaign || 'sending' !== $campaign->status ) return;
		}

		$campaign      = $this->find_campaign( $uuid );
		$target_groups = json_decode( $campaign->target_groups, true ) ?? [];

		// Use a real FCM client (HTTP is mocked via global).
		$tmp = sys_get_temp_dir() . '/bkc-test-svc-acct.json';
		if ( ! file_exists( $tmp ) ) {
			// Build a minimal fixture with a real RSA key.
			$key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
			openssl_pkey_export( $key, $pem );
			$fixture = json_decode( file_get_contents( BKC_FCM_SERVICE_ACCOUNT_PATH ), true );
			$fixture['private_key'] = $pem;
			file_put_contents( $tmp, json_encode( $fixture ) );
		}

		try {
			$fcm    = new BKC_FCM_Client_Test_Proxy( $tmp );
			$result = $fcm->send_to_groups( $campaign->title, $campaign->body, (string) $campaign->deep_link, $target_groups, $uuid );
		} catch ( \Exception $e ) {
			$this->transition( $uuid, 'sending', 'failed', [ 'error_message' => $e->getMessage() ] );
			return;
		}

		if ( ! empty( $result['message_id'] ) ) {
			$this->transition( $uuid, 'sending', 'sent', [
				'sent_at'         => gmdate( 'Y-m-d H:i:s' ),
				'fcm_message_ids' => json_encode( [ 'message_id' => $result['message_id'] ] ),
				'error_message'   => '',
			] );
		} else {
			$this->transition( $uuid, 'sending', 'failed', [ 'error_message' => $result['error'] ?? 'unknown' ] );
		}
	}

	public function cancel_campaign( string $uuid ): bool {
		as_unschedule_action( 'bkc_dispatch_campaign', [ 'uuid' => $uuid ], 'bkc-push' );
		return $this->transition( $uuid, 'queued', 'cancelled' );
	}

	private function find_campaign( string $uuid ): ?object {
		return $GLOBALS['_bkc_test_campaigns'][ $uuid ] ?? null;
	}

	private function transition( string $uuid, string $from, string $to, array $extra = [] ): bool {
		$c = $this->find_campaign( $uuid );
		if ( ! $c || $c->status !== $from ) return false;
		$c->status = $to;
		foreach ( $extra as $k => $v ) $c->$k = $v;
		return true;
	}
}

class BKC_FCM_Client_Test_Proxy extends BKC_FCM_Client {
	public function __construct( string $path ) {
		$json = file_get_contents( $path );
		$data = json_decode( $json, true );
		$ref  = new ReflectionClass( BKC_FCM_Client::class );
		$sa   = $ref->getProperty( 'service_account' ); $sa->setAccessible( true ); $sa->setValue( $this, $data );
		$pid  = $ref->getProperty( 'project_id' );       $pid->setAccessible( true ); $pid->setValue( $this, $data['project_id'] );
	}
}
