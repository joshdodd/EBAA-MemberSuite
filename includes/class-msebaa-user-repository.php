<?php
/**
 * User meta repository for MemberSuite identity links.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and looks up MemberSuite ↔ WordPress identity and profile snapshots.
 *
 * Linking is always by owner ID — never by email alone.
 */
class Msebaa_User_Repository {

	/**
	 * Meta key: MemberSuite Individual owner ID (GUID).
	 */
	public const META_OWNER_ID = 'msebaa_owner_id';

	/**
	 * Meta key: MemberSuite user ID.
	 */
	public const META_USER_ID = 'msebaa_user_id';

	/**
	 * Meta key: membership ID (may be empty).
	 */
	public const META_MEMBERSHIP_ID = 'msebaa_membership_id';

	/**
	 * Meta key: receives member benefits ('1' / '0').
	 */
	public const META_RECEIVES_BENEFITS = 'msebaa_receives_member_benefits';

	/**
	 * Meta key: first name.
	 */
	public const META_FIRST_NAME = 'msebaa_first_name';

	/**
	 * Meta key: last name.
	 */
	public const META_LAST_NAME = 'msebaa_last_name';

	/**
	 * Meta key: email.
	 */
	public const META_EMAIL = 'msebaa_email';

	/**
	 * Meta key: last synced GMT datetime.
	 */
	public const META_LAST_SYNCED = 'msebaa_last_synced';

	/**
	 * Meta key: SSO-linked flag ('1').
	 */
	public const META_LINKED = 'msebaa_linked';

	/**
	 * Find a WordPress user ID by MemberSuite owner ID.
	 *
	 * @param string $owner_id MemberSuite Individual GUID.
	 * @return int|null User ID or null when not found.
	 */
	public static function find_by_owner_id( string $owner_id ): ?int {
		$owner_id = sanitize_text_field( $owner_id );

		if ( '' === $owner_id ) {
			return null;
		}

		$users = get_users(
			array(
				'meta_key'   => self::META_OWNER_ID,
				'meta_value' => $owner_id,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		if ( empty( $users ) ) {
			return null;
		}

		return (int) $users[0];
	}

	/**
	 * Save or update the MemberSuite identity link and profile snapshot on a user.
	 *
	 * Does not look up or link by email. Caller must resolve the WP user (create or
	 * find by owner ID) before calling this method.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $data    Link fields (owner_id required).
	 * @return true|WP_Error True on success, WP_Error when owner_id missing or conflict.
	 */
	public static function save_link( int $user_id, array $data ) {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'msebaa_invalid_user',
				__( 'Invalid WordPress user for MemberSuite link.', 'membersuite-ebaa' )
			);
		}

		$owner_id = isset( $data['owner_id'] )
			? sanitize_text_field( (string) $data['owner_id'] )
			: '';

		if ( '' === $owner_id ) {
			return new WP_Error(
				'msebaa_missing_owner_id',
				__( 'MemberSuite owner ID is required to link a user.', 'membersuite-ebaa' )
			);
		}

		$existing = self::find_by_owner_id( $owner_id );
		if ( null !== $existing && $existing !== $user_id ) {
			return new WP_Error(
				'msebaa_owner_id_conflict',
				__( 'This MemberSuite individual is already linked to another WordPress user.', 'membersuite-ebaa' )
			);
		}

		$receives = ! empty( $data['receives_member_benefits'] );
		$synced   = isset( $data['last_synced'] )
			? sanitize_text_field( (string) $data['last_synced'] )
			: gmdate( 'Y-m-d H:i:s' );

		update_user_meta( $user_id, self::META_OWNER_ID, $owner_id );
		update_user_meta(
			$user_id,
			self::META_USER_ID,
			isset( $data['user_id'] ) ? sanitize_text_field( (string) $data['user_id'] ) : ''
		);
		update_user_meta(
			$user_id,
			self::META_MEMBERSHIP_ID,
			isset( $data['membership_id'] ) ? sanitize_text_field( (string) $data['membership_id'] ) : ''
		);
		update_user_meta( $user_id, self::META_RECEIVES_BENEFITS, $receives ? '1' : '0' );
		update_user_meta(
			$user_id,
			self::META_FIRST_NAME,
			isset( $data['first_name'] ) ? sanitize_text_field( (string) $data['first_name'] ) : ''
		);
		update_user_meta(
			$user_id,
			self::META_LAST_NAME,
			isset( $data['last_name'] ) ? sanitize_text_field( (string) $data['last_name'] ) : ''
		);
		update_user_meta(
			$user_id,
			self::META_EMAIL,
			isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : ''
		);
		update_user_meta( $user_id, self::META_LAST_SYNCED, $synced );
		update_user_meta( $user_id, self::META_LINKED, '1' );

		return true;
	}

	/**
	 * Get identity + profile snapshot from user meta.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed>|null Snapshot or null when user missing / not linked.
	 */
	public static function get_snapshot( int $user_id ): ?array {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return null;
		}

		$linked = get_user_meta( $user_id, self::META_LINKED, true );
		if ( '1' !== $linked ) {
			return null;
		}

		$benefits_raw = get_user_meta( $user_id, self::META_RECEIVES_BENEFITS, true );

		return array(
			'owner_id'                 => (string) get_user_meta( $user_id, self::META_OWNER_ID, true ),
			'user_id'                  => (string) get_user_meta( $user_id, self::META_USER_ID, true ),
			'membership_id'            => (string) get_user_meta( $user_id, self::META_MEMBERSHIP_ID, true ),
			'receives_member_benefits' => ( '1' === $benefits_raw ),
			'first_name'               => (string) get_user_meta( $user_id, self::META_FIRST_NAME, true ),
			'last_name'                => (string) get_user_meta( $user_id, self::META_LAST_NAME, true ),
			'email'                    => (string) get_user_meta( $user_id, self::META_EMAIL, true ),
			'last_synced'              => (string) get_user_meta( $user_id, self::META_LAST_SYNCED, true ),
			'linked'                   => true,
		);
	}
}
