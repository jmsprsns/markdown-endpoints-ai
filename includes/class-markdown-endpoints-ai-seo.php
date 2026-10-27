<?php
/**
 * Reads what the site's SEO plugin already knows about a post: its meta
 * description, canonical URL, noindex setting and primary category.
 *
 * Supports Yoast SEO, Rank Math, SEOPress and All in One SEO. Only the
 * active plugin is asked, so leftover meta from one the site used to run
 * doesn't count. Every call into another plugin is guarded, because their
 * internals change between versions.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * SEO plugin bridge.
 */
final class Markdown_Endpoints_AI_SEO {

	/**
	 * The active SEO plugin.
	 *
	 * @return string yoast, rankmath, seopress, aioseo or ''.
	 */
	public static function active() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) ) {
			return 'rankmath';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		if ( defined( 'AIOSEO_VERSION' ) && function_exists( 'aioseo' ) ) {
			return 'aioseo';
		}

		return '';
	}

	/**
	 * Display name of the active SEO plugin.
	 *
	 * @return string
	 */
	public static function active_name() {
		$names  = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'seopress' => 'SEOPress',
			'aioseo'   => 'All in One SEO',
		);
		$active = self::active();

		return isset( $names[ $active ] ) ? $names[ $active ] : '';
	}

	/**
	 * The post's meta description, with the SEO plugin's variables filled in.
	 *
	 * @param WP_Post $post Post.
	 * @return string Plain text, or '' when none is set.
	 */
	public static function description( WP_Post $post ) {
		$description = '';

		try {
			switch ( self::active() ) {
				case 'yoast':
					if ( function_exists( 'YoastSEO' ) ) {
						$meta = YoastSEO()->meta->for_post( $post->ID );
						if ( $meta ) {
							$description = (string) $meta->description;
						}
					}
					if ( '' === $description ) {
						$description = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
						if ( '' !== $description && function_exists( 'wpseo_replace_vars' ) ) {
							$description = wpseo_replace_vars( $description, $post );
						}
					}
					break;

				case 'rankmath':
					$description = (string) get_post_meta( $post->ID, 'rank_math_description', true );
					if ( '' !== $description && is_callable( array( 'RankMath\\Helper', 'replace_vars' ) ) ) {
						$description = call_user_func( array( 'RankMath\\Helper', 'replace_vars' ), $description, $post );
					}
					break;

				case 'seopress':
					$description = (string) get_post_meta( $post->ID, '_seopress_titles_desc', true );
					break;

				case 'aioseo':
					$data = self::aioseo_data( $post );
					if ( $data && ! empty( $data->description ) && isset( aioseo()->meta->description ) && is_callable( array( aioseo()->meta->description, 'getPostDescription' ) ) ) {
						$description = (string) aioseo()->meta->description->getPostDescription( $post );
					}
					break;
			}
		} catch ( \Throwable $e ) {
			$description = '';
		}

		$description = self::clean( $description );

		// Variables nobody replaced ("%%sep%%", "%excerpt%") mean the text isn't usable.
		if ( preg_match( '/%%[a-z_]+%%|%[a-z_]+%/i', $description ) ) {
			return '';
		}

		return $description;
	}

	/**
	 * Canonical URL set in the SEO plugin, else WordPress's own.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function canonical( WP_Post $post ) {
		$url = '';

		switch ( self::active() ) {
			case 'yoast':
				$url = (string) get_post_meta( $post->ID, '_yoast_wpseo_canonical', true );
				break;
			case 'rankmath':
				$url = (string) get_post_meta( $post->ID, 'rank_math_canonical_url', true );
				break;
			case 'seopress':
				$url = (string) get_post_meta( $post->ID, '_seopress_robots_canonical', true );
				break;
			case 'aioseo':
				$data = self::aioseo_data( $post );
				$url  = $data && ! empty( $data->canonical_url ) ? (string) $data->canonical_url : '';
				break;
		}

		$url = self::clean_url( $url );

		if ( '' === $url ) {
			$url = function_exists( 'wp_get_canonical_url' ) ? (string) wp_get_canonical_url( $post ) : '';
			$url = '' !== $url ? $url : (string) get_permalink( $post );
		}

		return self::clean_url( $url );
	}

	/**
	 * Whether the SEO plugin keeps this post out of search results.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_noindex( WP_Post $post ) {
		try {
			switch ( self::active() ) {
				case 'yoast':
					$value = (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
					if ( '1' === $value ) {
						return true;
					}
					if ( '2' === $value ) {
						return false;
					}
					return class_exists( 'WPSEO_Options' ) && (bool) WPSEO_Options::get( 'noindex-' . $post->post_type, false );

				case 'rankmath':
					$robots = get_post_meta( $post->ID, 'rank_math_robots', true );
					if ( is_array( $robots ) && $robots ) {
						return in_array( 'noindex', $robots, true );
					}
					if ( is_callable( array( 'RankMath\\Helper', 'get_settings' ) ) ) {
						$custom = call_user_func( array( 'RankMath\\Helper', 'get_settings' ), 'titles.pt_' . $post->post_type . '_custom_robots' );
						$robots = $custom
							? call_user_func( array( 'RankMath\\Helper', 'get_settings' ), 'titles.pt_' . $post->post_type . '_robots' )
							: call_user_func( array( 'RankMath\\Helper', 'get_settings' ), 'titles.robots_global' );
						return is_array( $robots ) && in_array( 'noindex', $robots, true );
					}
					return false;

				case 'seopress':
					if ( 'yes' === get_post_meta( $post->ID, '_seopress_robots_index', true ) ) {
						return true;
					}
					$titles = get_option( 'seopress_titles_option_name' );
					return is_array( $titles ) && ! empty( $titles['seopress_titles_single_titles'][ $post->post_type ]['noindex'] );

				case 'aioseo':
					$data = self::aioseo_data( $post );
					return $data && empty( $data->robots_default ) && ! empty( $data->robots_noindex );
			}
		} catch ( \Throwable $e ) {
			return false;
		}

		return false;
	}

	/**
	 * The primary term the SEO plugin picked for a taxonomy.
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $taxonomy Taxonomy.
	 * @return int Term ID, or 0.
	 */
	public static function primary_term( WP_Post $post, $taxonomy ) {
		$keys   = array(
			'yoast'    => '_yoast_wpseo_primary_' . $taxonomy,
			'rankmath' => 'rank_math_primary_' . $taxonomy,
			'seopress' => 'category' === $taxonomy ? '_seopress_robots_primary_cat' : '',
		);
		$active = self::active();

		if ( empty( $keys[ $active ] ) ) {
			return 0;
		}

		return absint( get_post_meta( $post->ID, $keys[ $active ], true ) );
	}

	/**
	 * All in One SEO's row for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return object|null
	 */
	private static function aioseo_data( WP_Post $post ) {
		try {
			if ( function_exists( 'aioseo' ) && isset( aioseo()->meta->metaData ) && is_callable( array( aioseo()->meta->metaData, 'getMetaData' ) ) ) {
				$data = aioseo()->meta->metaData->getMetaData( $post );
				return is_object( $data ) ? $data : null;
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Plain single-line text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function clean( $text ) {
		$text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Linear, see Markdown_Endpoints_AI_Schema::plain().

		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * An http(s) URL with nothing that could break a header.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function clean_url( $url ) {
		$url = trim( preg_replace( '/[\x00-\x1f\x7f]/', '', (string) $url ) );

		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		return esc_url_raw( $url, array( 'http', 'https' ) );
	}
}
