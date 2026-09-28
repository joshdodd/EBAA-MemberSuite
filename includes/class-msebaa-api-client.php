<?php
/**
 * MemberSuite REST API client.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTPS client for MemberSuite Outside SSO and related REST calls.
 *
 * Passwords and tokens must never be logged or echoed to the browser.
 */
class Msebaa_Api_Client {

	/**
	 * MemberSuite REST base URL (HTTPS only).
	 */
	public const BASE_URL = 'https://rest.membersuite.com';

	/**
	 * Transient holding the API service-account idToken.
	 */
	public const ID_TOKEN_TRANSIENT = 'msebaa_api_id_token';

	/**
	 * idToken cache TTL in seconds (4 hours, under MemberSuite's ~5 hour validity).
	 */
	public const ID_TOKEN_TTL = 14400;

	/**
	 * Locked password-reset operation (Security Swagger, reconfirmed 2026-09-28).
	 *
	 * GET only. Sends the association password-reset template. No CRM writes.
	 */
	public const PASSWORD_RESET_OPERATION = 'PortalUsers_SendForgottenPortalPasswordEmail';

	/**
	 * Path template for PASSWORD_RESET_OPERATION. One %s placeholder: tenant id.
	 */
	public const PASSWORD_RESET_PATH = '/security/v1/portalUsers/%s/sendForgottenPortalPasswordEmail';

	/**
	 * HTTP timeout from settings (seconds).
	 */
	private int $timeout;

	/**
	 * Tenant / partition ID from settings.
	 */
	private string $tenant_id;

	/**
	 * API service-account email from settings.
	 */
	private string $api_user_email;

	/**
	 * API service-account password from settings (never logged).
	 */
	private string $api_user_password;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null $settings Optional settings override; defaults to msebaa_get_settings().
	 */
	public function __construct( ?array $settings = null ) {
		$settings        = is_array( $settings ) ? $settings : msebaa_get_settings();
		$this->tenant_id = isset( $settings['tenant_id'] ) ? (string) $settings['tenant_id'] : '';
		$this->timeout   = isset( $settings['http_timeout'] )
			? absint( $settings['http_timeout'] )
			: Msebaa_Settings::DEFAULT_HTTP_TIMEOUT;

		$this->api_user_email    = isset( $settings['api_user_email'] ) ? (string) $settings['api_user_email'] : '';
		$this->api_user_password = isset( $settings['api_user_password'] ) ? (string) $settings['api_user_password'] : '';
	}

	/**
	 * Register hooks that keep the cached idToken in step with settings.
	 */
	public static function register(): void {
		add_action(
			'update_option_' . Msebaa_Settings::OPTION_KEY,
			array( __CLASS__, 'maybe_flush_id_token' ),
			10,
			2
		);
	}

	/**
	 * Drop the cached idToken when the credentials behind it change.
	 *
	 * @param mixed $old_value Previous settings array.
	 * @param mixed $value     New settings array.
	 */
	public static function maybe_flush_id_token( $old_value, $value ): void {
		$old = is_array( $old_value ) ? $old_value : array();
		$new = is_array( $value ) ? $value : array();

		foreach ( array( 'tenant_id', 'api_user_email', 'api_user_password' ) as $key ) {
			if ( (string) ( $old[ $key ] ?? '' ) !== (string) ( $new[ $key ] ?? '' ) ) {
				self::flush_id_token();

				return;
			}
		}
	}

	/**
	 * Discard the cached API service-account idToken.
	 */
	public static function flush_id_token(): void {
		delete_transient( self::ID_TOKEN_TRANSIENT );
	}

