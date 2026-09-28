<?php
/**
 * Uninstall cleanup for MemberSuite EBAA.
 *
 * Removes plugin options, members-only post meta, msebaa_* user meta,
 * msebaa_* transients, and the custom member role. Does not delete
 * WordPress users, posts, pages, or media files.
 *
 * @package Msebaa
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Plugin settings, including the API service-account password.
delete_option( 'msebaa_settings' );

// Known transient, deleted by name so external object caches are cleared too.
delete_transient( 'msebaa_api_id_token' );

// Members-only flags on posts, pages, and attachments.
// Deletes the meta key only. Does not call wp_delete_attachment() or remove files.
delete_post_meta_by_key( '_msebaa_members_only' );

// All msebaa_* user meta (accounts remain).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( 'msebaa_' ) . '%'
	)
);

// Remap users on the custom role to subscriber, then remove the role.
$member_users = get_users(
	array(
		'role'   => 'msebaa_member',
		'fields' => 'ID',
	)
);

foreach ( $member_users as $user_id ) {
	$user = get_userdata( (int) $user_id );
	if ( $user instanceof WP_User ) {
		$user->set_role( 'subscriber' );
	}
}

remove_role( 'msebaa_member' );

// msebaa_* transients (site and network-style timeout keys), covering
// msebaa_api_id_token and every msebaa_pwreset_* rate-limit counter.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_msebaa_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_msebaa_' ) . '%'
	)
);

if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$wpdb->esc_like( '_site_transient_msebaa_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_msebaa_' ) . '%'
		)
	);
}
