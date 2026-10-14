<?php
/**
 * Members Only controls: sidebar meta box for posts and pages,
 * media-details checkbox for attachments.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saves `_msebaa_members_only` when the editor has edit capability for the item.
 */
class Msebaa_Members_Only_Meta {

	/**
	 * Nonce action for the meta box save.
	 */
	public const NONCE_ACTION = 'msebaa_members_only_save';

	/**
	 * Nonce field name.
	 */
	public const NONCE_FIELD = 'msebaa_members_only_nonce';

	/**
	 * Post types that show the sidebar Members Only meta box.
	 *
	 * Attachments use the media-details field only, so Edit Media does not
	 * show a second sidebar checkbox.
	 *
	 * @var string[]
	 */
	private const POST_TYPES = array( 'post', 'page' );

	/**
	 * Register the meta box, the media-details field, and save-failure notices.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'attachment_fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_attachment_fields' ), 10, 2 );
	}

	/**
	 * Add the checkbox meta box to posts and pages.
	 */
	public static function add_meta_boxes(): void {
		foreach ( self::POST_TYPES as $post_type ) {
			add_meta_box(
				'msebaa_members_only',
				__( 'Members Only', 'membersuite-ebaa' ),
				array( __CLASS__, 'render' ),
				$post_type,
				'side',
				'default',
				array(
					'__block_editor_compatible_meta_box' => true,
				)
			);
		}
	}

	/**
	 * Render the Members Only checkbox.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$checked = self::is_flagged( $post->ID );
		?>
		<p>
			<label for="msebaa_members_only">
				<input
					type="checkbox"
					id="msebaa_members_only"
					name="msebaa_members_only"
					value="1"
					<?php checked( $checked ); ?>
				/>
				<?php echo esc_html__( 'Members Only', 'membersuite-ebaa' ); ?>
			</label>
		</p>
		<p class="description">
			<?php echo esc_html__( 'Only signed-in members can access this item. Site administrators can still open it.', 'membersuite-ebaa' ); ?>
		</p>
		<?php
	}

	/**
	 * Persist the checkbox from the post editor.
	 *
	 * Users who cannot edit the item, or requests without a valid nonce,
	 * do not change the flag. Missing nonce also covers autosave, quick edit,
	 * and REST saves that did not include this meta box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save( int $post_id, WP_Post $post ): void {
		if ( ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$checked = isset( $_POST['msebaa_members_only'] )
			&& '1' === sanitize_text_field( wp_unslash( $_POST['msebaa_members_only'] ) );

		self::store_flag( $post_id, $checked );
	}

	/**
	 * Checkbox on the media modal / attachment compat form.
	 *
	 * The modal does not render meta boxes. WordPress verifies its own
	 * media nonce before `attachment_fields_to_save`; this field still
	 * re-checks `edit_post` on save.
	 *
	 * @param array<string, mixed> $form_fields Existing fields.
	 * @param WP_Post              $post        Attachment.
	 * @return array<string, mixed>
	 */
	public static function attachment_fields( array $form_fields, WP_Post $post ): array {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $form_fields;
		}

		$checked = self::is_flagged( $post->ID );
		$post_id = (int) $post->ID;

		$html  = '<input type="hidden" name="attachments[' . $post_id . '][msebaa_members_only_present]" value="1" />';
		$html .= '<input type="checkbox" name="attachments[' . $post_id . '][msebaa_members_only]" value="1" ' . checked( $checked, true, false ) . ' />';

		$form_fields['msebaa_members_only'] = array(
			'label' => __( 'Members Only', 'membersuite-ebaa' ),
			'input' => 'html',
			'html'  => $html,
			'helps' => __( 'Only signed-in members can access this file. Site administrators can still open it.', 'membersuite-ebaa' ),
		);

		return $form_fields;
	}

	/**
	 * Save the media-modal checkbox.
	 *
	 * @param array<string, mixed> $post       Attachment post data.
	 * @param array<string, mixed> $attachment Submitted attachment fields.
	 * @return array<string, mixed>
	 */
	public static function save_attachment_fields( array $post, array $attachment ): array {
		$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		if ( $post_id <= 0 || empty( $attachment['msebaa_members_only_present'] ) ) {
			return $post;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $post;
		}

		$checked = isset( $attachment['msebaa_members_only'] ) && '1' === (string) $attachment['msebaa_members_only'];
		if ( class_exists( 'Msebaa_Media_Gate' ) ) {
			Msebaa_Media_Gate::apply_members_only( $post_id, $checked );
			return $post;
		}

		self::store_flag( $post_id, $checked );

		return $post;
	}

	/**
	 * Remember a members-only save error for the current user.
	 *
	 * @param string $message Already translated admin message.
	 */
	public static function queue_error( string $message ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || '' === $message ) {
			return;
		}

		set_transient( self::error_transient_key( $user_id ), $message, MINUTE_IN_SECONDS );
	}

	/**
	 * Show a queued members-only save error once.
	 */
	public static function render_admin_notice(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$key     = self::error_transient_key( $user_id );
		$message = get_transient( $key );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( $key );

		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Transient key for a user's members-only save error.
	 *
	 * @param int $user_id User ID.
	 */
	private static function error_transient_key( int $user_id ): string {
		return 'msebaa_media_admin_error_' . $user_id;
	}

	/**
	 * Whether the members-only flag is stored.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function is_flagged( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, Msebaa_Content_Gate::META_KEY, true );
	}

	/**
	 * Write or remove the members-only flag.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $enabled True stores `'1'`; false deletes the meta.
	 */
	private static function store_flag( int $post_id, bool $enabled ): void {
		if ( $enabled ) {
			update_post_meta( $post_id, Msebaa_Content_Gate::META_KEY, '1' );
			return;
		}

		delete_post_meta( $post_id, Msebaa_Content_Gate::META_KEY );
	}
}