	/**
	 * Authenticate with email/password via loginUser.
	 *
	 * @param string $email    MemberSuite email.
	 * @param string $password MemberSuite password (never logged).
	 * @return array{id_token: string, access_token: string, refresh_token: string}|WP_Error
	 */
	public function login_user( string $email, string $password ) {
		$data = $this->post_login_user( $email, $password );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$id_token      = $this->string_field( $data, array( 'idToken', 'id_token' ) );
		$access_token  = $this->string_field( $data, array( 'accessToken', 'access_token' ) );
		$refresh_token = $this->string_field( $data, array( 'refreshToken', 'refresh_token' ) );

		if ( '' === $id_token || '' === $access_token || '' === $refresh_token ) {
			return new WP_Error(
				'msebaa_login_failed',
				__( 'Sign-in failed. Please try again or contact support.', 'membersuite-ebaa' )
			);
		}

		return array(
			'id_token'      => $id_token,
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
		);
	}

	/**
	 * Authenticate as the API service account and return its idToken.
	 *
	 * Separate from login_user(): service-account auth only needs the idToken,
	 * and its failures are operator problems rather than member sign-in problems.
	 *
	 * @return string|WP_Error
	 */
	public function login_api_user() {
		if ( '' === $this->api_user_email || '' === $this->api_user_password ) {
			return new WP_Error(
				'msebaa_missing_api_credentials',
				__( 'MemberSuite API credentials are not configured.', 'membersuite-ebaa' )
			);
		}

		$data = $this->post_login_user( $this->api_user_email, $this->api_user_password );
		if ( is_wp_error( $data ) ) {
			return new WP_Error(
				'msebaa_api_auth_failed',
				__( 'Unable to authorize with MemberSuite.', 'membersuite-ebaa' ),
				$data->get_error_data()
			);
		}

		$id_token = $this->string_field( $data, array( 'idToken', 'id_token' ) );

		if ( '' === $id_token ) {
			return new WP_Error(
				'msebaa_api_auth_failed',
				__( 'Unable to authorize with MemberSuite.', 'membersuite-ebaa' )
			);
		}

		return $id_token;
	}

	/**
	 * Return a cached API service-account idToken, logging in when needed.
	 *
	 * @param bool $force_refresh Skip the cache and re-authenticate.
	 * @return string|WP_Error
	 */
	public function get_api_id_token( bool $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::ID_TOKEN_TRANSIENT );

			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$id_token = $this->login_api_user();
		if ( is_wp_error( $id_token ) ) {
			return $id_token;
		}

		set_transient( self::ID_TOKEN_TRANSIENT, $id_token, self::ID_TOKEN_TTL );

