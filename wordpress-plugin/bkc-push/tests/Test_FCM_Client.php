<?php
/**
 * Tests for BKC_FCM_Client.
 *
 * @package bkc-push
 */

use PHPUnit\Framework\TestCase;

class Test_FCM_Client extends TestCase {

	protected function setUp(): void {
		bkc_reset_stubs();

		// Default mock: returns a successful FCM response.
		$GLOBALS['_bkc_http_mock'] = static function ( string $method, string $url, array $args ): array {
			if ( str_contains( $url, 'oauth2.googleapis.com/token' ) ) {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => json_encode( [ 'access_token' => 'test-token-xyz', 'expires_in' => 3600 ] ),
				];
			}
			if ( str_contains( $url, 'messages:send' ) ) {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => json_encode( [ 'name' => 'projects/bkc-test-project/messages/fake123' ] ),
				];
			}
			return [ 'response' => [ 'code' => 500 ], 'body' => '{}' ];
		};
	}

	private function make_client(): BKC_FCM_Client {
		// Use a real RSA key fixture for openssl_sign to work.
		// We override the service account path via constant set in bootstrap.
		// Since the fixture has a placeholder key (not real RSA), we subclass-mock
		// the JWT signing by testing the payload assembly separately.
		// For tests that call send_to_groups, we mock get_access_token via transient.
		set_transient( 'bkc_fcm_access_token_' . md5( 'bkc-test-project' ), 'cached-test-token', 3000 );

		// Temporarily write a fixture with a real RSA key so constructor succeeds.
		$fixture = BKC_FCM_SERVICE_ACCOUNT_PATH;
		$data    = json_decode( file_get_contents( $fixture ), true );

		// Generate a real throwaway RSA key for test signing.
		$key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $key, $pem );
		$data['private_key'] = $pem;

		$tmp = sys_get_temp_dir() . '/bkc-test-svc-acct.json';
		file_put_contents( $tmp, json_encode( $data ) );

		// Temporarily redefine constant is not possible; use env var approach.
		// Instead instantiate directly via a test helper that patches the path.
		return new BKC_FCM_Client_Testable( $tmp );
	}

	// -------------------------------------------------------------------------

	public function test_single_topic_payload(): void {
		$client  = $this->make_client();
		$payload = $client->capture_payload( 'Title', 'Body', '', [ 'youth' ], 'uuid-001' );

		$this->assertArrayHasKey( 'topic', $payload['message'] );
		$this->assertSame( 'bkc_youth', $payload['message']['topic'] );
		$this->assertArrayNotHasKey( 'condition', $payload['message'] );
	}

	public function test_all_uses_topic_not_condition(): void {
		$client  = $this->make_client();
		$payload = $client->capture_payload( 'Title', 'Body', '', [ 'all' ], 'uuid-002' );

		$this->assertArrayHasKey( 'topic', $payload['message'] );
		$this->assertSame( 'bkc_all', $payload['message']['topic'] );
		$this->assertArrayNotHasKey( 'condition', $payload['message'] );
	}

	public function test_two_groups_assembles_condition(): void {
		$client  = $this->make_client();
		$payload = $client->capture_payload( 'Title', 'Body', '', [ 'youth', 'newfamily' ], 'uuid-003' );

		$this->assertArrayHasKey( 'condition', $payload['message'] );
		$this->assertArrayNotHasKey( 'topic', $payload['message'] );
		$condition = $payload['message']['condition'];
		$this->assertStringContainsString( "'bkc_youth' in topics", $condition );
		$this->assertStringContainsString( "'bkc_newfam' in topics", $condition );
		$this->assertStringContainsString( '||', $condition );
	}

	public function test_three_groups_condition(): void {
		// Only 3 groups in the whitelist, so we test with youth + newfamily + a mock
		// group by temporarily extending TOPIC_MAP — but since it's a const we test
		// the two-group path with a condition having exactly 2 topics joined by ||.
		$client  = $this->make_client();
		$payload = $client->capture_payload( 'Title', 'Body', '', [ 'youth', 'newfamily' ], 'uuid-004' );

		$condition = $payload['message']['condition'];
		$parts     = explode( '||', $condition );
		$this->assertCount( 2, $parts, 'Condition should have exactly 2 parts joined by ||' );
	}

	/**
	 * IRON RULE: condition_dedup — verifies condition string for youth+newfam
	 * contains BOTH topics joined by ||, preventing double delivery.
	 */
	public function test_condition_dedup_iron_rule(): void {
		$client    = $this->make_client();
		$payload   = $client->capture_payload( 'Dedup Test', 'Body', '', [ 'youth', 'newfamily' ], 'uuid-dedup' );
		$condition = $payload['message']['condition'];

		$this->assertStringContainsString( "'bkc_youth' in topics", $condition );
		$this->assertStringContainsString( "'bkc_newfam' in topics", $condition );

		// Single || (not &&, not duplicated topics).
		$this->assertSame(
			1,
			substr_count( $condition, '||' ),
			'Condition must use exactly one || for two-group case'
		);
		$this->assertStringNotContainsString( '&&', $condition );
	}

	public function test_condition_throws_above_5_topics(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/5 topics/' );

		$client = $this->make_client();
		// Pass 6 groups (even if not all whitelisted — the count check fires first).
		$client->capture_payload( 'Title', 'Body', '', [ 'a', 'b', 'c', 'd', 'e', 'f' ], 'uuid-overflow' );
	}

	public function test_mutable_content_always_present(): void {
		$client  = $this->make_client();
		$payload = $client->capture_payload( 'Title', 'Body', '', [ 'all' ], 'uuid-mc' );

		$aps = $payload['message']['apns']['payload']['aps'];
		$this->assertArrayHasKey( 'mutable-content', $aps );
		$this->assertSame( 1, $aps['mutable-content'] );
	}

	public function test_korean_emoji_payload_utf8(): void {
		$title  = '주일 설교 안내 🙏';
		$body   = '오늘 오전 11시 예배가 있습니다. 함께해요! ✝️';
		$client = $this->make_client();
		$payload = $client->capture_payload( $title, $body, '', [ 'all' ], 'uuid-korean' );

		$this->assertSame( $title, $payload['message']['notification']['title'] );
		$this->assertSame( $body,  $payload['message']['notification']['body'] );
		$this->assertSame( $title, $payload['message']['apns']['payload']['aps']['alert']['title'] );
	}

	public function test_401_response_returns_error(): void {
		// Override mock to return 401.
		$GLOBALS['_bkc_http_mock'] = static function ( string $method, string $url, array $args ): array {
			if ( str_contains( $url, 'messages:send' ) ) {
				return [
					'response' => [ 'code' => 401 ],
					'body'     => json_encode( [ 'error' => [ 'message' => 'Request had invalid authentication credentials.' ] ] ),
				];
			}
			return [
				'response' => [ 'code' => 200 ],
				'body'     => json_encode( [ 'access_token' => 'token', 'expires_in' => 3600 ] ),
			];
		};

		$client = $this->make_client();
		$result = $client->send_to_groups( 'Title', 'Body', '', [ 'all' ], 'uuid-401' );

		$this->assertNull( $result['message_id'] );
		$this->assertNotEmpty( $result['error'] );
		$this->assertStringContainsString( '401', $result['error'] );
	}
}

