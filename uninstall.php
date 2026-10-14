<?php
/**
 * Uninstall cleanup for MemberSuite EBAA.
 *
 * Removes plugin options, members-only post meta, msebaa_* user meta,
 * msebaa_* transients, and the custom member role. Restores privately
 * stored media to the uploads directory first. Does not delete
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
delete_option( 'msebaa_portal_host' );

// Known transient, deleted by name so external object caches are cleared too.
delete_transient( 'msebaa_api_id_token' );

// Restore privately stored members-only files before dropping their meta.
// Does not delete attachment posts. A file that cannot be moved back keeps its meta.
require_once __DIR__ . '/includes/class-msebaa-content-gate.php';
require_once __DIR__ . '/includes/class-msebaa-media-gate.php';
$msebaa_restore_failed = Msebaa_Media_Gate::restore_all_for_uninstall();

// Members-only flags on posts, pages, and attachments.
// Deletes the meta key only. Does not call wp_delete_attachment() or remove files.
delete_post_meta_by_key( '_msebaa_members_only' );

// Successful restores already removed this key. Keep it for files that could not be moved back,
// and put the members-only flag back so a reinstall still gates those files.
foreach ( $msebaa_restore_failed as $msebaa_attachment_id ) {
	update_post_meta( (int) $msebaa_attachment_id, '_msebaa_members_only', '1' );
}

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
