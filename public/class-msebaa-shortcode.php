<?php
/**
 * SSO login shortcode.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders `[msebaa_sso_login]` and enqueues related front-end assets.
 */
class Msebaa_Shortcode {

	/**
	 * Shortcode tag.
	 */
	public const TAG = 'msebaa_sso_login';

	/**
	 * Whether assets were queued for this request.
	 *
	 * @var bool
	 */
	private static bool $assets_queued = false;

	/**
	 * Register the shortcode.
	 */
	public static function register(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the SSO login form or signed-in notice.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'redirect' => '',
			),
			$atts,
			self::TAG
		);

		self::enqueue_assets();

		$media_notice = class_exists( 'Msebaa_Media_Gate' ) ? Msebaa_Media_Gate::denial_notice_html() : '';
		$reset_result = Msebaa_Password_Reset::process_submission();
		$reset_open   = is_array( $reset_result );

		if ( is_user_logged_in() ) {
			return $media_notice
				. '<div class="msebaa-sso-form msebaa-sso-form--signed-in">'
				. '<p role="status">' . esc_html__( 'You are already signed in.', 'membersuite-ebaa' ) . '</p>'
				. self::forgot_panel_html( $reset_open )
				. '</div>';
		}

		$redirect = '';
		if ( '' !== $atts['redirect'] ) {
			$redirect = Msebaa_Sso::validate_redirect_url( $atts['redirect'] );
		}
		if ( '' === $redirect && isset( $_GET['msebaa_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redirect = Msebaa_Sso::validate_redirect_url(
				(string) wp_unslash( $_GET['msebaa_redirect'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
		}

		$error_message = '';
		if ( isset( $_GET['msebaa_sso_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error_message = Msebaa_Sso::consume_error(
				(string) wp_unslash( $_GET['msebaa_sso_error'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
		}

		$action = admin_url( 'admin-post.php' );

		ob_start();
		echo $media_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Msebaa_Media_Gate::denial_notice_html().
		?>
		<div class="msebaa-sso-form">
			<?php if ( '' !== $error_message ) : ?>
				<div class="msebaa-sso-form__error" role="alert">
					<p><?php echo esc_html( $error_message ); ?></p>
				</div>
			<?php endif; ?>
			<form class="msebaa-sso-form__form" method="post" action="<?php echo esc_url( $action ); ?>" novalidate>
				<input type="hidden" name="action" value="<?php echo esc_attr( Msebaa_Sso::ACTION ); ?>" />
				<?php wp_nonce_field( Msebaa_Sso::NONCE_ACTION, Msebaa_Sso::NONCE_FIELD ); ?>
				<?php if ( '' !== $redirect ) : ?>
					<input type="hidden" name="msebaa_redirect" value="<?php echo esc_url( $redirect ); ?>" />
				<?php endif; ?>

				<p class="msebaa-sso-form__field">
					<label for="msebaa_email"><?php echo esc_html__( 'Email', 'membersuite-ebaa' ); ?></label>
					<input
						type="email"
						id="msebaa_email"
						name="msebaa_email"
						autocomplete="username"
						required
					/>
				</p>

				<p class="msebaa-sso-form__field">
					<label for="msebaa_password"><?php echo esc_html__( 'Password', 'membersuite-ebaa' ); ?></label>
					<input
						type="password"
						id="msebaa_password"
						name="msebaa_password"
						autocomplete="current-password"
						required
					/>
				</p>

				<p class="msebaa-sso-form__actions">
					<button type="submit" class="msebaa-sso-form__submit">
						<?php echo esc_html__( 'Sign in', 'membersuite-ebaa' ); ?>
					</button>
				</p>
			</form>
			<?php echo self::forgot_panel_html( $reset_open ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in forgot_panel_html(). ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Inline “Forgot password?” panel that reveals the reset form on this embed.
	 *
	 * @param bool $open Whether to expand the panel (after a reset POST).
	 */
	private static function forgot_panel_html( bool $open ): string {
		if ( class_exists( 'Msebaa_Password_Reset_Shortcode' ) ) {
			Msebaa_Password_Reset_Shortcode::enqueue_assets();
		}

		$open_attr = $open ? ' open' : '';

		$html  = '<details class="msebaa-sso-form__forgot"' . $open_attr . '>';
		$html .= '<summary>' . esc_html__( 'Forgot password?', 'membersuite-ebaa' ) . '</summary>';
		$html .= Msebaa_Password_Reset_Shortcode::form_html( 'inline' );
		$html .= '</details>';

		return $html;
	}

	/**
	 * Enqueue form CSS only when the shortcode renders.
	 */
	private static function enqueue_assets(): void {
		if ( self::$assets_queued ) {
			return;
		}

		self::$assets_queued = true;

		wp_enqueue_style(
			'msebaa-sso-form',
			MSEBAA_PLUGIN_URL . 'public/css/msebaa-sso-form.css',
			array(),
			MSEBAA_VERSION
		);
	}
}
