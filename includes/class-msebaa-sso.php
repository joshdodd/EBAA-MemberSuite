<?php
/**
 * Outside SSO orchestration.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles SSO form submission, user provision/link, redirect context, and related hooks.
 */
class Msebaa_Sso {

	/**
	 * Nonce action for the SSO login form.
	 */
	public const NONCE_ACTION = 'msebaa_sso_login';

	/**
	 * Nonce field name.
	 */
	public const NONCE_FIELD = 'msebaa_sso_nonce';

	/**
	 * Admin-post action name.
	 */
	public const ACTION = 'msebaa_sso_login';

	/**
	 * Redirect transient TTL (15 minutes).
	 */
	public const REDIRECT_TTL = 900;

	/**
	 * Transient prefix for return URLs.
	 */
	public const REDIRECT_PREFIX = 'msebaa_redirect_';

	/**
	 * Transient prefix for form error messages.
	 */
	public const ERROR_PREFIX = 'msebaa_sso_error_';

	/**
	 * Register form POST handlers and WordPress login filters.
	 */
	public static function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_login' ) );

		/*
		 * Must run after core's authenticate handlers at priority 20:
		 * wp_authenticate_username_password() replaces an incoming WP_Error
		 * with a successful WP_User, so an earlier block is discarded.
		 */
		add_filter( 'authenticate', array( __CLASS__, 'block_password_login_for_linked' ), 40, 3 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'disallow_password_reset_for_linked' ), 10, 2 );
	}

	/**
	 * Block wp-login.php password authentication for MemberSuite-linked users.
	 *
	 * A linked account is rejected whether or not the password was correct, so the
	 * visitor always gets SSO guidance. Unlinked users (including administrators
	 * without msebaa_linked) keep WordPress's own result, errors included.
	 *
	 * @param null|WP_User|WP_Error $user     Current authentication result.
	 * @param string                $username Submitted username or email.
	 * @param string                $password Submitted password (unused; never logged).
	 * @return null|WP_User|WP_Error
	 */
	public static function block_password_login_for_linked( $user, $username, $password ) {
		unset( $password );

		$wp_user = null;
		if ( $user instanceof WP_User ) {
			$wp_user = $user;
		} elseif ( is_string( $username ) && '' !== $username ) {
			if ( is_email( $username ) ) {
				$wp_user = get_user_by( 'email', $username );
			} else {
				$wp_user = get_user_by( 'login', $username );
			}
		}

		if ( ! $wp_user instanceof WP_User ) {
			return $user;
		}

		if ( ! self::is_linked_user( $wp_user->ID ) ) {
			return $user;
		}

		$login_url = self::get_sso_login_url();

		return new WP_Error(
			'msebaa_sso_required',
			sprintf(
				/* translators: 1: opening link tag to the member sign-in page, 2: closing link tag */
				__( 'This account signs in through MemberSuite. Please use the %1$smember sign-in page%2$s.', 'membersuite-ebaa' ),
				'<a href="' . esc_url( $login_url ) . '">',
				'</a>'
			)
		);
	}

	/**
	 * Disallow WordPress password reset for MemberSuite-linked users.
	 *
	 * @param bool|WP_Error $allow   Whether reset is allowed, or another plugin's error.
	 * @param int           $user_id User ID.
	 * @return bool|WP_Error
	 */
	public static function disallow_password_reset_for_linked( $allow, $user_id ) {
		if ( self::is_linked_user( (int) $user_id ) ) {
			return false;
		}

		return $allow;
	}

	/**
	 * Whether a user is linked via MemberSuite SSO.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function is_linked_user( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return '1' === (string) get_user_meta( $user_id, Msebaa_User_Repository::META_LINKED, true );
	}

	/**
	 * Login page URL for SSO (configured page or home).
	 */
	public static function get_sso_login_url(): string {
		if ( function_exists( 'msebaa_get_login_url' ) ) {
			return msebaa_get_login_url();
		}

		$settings = msebaa_get_settings();
		$page_id  = isset( $settings['login_page_id'] ) ? absint( $settings['login_page_id'] ) : 0;

		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return home_url( '/' );
	}

	/**
	 * Handle SSO login form POST.
	 */
	public static function handle_login(): void {
		// Without a referer, fall back to the login page: the home page has no
		// form to render the error on, so the message would be lost.
		$referer  = wp_get_referer();
		$fallback = $referer ? $referer : self::get_sso_login_url();

		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			self::fail_and_redirect(
				$fallback,
				__( 'Your sign-in request was invalid. Please try again.', 'membersuite-ebaa' )
			);
		}

		$email = isset( $_POST['msebaa_email'] )
			? sanitize_email( wp_unslash( $_POST['msebaa_email'] ) )
			: '';

		// Password used only for the outbound MemberSuite call; never stored.
		$password = isset( $_POST['msebaa_password'] )
			? (string) wp_unslash( $_POST['msebaa_password'] )
			: '';

		$redirect_raw = '';
		if ( isset( $_POST['msebaa_redirect'] ) ) {
			$redirect_raw = (string) wp_unslash( $_POST['msebaa_redirect'] );
		} elseif ( isset( $_GET['msebaa_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redirect_raw = (string) wp_unslash( $_GET['msebaa_redirect'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$redirect_token = self::store_redirect( $redirect_raw );

		if ( '' === $email || '' === $password ) {
			self::fail_and_redirect(
				$fallback,
				__( 'Sign-in failed. Please try again or contact support.', 'membersuite-ebaa' )
			);
		}

		$result = self::authenticate( $email, $password );

		// Clear password from local scope as soon as auth returns.
		$password = '';

		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $fallback, $result->get_error_message() );
		}

		$return_url = self::consume_redirect( $redirect_token );
		if ( '' === $return_url ) {
			$return_url = $fallback;
		}

		// Entitled members return to the original post, page, or download.
		// Anyone else stays on that item's denial message.
		$return_url = Msebaa_Content_Gate::redirect_after_sso( $return_url );
		if ( '' === $return_url ) {
			$return_url = $fallback;
		}

		$return_url = wp_validate_redirect( $return_url, home_url( '/' ) );

		wp_safe_redirect( $return_url );
		exit;
	}

	/**
	 * Run Outside SSO and establish a WordPress session.
	 *
	 * @param string $email    Sanitized email.
	 * @param string $password MemberSuite password (never logged).
	 * @return true|WP_Error
	 */
	public static function authenticate( string $email, string $password ) {
		$client = new Msebaa_Api_Client();

		$login = $client->login_user( $email, $password );
		if ( is_wp_error( $login ) ) {
			return self::generic_auth_error( $login );
		}

		$next_url = home_url( '/' );
		$jwt      = $client->jwt_sso(
			$login['id_token'],
			$login['refresh_token'],
			$login['access_token'],
			$next_url
		);
		if ( is_wp_error( $jwt ) ) {
			return self::generic_auth_error( $jwt );
		}

		$bearer = $client->bearer_token_sso( $jwt['token_guid'] );
		if ( is_wp_error( $bearer ) ) {
			return self::generic_auth_error( $bearer );
		}

		$whoami = $client->whoami( $bearer['id_token'] );
		if ( is_wp_error( $whoami ) ) {
			return self::generic_auth_error( $whoami );
		}

		// Fail closed: no WordPress session without a stable ownerId.
		$owner_id = self::owner_id_from_whoami( $whoami );
		if ( '' === $owner_id ) {
			return new WP_Error(
				'msebaa_missing_owner',
				__( 'Unable to complete sign-in.', 'membersuite-ebaa' )
			);
		}

		$user_id = self::provision_or_link( $whoami );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'msebaa_user_missing',
				__( 'Unable to complete sign-in.', 'membersuite-ebaa' )
			);
		}

		// true → msebaa_member; null/false/absent → subscriber; privileged roles untouched.
		$receives = self::receives_benefits_from_whoami( $whoami );
		msebaa_map_role_from_benefits( $receives, $user );

		$synced_at = gmdate( 'Y-m-d H:i:s' );
		Msebaa_Cache::invalidate( $user_id );
		Msebaa_Cache::set(
			$user_id,
			array(
				'receives_member_benefits' => $receives,
				'profile'                  => array(
					'first_name' => isset( $whoami['firstName'] ) ? (string) $whoami['firstName'] : '',
					'last_name'  => isset( $whoami['lastName'] ) ? (string) $whoami['lastName'] : '',
					'email'      => isset( $whoami['email'] ) ? (string) $whoami['email'] : '',
					'owner_id'   => $owner_id,
				),
				'synced_at'                => $synced_at,
			)
		);

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		return true;
	}

	/**
	 * Extract and sanitize ownerId from whoami (empty when missing).
	 *
	 * @param array<string, mixed> $whoami whoami payload.
	 */
	public static function owner_id_from_whoami( array $whoami ): string {
		if ( empty( $whoami['ownerId'] ) ) {
			return '';
		}

		return sanitize_text_field( (string) $whoami['ownerId'] );
	}

	/**
	 * Normalize receivesMemberBenefits: only explicit true/1 counts as member.
	 *
	 * null, false, absent, or any other value → false (subscriber).
	 *
	 * @param array<string, mixed> $whoami whoami payload.
	 */
	public static function receives_benefits_from_whoami( array $whoami ): bool {
		if ( ! array_key_exists( 'receivesMemberBenefits', $whoami ) ) {
			return false;
		}

		$value = $whoami['receivesMemberBenefits'];

		if ( true === $value || 1 === $value || '1' === $value ) {
			return true;
		}

		return false;
	}

	/**
	 * Create or reuse a WP user linked by MemberSuite ownerId only.
	 *
	 * @param array<string, mixed> $whoami whoami payload.
	 * @return int|WP_Error User ID or error.
	 */
	public static function provision_or_link( array $whoami ) {
		$owner_id = self::owner_id_from_whoami( $whoami );
		if ( '' === $owner_id ) {
			return new WP_Error(
				'msebaa_missing_owner',
				__( 'Unable to complete sign-in.', 'membersuite-ebaa' )
			);
		}

		$email = isset( $whoami['email'] ) ? sanitize_email( (string) $whoami['email'] ) : '';
		$first = isset( $whoami['firstName'] ) ? sanitize_text_field( (string) $whoami['firstName'] ) : '';
		$last  = isset( $whoami['lastName'] ) ? sanitize_text_field( (string) $whoami['lastName'] ) : '';

		$existing_id = Msebaa_User_Repository::find_by_owner_id( $owner_id );

		if ( null === $existing_id ) {
			if ( '' === $email || ! is_email( $email ) ) {
				return new WP_Error(
					'msebaa_invalid_email',
					__( 'Unable to complete sign-in.', 'membersuite-ebaa' )
				);
			}

			$email_user_id = email_exists( $email );
			if ( $email_user_id ) {
				// Never link by email alone when ownerId does not match.
				return new WP_Error(
					'msebaa_email_conflict',
					__( 'Unable to complete sign-in.', 'membersuite-ebaa' )
				);
			}

			$login   = self::unique_username_from_email( $email );
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 32, true, true ),
					'first_name'   => $first,
					'last_name'    => $last,
					'display_name' => trim( $first . ' ' . $last ),
					'role'         => 'subscriber',
				)
			);

			if ( is_wp_error( $user_id ) ) {
				return new WP_Error(
					'msebaa_provision_failed',
					__( 'Unable to complete sign-in.', 'membersuite-ebaa' )
				);
			}
		} else {
			$user_id = $existing_id;

			$update = array( 'ID' => $user_id );
			if ( '' !== $first ) {
				$update['first_name'] = $first;
			}
			if ( '' !== $last ) {
				$update['last_name'] = $last;
			}
			if ( '' !== $email && is_email( $email ) ) {
				$other = email_exists( $email );
				if ( ! $other || (int) $other === $user_id ) {
					$update['user_email'] = $email;
				}
			}
			if ( count( $update ) > 1 ) {
				wp_update_user( $update );
			}
		}

		$receives = self::receives_benefits_from_whoami( $whoami );

		$saved = Msebaa_User_Repository::save_link(
			(int) $user_id,
			array(
				'owner_id'                 => $owner_id,
				'user_id'                  => isset( $whoami['userId'] ) ? (string) $whoami['userId'] : '',
				'membership_id'            => isset( $whoami['membershipId'] ) ? (string) $whoami['membershipId'] : '',
				'receives_member_benefits' => $receives,
				'first_name'               => $first,
				'last_name'                => $last,
				'email'                    => $email,
				'last_synced'              => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		if ( is_wp_error( $saved ) ) {
			return new WP_Error(
				'msebaa_link_failed',
				__( 'Unable to complete sign-in.', 'membersuite-ebaa' )
			);
		}

		return (int) $user_id;
	}

	/**
	 * Validate and persist a return URL; returns opaque token (empty if none).
	 *
	 * @param string $url Candidate redirect URL from query/POST.
	 */
	public static function store_redirect( string $url ): string {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return '';
		}

		$validated = wp_validate_redirect( $url, '' );
		if ( '' === $validated ) {
			return '';
		}

		$token = wp_generate_password( 32, false, false );
		set_transient( self::REDIRECT_PREFIX . $token, $validated, self::REDIRECT_TTL );

		return $token;
	}

	/**
	 * Read and clear a stored return URL.
	 *
	 * @param string $token Opaque token from store_redirect().
	 */
	public static function consume_redirect( string $token ): string {
		if ( '' === $token ) {
			return '';
		}

		$key = self::REDIRECT_PREFIX . $token;
		$url = get_transient( $key );
		delete_transient( $key );

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		return (string) wp_validate_redirect( $url, '' );
	}

	/**
	 * Validate a redirect URL for form hidden fields (local only).
	 *
	 * @param string $url Candidate URL.
	 */
	public static function validate_redirect_url( string $url ): string {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return '';
		}

		return (string) wp_validate_redirect( $url, '' );
	}

	/**
	 * Store a visitor-facing error and redirect back to the form.
	 *
	 * @param string $url     Fallback return URL.
	 * @param string $message Translatable generic message (no API dumps).
	 */
	private static function fail_and_redirect( string $url, string $message ): void {
		$token = wp_generate_password( 12, false, false );
		set_transient( self::ERROR_PREFIX . $token, $message, 5 * MINUTE_IN_SECONDS );

		$url = wp_validate_redirect( $url, home_url( '/' ) );
		$url = add_query_arg( 'msebaa_sso_error', rawurlencode( $token ), $url );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Pull a stored SSO error message for display on the form.
	 *
	 * @param string $token Error token from query arg.
	 */
	public static function consume_error( string $token ): string {
		$token = sanitize_text_field( $token );
		if ( '' === $token ) {
			return '';
		}

		$key     = self::ERROR_PREFIX . $token;
		$message = get_transient( $key );
		delete_transient( $key );

		return is_string( $message ) ? $message : '';
	}

	/**
	 * Map transport/API errors to generic visitor messages (no dumps).
	 *
	 * @param WP_Error $error Upstream error.
	 */
	private static function generic_auth_error( WP_Error $error ): WP_Error {
		if ( 'msebaa_http_error' === $error->get_error_code() ) {
			return new WP_Error(
				'msebaa_unavailable',
				__( 'The membership service is temporarily unavailable. Please try again later.', 'membersuite-ebaa' )
			);
		}

		return new WP_Error(
			'msebaa_auth_failed',
			__( 'Sign-in failed. Please try again or contact support.', 'membersuite-ebaa' )
		);
	}

	/**
	 * Build a unique WP user_login from an email address.
	 *
	 * @param string $email Sanitized email.
	 */
	private static function unique_username_from_email( string $email ): string {
		$local = strstr( $email, '@', true );
		$base  = sanitize_user( (string) $local, true );
		if ( '' === $base ) {
			$base = 'msebaa_user';
		}

		$login  = $base;
		$suffix = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $suffix;
			++$suffix;
		}

		return $login;
	}
}