		return $id_token;
	}

	/**
	 * Ask MemberSuite to send its standard portal password-reset email.
	 *
	 * Locked to Security Swagger operation PortalUsers_SendForgottenPortalPasswordEmail
	 * (reconfirmed 2026-09-28): GET sendForgottenPortalPasswordEmail with required
	 * query `email`. Optional `nextUrl` is unused in v1. Does not create, update,
	 * or delete Individual or Membership records.
	 *
	 * @param string $email Sanitized visitor email.
	 * @return true|WP_Error True when transport was accepted or the outcome is ambiguous;
	 *                       WP_Error on missing config, auth failure, or transport failure.
	 */
	public function request_password_reset_email( string $email ) {
		if ( '' === $this->tenant_id ) {
			return new WP_Error(
				'msebaa_missing_tenant',
				__( 'MemberSuite is not configured.', 'membersuite-ebaa' )
			);
		}

		if ( '' === $email ) {
			return new WP_Error(
				'msebaa_invalid_email',
				__( 'Please enter a valid email address.', 'membersuite-ebaa' )
			);
		}

		$query = http_build_query(
			array(
				'email' => $email,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		$path = sprintf( self::PASSWORD_RESET_PATH, rawurlencode( $this->tenant_id ) ) . '?' . $query;

		$response = $this->authorized_request(
			'GET',
			$path,
			array(
				'headers' => array(
					'Accept'        => 'application/json',
					'Cache-Control' => 'no-store',
				),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			return true;
		}

		$code = $response->get_error_code();

		if ( 'msebaa_http_status' === $code ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

			if ( 401 === $status || 403 === $status ) {
				return new WP_Error(
					'msebaa_api_auth_failed',
					__( 'Unable to authorize with MemberSuite.', 'membersuite-ebaa' ),
					array( 'status' => $status )
				);
			}

			if ( $status >= 500 ) {
				return $response;
			}

			// 400/404 and other ambiguous 4xx: do not reveal whether the address exists.
			return true;
		}

		if ( in_array( $code, array( 'msebaa_api_auth_failed', 'msebaa_missing_api_credentials', 'msebaa_missing_tenant' ), true ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * Start JWT SSO handshake; extract tokenGUID from Location on success.
	 *
	 * @param string $id_token      Login idToken (never logged).
	 * @param string $refresh_token Refresh token (never logged).
	 * @param string $access_token  Access token (never logged).
	 * @param string $next_url      Plugin callback URL.
	 * @return array{token_guid: string, location: string}|WP_Error
	 */
	public function jwt_sso( string $id_token, string $refresh_token, string $access_token, string $next_url ) {
		if ( '' === $this->tenant_id ) {
			return new WP_Error(
				'msebaa_missing_tenant',
				__( 'MemberSuite is not configured.', 'membersuite-ebaa' )
			);
		}

		$path = '/platform/v2/JWTSSO/' . rawurlencode( $this->tenant_id );

		$body = array(
			'idToken'          => $id_token,
			'refreshToken'     => $refresh_token,
			'accessToken'      => $access_token,
			'nextUrl'          => $next_url,
			'IsSignUp'         => 'false',
			'IsForgotPassword' => 'false',
		);

		$response = $this->request(
			'POST',
			$path,
			array(
				'headers'     => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'        => $body,
				'redirection' => 0,
			),
			true
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$headers  = $response['headers'];
		$location = '';
		if ( ! empty( $headers['location'] ) ) {
			$location = is_array( $headers['location'] )
				? (string) $headers['location'][0]
				: (string) $headers['location'];
		}

		$token_guid = $this->extract_token_guid( $location );
		if ( '' === $token_guid && is_array( $response['body'] ) ) {
			$token_guid = $this->string_field( $response['body'], array( 'tokenGUID', 'tokenGuid', 'token_guid' ) );
		}

		if ( '' === $token_guid ) {
			return new WP_Error(
				'msebaa_jwt_sso_failed',
				__( 'Sign-in failed. Please try again or contact support.', 'membersuite-ebaa' )
			);
		}

		return array(
			'token_guid' => $token_guid,
			'location'   => $location,
		);
	}

	/**
	 * Exchange tokenGUID for SSO bearer idToken.
	 *
	 * @param string $token_guid SSO token GUID (never logged).
	 * @return array{id_token: string}|WP_Error
	 */
	public function bearer_token_sso( string $token_guid ) {
		if ( '' === $this->tenant_id || '' === $token_guid ) {
			return new WP_Error(
				'msebaa_bearer_sso_failed',
				__( 'Sign-in failed. Please try again or contact support.', 'membersuite-ebaa' )
			);
		}

		$query = http_build_query(
			array(
				'tokenGUID'    => $token_guid,
				'partitionKey' => $this->tenant_id,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		$path = '/platform/v2/bearerTokenSSO?' . $query;

		$response = $this->request(
			'GET',
			$path,
			array(
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = $response['body'];

		// Plain string body.
		if ( is_string( $body ) && '' !== $body && ! str_starts_with( trim( $body ), '{' ) && ! str_starts_with( trim( $body ), '[' ) ) {
			return array( 'id_token' => trim( $body, "\" \t\n\r" ) );
		}

		$data = $this->unwrap_payload( is_array( $body ) ? $body : array() );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$id_token = $this->string_field( $data, array( 'idToken', 'id_token', 'token' ) );
		if ( '' === $id_token && is_string( $data ) ) {
			$id_token = $data;
		}

		if ( '' === $id_token ) {
			return new WP_Error(
				'msebaa_bearer_sso_failed',
				__( 'Sign-in failed. Please try again or contact support.', 'membersuite-ebaa' )
			);
		}

		return array( 'id_token' => $id_token );
	}

	/**
	 * Fetch whoami profile with Bearer idToken.
	 *
	 * @param string $id_token SSO idToken (never logged).
	 * @return array<string, mixed>|WP_Error
	 */
	public function whoami( string $id_token ) {
		if ( '' === $id_token ) {
			return new WP_Error(
				'msebaa_whoami_failed',
				__( 'Sign-in failed. Please try again or contact support.', 'membersuite-ebaa' )
			);
		}

		$response = $this->request(
			'GET',
			'/platform/v2/whoami',
			array(
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $id_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->unwrap_payload( $response['body'] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'msebaa_whoami_failed',
				__( 'Sign-in failed. Please try again or contact support.', 'membersuite-ebaa' )
			);
		}

		return $data;
	}

	/**
	 * Current tenant ID.
	 */
	public function get_tenant_id(): string {
		return $this->tenant_id;
	}

	/**
	 * Current HTTP timeout.
	 */
	public function get_timeout(): int {
		return $this->timeout;
	}

	/**
	 * POST loginUser and return the unwrapped payload.
	 *
	 * @param string $email    Account email.
	 * @param string $password Account password (never logged).
	 * @return array<string, mixed>|string|WP_Error
	 */
	protected function post_login_user( string $email, string $password ) {
		if ( '' === $this->tenant_id ) {
			return new WP_Error(
				'msebaa_missing_tenant',
				__( 'MemberSuite is not configured.', 'membersuite-ebaa' )
			);
		}

		$path = '/platform/v2/loginUser/' . rawurlencode( $this->tenant_id );

		$response = $this->request(
			'POST',
			$path,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'email'    => $email,
						'password' => $password,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->unwrap_payload( $response['body'] );
	}

	/**
	 * Perform a request authorized with the API service-account Bearer token.
	 *
	 * Retries once with a fresh token when MemberSuite rejects the cached one.
	 *
	 * @param string               $method         HTTP method.
	 * @param string               $path           Path or path+query relative to base URL.
	 * @param array<string, mixed> $args           wp_remote_* args.
	 * @param bool                 $allow_redirect When true, 3xx with Location is success.
	 * @return array{body: mixed, headers: array, code: int}|WP_Error
	 */
	protected function authorized_request( string $method, string $path, array $args = array(), bool $allow_redirect = false ) {
		$id_token = $this->get_api_id_token();
		if ( is_wp_error( $id_token ) ) {
			return $id_token;
		}

		$response = $this->request( $method, $path, $this->with_bearer( $args, $id_token ), $allow_redirect );

		if ( ! $this->is_unauthorized( $response ) ) {
			return $response;
		}

		self::flush_id_token();

		$id_token = $this->get_api_id_token( true );
		if ( is_wp_error( $id_token ) ) {
			return $id_token;
		}

		return $this->request( $method, $path, $this->with_bearer( $args, $id_token ), $allow_redirect );
	}

	/**
	 * Add the Authorization header. MemberSuite requires `Bearer ` then the token.
	 *
	 * @param array<string, mixed> $args     wp_remote_* args.
	 * @param string               $id_token Service-account idToken (never logged).
	 * @return array<string, mixed>
	 */
	protected function with_bearer( array $args, string $id_token ): array {
		$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();

		$headers['Authorization'] = 'Bearer ' . $id_token;
		$args['headers']          = $headers;

		return $args;
	}

	/**
	 * True when a request result is a 401 from MemberSuite.
	 *
	 * @param array{body: mixed, headers: array, code: int}|WP_Error $response Request result.
	 */
	protected function is_unauthorized( $response ): bool {
		if ( ! is_wp_error( $response ) || 'msebaa_http_status' !== $response->get_error_code() ) {
			return false;
		}

		$data = $response->get_error_data();

		return is_array( $data ) && isset( $data['status'] ) && 401 === (int) $data['status'];
	}

	/**
	 * Perform an HTTPS request via wp_remote_*.
	 *
	 * @param string               $method           HTTP method.
	 * @param string               $path             Path or path+query relative to base URL.
	 * @param array<string, mixed> $args             wp_remote_* args.
	 * @param bool                 $allow_redirect   When true, 3xx with Location is success.
	 * @return array{body: mixed, headers: array, code: int}|WP_Error
	 */
	protected function request( string $method, string $path, array $args = array(), bool $allow_redirect = false ) {
		$url  = $this->build_url( $path );
		$args = $this->prepare_args( $args );

		if ( 'GET' === strtoupper( $method ) ) {
			$response = wp_remote_get( $url, $args );
		} else {
			$response = wp_remote_post( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'msebaa_http_error',
				__( 'Unable to reach MemberSuite. Please try again later.', 'membersuite-ebaa' )
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$headers = is_object( $headers ) && method_exists( $headers, 'getAll' )
			? $headers->getAll()
			: (array) $headers;

		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) ) {
			$body = $raw;
		}

		$ok = ( $code >= 200 && $code < 300 )
			|| ( $allow_redirect && $code >= 300 && $code < 400 );

		if ( ! $ok ) {
			return new WP_Error(
				'msebaa_http_status',
				__( 'MemberSuite returned an unexpected response.', 'membersuite-ebaa' ),
				array( 'status' => $code )
			);
		}

		return array(
			'body'    => $body,
			'headers' => $headers,
			'code'    => $code,
		);
	}

	/**
	 * Build absolute HTTPS URL for a path (may include query string).
	 *
	 * @param string $path Relative path.
	 */
	protected function build_url( string $path ): string {
		if ( str_starts_with( $path, 'http://' ) || str_starts_with( $path, 'https://' ) ) {
			return $path;
		}

		$path = '/' . ltrim( $path, '/' );

		return self::BASE_URL . $path;
	}

	/**
	 * Merge timeout and defaults into remote request args.
	 *
	 * @param array<string, mixed> $args Request args.
	 * @return array<string, mixed>
	 */
	protected function prepare_args( array $args ): array {
		$args['timeout']   = $this->timeout;
		$args['sslverify'] = true;

		return $args;
	}

	/**
	 * Unwrap MemberSuite envelope ({ success, data }) or pass through a flat payload.
	 *
	 * @param mixed $body Decoded body.
	 * @return array<string, mixed>|string|WP_Error
	 */
	protected function unwrap_payload( $body ) {
		if ( is_string( $body ) ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				$body = $decoded;
			} else {
				return $body;
			}
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'msebaa_invalid_payload',
				__( 'Sign-in failed. Please try again or contact support.', 'membersuite-ebaa' )
			);
		}

		if ( array_key_exists( 'success', $body ) && false === $body['success'] ) {
			return new WP_Error(
				'msebaa_api_failure',
				__( 'Sign-in failed. Please try again or contact support.', 'membersuite-ebaa' )
			);
		}

		if ( isset( $body['data'] ) && ( is_array( $body['data'] ) || is_string( $body['data'] ) ) ) {
			return $body['data'];
		}

		return $body;
	}

	/**
	 * Read the first non-empty string field from candidate keys.
	 *
	 * @param array<string, mixed>|string $data Payload.
	 * @param string[]                    $keys Candidate keys.
	 */
	protected function string_field( $data, array $keys ): string {
		if ( ! is_array( $data ) ) {
			return is_string( $data ) ? $data : '';
		}

		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$value = trim( (string) $data[ $key ] );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * Extract tokenGUID from a Location URL.
	 *
	 * @param string $location Redirect Location header value.
	 */
	protected function extract_token_guid( string $location ): string {
		if ( '' === $location ) {
			return '';
		}

		$parts = wp_parse_url( $location );
		if ( empty( $parts['query'] ) ) {
			return '';
		}

		parse_str( (string) $parts['query'], $query );
		if ( empty( $query['tokenGUID'] ) ) {
			return '';
		}

		return sanitize_text_field( (string) $query['tokenGUID'] );
	}
}
