<?php
/**
 * Which posts get a Markdown version.
 *
 * A post is only ever served when an anonymous visitor could read it and
 * search engines are allowed to index it: published, no password, not
 * noindexed by the SEO plugin or the site, not members-only, and not switched
 * off for its type, category or itself. The page is also rendered as a
 * logged-out visitor, and anything that render turns up (a noindex tag, a
 * redirect to a login page, paywall markup) is remembered too.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Eligibility rules.
 */
final class Markdown_Endpoints_AI_Access {

	/**
	 * Post meta: 1 when the author switched Markdown off for this post.
	 */
	const META_EXCLUDE = '_mdai_exclude';

	/**
	 * Excluded categories with their children, once worked out.
	 *
	 * @var int[]|null
	 */
	private static $excluded_terms = null;

	/**
	 * Answers already worked out in this request (front end only), by post ID.
	 *
	 * @var array<int,bool>
	 */
	private static $memo = array();

	/**
	 * Why a post isn't served, or '' when it is.
	 *
	 * @param WP_Post                      $post   Post.
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return string Reason code (see {@see reason_label()}).
	 */
	public static function why_not( WP_Post $post, Markdown_Endpoints_AI_Plugin $plugin ) {
		if ( ! $plugin->is_enabled() ) {
			return 'off';
		}

		if ( ! in_array( $post->post_type, $plugin->post_types(), true ) ) {
			return 'type';
		}

		if ( 'publish' !== $post->post_status ) {
			return 'status';
		}

		if ( '' !== (string) $post->post_password ) {
			return 'password';
		}

		if ( get_post_meta( $post->ID, self::META_EXCLUDE, true ) ) {
			return 'excluded';
		}

		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return 'site_noindex';
		}

		if ( self::in_excluded_category( $post, $plugin ) ) {
			return 'category';
		}

		if ( self::is_special_page( $post ) ) {
			return 'special';
		}

		if ( Markdown_Endpoints_AI_SEO::is_noindex( $post ) ) {
			return 'noindex';
		}

		if ( self::is_restricted( $post ) ) {
			return 'members';
		}

		$blocked = Markdown_Endpoints_AI_Cache::blocked_reason( $post, $plugin );
		if ( '' !== $blocked ) {
			return $blocked;
		}

		/**
		 * Filter whether a post gets a Markdown version.
		 *
		 * Return a short reason code to refuse it, or '' to serve it.
		 *
		 * @param string  $reason '' when the post is served.
		 * @param WP_Post $post   Post.
		 */
		$reason = apply_filters( 'markdown_endpoints_ai_why_not', '', $post );

