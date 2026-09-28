<?php
/**
 * Password-reset email orchestration.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates reset requests, applies rate limits, and asks MemberSuite to send
 * its standard password-reset email.
 *
 * Does not call WordPress `retrieve_password` and does not change the current
 * session password. Signed-in visitors may submit a request.
 */
class Msebaa_Password_Reset {

	/**
	 * Nonce action for reset forms.
	 */
	public const NONCE_ACTION = 'msebaa_password_reset';

	/**
	 * Nonce field name.
	 */
	public const NONCE_FIELD = 'msebaa_pwreset_nonce';

	/**
	 * Email field name.
	 */
	public const EMAIL_FIELD = 'msebaa_reset_email';

	/**
	 * Outcome: invalid or missing email / nonce.
	 */
	public const OUTCOME_VALIDATION = 'validation_error';

	/**
	 * Outcome: MemberSuite accepted the request or the result is ambiguous.
	 */
	public const OUTCOME_ACCEPTED = 'accepted';

	/**
	 * Outcome: over the per-visitor limit (visitor copy matches accepted).
	 */
	public const OUTCOME_RATE_LIMITED = 'rate_limited';

	/**
	 * Outcome: missing configuration or MemberSuite auth/transport failure.
	 */
	public const OUTCOME_UNAVAILABLE = 'unavailable';

	/**
	 * Memoized result for the current request so multiple embeds process once.
	 *
	 * @var array{outcome: string, email: string}|null
	 */
	private static ?array $result = null;

	/**
	 * Whether this request has already been processed.
	 */
	private static bool $processed = false;

	/**
	 * Process a POST from a plugin reset form, or return null when this is not one.
	 *
	 * @return array{outcome: string, email: string}|null
	 */
	public static function process_submission(): ?array {
		if ( self::$processed ) {
			return self::$result;
		}

		self::$processed = true;

		if ( ! self::is_reset_post() ) {
			self::$result = null;
			return self::$result;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			self::$result = array(
				'outcome' => self::OUTCOME_VALIDATION,
				'email'   => '',
			);
			return self::$result;
		}

		$raw_email = isset( $_POST[ self::EMAIL_FIELD ] )
			? wp_unslash( $_POST[ self::EMAIL_FIELD ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_email() below.
			: '';
		$email     = is_string( $raw_email ) ? sanitize_email( $raw_email ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			self::$result = array(
				'outcome' => self::OUTCOME_VALIDATION,
				'email'   => '',
			);
			return self::$result;
		}

		if ( ! msebaa_has_api_credentials() ) {
			self::$result = array(
				'outcome' => self::OUTCOME_UNAVAILABLE,
				'email'   => $email,
			);
			return self::$result;
		}

		if ( Msebaa_Rate_Limit::is_limited() ) {
			self::$result = array(
				'outcome' => self::OUTCOME_RATE_LIMITED,
				'email'   => $email,
			);
			return self::$result;
		}

		$client = new Msebaa_Api_Client();
		$remote = $client->request_password_reset_email( $email );

		Msebaa_Rate_Limit::record_attempt();

		if ( is_wp_error( $remote ) ) {
			self::$result = array(
				'outcome' => self::OUTCOME_UNAVAILABLE,
				'email'   => $email,
			);
			return self::$result;
		}

		self::$result = array(
			'outcome' => self::OUTCOME_ACCEPTED,
			'email'   => $email,
		);

		return self::$result;
	}

	/**
	 * Visitor-facing copy for an outcome. Rate-limited uses the accepted wording.
	 *
	 * @param string $outcome Outcome constant.
	 */
	public static function message( string $outcome ): string {
		if ( self::OUTCOME_VALIDATION === $outcome ) {
			return __( 'Please enter a valid email address.', 'membersuite-ebaa' );
		}

		if ( self::OUTCOME_UNAVAILABLE === $outcome ) {
			return __( 'Password reset is temporarily unavailable. Please try again later.', 'membersuite-ebaa' );
		}

		return __(
			'If an account exists for that email address, password reset instructions have been sent.',
			'membersuite-ebaa'
		);
	}

	/**
	 * True when the current POST belongs to a plugin password-reset form.
	 */
	private static function is_reset_post(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: '';

		if ( 'POST' !== $method ) {
			return false;
		}

		return isset( $_POST[ self::NONCE_FIELD ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Identifying our form; nonce is verified in process_submission().
	}
}
