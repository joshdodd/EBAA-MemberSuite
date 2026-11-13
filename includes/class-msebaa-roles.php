<?php
/**
 * Custom member role registration and mapping.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers `msebaa_member` and maps benefits to roles without downgrading admins.
 */
class Msebaa_Roles {

	/**
	 * Custom member role slug.
	 */
	public const ROLE_SLUG = 'msebaa_member';

	/**
	 * Register the member role with subscriber-equivalent capabilities.
	 */
	public static function register_role(): void {
		$subscriber = get_role( 'subscriber' );
		$caps       = ( null !== $subscriber ) ? $subscriber->capabilities : array( 'read' => true );

		add_role(
			self::ROLE_SLUG,
			__( 'Member', 'membersuite-ebaa' ),
			$caps
		);
	}

	/**
	 * Store a true benefits flag for the current administrator or editor.
	 *
	 * Runs on `init` so the cached flag is already true in wp-admin, including
	 * the block editor. Does nothing once user meta and the transient are true.
	 * MemberSuite sync cannot replace that true value for these roles.
	 */
	public static function persist_staff_benefits(): void {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User || $user->ID <= 0 || ! self::retains_member_benefits( $user ) ) {
			return;
		}

		$user_id = (int) $user->ID;

		if ( class_exists( 'Msebaa_User_Repository' ) ) {
			$stored = get_user_meta( $user_id, Msebaa_User_Repository::META_RECEIVES_BENEFITS, true );
			if ( '1' !== (string) $stored ) {
				update_user_meta( $user_id, Msebaa_User_Repository::META_RECEIVES_BENEFITS, '1' );
			}
		}

		if ( ! class_exists( 'Msebaa_Cache' ) ) {
			return;
		}

		$cached = Msebaa_Cache::get( $user_id );
		if ( is_array( $cached ) && true === ( $cached['receives_member_benefits'] ?? null ) ) {
			return;
		}

		$profile = ( is_array( $cached ) && isset( $cached['profile'] ) && is_array( $cached['profile'] ) )
			? $cached['profile']
			: array();
		$synced  = ( is_array( $cached ) && isset( $cached['synced_at'] ) && is_scalar( $cached['synced_at'] ) )
			? (string) $cached['synced_at']
			: gmdate( 'Y-m-d H:i:s' );

		Msebaa_Cache::set(
			$user_id,
			array(
				'receives_member_benefits' => true,
				'profile'                  => $profile,
				'synced_at'                => $synced,
			)
		);
	}

	/**
	 * Register the member role when it is absent.
	 *
	 * Activation seeds the role, but a site activated before the role existed —
	 * or one where it was removed — would otherwise never get it back.
	 * `add_role()` is a no-op when the role is already loaded.
	 */
	public static function maybe_register_role(): void {
		if ( null === get_role( self::ROLE_SLUG ) ) {
			self::register_role();
		}
	}

	/**
	 * Remove the custom member role (used on uninstall).
	 */
	public static function remove_role(): void {
		remove_role( self::ROLE_SLUG );
	}

	/**
	 * Whether MemberSuite sync must leave this user's benefits flag true.
	 *
	 * Administrators and editors always receive member benefits. A false or
	 * missing MemberSuite value must not replace that. Custom roles with
	 * `manage_options` are included with administrators.
	 *
	 * @param WP_User $user User to inspect.
	 */
	public static function retains_member_benefits( WP_User $user ): bool {
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}

		$roles = array( 'administrator', 'editor' );

		/**
		 * Filter role slugs that always receive member benefits.
		 *
		 * Sync must not persist a false benefits flag for these roles.
		 *
		 * @param string[] $roles Role slugs.
		 * @param WP_User  $user  User being evaluated.
		 */
		$roles = apply_filters( 'msebaa_roles_retaining_member_benefits', $roles, $user );

		foreach ( (array) $roles as $role ) {
			if ( in_array( (string) $role, (array) $user->roles, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the user has a privileged role that must not be remapped.
	 *
	 * @param WP_User $user User to inspect.
	 */
	public static function is_privileged( WP_User $user ): bool {
		if ( user_can( $user, 'manage_options' ) || user_can( $user, 'edit_users' ) ) {
			return true;
		}

		if ( self::retains_member_benefits( $user ) ) {
			return true;
		}

		$privileged = array( 'administrator', 'editor' );

		/**
		 * Filter which role slugs are treated as privileged (never remapped by SSO).
		 *
		 * @param string[] $privileged Role slugs.
		 * @param WP_User  $user       User being evaluated.
		 */
		$privileged = apply_filters( 'msebaa_privileged_roles', $privileged, $user );

		foreach ( (array) $privileged as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Map membership benefits to the appropriate WordPress role.
	 *
	 * Sets `msebaa_member` when benefits are true, otherwise `subscriber`.
	 * Does not overwrite privileged roles (administrator, editor, manage_options).
	 *
	 * @param bool    $receives_benefits Whether the individual receives member benefits.
	 * @param WP_User $user              Target user.
	 */
	public static function map_role_from_benefits( bool $receives_benefits, WP_User $user ): void {
		if ( self::is_privileged( $user ) ) {
			return;
		}

		$target = $receives_benefits ? self::ROLE_SLUG : 'subscriber';

		// Ensure target role exists before assignment.
		if ( $receives_benefits && null === get_role( self::ROLE_SLUG ) ) {
			self::register_role();
		}

		$user->set_role( $target );
	}
}

/**
 * Map membership benefits to the appropriate WordPress role.
 *
 * @param bool    $receives_benefits Whether the individual receives member benefits.
 * @param WP_User $user              Target user.
 */
function msebaa_map_role_from_benefits( bool $receives_benefits, WP_User $user ): void {
	Msebaa_Roles::map_role_from_benefits( $receives_benefits, $user );
}