		return is_string( $reason ) ? sanitize_key( $reason ) : '';
	}

	/**
	 * Whether a post is served.
	 *
	 * @param WP_Post                      $post   Post.
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return bool
	 */
	public static function is_served( WP_Post $post, Markdown_Endpoints_AI_Plugin $plugin ) {
		if ( is_admin() || wp_doing_cron() ) {
			return '' === self::why_not( $post, $plugin );
		}

		if ( ! isset( self::$memo[ $post->ID ] ) ) {
			self::$memo[ $post->ID ] = '' === self::why_not( $post, $plugin );
		}

		return self::$memo[ $post->ID ];
	}

	/**
	 * A reason code in words, for the editor and the settings page.
	 *
	 * @param string $code Reason code.
	 * @return string
	 */
	public static function reason_label( $code ) {
		$labels = array(
			'off'             => __( 'Markdown endpoints are switched off.', 'markdown-endpoints-ai' ),
			'type'            => __( 'Markdown isn\'t on for this post type.', 'markdown-endpoints-ai' ),
			'status'          => __( 'Not published yet. Agents only get published content.', 'markdown-endpoints-ai' ),
			'password'        => __( 'Password protected, so it\'s never served.', 'markdown-endpoints-ai' ),
			'excluded'        => __( 'Switched off for this post.', 'markdown-endpoints-ai' ),
			'site_noindex'    => __( 'Your site asks search engines not to index it (Settings > Reading), so nothing is served.', 'markdown-endpoints-ai' ),
			'category'        => __( 'In a category you excluded.', 'markdown-endpoints-ai' ),
			'special'         => __( 'Shop, cart, checkout, account and blog index pages aren\'t articles, so they\'re skipped.', 'markdown-endpoints-ai' ),
			'noindex'         => __( 'Set to noindex, so it\'s never served.', 'markdown-endpoints-ai' ),
			'members'         => __( 'Members-only content, so it\'s never served.', 'markdown-endpoints-ai' ),
			'render_noindex'  => __( 'The page carries a noindex tag, so it\'s never served.', 'markdown-endpoints-ai' ),
			'render_redirect' => __( 'Logged-out visitors are redirected away from this page, so it\'s never served.', 'markdown-endpoints-ai' ),
			'render_error'    => __( 'The page didn\'t load for a logged-out visitor, so nothing is served. It\'s tried again after the post changes.', 'markdown-endpoints-ai' ),
			'paywall'         => __( 'Paywalled, and the page\'s markup doesn\'t say which part is free, so it\'s never served.', 'markdown-endpoints-ai' ),
		);

		return isset( $labels[ $code ] ) ? $labels[ $code ] : __( 'Not served.', 'markdown-endpoints-ai' );
	}

	/**
	 * Whether the post is in an excluded category (or one below it).
	 *
	 * @param WP_Post                      $post   Post.
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return bool
	 */
	private static function in_excluded_category( WP_Post $post, Markdown_Endpoints_AI_Plugin $plugin ) {
		if ( null === self::$excluded_terms ) {
			$terms = array_filter( array_map( 'absint', (array) $plugin->get_setting( 'exclude_cats' ) ) );
			$all   = $terms;
			foreach ( $terms as $term_id ) {
				$children = get_term_children( $term_id, 'category' );
				if ( is_array( $children ) ) {
					$all = array_merge( $all, array_map( 'absint', $children ) );
				}
			}
			self::$excluded_terms = array_values( array_unique( $all ) );
		}

		if ( ! self::$excluded_terms || ! is_object_in_taxonomy( $post->post_type, 'category' ) ) {
			return false;
		}

		return has_term( self::$excluded_terms, 'category', $post );
	}

	/**
	 * Forget answers worked out earlier in the request (after a save).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$excluded_terms = null;
		self::$memo           = array();
	}

	/**
	 * Pages that aren't articles: WooCommerce's shop, cart, checkout and
	 * account pages, and the page that shows the latest posts.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function is_special_page( WP_Post $post ) {
		if ( 'page' !== $post->post_type ) {
			return false;
		}

		$ids = array( (int) get_option( 'page_for_posts' ) );

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $page ) {
				$ids[] = (int) wc_get_page_id( $page );
			}
		}

		return in_array( (int) $post->ID, array_filter( $ids ), true );
	}

	/**
	 * Whether a membership plugin keeps this post for members.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_restricted( WP_Post $post ) {
		$restricted = false;

		try {
			// Paid Memberships Pro: any required level.
			if ( function_exists( 'pmpro_has_membership_access' ) ) {
				$access     = pmpro_has_membership_access( $post->ID, null, true );
				$restricted = is_array( $access ) && ! empty( $access[1] );
			}

			// MemberPress: any rule that covers the post.
			if ( ! $restricted && class_exists( 'MeprRule' ) && is_callable( array( 'MeprRule', 'get_rules' ) ) ) {
				$rules      = call_user_func( array( 'MeprRule', 'get_rules' ), $post );
				$restricted = ! empty( $rules );
			}

			// Restrict Content Pro.
			if ( ! $restricted && function_exists( 'rcp_is_restricted_content' ) ) {
				$restricted = (bool) rcp_is_restricted_content( $post->ID );
			}

			// WooCommerce Memberships.
			if ( ! $restricted && function_exists( 'wc_memberships_is_post_content_restricted' ) ) {
				$restricted = (bool) wc_memberships_is_post_content_restricted( $post->ID );
			}

			// Paid Member Subscriptions.
			if ( ! $restricted && function_exists( 'pms_is_post_restricted' ) ) {
				$restricted = (bool) pms_is_post_restricted( $post->ID );
			}

			// Ultimate Member: "logged-in users only".
			if ( ! $restricted && function_exists( 'UM' ) ) {
				$rules      = get_post_meta( $post->ID, 'um_content_restriction', true );
				$restricted = is_array( $rules ) && ! empty( $rules['_um_custom_access_settings'] ) && isset( $rules['_um_accessible'] ) && 2 === (int) $rules['_um_accessible'];
			}
		} catch ( \Throwable $e ) {
			// A membership plugin we can't read: be safe and don't serve.
			$restricted = true;
		}

		/**
		 * Filter whether a post is members-only (and so never served).
		 *
		 * @param bool    $restricted Members-only.
		 * @param WP_Post $post       Post.
		 */
		return (bool) apply_filters( 'markdown_endpoints_ai_is_restricted', $restricted, $post );
	}
}
