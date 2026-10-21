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
		add_action(
			'update_option_' . Msebaa_Settings::OPTION_KEY,
			array( __CLASS__, 'maybe_test_api_connection' ),
			20,
			2
		);
		add_action(
			'add_option_' . Msebaa_Settings::OPTION_KEY,
			array( __CLASS__, 'maybe_test_api_connection_on_add' ),
			20,
			2
		);
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

		add_settings_section(
			'msebaa_settings_content',
			__( 'Members-only content', 'membersuite-ebaa' ),
			array( __CLASS__, 'render_content_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'msebaa_content_denial_message',
			__( 'Content Denial Message', 'membersuite-ebaa' ),
			array( __CLASS__, 'render_content_denial_message_field' ),
			self::PAGE_SLUG,
			'msebaa_settings_content'
		);
	}

	/**
	 * Members-only content section description.
	 */
	public static function render_content_section(): void {
		echo '<p>' . esc_html__(
			'Message shown in place of a members-only post or page when the visitor is not allowed to view it.',
			'membersuite-ebaa'
		) . '</p>';
	}

	/**
	 * Section description.
	 */
	public static function render_section(): void {
		echo '<p>' . esc_html__(
			'Configure MemberSuite SSO connection details, the API service account used for password-reset requests, and the page that embeds the login form. Saving a changed API user email or password signs in with those credentials and reports whether MemberSuite accepted them.',
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
	 * Content denial message field.
	 */
	public static function render_content_denial_message_field(): void {
		$settings = msebaa_get_settings();
		printf(
			'<textarea class="large-text" rows="3" id="msebaa_content_denial_message" name="%1$s[content_denial_message]">%2$s</textarea>',
			esc_attr( Msebaa_Settings::OPTION_KEY ),
			esc_textarea( $settings['content_denial_message'] )
		);
		echo '<p class="description">' . esc_html__(
			'Plain text. Leave blank to restore the default message.',
			'membersuite-ebaa'
		) . '</p>';
	}

	/**
	 * Sign in with the API user when that email or password was just updated.
	 *
	 * Runs after the saved idToken is discarded, so a successful check stores a
	 * fresh token. The option is already written; this only reports the result.
	 *
	 * @param mixed $old_value Previous settings array.
	 * @param mixed $value     New settings array.
	 */
	public static function maybe_test_api_connection( $old_value, $value ): void {
		$old = is_array( $old_value ) ? $old_value : array();
		$new = is_array( $value ) ? $value : array();

		if ( ! self::api_user_credentials_changed( $old, $new ) ) {
			return;
		}

		self::report_api_connection( $new );
	}

	/**
	 * Sign in with the API user when settings are first created with credentials.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New settings array.
	 */
	public static function maybe_test_api_connection_on_add( string $option, $value ): void {
		if ( Msebaa_Settings::OPTION_KEY !== $option ) {
			return;
		}

		$new = is_array( $value ) ? $value : array();

		if ( ! self::api_user_credentials_changed( Msebaa_Settings::defaults(), $new ) ) {
			return;
		}

		self::report_api_connection( $new );
	}

	/**
	 * True when the API user email or password differs.
	 *
	 * @param array<string, mixed> $old Previous settings.
	 * @param array<string, mixed> $new New settings.
	 */
	private static function api_user_credentials_changed( array $old, array $new ): bool {
		foreach ( array( 'api_user_email', 'api_user_password' ) as $key ) {
			$previous = isset( $old[ $key ] ) && is_scalar( $old[ $key ] ) ? (string) $old[ $key ] : '';
			$current  = isset( $new[ $key ] ) && is_scalar( $new[ $key ] ) ? (string) $new[ $key ] : '';

			if ( $previous !== $current ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Call MemberSuite loginUser and record a settings notice.
	 *
	 * The notice never includes the password, tokens, or the remote response body.
	 *
	 * @param array<string, mixed> $settings Saved settings, including the API password.
	 */
	private static function report_api_connection( array $settings ): void {
		$tenant_id = isset( $settings['tenant_id'] ) && is_scalar( $settings['tenant_id'] )
			? (string) $settings['tenant_id']
			: '';
		$email     = isset( $settings['api_user_email'] ) && is_scalar( $settings['api_user_email'] )
			? (string) $settings['api_user_email']
			: '';
		$password  = isset( $settings['api_user_password'] ) && is_scalar( $settings['api_user_password'] )
			? (string) $settings['api_user_password']
			: '';

		if ( '' === $tenant_id || '' === $email || '' === $password ) {
			add_settings_error(
				Msebaa_Settings::OPTION_KEY,
				'msebaa_api_connection_failed',
				__( 'Settings saved, but the API connection could not be tested because the Association Key, API user email, or API user password is missing.', 'membersuite-ebaa' ),
				'error'
			);
			return;
		}

		$client = new Msebaa_Api_Client( $settings );
		$result = $client->get_api_id_token( true );

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				Msebaa_Settings::OPTION_KEY,
				'msebaa_api_connection_failed',
				__( 'Settings saved, but the MemberSuite API connection failed. Check the API user email, password, and Association Key.', 'membersuite-ebaa' ),
				'error'
			);
			return;
		}

		add_settings_error(
			Msebaa_Settings::OPTION_KEY,
			'msebaa_api_connection_succeeded',
			__( 'Settings saved. The MemberSuite API connection succeeded.', 'membersuite-ebaa' ),
			'success'
		);
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
