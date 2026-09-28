<?php
/**
 * Standalone password-reset shortcode.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders `[msebaa_password_reset]` and the shared reset form markup.
 */
class Msebaa_Password_Reset_Shortcode {

	/**
	 * Shortcode tag.
	 */
	public const TAG = 'msebaa_password_reset';

	/**
	 * Whether assets were queued for this request.
	 */
	private static bool $assets_queued = false;

	/**
	 * Register the shortcode.
	 */
	public static function register(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the standalone reset form. Available to signed-in visitors as well.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes (unused in v1).
	 */
	public static function render( $atts = array() ): string {
		unset( $atts );
		self::enqueue_assets();

		return self::form_html( 'standalone' );
	}

	/**
	 * Markup for the reset form, used by the standalone shortcode and the SSO inline panel.
	 *
	 * @param string $context `standalone` or `inline`.
	 */
	public static function form_html( string $context = 'standalone' ): string {
		$result  = Msebaa_Password_Reset::process_submission();
		$outcome = is_array( $result ) ? $result['outcome'] : '';
		$email   = is_array( $result ) ? $result['email'] : '';

		$wrapper_class = 'standalone' === $context
			? 'msebaa-password-reset'
			: 'msebaa-password-reset msebaa-password-reset--inline';

		$email_id = 'standalone' === $context ? 'msebaa_reset_email' : 'msebaa_reset_email_sso';

		$show_form = Msebaa_Password_Reset::OUTCOME_ACCEPTED !== $outcome
			&& Msebaa_Password_Reset::OUTCOME_RATE_LIMITED !== $outcome;

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrapper_class ); ?>">
			<?php if ( '' !== $outcome ) : ?>
				<?php echo self::message_html( $outcome ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in message_html(). ?>
			<?php endif; ?>

			<?php if ( $show_form ) : ?>
				<form class="msebaa-password-reset__form" method="post" action="" novalidate>
					<?php wp_nonce_field( Msebaa_Password_Reset::NONCE_ACTION, Msebaa_Password_Reset::NONCE_FIELD ); ?>

					<p class="msebaa-password-reset__field">
						<label for="<?php echo esc_attr( $email_id ); ?>"><?php echo esc_html__( 'Email', 'membersuite-ebaa' ); ?></label>
						<input
							type="email"
							id="<?php echo esc_attr( $email_id ); ?>"
							name="<?php echo esc_attr( Msebaa_Password_Reset::EMAIL_FIELD ); ?>"
							value="<?php echo esc_attr( $email ); ?>"
							autocomplete="email"
							required
						/>
					</p>

					<p class="msebaa-password-reset__actions">
						<button type="submit" class="msebaa-password-reset__submit">
							<?php echo esc_html__( 'Send reset instructions', 'membersuite-ebaa' ); ?>
						</button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Enqueue reset-form CSS. Safe to call more than once per request.
	 */
	public static function enqueue_assets(): void {
		if ( self::$assets_queued ) {
			return;
		}

		self::$assets_queued = true;

		wp_enqueue_style(
			'msebaa-password-reset',
			MSEBAA_PLUGIN_URL . 'public/css/msebaa-password-reset.css',
			array(),
			MSEBAA_VERSION
		);
	}

	/**
	 * Escaped status message markup for an outcome.
	 *
	 * @param string $outcome Outcome constant.
	 */
	private static function message_html( string $outcome ): string {
		$message = Msebaa_Password_Reset::message( $outcome );
		$role    = 'status';
		$class   = 'msebaa-password-reset__message msebaa-password-reset__message--confirmation';

		if ( Msebaa_Password_Reset::OUTCOME_VALIDATION === $outcome ) {
			$role  = 'alert';
			$class = 'msebaa-password-reset__message msebaa-password-reset__message--error';
		} elseif ( Msebaa_Password_Reset::OUTCOME_UNAVAILABLE === $outcome ) {
			$role  = 'alert';
			$class = 'msebaa-password-reset__message msebaa-password-reset__message--unavailable';
		}

		return '<div class="' . esc_attr( $class ) . '" role="' . esc_attr( $role ) . '"><p>'
			. esc_html( $message )
			. '</p></div>';
	}
}
