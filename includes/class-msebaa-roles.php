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
	 * Whether the user has a privileged role that must not be remapped.
	 *
	 * @param WP_User $user User to inspect.
	 */
	public static function is_privileged( WP_User $user ): bool {
		if ( user_can( $user, 'manage_options' ) || user_can( $user, 'edit_users' ) ) {
			return true;
		}

		$privileged = array( 'administrator' );

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
	 * Does not overwrite privileged roles (e.g. administrator / manage_options).
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
