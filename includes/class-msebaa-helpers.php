<?php
/**
 * Theme-facing helper functions.
 *
 * Public contract: specs/001-membersuite-sso/contracts/theme-helpers.md
 * Call these functions after WordPress `init`. They do not echo, die, or call MemberSuite.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal snapshot assembly for theme helpers.
 *
 * Not part of the theme API. Themes must call the msebaa_* functions.
 */
class Msebaa_Helpers {

	/**
	 * String fields themes may request via msebaa_get_member_field().
	 *
	 * @var string[]
	 */
	private const STRING_FIELDS = array(
		'owner_id',
		'email',
		'first_name',
		'last_name',
		'membership_id',
		'last_synced',
	);

	/**
	 * Whether WordPress `init` has started so helpers can read the session safely.
	 */
	public static function is_ready(): bool {
		return function_exists( 'is_user_logged_in' )
			&& ( did_action( 'init' ) || doing_action( 'init' ) );
	}

	/**
	 * Current member array from cache and user meta, or null when no linked session.
	 *
	 * Cache is preferred when present. User meta is the last-known fallback when the
	 * transient has expired. Neither path performs a remote call.
	 *
	 * @return array{
	 *     owner_id: string,
	 *     email: string,
	 *     first_name: string,
	 *     last_name: string,
	 *     receives_member_benefits: bool,
	 *     membership_id: string,
	 *     wp_user_id: int,
	 *     last_synced: string
	 * }|null
	 */
	public static function current_member(): ?array {
		if ( ! self::is_ready() || ! is_user_logged_in() ) {
			return null;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}

		$cache    = class_exists( 'Msebaa_Cache' ) ? Msebaa_Cache::get( $user_id ) : null;
		$snapshot = class_exists( 'Msebaa_User_Repository' ) ? Msebaa_User_Repository::get_snapshot( $user_id ) : null;

		if ( null === $cache && null === $snapshot ) {
			return null;
		}

		$profile = array();
		if ( is_array( $cache ) && isset( $cache['profile'] ) && is_array( $cache['profile'] ) ) {
			$profile = $cache['profile'];
		}

		$owner_id = self::pick_string( $profile, $snapshot, 'owner_id' );
		if ( '' === $owner_id ) {
			return null;
		}

		return array(
			'owner_id'                 => $owner_id,
			'email'                    => self::pick_string( $profile, $snapshot, 'email' ),
			'first_name'               => self::pick_string( $profile, $snapshot, 'first_name' ),
			'last_name'                => self::pick_string( $profile, $snapshot, 'last_name' ),
			'receives_member_benefits' => self::benefits_from_sources( $cache, $snapshot ),
			'membership_id'            => is_array( $snapshot ) ? (string) ( $snapshot['membership_id'] ?? '' ) : '',
			'wp_user_id'               => $user_id,
			'last_synced'              => self::last_synced_from_sources( $cache, $snapshot ),
		);
	}

	/**
	 * Cached benefits flag. Missing or unknown values are false.
	 *
	 * @param array<string, mixed>|null $cache    Membership transient or null.
	 * @param array<string, mixed>|null $snapshot User-meta snapshot or null.
	 */
	public static function benefits_from_sources( ?array $cache, ?array $snapshot ): bool {
		if ( is_array( $cache ) && array_key_exists( 'receives_member_benefits', $cache ) ) {
			return true === $cache['receives_member_benefits'];
		}

		if ( is_array( $snapshot ) && array_key_exists( 'receives_member_benefits', $snapshot ) ) {
			return true === $snapshot['receives_member_benefits'];
		}

		return false;
	}

	/**
	 * Benefits flag for the current user without a remote call.
	 */
	public static function current_user_receives_benefits(): bool {
		$member = self::current_member();
		if ( null === $member ) {
			return false;
		}

		return true === $member['receives_member_benefits'];
	}

	/**
	 * Local-only redirect URL, or empty when the candidate is missing or off-site.
	 *
	 * @param string $redirect Candidate return URL.
	 */
	public static function safe_local_redirect( string $redirect ): string {
		$redirect = esc_url_raw( $redirect );
		if ( '' === $redirect || ! function_exists( 'wp_validate_redirect' ) ) {
			return '';
		}

		return (string) wp_validate_redirect( $redirect, '' );
	}

