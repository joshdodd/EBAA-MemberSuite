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
 * Rewrites attachment URLs, stores members-only files outside the web root,
 * and streams or redirects gated downloads.
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
	 * Post meta listing uploads-relative paths removed while a file is members-only.
	 *
	 * The first entry is the path previously stored in `_wp_attached_file`.
	 */
	public const PATHS_META = '_msebaa_private_paths';

	/**
	 * Register URL filters on every request, and front-end gates on public requests.
	 *
	 * URL filters also run in wp-admin so the Edit Media File URL shows the download route.
	 */
	public static function register(): void {
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'wp_get_original_image_url', array( __CLASS__, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_image_src' ), 10, 4 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_srcset' ), 10, 5 );

		if ( is_admin() ) {
			return;
		}

		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ) );
		add_filter( 'the_content', array( __CLASS__, 'rewrite_content_urls' ), 12 );
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
		if ( ! Msebaa_Content_Gate::is_members_only( (int) $attachment_id ) ) {
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

		if ( ! is_array( $image ) || ! isset( $image[0] ) ) {
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

		if ( ! Msebaa_Content_Gate::is_members_only( (int) $attachment_id ) ) {
			return $sources;
		}

		return false;
	}

	/**
	 * Stream a gated download or redirect attachment singular views that are denied.
	 */
	public static function handle_request(): void {
		self::maybe_stream_download();
		self::maybe_redirect_removed_upload();
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
		$real = self::allowed_realpath( is_string( $file ) ? $file : '' );

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
	 * Real path of a file inside uploads or private storage, or null when it is not.
	 *
	 * @param string $file Absolute or relative path from get_attached_file().
	 */
	private static function allowed_realpath( string $file ): ?string {
		if ( '' === $file ) {
			return null;
		}

		$real = realpath( $file );
		if ( false === $real ) {
			return null;
		}

		$real = wp_normalize_path( $real );
		foreach ( self::readable_roots() as $root ) {
			if ( $real === $root || str_starts_with( $real, trailingslashit( $root ) ) ) {
				return $real;
			}
		}

		return null;
	}

	/**
	 * Directories a gated download is allowed to read.
	 *
	 * @return string[]
	 */
	private static function readable_roots(): array {
		$roots   = array();
		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$base = realpath( (string) $uploads['basedir'] );
			if ( false !== $base ) {
				$roots[] = wp_normalize_path( $base );
			}
		}

		$private = realpath( self::private_root() );
		if ( false !== $private ) {
			$roots[] = wp_normalize_path( $private );
		}

		return $roots;
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

	/**
	 * Move an attachment into or out of private storage and persist the flag.
	 *
	 * Enabling fails closed: when the files cannot leave the public uploads
	 * tree, the flag is not saved. Disabling restores the public copies first.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $enabled       True stores the file privately.
	 */
	public static function apply_members_only( int $attachment_id, bool $enabled ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}

		if ( $enabled ) {
			return self::protect( $attachment_id );
		}

		return self::release( $attachment_id );
	}

	/**
	 * Restore every privately stored attachment, then drop its gate meta.
	 *
	 * Called from uninstall before the blanket members-only meta delete. Attachment
	 * posts are not deleted. Returns IDs whose files could not be moved back;
	 * those attachments keep `_msebaa_private_paths`.
	 *
	 * @return int[]
	 */
	public static function restore_all_for_uninstall(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::PATHS_META
			)
		);

		$failed = array();
		foreach ( $ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( ! self::release( $attachment_id ) ) {
				$failed[] = $attachment_id;
			}
		}

		return $failed;
	}

	/**
	 * Directory outside the web root that holds members-only files.
	 */
	public static function private_root(): string {
		return wp_normalize_path( trailingslashit( dirname( ABSPATH ) ) . 'msebaa-private' );
	}

	/**
	 * Replace members-only upload URLs in rendered post content.
	 *
	 * @param mixed $content Post content.
	 * @return mixed
	 */
	public static function rewrite_content_urls( $content ) {
		if ( ! is_string( $content ) || is_admin() || is_feed() ) {
			return $content;
		}

		if ( ! str_contains( $content, '/uploads/' ) && ! str_contains( $content, self::QUERY_ARG ) ) {
			return $content;
		}

		$pairs = self::protected_url_pairs();
		if ( array() === $pairs ) {
			return $content;
		}

		return str_replace( array_keys( $pairs ), array_values( $pairs ), $content );
	}

	/**
	 * Redirect a request for a removed uploads path. Does not send the file.
	 */
	private static function maybe_redirect_removed_upload(): void {
		if ( isset( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Download route is handled separately.
			return;
		}

		$relative = self::request_uploads_relative();
		if ( '' === $relative ) {
			return;
		}

		$attachment_id = self::attachment_id_for_relative_path( $relative );
		if ( $attachment_id <= 0 ) {
			return;
		}

		$target = Msebaa_Content_Gate::is_allowed( $attachment_id )
			? self::download_url( $attachment_id )
			: self::denied_login_url( $attachment_id );

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Move public files for an attachment into private storage and set the flag.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function protect( int $attachment_id ): bool {
		if ( self::is_stored_privately( $attachment_id ) ) {
			update_post_meta( $attachment_id, Msebaa_Content_Gate::META_KEY, '1' );
			$paths = get_post_meta( $attachment_id, self::PATHS_META, true );
			if ( is_array( $paths ) ) {
				self::rewrite_stored_links( $attachment_id, $paths, true );
			}
			return true;
		}

		$collected = self::collect_public_files( $attachment_id );
		if ( null === $collected ) {
			return self::fail(
				__( 'Members Only could not be enabled because the file could not be moved out of the public uploads folder. The file is still publicly available.', 'membersuite-ebaa' )
			);
		}

		$dir = self::ensure_private_dir( $attachment_id );
		if ( null === $dir ) {
			return self::fail(
				__( 'Members Only could not be enabled because the file could not be moved out of the public uploads folder. The file is still publicly available.', 'membersuite-ebaa' )
			);
		}

		$moved = array();
		foreach ( $collected['files'] as $relative => $absolute ) {
			$destination = wp_normalize_path( trailingslashit( $dir ) . wp_basename( $absolute ) );
			if ( ! self::move_file( $absolute, $destination ) ) {
				self::rollback_moves( $moved );
				return self::fail(
					__( 'Members Only could not be enabled because the file could not be moved out of the public uploads folder. The file is still publicly available.', 'membersuite-ebaa' )
				);
			}
			$moved[] = array( $absolute, $destination );
		}

		$paths = array( $collected['main'] );
		foreach ( array_keys( $collected['files'] ) as $relative ) {
			if ( $relative !== $collected['main'] ) {
				$paths[] = $relative;
			}
		}

		$main_destination = wp_normalize_path( trailingslashit( $dir ) . wp_basename( $collected['files'][ $collected['main'] ] ) );
		update_attached_file( $attachment_id, $main_destination );
		update_post_meta( $attachment_id, self::PATHS_META, $paths );
		update_post_meta( $attachment_id, Msebaa_Content_Gate::META_KEY, '1' );
		self::rewrite_stored_links( $attachment_id, $paths, true );

		return true;
	}

	/**
	 * Move a privately stored attachment back to its public uploads paths.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function release( int $attachment_id ): bool {
		$paths = get_post_meta( $attachment_id, self::PATHS_META, true );
		if ( ! is_array( $paths ) || array() === $paths ) {
			delete_post_meta( $attachment_id, Msebaa_Content_Gate::META_KEY );
			return true;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return self::fail(
				__( 'Members Only could not be turned off because the file could not be moved back to the uploads folder.', 'membersuite-ebaa' )
			);
		}

		$basedir = wp_normalize_path( (string) $uploads['basedir'] );
		$dir     = self::private_dir( $attachment_id );
		$moved   = array();

		foreach ( $paths as $relative ) {
			if ( ! is_string( $relative ) || '' === $relative || str_contains( $relative, '..' ) ) {
				self::rollback_moves( $moved );
				return self::fail(
					__( 'Members Only could not be turned off because the file could not be moved back to the uploads folder.', 'membersuite-ebaa' )
				);
			}

			$relative    = ltrim( $relative, '/' );
			$source      = wp_normalize_path( trailingslashit( $dir ) . wp_basename( $relative ) );
			$destination = wp_normalize_path( path_join( $basedir, $relative ) );

			if ( is_file( $destination ) && ! is_file( $source ) ) {
				continue;
			}

			if ( ! is_file( $source ) || ! self::move_file( $source, $destination ) ) {
				self::rollback_moves( $moved );
				return self::fail(
					__( 'Members Only could not be turned off because the file could not be moved back to the uploads folder.', 'membersuite-ebaa' )
				);
			}

			$moved[] = array( $source, $destination );
		}

		$main = is_string( $paths[0] ) ? ltrim( $paths[0], '/' ) : '';
		if ( '' !== $main ) {
			update_attached_file( $attachment_id, $main );
		}

		self::rewrite_stored_links( $attachment_id, $paths, false );
		delete_post_meta( $attachment_id, self::PATHS_META );
		delete_post_meta( $attachment_id, Msebaa_Content_Gate::META_KEY );
		self::remove_empty_dir( $dir );

		return true;
	}

	/**
	 * Whether this attachment's bytes already live in private storage.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function is_stored_privately( int $attachment_id ): bool {
		$paths = get_post_meta( $attachment_id, self::PATHS_META, true );
		if ( ! is_array( $paths ) || array() === $paths ) {
			return false;
		}

		$file = get_attached_file( $attachment_id );
		return is_string( $file ) && '' !== $file && self::path_is_inside( $file, self::private_root() );
	}

	/**
	 * Public files for an attachment, keyed by uploads-relative path.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{main: string, files: array<string, string>}|null
	 */
	private static function collect_public_files( int $attachment_id ): ?array {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		$basedir  = (string) $uploads['basedir'];
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $attached ) || '' === $attached ) {
			return null;
		}

		$main_absolute = self::is_absolute_path( $attached ) ? $attached : path_join( $basedir, $attached );
		if ( ! self::path_is_inside( $main_absolute, $basedir ) || ! is_file( $main_absolute ) ) {
			return null;
		}

		$main_real     = realpath( $main_absolute );
		$basedir_real  = realpath( $basedir );
		if ( false === $main_real || false === $basedir_real ) {
			return null;
		}

		$main_real     = wp_normalize_path( $main_real );
		$basedir_real  = wp_normalize_path( $basedir_real );
		$main_relative = ltrim( substr( $main_real, strlen( $basedir_real ) ), '/' );
		if ( '' === $main_relative || str_contains( $main_relative, '..' ) ) {
			return null;
		}

		$files = array( $main_relative => $main_real );
		$meta  = wp_get_attachment_metadata( $attachment_id );
		$dir   = wp_normalize_path( dirname( $main_real ) );

		if ( is_array( $meta ) ) {
			$extras = array();
			if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
				$extras[] = $meta['original_image'];
			}
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
						$extras[] = $size['file'];
					}
				}
			}

			foreach ( $extras as $basename ) {
				$basename = wp_basename( $basename );
				if ( '' === $basename || str_contains( $basename, '..' ) ) {
					continue;
				}

				$absolute = wp_normalize_path( path_join( $dir, $basename ) );
				if ( ! self::path_is_inside( $absolute, $basedir ) || ! is_file( $absolute ) ) {
					continue;
				}

				$real = realpath( $absolute );
				if ( false === $real ) {
					continue;
				}

				$real     = wp_normalize_path( $real );
				$relative = ltrim( substr( $real, strlen( $basedir_real ) ), '/' );
				if ( '' === $relative || str_contains( $relative, '..' ) ) {
					continue;
				}

				$files[ $relative ] = $real;
			}
		}

		return array(
			'main'  => $main_relative,
			'files' => $files,
		);
	}

	/**
	 * Create the private directory for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function ensure_private_dir( int $attachment_id ): ?string {
		$root = self::private_root();
		$web  = wp_normalize_path( untrailingslashit( ABSPATH ) );
		if ( $root === $web || str_starts_with( $root, trailingslashit( $web ) ) ) {
			return null;
		}

		if ( ! wp_mkdir_p( $root ) ) {
			return null;
		}

		$index = wp_normalize_path( trailingslashit( $root ) . 'index.php' );
		if ( ! is_file( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- One-line guard file outside the web root.
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$dir = self::private_dir( $attachment_id );
		if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
			return null;
		}

		return $dir;
	}

	/**
	 * Private directory for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function private_dir( int $attachment_id ): string {
		return wp_normalize_path( trailingslashit( self::private_root() ) . (string) absint( $attachment_id ) );
	}

	/**
	 * Move a file, falling back to copy and delete when rename cannot cross directories.
	 *
	 * @param string $from Source path.
	 * @param string $to   Destination path.
	 */
	private static function move_file( string $from, string $to ): bool {
		if ( ! is_file( $from ) || file_exists( $to ) ) {
			return false;
		}

		if ( ! wp_mkdir_p( dirname( $to ) ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-volume move; copy fallback follows.
		if ( rename( $from, $to ) ) {
			return is_file( $to );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Fallback when rename cannot cross filesystems.
		if ( ! copy( $from, $to ) ) {
			return false;
		}

		wp_delete_file( $from );
		if ( is_file( $from ) ) {
			wp_delete_file( $to );
			return false;
		}

		return is_file( $to );
	}

	/**
	 * Move files back to their sources after a failed batch.
	 *
	 * @param array<int, array{0: string, 1: string}> $moved Pairs of source, destination.
	 */
	private static function rollback_moves( array $moved ): void {
		foreach ( array_reverse( $moved ) as $pair ) {
			self::move_file( $pair[1], $pair[0] );
		}
	}

	/**
	 * Remove a private attachment directory when it no longer holds files.
	 *
	 * @param string $dir Directory path.
	 */
	private static function remove_empty_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return;
		}

		$entries = array_diff( $entries, array( '.', '..' ) );
		if ( array() !== $entries ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Directory is empty and was created by this plugin.
		rmdir( $dir );
	}

	/**
	 * Rewrite stored post and page content for one attachment.
	 *
	 * @param int      $attachment_id  Attachment ID.
	 * @param string[] $relative_paths Uploads-relative paths, main file first.
	 * @param bool     $protect        True points links at the download route.
	 */
	private static function rewrite_stored_links( int $attachment_id, array $relative_paths, bool $protect ): void {
		$pairs = self::link_pairs( $attachment_id, $relative_paths, $protect );
		if ( array() === $pairs ) {
			return;
		}

		$needles = $protect ? $relative_paths : array( self::QUERY_ARG . '=' . $attachment_id );
		$ids     = self::post_ids_containing( $needles );
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || ! is_string( $post->post_content ) || '' === $post->post_content ) {
				continue;
			}

			$updated = str_replace( array_keys( $pairs ), array_values( $pairs ), $post->post_content );
			if ( $updated === $post->post_content ) {
				continue;
			}

			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$wpdb->posts,
				array( 'post_content' => $updated ),
				array( 'ID' => $post_id ),
				array( '%s' ),
				array( '%d' )
			);
			clean_post_cache( $post_id );
		}
	}

	/**
	 * Public URL variants that should become the download route, for every protected file.
	 *
	 * @return array<string, string>
	 */
	private static function protected_url_pairs(): array {
		static $pairs = null;
		if ( is_array( $pairs ) ) {
			return $pairs;
		}

		$pairs = array();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::PATHS_META
			)
		);

		foreach ( $ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( ! Msebaa_Content_Gate::is_members_only( $attachment_id ) ) {
				continue;
			}

			$paths = get_post_meta( $attachment_id, self::PATHS_META, true );
			if ( ! is_array( $paths ) ) {
				continue;
			}

			$pairs = array_merge( $pairs, self::link_pairs( $attachment_id, $paths, true ) );
		}

		uksort(
			$pairs,
			static function ( string $left, string $right ): int {
				return strlen( $right ) <=> strlen( $left );
			}
		);

		return $pairs;
	}

	/**
	 * Search/replace pairs for one attachment's links.
	 *
	 * Longer URLs are listed first so a full uploads URL is replaced before any shorter form.
	 *
	 * @param int      $attachment_id  Attachment ID.
	 * @param string[] $relative_paths Uploads-relative paths, main file first.
	 * @param bool     $protect        True maps public URLs to the download route.
	 * @return array<string, string>
	 */
	private static function link_pairs( int $attachment_id, array $relative_paths, bool $protect ): array {
		$baseurl = self::uploads_baseurl();
		if ( '' === $baseurl ) {
			return array();
		}

		$download = self::download_url( $attachment_id );
		$pairs    = array();

		if ( $protect ) {
			foreach ( $relative_paths as $relative ) {
				if ( ! is_string( $relative ) || '' === $relative ) {
					continue;
				}

				$url = $baseurl . '/' . ltrim( $relative, '/' );
				foreach ( self::url_variants( $url ) as $variant ) {
					$pairs[ $variant ] = self::matching_form( $variant, $download );
				}
			}
		} else {
			$main = '';
			foreach ( $relative_paths as $relative ) {
				if ( is_string( $relative ) && '' !== $relative ) {
					$main = ltrim( $relative, '/' );
					break;
				}
			}

			if ( '' === $main ) {
				return array();
			}

			$public = $baseurl . '/' . $main;
			foreach ( self::url_variants( $download ) as $variant ) {
				$pairs[ $variant ] = self::matching_form( $variant, $public );
			}
		}

		uksort(
			$pairs,
			static function ( string $left, string $right ): int {
				return strlen( $right ) <=> strlen( $left );
			}
		);

		return $pairs;
	}

	/**
	 * Scheme and JSON-escaped forms of a URL.
	 *
	 * @param string $url Absolute URL.
	 * @return string[]
	 */
	private static function url_variants( string $url ): array {
		$variants = array(
			$url,
			set_url_scheme( $url, 'https' ),
			set_url_scheme( $url, 'http' ),
		);

		$protocol_relative = preg_replace( '#^https?:#', '', $url );
		if ( is_string( $protocol_relative ) && '' !== $protocol_relative ) {
			$variants[] = $protocol_relative;
		}

		$escaped = array();
		foreach ( $variants as $variant ) {
			$json = str_replace( '/', '\\/', $variant );
			if ( $json !== $variant ) {
				$escaped[] = $json;
			}
		}

		return array_values( array_unique( array_merge( $variants, $escaped ) ) );
	}

	/**
	 * Return $target in the same plain or JSON-escaped form as $needle.
	 *
	 * @param string $needle Source text.
	 * @param string $target Replacement URL.
	 */
	private static function matching_form( string $needle, string $target ): string {
		if ( str_contains( $needle, '\\/' ) ) {
			return str_replace( '/', '\\/', $target );
		}

		return $target;
	}

	/**
	 * Uploads base URL without a trailing slash.
	 */
	private static function uploads_baseurl(): string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		return untrailingslashit( (string) $uploads['baseurl'] );
	}

	/**
	 * Post and page IDs whose content contains any needle.
	 *
	 * @param string[] $needles Substrings to find.
	 * @return int[]
	 */
	private static function post_ids_containing( array $needles ): array {
		global $wpdb;

		$ids = array();
		foreach ( $needles as $needle ) {
			if ( ! is_string( $needle ) || strlen( $needle ) < 3 ) {
				continue;
			}

			$like = '%' . $wpdb->esc_like( $needle ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s",
					$like
				)
			);

			foreach ( $found as $post_id ) {
				$ids[] = (int) $post_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Uploads-relative path for the current request, or an empty string.
	 */
	private static function request_uploads_relative(): string {
		$candidates = array();

		if ( isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only path match.
			$uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared as a path, not stored or printed.
			if ( is_string( $uri ) && '' !== $uri ) {
				$candidates[] = $uri;
			}
		}

		if ( isset( $_SERVER['REDIRECT_URL'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nginx internal redirect of a missing upload.
			$redirect = wp_unslash( $_SERVER['REDIRECT_URL'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared as a path, not stored or printed.
			if ( is_string( $redirect ) && '' !== $redirect ) {
				$candidates[] = $redirect;
			}
		}

		if ( isset( $_GET['q'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Local nginx 404 fallthrough query.
			$query_path = wp_unslash( $_GET['q'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared as a path, not stored or printed.
			if ( is_string( $query_path ) && '' !== $query_path ) {
				$candidates[] = '/' . ltrim( $query_path, '/' );
			}
		}

		foreach ( $candidates as $candidate ) {
			$relative = self::uploads_relative_from_candidate( $candidate );
			if ( '' !== $relative ) {
				return $relative;
			}
		}

		return '';
	}

	/**
	 * Uploads-relative path inside a request URI, or an empty string.
	 *
	 * @param string $uri Request URI or path.
	 */
	private static function uploads_relative_from_candidate( string $uri ): string {
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = $uri;
		}

		$path = rawurldecode( $path );
		if ( ! str_starts_with( $path, '/' ) ) {
			$path = '/' . $path;
		}

		$uploads = wp_upload_dir();
		$base    = wp_parse_url( (string) ( $uploads['baseurl'] ?? '' ), PHP_URL_PATH );
		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}

		$base = untrailingslashit( $base );
		if ( ! str_starts_with( $path, $base . '/' ) ) {
			return '';
		}

		$relative = ltrim( substr( $path, strlen( $base ) ), '/' );
		if ( '' === $relative || str_contains( $relative, '..' ) ) {
			return '';
		}

		return $relative;
	}

	/**
	 * Attachment whose private-path list contains this uploads-relative path.
	 *
	 * @param string $relative Uploads-relative path.
	 */
	private static function attachment_id_for_relative_path( string $relative ): int {
		$candidates = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 20,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs only for uploads-path requests.
					array(
						'key'     => self::PATHS_META,
						'value'   => $relative,
						'compare' => 'LIKE',
					),
				),
			)
		);

		foreach ( $candidates as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$paths         = get_post_meta( $attachment_id, self::PATHS_META, true );
			if ( is_array( $paths ) && in_array( $relative, $paths, true ) && Msebaa_Content_Gate::is_members_only( $attachment_id ) ) {
				return $attachment_id;
			}
		}

		return 0;
	}

	/**
	 * Whether a path lives inside a directory.
	 *
	 * @param string $path File path.
	 * @param string $root Directory.
	 */
	private static function path_is_inside( string $path, string $root ): bool {
		$real_root = realpath( $root );
		$real_path = realpath( $path );
		if ( false === $real_root || false === $real_path ) {
			return false;
		}

		$real_root = wp_normalize_path( $real_root );
		$real_path = wp_normalize_path( $real_path );

		return $real_path === $real_root || str_starts_with( $real_path, trailingslashit( $real_root ) );
	}

	/**
	 * Whether a stored attachment path is absolute.
	 *
	 * @param string $path Path from post meta.
	 */
	private static function is_absolute_path( string $path ): bool {
		return str_starts_with( $path, '/' ) || 1 === preg_match( '/^[A-Za-z]:[\\\\\\/]/', $path );
	}

	/**
	 * Record an admin error and report failure.
	 *
	 * @param string $message Translated message.
	 */
	private static function fail( string $message ): bool {
		if ( class_exists( 'Msebaa_Members_Only_Meta' ) ) {
			Msebaa_Members_Only_Meta::queue_error( $message );
		}

		return false;
	}
}
