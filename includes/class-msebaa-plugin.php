<?php
/**
 * Plugin bootstrap and dependency wiring.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central boot class: loads dependencies and registers hooks.
 */
class Msebaa_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Msebaa_Plugin|null
	 */
	private static ?Msebaa_Plugin $instance = null;

	/**
	 * Get the singleton instance.
	 */
	public static function instance(): Msebaa_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Load dependencies and register runtime hooks.
	 *
	 * Does not execute SSO, password-reset, or gating side effects on bare include.
	 * Requires class files, registers admin screens in wp-admin, and registers
	 * front-end content and media gates on public requests.
	 */
	public function init(): void {
		$this->load_dependencies();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( 'Msebaa_Roles', 'maybe_register_role' ) );

		/*
		 * Theme helpers on every front-end and admin request. The file is
		 * loaded now and again on `init` so templates can call msebaa_* after init.
		 */
		$this->load_theme_helpers();
		add_action( 'init', array( $this, 'load_theme_helpers' ), 0 );

		Msebaa_Api_Client::register();
		Msebaa_Sso::register();
		Msebaa_Shortcode::register();
		Msebaa_Password_Reset_Shortcode::register();

		if ( is_admin() ) {
			Msebaa_Admin_Settings::register();
			Msebaa_Members_Only_Meta::register();
		} else {
			Msebaa_Content_Gate::register();
			Msebaa_Media_Gate::register();
		}
	}

	/**
	 * Load translations from `languages/`.
	 *
	 * Runs on `init`; loading earlier triggers a WordPress notice.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'membersuite-ebaa',
			false,
			dirname( plugin_basename( MSEBAA_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Load theme helper functions for front-end and admin requests.
	 *
	 * Safe to call more than once. Does not call MemberSuite.
	 */
	public function load_theme_helpers(): void {
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-helpers.php';
	}

	/**
	 * Plugin activation: seed settings defaults and register member role.
	 *
	 * Safe to call from register_activation_hook; loads only what activation needs.
	 */
	public static function activate(): void {
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-settings.php';
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-roles.php';

		if ( false === get_option( Msebaa_Settings::OPTION_KEY, false ) ) {
			add_option( Msebaa_Settings::OPTION_KEY, Msebaa_Settings::defaults(), '', false );
		}

		Msebaa_Roles::register_role();
	}

	/**
	 * Plugin deactivation: clear scheduled events only.
	 *
	 * MUST NOT delete settings, user meta, post meta, or WordPress users.
	 */
	public static function deactivate(): void {
		$hooks = array(
			'msebaa_sync_membership',
			'msebaa_purge_expired_redirects',
		);

		/**
		 * Filter cron hook names to clear on deactivation.
		 *
		 * @param string[] $hooks Hook names.
		 */
		$hooks = apply_filters( 'msebaa_deactivation_cron_hooks', $hooks );

		foreach ( (array) $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			while ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}
		}
	}

	/**
	 * Require plugin class files.
	 */
	private function load_dependencies(): void {
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-settings.php';
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-api-client.php';
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-sso.php';
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-user-repository.php';
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-cache.php';
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-roles.php';
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-content-gate.php';
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-media-gate.php';
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-rate-limit.php';
		require_once MSEBAA_PLUGIN_DIR . 'includes/class-msebaa-password-reset.php';
		require_once MSEBAA_PLUGIN_DIR . 'public/class-msebaa-shortcode.php';
		require_once MSEBAA_PLUGIN_DIR . 'public/class-msebaa-password-reset-shortcode.php';

		if ( is_admin() ) {
			require_once MSEBAA_PLUGIN_DIR . 'admin/class-msebaa-admin-settings.php';
			require_once MSEBAA_PLUGIN_DIR . 'admin/class-msebaa-members-only-meta.php';
		}
	}
}
