<?php
/**
 * Members-only post and page content gating.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entitlement checks and `the_content` denial for members-only posts/pages.
 */
class Msebaa_Content_Gate {

	/**
	 * Post meta key. Value `'1'` when Members Only is enabled; absent when not.
	 */
	public const META_KEY = '_msebaa_members_only';

	/**
	 * Register the singular content filter.
	 *
	 * Front-end only. Call from the plugin boot when `! is_admin()`.
	 */
	public static function register(): void {
		// Priority 1 runs before do_shortcode so restricted bodies never execute.
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 1 );
	}

	/**
	 * Whether the item is flagged Members Only.
	 *
	 * @param int $post_id Post, page, or attachment ID.
	 */
	public static function is_members_only( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		return '1' === (string) get_post_meta( $post_id, self::META_KEY, true );
	}

	/**
	 * Shared entitlement predicate for posts, pages, and media.
	 *
	 * Allowed when the item is not members-only, the requester has
	 * `manage_options`, or the requester is signed in with a cached
	 * benefits flag that is strictly true. Missing or unknown benefits
	 * fail closed.
	 *
	 * @param int $post_id Post, page, or attachment ID.
	 */
	public static function is_allowed( int $post_id ): bool {
		if ( ! self::is_members_only( $post_id ) ) {
			return true;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( is_user_logged_in() && function_exists( 'msebaa_receives_member_benefits' ) && msebaa_receives_member_benefits() ) {
			return true;
		}

		return false;
	}

	/**
	 * Replace members-only body content on singular posts and pages.
	 *
	 * Archives, search, and feeds are left unchanged so listing teasers stay visible.
	 *
	 * @param mixed $content Post content.
	 * @return mixed
	 */
	public static function filter_content( $content ) {
		if ( ! is_string( $content ) || is_admin() || is_feed() || ! is_singular( array( 'post', 'page' ) ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! is_numeric( $post_id ) ) {
			return $content;
		}

		$post_id = (int) $post_id;
		if ( $post_id <= 0 || self::is_allowed( $post_id ) ) {
			return $content;
		}

		return self::denial_markup( $post_id );
	}

	/**
	 * Choose the URL to open after a successful SSO when a return target was stored.
	 *
	 * Entitled visitors go to the original post, page, or gated download.
	 * Everyone else stays on the denial experience: the post or page itself
	 * (in-place message) or the login page with the file-denied message.
	 *
	 * @param string $return_url Validated local return URL, or empty.
	 */
	public static function redirect_after_sso( string $return_url ): string {
		$return_url = esc_url_raw( $return_url );
		if ( '' === $return_url ) {
			return '';
		}

		$post_id = self::post_id_from_url( $return_url );
		if ( $post_id <= 0 || ! self::is_members_only( $post_id ) ) {
			return $return_url;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $return_url;
		}

		if ( self::is_allowed( $post_id ) ) {
			if ( 'attachment' === $post->post_type && class_exists( 'Msebaa_Media_Gate' ) ) {
				return Msebaa_Media_Gate::download_url( $post_id );
			}

			$permalink = get_permalink( $post_id );
			return is_string( $permalink ) && '' !== $permalink ? $permalink : $return_url;
		}

		if ( 'attachment' === $post->post_type && class_exists( 'Msebaa_Media_Gate' ) ) {
			return Msebaa_Media_Gate::denied_login_url( $post_id );
		}

		$permalink = get_permalink( $post_id );
		return is_string( $permalink ) && '' !== $permalink ? $permalink : $return_url;
	}

	/**
	 * Resolve a local return URL to a post, page, or attachment ID.
	 *
	 * @param string $url Candidate return URL.
	 */
	public static function post_id_from_url( string $url ): int {
		$url = esc_url_raw( $url );
		if ( '' === $url || '' === (string) wp_validate_redirect( $url, '' ) ) {
			return 0;
		}

		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			$args = array();
			wp_parse_str( $query, $args );
			if ( isset( $args['msebaa_download'] ) ) {
				$attachment_id = absint( $args['msebaa_download'] );
				$attachment    = get_post( $attachment_id );
				if ( $attachment instanceof WP_Post && 'attachment' === $attachment->post_type ) {
					return $attachment_id;
				}
			}
		}

		return absint( url_to_postid( $url ) );
	}

	/**
	 * In-place denial message with a login link back to this item.
	 *
	 * @param int $post_id Post or page ID.
	 */
	public static function denial_markup( int $post_id ): string {
		$permalink = get_permalink( $post_id );
		$target    = is_string( $permalink ) ? $permalink : '';
		$login_url = function_exists( 'msebaa_get_login_url' ) ? msebaa_get_login_url( $target ) : home_url( '/' );

		$html  = '<div class="msebaa-members-only" role="status">';
		$html .= '<p>' . esc_html__( 'This content is for logged-in members only.', 'membersuite-ebaa' ) . '</p>';
		$html .= '<p><a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Sign in', 'membersuite-ebaa' ) . '</a></p>';
		$html .= '</div>';

		return $html;
	}
}
