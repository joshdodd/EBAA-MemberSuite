<?php
/**
 * Members-only media URL rewriting and download gate.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites attachment URLs and streams or redirects gated media downloads.
 */
class Msebaa_Media_Gate {

	/**
	 * Query argument for the gated download route.
	 */
	public const QUERY_ARG = 'msebaa_download';

	/**
	 * Query argument that marks a login landing after a denied file request.
	 */
	public const DENIED_ARG = 'msebaa_media_denied';

	/**
	 * Extensions that must never be streamed from the upload directory.
	 *
	 * @var string[]
	 */
	private const BLOCKED_EXTENSIONS = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar' );

	/**
	 * Whether the file-denied notice was already printed this request.
	 *
	 * @var bool
	 */
	private static bool $notice_printed = false;

	/**
	 * Register front-end URL filters, the download handler, and the denial notice.
	 *
	 * Call from the plugin boot when `! is_admin()` so the media library keeps real file URLs.
	 */
	public static function register(): void {
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'wp_get_original_image_url', array( __CLASS__, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_image_src' ), 10, 4 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_srcset' ), 10, 5 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ) );
		add_filter( 'the_content', array( __CLASS__, 'prepend_denial_notice' ), 999 );
	}

	/**
	 * Point public attachment URLs at the gated download route.
	 *
	 * @param mixed $url           Original attachment URL, or false when core found no file.
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public static function filter_attachment_url( $url, $attachment_id ) {
		if ( is_admin() || ! Msebaa_Content_Gate::is_members_only( (int) $attachment_id ) ) {
			return $url;
		}

		return self::download_url( (int) $attachment_id );
	}

	/**
	 * Rewrite image src URLs, including intermediate sizes that bypass wp_get_attachment_url.
	 *
	 * @param array{0: string, 1: int, 2: int}|false $image         Image data or false.
	 * @param int                                    $attachment_id Attachment ID.
	 * @param string|int[]                           $size          Requested size.
	 * @param bool                                   $icon          Whether the image is an icon.
	 * @return array{0: string, 1: int, 2: int}|false
	 */
	public static function filter_image_src( $image, $attachment_id, $size, $icon ) {
		unset( $size, $icon );

		if ( is_admin() || ! is_array( $image ) || ! isset( $image[0] ) ) {
			return $image;
		}

		$attachment_id = (int) $attachment_id;
		if ( ! Msebaa_Content_Gate::is_members_only( $attachment_id ) ) {
			return $image;
		}

		$image[0] = self::download_url( $attachment_id );

		return $image;
	}

	/**
	 * Drop srcset for members-only images so intermediate files are not published.
	 *
	 * @param array<int, array<string, mixed>>|false $sources       Srcset sources.
	 * @param array{0: int, 1: int}                  $size_array    Width and height.
	 * @param string                                 $image_src     Image src.
	 * @param array<string, mixed>                   $image_meta    Attachment metadata.
	 * @param int                                    $attachment_id Attachment ID.
	 * @return array<int, array<string, mixed>>|false
	 */
	public static function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		unset( $size_array, $image_src, $image_meta );

		if ( is_admin() || ! Msebaa_Content_Gate::is_members_only( (int) $attachment_id ) ) {
			return $sources;
		}

		return false;
	}

	/**
	 * Stream a gated download or redirect attachment singular views that are denied.
	 */
	public static function handle_request(): void {
		self::maybe_stream_download();
		self::maybe_guard_attachment_singular();
	}

	/**
	 * Public URL of the download endpoint for an attachment.
	 *
	 * Does not call `wp_get_attachment_url`, so URL filters cannot recurse.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function download_url( int $attachment_id ): string {
		return add_query_arg(
			self::QUERY_ARG,
			(string) absint( $attachment_id ),
			home_url( '/' )
		);
	}

	/**
	 * Login URL with the non-member file message and a return to this download.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function denied_login_url( int $attachment_id ): string {
		$return    = self::download_url( $attachment_id );
		$login_url = function_exists( 'msebaa_get_login_url' ) ? msebaa_get_login_url( $return ) : home_url( '/' );

		return add_query_arg( self::DENIED_ARG, '1', $login_url );
	}

	/**
	 * File-denied notice for the login page. Empty when this request was not a denial.
	 *
	 * Safe to call more than once; only the first call returns markup.
	 */
	public static function denial_notice_html(): string {
		if ( self::$notice_printed || ! self::is_denied_request() ) {
			return '';
		}

		self::$notice_printed = true;

		return '<div class="msebaa-media-denied" role="alert"><p>'
			. esc_html__( 'This file is not accessible to non-members.', 'membersuite-ebaa' )
			. '</p></div>';
	}

	/**
	 * Prepend the file-denied notice when the login page does not render the SSO shortcode.
	 *
	 * @param mixed $content Post content.
	 * @return mixed
	 */
	public static function prepend_denial_notice( $content ) {
		if ( ! is_string( $content ) || is_admin() || is_feed() ) {
			return $content;
		}

		$notice = self::denial_notice_html();
		if ( '' === $notice ) {
			return $content;
		}

		return $notice . $content;
	}

	/**
	 * Serve `?msebaa_download={id}` when the requester is entitled.
	 */
	private static function maybe_stream_download(): void {
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only entitlement gate, not a state change.
			return;
		}

		$attachment_id = absint( wp_unslash( $_GET[ self::QUERY_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type || 'trash' === $attachment->post_status ) {
			self::not_found();
		}

		if ( ! Msebaa_Content_Gate::is_members_only( $attachment_id ) ) {
			$canonical = wp_get_attachment_url( $attachment_id );
			if ( is_string( $canonical ) && '' !== $canonical ) {
				wp_safe_redirect( $canonical );
				exit;
			}

			self::not_found();
		}

		if ( ! Msebaa_Content_Gate::is_allowed( $attachment_id ) ) {
			wp_safe_redirect( self::denied_login_url( $attachment_id ) );
			exit;
		}

		self::stream_attachment( $attachment_id );
	}

	/**
	 * Keep members-only attachment pages from rendering for unauthorized visitors.
	 */
	private static function maybe_guard_attachment_singular(): void {
		if ( ! is_singular( 'attachment' ) ) {
			return;
		}

		$attachment_id = (int) get_queried_object_id();
		if ( $attachment_id <= 0 || ! Msebaa_Content_Gate::is_members_only( $attachment_id ) ) {
			return;
		}

		if ( Msebaa_Content_Gate::is_allowed( $attachment_id ) ) {
			return;
		}

		wp_safe_redirect( self::denied_login_url( $attachment_id ) );
		exit;
	}

	/**
	 * Stream an attachment that lives inside the uploads directory.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function stream_attachment( int $attachment_id ): void {
		$file = get_attached_file( $attachment_id );
		$real = self::uploads_realpath( is_string( $file ) ? $file : '' );

		if ( null === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			self::not_found();
		}

		$extension = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, self::BLOCKED_EXTENSIONS, true ) ) {
			self::not_found();
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! is_string( $mime ) || 1 !== preg_match( '/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $mime ) ) {
			$mime = 'application/octet-stream';
		}

		$filename = sanitize_file_name( basename( $real ) );
		if ( '' === $filename ) {
			$filename = 'download';
		}

		$disposition = str_starts_with( $mime, 'image/' ) ? 'inline' : 'attachment';
		$length      = filesize( $real );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $filename . '"' );
		if ( false !== $length ) {
			header( 'Content-Length: ' . (string) $length );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- Binary stream; WP_Filesystem buffers the whole file.
		readfile( $real );
		exit;
	}

	/**
	 * Real path of a file inside the uploads directory, or null when it is not.
	 *
	 * @param string $file Absolute or relative path from get_attached_file().
	 */
	private static function uploads_realpath( string $file ): ?string {
		if ( '' === $file ) {
			return null;
		}

		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? realpath( (string) $uploads['basedir'] ) : false;
		$real    = realpath( $file );

		if ( false === $base || false === $real ) {
			return null;
		}

		$base = wp_normalize_path( $base );
		$real = wp_normalize_path( $real );
		$root = trailingslashit( $base );

		if ( $real !== $base && 0 !== strpos( $real, $root ) ) {
			return null;
		}

		return $real;
	}

	/**
	 * Whether this request was redirected here after a denied file attempt.
	 */
	private static function is_denied_request(): bool {
		if ( ! isset( $_GET[ self::DENIED_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only query flag.
			return false;
		}

		$flag = sanitize_text_field( wp_unslash( $_GET[ self::DENIED_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return '1' === $flag;
	}

	/**
	 * End the request with a not-found response. Does not reveal file paths.
	 */
	private static function not_found(): void {
		wp_die(
			esc_html__( 'File not found.', 'membersuite-ebaa' ),
			esc_html__( 'File not found.', 'membersuite-ebaa' ),
			array( 'response' => 404 )
		);
		exit; // wp_die() exits unless a handler returns; do not continue into a stream.
	}
}
