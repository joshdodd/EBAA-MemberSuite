<?php
/**
 * Plugin settings defaults and reader.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and sanitizes the `msebaa_settings` option.
 */
class Msebaa_Settings {

	/**
	 * Option key for plugin settings.
	 */
	public const OPTION_KEY = 'msebaa_settings';

	/**
	 * Default HTTP timeout in seconds.
	 */
	public const DEFAULT_HTTP_TIMEOUT = 15;

	/**
	 * Minimum allowed HTTP timeout in seconds.
	 */
	public const MIN_HTTP_TIMEOUT = 5;

	/**
	 * Maximum allowed HTTP timeout in seconds.
	 */
	public const MAX_HTTP_TIMEOUT = 30;

	/**
	 * Default settings values.
	 *
	 * @return array{tenant_id: string, association_id: string, api_user_email: string, api_user_password: string, login_page_id: int, http_timeout: int}
	 */
	public static function defaults(): array {
		return array(
			'tenant_id'         => '',
			'association_id'    => '',
			'api_user_email'    => '',
			'api_user_password' => '',
			'login_page_id'     => 0,
			'http_timeout'      => self::DEFAULT_HTTP_TIMEOUT,
		);
	}

	/**
	 * Sanitize a settings array for storage (Settings API callback-safe).
	 *
	 * An empty `api_user_password` retains the previously stored secret so the
	 * admin form can render the field blank without wiping the credential.
	 *
	 * @param mixed $input Raw settings.
	 * @return array{tenant_id: string, association_id: string, api_user_email: string, api_user_password: string, login_page_id: int, http_timeout: int}
	 */
	public static function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$defaults = self::defaults();

		$tenant_id = isset( $input['tenant_id'] )
			? sanitize_text_field( (string) $input['tenant_id'] )
			: $defaults['tenant_id'];

		$association_id = isset( $input['association_id'] )
			? sanitize_text_field( (string) $input['association_id'] )
			: $defaults['association_id'];

		$api_user_email = isset( $input['api_user_email'] )
			? sanitize_email( (string) $input['api_user_email'] )
			: $defaults['api_user_email'];

		$api_user_password = isset( $input['api_user_password'] ) && is_scalar( $input['api_user_password'] )
			? (string) $input['api_user_password']
			: '';

		if ( '' === $api_user_password ) {
			$api_user_password = self::stored_api_user_password();
		}

		$login_page_id = isset( $input['login_page_id'] )
			? absint( $input['login_page_id'] )
			: $defaults['login_page_id'];

		$http_timeout = isset( $input['http_timeout'] )
			? absint( $input['http_timeout'] )
			: $defaults['http_timeout'];

		if ( $http_timeout < self::MIN_HTTP_TIMEOUT ) {
			$http_timeout = self::MIN_HTTP_TIMEOUT;
		} elseif ( $http_timeout > self::MAX_HTTP_TIMEOUT ) {
			$http_timeout = self::MAX_HTTP_TIMEOUT;
		}

		return array(
			'tenant_id'         => $tenant_id,
			'association_id'    => $association_id,
			'api_user_email'    => $api_user_email,
			'api_user_password' => $api_user_password,
			'login_page_id'     => $login_page_id,
			'http_timeout'      => $http_timeout,
		);
	}

	/**
	 * Return saved settings merged with defaults.
	 *
	 * @return array{tenant_id: string, association_id: string, api_user_email: string, api_user_password: string, login_page_id: int, http_timeout: int}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::sanitize( array_merge( self::defaults(), $stored ) );
	}

	/**
	 * True when every credential needed for authorized MemberSuite API calls is present.
	 */
	public static function has_api_credentials(): bool {
		$settings = self::get();

		$required = array( 'tenant_id', 'association_id', 'api_user_email', 'api_user_password' );

		foreach ( $required as $key ) {
			if ( '' === (string) $settings[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read the raw stored API user password without re-entering sanitize().
	 */
	private static function stored_api_user_password(): string {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['api_user_password'] ) || ! is_scalar( $stored['api_user_password'] ) ) {
			return '';
		}

		return (string) $stored['api_user_password'];
	}
}

/**
 * Return plugin settings with defaults applied.
 *
 * @return array{tenant_id: string, association_id: string, api_user_email: string, api_user_password: string, login_page_id: int, http_timeout: int}
 */
function msebaa_get_settings(): array {
	return Msebaa_Settings::get();
}

/**
 * True when the API service-account credentials required for reset requests are configured.
 */
function msebaa_has_api_credentials(): bool {
	return Msebaa_Settings::has_api_credentials();
}