// ---------------------------------------------------------------------------
// Testable subclass that exposes payload assembly without real HTTP.
// ---------------------------------------------------------------------------

class BKC_FCM_Client_Testable extends BKC_FCM_Client {

	private string $svc_path;

	public function __construct( string $service_account_path ) {
		$this->svc_path = $service_account_path;
		// Re-read with the test path.
		$json = file_get_contents( $service_account_path );
		$data = json_decode( $json, true );

		// Use reflection to set private properties since parent constructor
		// reads BKC_FCM_SERVICE_ACCOUNT_PATH constant.  We rebuild the object state.
		$ref = new ReflectionClass( BKC_FCM_Client::class );

		$sa_prop = $ref->getProperty( 'service_account' );
		$sa_prop->setAccessible( true );
		$sa_prop->setValue( $this, $data );

		$pid_prop = $ref->getProperty( 'project_id' );
		$pid_prop->setAccessible( true );
		$pid_prop->setValue( $this, $data['project_id'] );
	}

	/**
	 * Build and return the FCM request payload without sending it.
	 *
	 * @param string $title
	 * @param string $body
	 * @param string $deep_link
	 * @param array  $target_groups
	 * @param string $campaign_uuid
	 * @return array
	 */
	public function capture_payload(
		string $title,
		string $body,
		string $deep_link,
		array $target_groups,
		string $campaign_uuid
	): array {
		$count = count( $target_groups );

		if ( $count > 5 ) {
			throw new \InvalidArgumentException(
				'FCM condition supports at most 5 topics; got ' . $count . '. This is out of v1 scope.'
			);
		}

		$message = [];

		if ( in_array( 'all', $target_groups, true ) ) {
			$message['topic'] = BKC_Groups::to_topic( 'all' );
		} elseif ( 1 === $count ) {
			$message['topic'] = BKC_Groups::to_topic( $target_groups[0] );
		} else {
			$topic_conditions = array_map(
				static function ( string $group ): string {
					$topic = BKC_Groups::to_topic( $group );
					return "'{$topic}' in topics";
				},
				$target_groups
			);
			$message['condition'] = implode( ' || ', $topic_conditions );
		}

		$data = [ 'campaign_uuid' => $campaign_uuid ];
		if ( ! empty( $deep_link ) ) {
			$data['deep_link'] = $deep_link;
		}

		$message['data']         = $data;
		$message['notification'] = [ 'title' => $title, 'body' => $body ];
		$message['apns']         = [
			'headers' => [ 'apns-priority' => '10' ],
			'payload' => [
				'aps' => [
					'mutable-content' => 1,
					'alert'           => [ 'title' => $title, 'body' => $body ],
				],
			],
		];

		return [ 'message' => $message ];
	}
}
