<?php
/**
 * Plugin Name:       MemberSuite EBAA
 * Description:       MemberSuite Outside SSO, members-only gating, and password-reset email recovery for EBAA.
 * Version:           0.1.0
 * Author:            EBAA
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       membersuite-ebaa
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.1
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MSEBAA_VERSION', '0.1.0' );
define( 'MSEBAA_PLUGIN_FILE', __FILE__ );
define( 'MSEBAA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSEBAA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-plugin.php';

register_activation_hook( MSEBAA_PLUGIN_FILE, array( 'Msebaa_Plugin', 'activate' ) );
register_deactivation_hook( MSEBAA_PLUGIN_FILE, array( 'Msebaa_Plugin', 'deactivate' ) );

/**
 * Boot the plugin after WordPress loads plugins.
 */
function msebaa_plugins_loaded(): void {
	Msebaa_Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'msebaa_plugins_loaded' );
