<?php
/**
 * Membership status transient cache.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-backed membership snapshots keyed by WordPress user ID.
 */
class Msebaa_Cache {

	/**
	 * Default TTL in seconds (30 minutes).
	 */
	public const DEFAULT_TTL = 1800;

	/**
	 * Transient key prefix.
	 */
	public const KEY_PREFIX = 'msebaa_member_';

	/**
	 * Build transient key for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function key( int $user_id ): string {
		return self::KEY_PREFIX . absint( $user_id );
	}

	/**
	 * Get cached membership snapshot for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array{receives_member_benefits: bool, profile: array<string, mixed>, synced_at: string}|null
	 */
	public static function get( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		$cached = get_transient( self::key( $user_id ) );

		if ( ! is_array( $cached ) ) {
			return null;
		}

		return $cached;
	}

	/**
	 * Store a membership snapshot.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $snapshot Snapshot with receives_member_benefits, profile subset, synced_at.
	 * @param int                  $ttl     TTL in seconds.
	 */
	public static function set( int $user_id, array $snapshot, int $ttl = self::DEFAULT_TTL ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$normalized = array(
			'receives_member_benefits' => ! empty( $snapshot['receives_member_benefits'] ),
			'profile'                  => isset( $snapshot['profile'] ) && is_array( $snapshot['profile'] )
				? $snapshot['profile']
				: array(),
			'synced_at'                => isset( $snapshot['synced_at'] )
				? (string) $snapshot['synced_at']
				: gmdate( 'Y-m-d H:i:s' ),
		);

		set_transient( self::key( $user_id ), $normalized, max( 1, $ttl ) );
	}

	/**
	 * Invalidate cached membership for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function invalidate( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		delete_transient( self::key( $user_id ) );
	}
}
