<?php
/**
 * Settings API admin page.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the MemberSuite EBAA settings screen (`manage_options`).
 */
class Msebaa_Admin_Settings {

	/**
	 * Settings page slug.
	 */
	public const PAGE_SLUG = 'msebaa-settings';

	/**
	 * Option group for Settings API.
	 */
	public const OPTION_GROUP = 'msebaa_settings_group';

	/**
	 * Register admin hooks. Call only from admin context.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add Settings → MemberSuite EBAA submenu.
	 */
	public static function add_menu(): void {
		add_options_page(
			__( 'MemberSuite EBAA', 'membersuite-ebaa' ),
			__( 'MemberSuite EBAA', 'membersuite-ebaa' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register setting, section, and fields via Settings API.
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Msebaa_Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Msebaa_Settings', 'sanitize' ),
				'default'           => Msebaa_Settings::defaults(),
			)
		);

		add_settings_section(
			'msebaa_settings_main',
			__( 'Connection & login', 'membersuite-ebaa' ),
			array( __CLASS__, 'render_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'msebaa_association_id',
			__( 'Association ID', 'membersuite-ebaa' ),
			array( __CLASS__, 'render_association_id_field' ),
			self::PAGE_SLUG,
			'msebaa_settings_main'
		);

		add_settings_field(
			'msebaa_tenant_id',
			__( 'Association Key', 'membersuite-ebaa' ),
			array( __CLASS__, 'render_tenant_id_field' ),
			self::PAGE_SLUG,
			'msebaa_settings_main'
		);

		add_settings_field(
			'msebaa_api_user_email',
			__( 'API user email', 'membersuite-ebaa' ),
			array( __CLASS__, 'render_api_user_email_field' ),
			self::PAGE_SLUG,
			'msebaa_settings_main'
		);

		add_settings_field(
			'msebaa_api_user_password',
			__( 'API user password', 'membersuite-ebaa' ),
			array( __CLASS__, 'render_api_user_password_field' ),
			self::PAGE_SLUG,
			'msebaa_settings_main'
		);

		add_settings_field(
			'msebaa_login_page_id',
			__( 'Login page', 'membersuite-ebaa' ),
			array( __CLASS__, 'render_login_page_field' ),
			self::PAGE_SLUG,
			'msebaa_settings_main'
		);

		add_settings_field(
			'msebaa_http_timeout',
			__( 'HTTP timeout (seconds)', 'membersuite-ebaa' ),
			array( __CLASS__, 'render_http_timeout_field' ),
			self::PAGE_SLUG,
			'msebaa_settings_main'
		);
	}

	/**
	 * Section description.
	 */
	public static function render_section(): void {
		echo '<p>' . esc_html__(
			'Configure MemberSuite SSO connection details, the API service account used for password-reset requests, and the page that embeds the login form.',
			'membersuite-ebaa'
		) . '</p>';
	}

	/**
	 * Association Key field (MemberSuite tenant / partition key).
	 */
	public static function render_tenant_id_field(): void {
		$settings = msebaa_get_settings();
		printf(
			'<input type="text" class="regular-text" id="msebaa_tenant_id" name="%1$s[tenant_id]" value="%2$s" autocomplete="off" />',
			esc_attr( Msebaa_Settings::OPTION_KEY ),
			esc_attr( $settings['tenant_id'] )
		);
		echo '<p class="description">' . esc_html__(
			'Numeric partition key used in MemberSuite REST URLs (for example loginUser). This is not the association GUID.',
			'membersuite-ebaa'
		) . '</p>';
	}

	/**
	 * Association ID field.
	 */
	public static function render_association_id_field(): void {
		$settings = msebaa_get_settings();
		printf(
			'<input type="text" class="regular-text" id="msebaa_association_id" name="%1$s[association_id]" value="%2$s" autocomplete="off" />',
			esc_attr( Msebaa_Settings::OPTION_KEY ),
			esc_attr( $settings['association_id'] )
		);
		echo '<p class="description">' . esc_html__(
			'Association GUID from MemberSuite (typically 36 characters with hyphens). Required for password-reset requests.',
			'membersuite-ebaa'
		) . '</p>';
	}

	/**
	 * API service-account email field.
	 */
	public static function render_api_user_email_field(): void {
		$settings = msebaa_get_settings();
		printf(
			'<input type="email" class="regular-text" id="msebaa_api_user_email" name="%1$s[api_user_email]" value="%2$s" autocomplete="off" />',
			esc_attr( Msebaa_Settings::OPTION_KEY ),
			esc_attr( $settings['api_user_email'] )
		);
		echo '<p class="description">' . esc_html__(
			'Email address of the dedicated MemberSuite API user. This is not a member sign-in.',
			'membersuite-ebaa'
		) . '</p>';
	}

	/**
	 * API service-account password field. Never re-displays the stored secret.
	 */
	public static function render_api_user_password_field(): void {
		printf(
			'<input type="password" class="regular-text" id="msebaa_api_user_password" name="%1$s[api_user_password]" value="" autocomplete="new-password" />',
			esc_attr( Msebaa_Settings::OPTION_KEY )
		);
		echo '<p class="description">' . esc_html__(
			'Leave blank to keep the current password.',
			'membersuite-ebaa'
		) . '</p>';
	}

	/**
	 * Login page dropdown.
	 */
	public static function render_login_page_field(): void {
		$settings = msebaa_get_settings();

		wp_dropdown_pages(
			array(
				'name'              => Msebaa_Settings::OPTION_KEY . '[login_page_id]',
				'id'                => 'msebaa_login_page_id',
				'selected'          => $settings['login_page_id'],
				'show_option_none'  => __( '— Select —', 'membersuite-ebaa' ),
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html__(
			'Page that embeds the [msebaa_sso_login] shortcode.',
			'membersuite-ebaa'
		) . '</p>';
	}

	/**
	 * HTTP timeout field.
	 */
	public static function render_http_timeout_field(): void {
		$settings = msebaa_get_settings();
		printf(
			'<input type="number" class="small-text" id="msebaa_http_timeout" name="%1$s[http_timeout]" value="%2$d" min="%3$d" max="%4$d" step="1" />',
			esc_attr( Msebaa_Settings::OPTION_KEY ),
			(int) $settings['http_timeout'],
			(int) Msebaa_Settings::MIN_HTTP_TIMEOUT,
			(int) Msebaa_Settings::MAX_HTTP_TIMEOUT
		);
		echo '<p class="description">' . esc_html__(
			'Seconds for MemberSuite HTTP requests (5–30).',
			'membersuite-ebaa'
		) . '</p>';
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'membersuite-ebaa' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save Settings', 'membersuite-ebaa' ) );
				?>
			</form>
		</div>
		<?php
	}
}