	/**
	 * Configured login page URL, or the home URL when unset.
	 */
	public static function login_page_url(): string {
		if ( ! function_exists( 'msebaa_get_settings' ) || ! function_exists( 'home_url' ) ) {
			return '';
		}

		$settings = msebaa_get_settings();
		$page_id  = isset( $settings['login_page_id'] ) ? absint( $settings['login_page_id'] ) : 0;

		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			$permalink = get_permalink( $page_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		return home_url( '/' );
	}

	/**
	 * Pick a string field from the cache profile, then the user-meta snapshot.
	 *
	 * @param array<string, mixed>      $profile  Cache profile subset.
	 * @param array<string, mixed>|null $snapshot User-meta snapshot.
	 * @param string                    $key      Field key.
	 */
	private static function pick_string( array $profile, ?array $snapshot, string $key ): string {
		if ( array_key_exists( $key, $profile ) && is_scalar( $profile[ $key ] ) ) {
			return (string) $profile[ $key ];
		}

		if ( is_array( $snapshot ) && array_key_exists( $key, $snapshot ) && is_scalar( $snapshot[ $key ] ) ) {
			return (string) $snapshot[ $key ];
		}

		return '';
	}

	/**
	 * Last sync timestamp from cache, then user meta.
	 *
	 * @param array<string, mixed>|null $cache    Membership transient or null.
	 * @param array<string, mixed>|null $snapshot User-meta snapshot or null.
	 */
	private static function last_synced_from_sources( ?array $cache, ?array $snapshot ): string {
		if ( is_array( $cache ) && isset( $cache['synced_at'] ) && is_scalar( $cache['synced_at'] ) ) {
			return (string) $cache['synced_at'];
		}

		if ( is_array( $snapshot ) && isset( $snapshot['last_synced'] ) && is_scalar( $snapshot['last_synced'] ) ) {
			return (string) $snapshot['last_synced'];
		}

		return '';
	}

	/**
	 * Whether a member-field key is part of the public string contract.
	 *
	 * @param string $key Requested field.
	 */
	public static function is_string_field( string $key ): bool {
		return in_array( $key, self::STRING_FIELDS, true );
	}
}

/**
 * Whether a WordPress user session is active.
 *
 * True for any signed-in WordPress user, MemberSuite-linked or not.
 * Safe default before `init`, when signed out, or on failure: false.
 */
function msebaa_is_user_logged_in(): bool {
	if ( ! Msebaa_Helpers::is_ready() ) {
		return false;
	}

	return is_user_logged_in();
}

/**
 * Whether the current user is signed in and receives member benefits.
 *
 * Site administrators are not treated as members unless their cached snapshot
 * also has receives_member_benefits true. Use current_user_can( 'manage_options' )
 * in a theme when an operational override is required.
 * Safe default before `init`, when signed out, or when benefits are unknown: false.
 */
function msebaa_is_member(): bool {
	if ( ! msebaa_is_user_logged_in() ) {
		return false;
	}

	return msebaa_receives_member_benefits();
}

/**
 * Cached member-benefits flag for the current user.
 *
 * Reads Msebaa_Cache, then the user-repository snapshot. Does not call MemberSuite.
 * Safe default before `init`, when signed out, or when the flag is missing: false.
 */
function msebaa_receives_member_benefits(): bool {
	return Msebaa_Helpers::current_user_receives_benefits();
}

/**
 * Current linked member snapshot, or null when nobody linked is signed in.
 *
 * Keys: owner_id, email, first_name, last_name, receives_member_benefits,
 * membership_id (may be empty), wp_user_id, last_synced (GMT or empty).
 * Safe default before `init`, when signed out, or when the user is not linked: null.
 *
 * @return array{
 *     owner_id: string,
 *     email: string,
 *     first_name: string,
 *     last_name: string,
 *     receives_member_benefits: bool,
 *     membership_id: string,
 *     wp_user_id: int,
 *     last_synced: string
 * }|null
 */
function msebaa_get_current_member(): ?array {
	return Msebaa_Helpers::current_member();
}

/**
 * One string field from the current member snapshot.
 *
 * Allowed keys: owner_id, email, first_name, last_name, membership_id, last_synced.
 * Safe default for unknown keys, a signed-out visitor, or a missing field: empty string.
 *
 * @param string $key Field key from the member snapshot.
 */
function msebaa_get_member_field( string $key ): string {
	if ( ! Msebaa_Helpers::is_string_field( $key ) ) {
		return '';
	}

	$member = msebaa_get_current_member();
	if ( null === $member || ! array_key_exists( $key, $member ) || ! is_scalar( $member[ $key ] ) ) {
		return '';
	}

	return (string) $member[ $key ];
}

/**
 * URL of the configured SSO login page.
 *
 * Uses msebaa_settings login_page_id when that page is published. A local
 * $redirect is appended as the msebaa_redirect query argument. Off-site
 * redirects are dropped. Safe default when the login page is unset, unpublished,
 * or this runs before `init`: home URL.
 *
 * @param string $redirect Optional local URL to return to after sign-in.
 */
function msebaa_get_login_url( string $redirect = '' ): string {
	if ( ! function_exists( 'home_url' ) ) {
		return '';
	}

	if ( ! Msebaa_Helpers::is_ready() ) {
		return home_url( '/' );
	}

	$url  = Msebaa_Helpers::login_page_url();
	$safe = Msebaa_Helpers::safe_local_redirect( $redirect );

	if ( '' === $url ) {
		$url = home_url( '/' );
	}

	if ( '' === $safe ) {
		return $url;
	}

	return add_query_arg( 'msebaa_redirect', $safe, $url );
}

/**
 * WordPress logout URL.
 *
 * Optional $redirect must be a local URL; otherwise the logout URL targets home.
 * Safe default before `init` or when wp_logout_url() is unavailable: home URL.
 *
 * @param string $redirect Optional local URL to visit after logout.
 */
function msebaa_get_logout_url( string $redirect = '' ): string {
	if ( ! function_exists( 'home_url' ) ) {
		return '';
	}

	$target = home_url( '/' );

	if ( ! Msebaa_Helpers::is_ready() || ! function_exists( 'wp_logout_url' ) ) {
		return $target;
	}

	$safe = Msebaa_Helpers::safe_local_redirect( $redirect );
	if ( '' !== $safe ) {
		$target = $safe;
	}

	return wp_logout_url( $target );
}
