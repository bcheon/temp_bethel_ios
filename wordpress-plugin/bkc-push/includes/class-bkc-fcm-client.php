<?php
/**
 * BKC_FCM_Client — Firebase Cloud Messaging HTTP v1 client.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends push notifications via FCM HTTP v1 API using a service account JWT.
 */
class BKC_FCM_Client {

	/**
	 * Parsed service account JSON data.
	 *
	 * @var array
	 */
	private array $service_account;

	/**
	 * FCM project ID (from service account JSON).
	 *
	 * @var string
	 */
	private string $project_id;

	/**
	 * Load the service account JSON from the configured path.
	 *
	 * @throws \RuntimeException If the file is missing or malformed.
	 */
	public function __construct() {
		$path = BKC_FCM_SERVICE_ACCOUNT_PATH;

		if ( ! file_exists( $path ) ) {
			throw new \RuntimeException( "FCM service account file not found: {$path}" );
		}

		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $json ) {
			throw new \RuntimeException( "Cannot read FCM service account file: {$path}" );
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['project_id'] ) || empty( $data['private_key'] ) ) {
			throw new \RuntimeException( 'FCM service account JSON is invalid or missing required fields.' );
		}

		$this->service_account = $data;
		$this->project_id      = $data['project_id'];
	}

	/**
	 * Obtain a valid OAuth2 access token, using a 50-minute transient cache.
	 *
	 * Signs a JWT with RS256 using the service account private key.
	 *
	 * @return string Bearer access token.
	 * @throws \RuntimeException On JWT signing or token fetch failure.
	 */
	public function get_access_token(): string {
		$cache_key = 'bkc_fcm_access_token_' . md5( $this->project_id );
		$cached    = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}

		$now = time();
		$jwt = $this->build_jwt( $now );

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'timeout' => 15,
				'body'    => [
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'FCM token fetch failed: ' . $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			throw new \RuntimeException( "FCM token fetch returned HTTP {$code}: " . wp_remote_retrieve_body( $response ) );
		}

		// Cache for 50 minutes (tokens expire after 60 min).
		set_transient( $cache_key, $body['access_token'], 50 * MINUTE_IN_SECONDS );

		return $body['access_token'];
	}

	/**
	 * Send a push notification to the given group(s).
	 *
	 * IRON RULE — condition assembly logic:
	 * - 'all' in groups → use topic 'bkc_all' (no condition)
	 * - Single non-all group → use topic directly
	 * - 2-5 groups (none is 'all') → build condition string with ||
	 * - >5 groups → throws (out of v1 scope)
	 *
	 * @param string $title         Notification title.
	 * @param string $body          Notification body.
	 * @param string $deep_link     Optional deep link URL.
	 * @param array  $target_groups Group identifiers.
	 * @param string $campaign_uuid Campaign UUID for custom data payload.
	 * @return array{message_id: string|null, error: string|null}
	 */
	public function send_to_groups(
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

		// Build message target.
		$message = [];

		if ( in_array( 'all', $target_groups, true ) ) {
			// IRON RULE: 'all' always uses topic, never condition.
			$message['topic'] = BKC_Groups::to_topic( 'all' );
		} elseif ( 1 === $count ) {
			$message['topic'] = BKC_Groups::to_topic( $target_groups[0] );
		} else {
			// Build OR condition string for 2-5 topics.
			$topic_conditions = array_map(
				static function ( string $group ): string {
					$topic = BKC_Groups::to_topic( $group );
					return "'{$topic}' in topics";
				},
				$target_groups
			);
			$message['condition'] = implode( ' || ', $topic_conditions );
		}

		// Custom data payload.
		$data = [ 'campaign_uuid' => $campaign_uuid ];
		if ( ! empty( $deep_link ) ) {
			$data['deep_link'] = $deep_link;
		}

		// APNS-specific settings: mutable-content always set.
		$apns = [
			'headers' => [
				'apns-priority' => '10',
			],
			'payload' => [
				'aps' => [
					'mutable-content' => 1,
					'alert'           => [
						'title' => $title,
						'body'  => $body,
					],
				],
			],
		];

		$message['data']         = $data;
		$message['apns']         = $apns;
		$message['notification'] = [
			'title' => $title,
			'body'  => $body,
		];

		$payload = [ 'message' => $message ];

		try {
			$token = $this->get_access_token();
		} catch ( \RuntimeException $e ) {
			return [
				'message_id' => null,
				'error'      => $e->getMessage(),
			];
		}

		$url      = "https://fcm.googleapis.com/v1/projects/{$this->project_id}/messages:send";
		$response = wp_remote_post(
			$url,
			[
				'timeout' => 30,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json; charset=UTF-8',
				],
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'message_id' => null,
				'error'      => $response->get_error_message(),
			];
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		$http_code     = (int) wp_remote_retrieve_response_code( $response );

		if ( $http_code >= 200 && $http_code < 300 && ! empty( $response_body['name'] ) ) {
			return [
				'message_id' => $response_body['name'],
				'error'      => null,
			];
		}

		$error_message = $response_body['error']['message'] ?? wp_remote_retrieve_body( $response );
		return [
			'message_id' => null,
			'error'      => "HTTP {$http_code}: {$error_message}",
		];
	}

	/**
	 * Determine if an FCM API response indicates an unregistered/invalid token.
	 *
	 * @param array $response Result from send_to_groups().
	 * @return bool True if the token is unregistered and should be removed.
	 */
	public static function is_unregistered_error( array $response ): bool {
		if ( empty( $response['error'] ) ) {
			return false;
		}
		$error = strtolower( $response['error'] );
		return (
			str_contains( $error, 'unregistered' )
			|| str_contains( $error, 'not found' )
			|| str_contains( $error, 'invalid registration' )
		);
	}

	/**
	 * Build a signed JWT for the OAuth2 token request.
	 *
	 * @param int $now Current Unix timestamp.
	 * @return string Signed JWT string.
	 * @throws \RuntimeException On signing failure.
	 */
	private function build_jwt( int $now ): string {
		$header = $this->base64url_encode( (string) wp_json_encode( [
			'alg' => 'RS256',
			'typ' => 'JWT',
		] ) );

		$claims = $this->base64url_encode( (string) wp_json_encode( [
			'iss'   => $this->service_account['client_email'],
			'sub'   => $this->service_account['client_email'],
			'aud'   => 'https://oauth2.googleapis.com/token',
			'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
			'iat'   => $now,
			'exp'   => $now + 3600,
		] ) );

		$signing_input = $header . '.' . $claims;
		$private_key   = $this->service_account['private_key'];

		$signature = '';
		$result    = openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );

		if ( ! $result ) {
			throw new \RuntimeException( 'Failed to sign JWT: ' . openssl_error_string() );
		}

		return $signing_input . '.' . $this->base64url_encode( $signature );
	}

	/**
	 * URL-safe Base64 encode without padding.
	 *
	 * @param string $data Raw data to encode.
	 * @return string
	 */
	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
