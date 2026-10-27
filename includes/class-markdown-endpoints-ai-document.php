<?php
/**
 * Builds a post's Markdown file.
 *
 * The usual input is the whole page exactly as a logged-out visitor gets it
 * (theme, page builder, shortcodes and all). The post's content area is found
 * by a marker the plugin wraps around the_content during that render, with
 * page-builder and theme containers as fallbacks; clutter is removed; then
 * it's converted and topped with frontmatter from the SEO plugin.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Markdown document builder.
 */
final class Markdown_Endpoints_AI_Document {

	/**
	 * Largest page parsed, in bytes.
	 */
	const MAX_HTML = 8388608;

	/**
	 * Least text a builder or theme container needs to count as the content.
	 */
	const MIN_TEXT = 100;

	/**
	 * Clutter removed from the content area: ads, share buttons, newsletter
	 * and contact forms, related posts, tables of contents, cookie banners,
	 * in-content navigation and comments.
	 *
	 * @return string[]
	 */
	public static function junk_selectors() {
		$selectors = array(
			// Ads.
			'.adsbygoogle',
			'[id^="div-gpt-ad"]',
			'[data-ad-slot]',
			'.advertisement',
			'.ad-container',
			'.ad-slot',
			'.ad-unit',
			'.adthrive-ad',
			'.mv-ad-box',
			'.ezoic-ad',
			'[id^="ezoic-pub-ad"]',
			'.quads-location',
			'.code-block[class*="code-block-"]',
			'.wp-block-advads-gblock',
			'.google-auto-placed',
			// Share buttons.
			'.sharedaddy',
			'.sd-sharing-enabled',
			'.addtoany_share_save_container',
			'.a2a_kit',
			'.swp_social_panel',
			'.social-warfare',
			'.shared-counts-wrap',
			'.dpsp-share-buttons-wrapper',
			'.dpsp-content-wrapper',
			'.heateor_sss_sharing_container',
			'.mashsb-container',
			'.shareaholic-canvas',
			'.novashare-buttons',
			'.ss-inline-share-wrapper',
			'.essb_links',
			'.wp-block-jetpack-sharing-buttons',
			'.wp-block-outermost-social-sharing',
			'.wp-block-social-links',
			'.fssb',
			'.et_social_inline',
			'.elementor-widget-share-buttons',
			'.fl-module-social-buttons',
			'.brxe-post-sharing',
			'.share-buttons',
			'.sharing-buttons',
			'.social-share',
			'.post-share',
			// Newsletter boxes and forms.
			'.mc4wp-form',
			'.wpforms-container',
			'.gform_wrapper',
			'.wpcf7',
			'.frm_forms',
			'.nf-form-cont',
			'.formkit-form',
			'.ck_form',
			'.mailpoet_form',
			'.sib-form',
			'.tnp-subscription',
			'.newsletter-signup',
			'.newsletter-form',
			'.wp-block-jetpack-subscriptions',
			'.jetpack_subscription_widget',
			'.wp-block-jetpack-mailchimp',
			'.et_bloom_below_post',
			'.et_bloom_inline_form',
			'.et_pb_newsletter',
			'.elementor-widget-form',
			'.fl-module-subscribe-form',
			'.thrv_lead_generation',
			'.tve-leads-in-content',
			// Related posts.
			'.jp-relatedposts',
			'#jp-relatedposts',
			'.wp-block-jetpack-related-posts',
			'.yarpp-related',
			'.yarpp',
			'.crp_related',
			'.related-posts',
			'.relatedposts',
			'.rp4wp-related-posts',
			'.brxe-related-posts',
			// Tables of contents.
			'#ez-toc-container',
			'.ez-toc-container',
			'.lwptoc',
			'#toc_container',
			'.wp-block-rank-math-toc-block',
			'.wp-block-yoast-seo-table-of-contents',
			'.yoast-table-of-contents',
			'.ub_table-of-contents',
			'.wp-block-ub-table-of-contents-block',
			'.kb-table-of-content-nav',
			'.wp-block-kadence-tableofcontents',
			'.simpletoc',
			'.wp-block-simpletoc-toc',
			'.wp-block-uagb-table-of-contents',
			'.uagb-toc__wrap',
			'.elementor-widget-table-of-contents',
			'.brxe-post-toc',
			'.table-of-contents',
			// Cookie banners.
			'#cookie-law-info-bar',
			'.cli-modal',
			'#cmplz-cookiebanner-container',
			'.cmplz-cookiebanner',
			'#moove_gdpr_cookie_info_bar',
			'.cky-consent-container',
			'#CybotCookiebotDialog',
			'#onetrust-consent-sdk',
			'#BorlabsCookieBox',
			'#iubenda-cs-banner',
			'#catapult-cookie-bar',
			'#cookie-notice',
			'lccb-banner',
			'.lccb-banner',
			// Navigation and comments inside the content.
			'nav',
			'.wp-block-navigation',
			'.post-navigation',
			'.nav-links',
			'.breadcrumbs',
			'.rank-math-breadcrumb',
			'#breadcrumbs',
			'.yoast-breadcrumbs',
			'.woocommerce-breadcrumb',
			'#comments',
			'.comments-area',
			'#respond',
			'.wp-block-comments',
			'.wp-block-post-comments-form',
			'.wp-block-search',
			'.wp-block-loginout',
			// Page-builder widgets that aren't content (Elementor's desktop tab titles repeat the mobile ones).
			'.elementor-tabs-wrapper',
			'.elementor-widget-nav-menu',
			'.elementor-widget-search-form',
			'.elementor-widget-post-navigation',
			'.elementor-widget-post-comments',
			'.elementor-widget-login',
			'.et_pb_social_media_follow',
			'.et_pb_comments_module',
			'.et_pb_post_nav',
			'.et_pb_search',
			'.et_pb_contact_form_container',
			'.fl-module-contact-form',
			'.fl-module-search',
			'.fl-module-menu',
			'.brxe-nav-menu',
			'.brxe-post-navigation',
			'.brxe-post-comments',
			'.vc_tta-tabs-list',
			// Reading-time labels, audio players and print buttons.
			'.reading-time-line',
			'.rt-reading-time',
			'.span-reading-time',
			'.btap-player',
			'.printfriendly',
			'.wp-block-file__button',
		);

		/**
		 * Filter the clutter selectors removed from every page.
		 *
		 * @param string[] $selectors CSS selectors.
		 */
		$filtered = apply_filters( 'markdown_endpoints_ai_junk_selectors', $selectors );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $selectors;
	}

	/**
	 * Page chrome removed when the content area had to be found by a wide
	 * container (article, main, body): site header and footer, sidebars,
	 * post meta and the title (it's in the frontmatter).
	 *
	 * @return string[]
	 */
	private static function chrome_selectors() {
		return array( 'header', 'footer', 'aside', '.site-header', '#masthead', '.site-footer', '#colophon', '.wp-block-template-part', '.widget-area', '#secondary', '.sidebar', '#sidebar', '.entry-meta', '.entry-footer', '.post-meta', '.wp-block-post-title', '.entry-title', '.wp-block-post-date', '.wp-block-post-author', '.wp-block-post-author-name', '.wp-block-post-terms', '.wp-block-query', '.wp-block-post-navigation-link', '#wpadminbar' );
	}

	/**
	 * Containers tried, in order, when the content marker isn't on the page.
	 * The second group is wide enough that page chrome has to come out.
	 *
	 * @return array{0: string[], 1: string[]}
	 */
	private static function root_selectors() {
		return array(
			array( '.elementor-widget-theme-post-content', '.elementor[data-elementor-type="wp-post"]', '.elementor[data-elementor-type="wp-page"]', '.et_pb_post_content', '.et-l--post', '.fl-post-content', '.fl-builder-content', '.brxe-post-content', '#brx-content', '.wp-block-post-content', '.entry-content', '.post-content', '.article-content', '.single-content', '.td-post-content', '.post-entry' ),
			array( 'article', 'main', '[role="main"]', '#content', '#main', '.site-content', 'body' ),
		);
	}

	/**
	 * Build the Markdown from a fully rendered page.
	 *
	 * @param WP_Post                      $post   Post.
	 * @param string                       $html   The page's HTML.
	 * @param string                       $marker Value of the content marker attribute.
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return array Result, see {@see result()}.
	 */
	public static function from_page( WP_Post $post, $html, $marker, Markdown_Endpoints_AI_Plugin $plugin ) {
		$html_bytes = strlen( $html );

		if ( $html_bytes > self::MAX_HTML ) {
			return self::from_content( $post, $plugin );
		}

		$doc = self::parse( $html );
		if ( ! $doc ) {
			return self::from_content( $post, $plugin );
		}

		$xpath = new DOMXPath( $doc );
		$head  = self::head( $xpath );

		if ( $head['noindex'] ) {
			return self::result( '', 'rendered', $html_bytes, 'render_noindex' );
		}

		// Google's paywall markup: leave out the parts for paying readers.
		$schema    = new Markdown_Endpoints_AI_Schema( $doc );
		$paywall   = $schema->paywall();
		$paywalled = false;
		if ( $paywall['paywalled'] ) {
			if ( ! $paywall['selectors'] ) {
				return self::result( '', 'rendered', $html_bytes, 'paywall' );
			}
			foreach ( $paywall['selectors'] as $selector ) {
				if ( ! Markdown_Endpoints_AI_Selector::is_valid( $selector ) ) {
					return self::result( '', 'rendered', $html_bytes, 'paywall' );
				}
			}
			self::remove( $xpath, $doc->documentElement, $paywall['selectors'] );
			$paywalled = true;
		}

		list( $root, $wide ) = self::find_root( $doc, $xpath, $marker, $plugin );

		if ( ! $root ) {
			return self::from_content( $post, $plugin );
		}

		self::clean_root( $xpath, $root, $wide, $plugin );

		$converter = new Markdown_Endpoints_AI_Converter( self::base_url( $post ), (bool) $plugin->get_setting( 'images' ) );
		$body      = self::drop_title( $converter->convert( $root ), $post );
		$extra     = $plugin->get_setting( 'schema' ) ? $schema->sections( $body ) : '';
		$markdown  = self::assemble( $post, $body, $extra, $converter->footnotes(), $head, $plugin, $paywalled );

		// Next to nothing found on the page: the post content may do better.
		if ( strlen( trim( $body ) ) < 20 && ! $paywalled ) {
			$fallback = self::from_content( $post, $plugin );
			if ( strlen( $fallback['markdown'] ) > strlen( $markdown ) + 200 ) {
				$fallback['html_bytes'] = $html_bytes;
				return $fallback;
			}
		}

		return self::result( $markdown, 'rendered', $html_bytes, '' );
	}

	/**
	 * Build the Markdown from the post's content alone (the_content filters,
	 * no theme). Used when the page can't be rendered, and by the "Post
	 * content only" setting.
	 *
	 * @param WP_Post                      $post       Post.
	 * @param Markdown_Endpoints_AI_Plugin $plugin     Plugin.
	 * @param bool                         $as_visitor Render as a logged-out visitor.
	 * @return array Result, see {@see result()}.
	 */
	public static function from_content( WP_Post $post, Markdown_Endpoints_AI_Plugin $plugin, $as_visitor = true ) {
		$html = self::render_content( $post, $as_visitor );
		$doc  = self::parse( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="mdai-root">' . $html . '</div></body></html>' );

		if ( ! $doc ) {
			return self::result( '', 'content', strlen( $html ), 'render_error' );
		}

		$xpath = new DOMXPath( $doc );
		$root  = $doc->getElementById( 'mdai-root' );

		if ( ! $root ) {
			return self::result( '', 'content', strlen( $html ), 'render_error' );
		}

		self::clean_root( $xpath, $root, false, $plugin );

		$converter = new Markdown_Endpoints_AI_Converter( self::base_url( $post ), (bool) $plugin->get_setting( 'images' ) );
		$body      = self::drop_title( $converter->convert( $root ), $post );
		$head      = array(
			'noindex'     => false,
			'canonical'   => '',
			'description' => '',
			'lang'        => '',
		);

		return self::result( self::assemble( $post, $body, '', $converter->footnotes(), $head, $plugin, false ), 'content', strlen( $html ), '' );
	}

	/**
	 * The post's content through the_content, the way a single post page
	 * shows it (all pages of a paged post, past the "more" tag).
	 *
	 * @param WP_Post $post       Post.
	 * @param bool    $as_visitor Render as a logged-out visitor.
	 * @return string HTML.
	 */
	private static function render_content( WP_Post $post, $as_visitor ) {
		global $more;

		$user_id    = get_current_user_id();
		$saved_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$saved_more = $more;

		if ( $as_visitor && $user_id ) {
			wp_set_current_user( 0 );
		}

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
		setup_postdata( $post );
		$more = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.

		add_filter( 'content_pagination', array( __CLASS__, 'join_pages' ), 99 );
		$html = (string) apply_filters( 'the_content', get_the_content( null, false, $post ) );
		remove_filter( 'content_pagination', array( __CLASS__, 'join_pages' ), 99 );

		$more            = $saved_more; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['post'] = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $saved_post instanceof WP_Post ) {
			setup_postdata( $saved_post );
		}

		if ( $as_visitor && $user_id ) {
			wp_set_current_user( $user_id );
		}

		return $html;
	}

	/**
	 * Put every page of a paged post (<!--nextpage-->) on one page.
	 *
	 * @param mixed $pages Pages.
	 * @return array
	 */
	public static function join_pages( $pages ) {
		return is_array( $pages ) && count( $pages ) > 1 ? array( implode( "\n\n", $pages ) ) : $pages;
	}

	/**
	 * Parse HTML into a DOM, quietly (real pages are rarely valid) and
	 * without ever touching the network.
	 *
	 * @param string $html HTML.
	 * @return DOMDocument|null
	 */
	public static function parse( $html ) {
		if ( ! class_exists( 'DOMDocument' ) || '' === trim( (string) $html ) ) {
			return null;
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		foreach ( $doc->childNodes as $node ) {
			if ( XML_PI_NODE === $node->nodeType ) {
				$doc->removeChild( $node );
				break;
			}
		}

		return $doc;
	}

	/**
	 * What the rendered <head> says: robots, canonical, description, language.
	 *
	 * @param DOMXPath $xpath Page.
	 * @return array{noindex: bool, canonical: string, description: string, lang: string}
	 */
	private static function head( DOMXPath $xpath ) {
		$noindex = false;
		foreach ( $xpath->query( '//meta[@name and @content]' ) as $meta ) {
			$name = strtolower( trim( $meta->getAttribute( 'name' ) ) );
			if ( ( 'robots' === $name || 'googlebot' === $name ) && preg_match( '/(?:^|[\s,])(?:noindex|none)(?:$|[\s,])/i', $meta->getAttribute( 'content' ) ) ) {
				$noindex = true;
				break;
			}
		}

		$canonical = '';
		foreach ( $xpath->query( '//link[@rel and @href]' ) as $link ) {
			if ( in_array( 'canonical', preg_split( '/\s+/', strtolower( trim( $link->getAttribute( 'rel' ) ) ) ), true ) ) {
				$canonical = Markdown_Endpoints_AI_SEO::clean_url( $link->getAttribute( 'href' ) );
				break;
			}
		}

		$description = '';
		foreach ( $xpath->query( '//meta[@name and @content]' ) as $meta ) {
			if ( 'description' === strtolower( trim( $meta->getAttribute( 'name' ) ) ) ) {
				$description = Markdown_Endpoints_AI_SEO::clean( $meta->getAttribute( 'content' ) );
				break;
			}
		}

		$lang  = '';
		$nodes = $xpath->query( '/html/@lang' );
		if ( $nodes && $nodes->length ) {
			$lang = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $nodes->item( 0 )->nodeValue );
		}

		return array(
			'noindex'     => $noindex,
			'canonical'   => $canonical,
			'description' => $description,
			'lang'        => substr( $lang, 0, 20 ),
		);
	}

	/**
	 * Find the post's content on the page.
	 *
	 * @param DOMDocument                  $doc    Page.
	 * @param DOMXPath                     $xpath  XPath over it.
	 * @param string                       $marker Content marker value.
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return array{0: DOMElement|null, 1: bool} The root, and whether it's a
	 *         wide container that still holds page chrome.
	 */
	private static function find_root( DOMDocument $doc, DOMXPath $xpath, $marker, Markdown_Endpoints_AI_Plugin $plugin ) {
		// 1. The site's own content selector.
		$own = trim( (string) $plugin->get_setting( 'content_selector' ) );
		if ( '' !== $own && Markdown_Endpoints_AI_Selector::is_valid( $own ) ) {
			$matches = self::top_level( $xpath->query( Markdown_Endpoints_AI_Selector::to_xpath( $own ), $doc->documentElement ) );
			if ( $matches ) {
				return array( self::combine( $doc, $matches ), false );
			}
		}

		// 2. The marker wrapped around the_content while the page rendered.
		if ( '' !== $marker ) {
			$best = self::largest( $xpath->query( '//*[@data-mdai-content=' . Markdown_Endpoints_AI_Selector::literal( $marker ) . ']' ) );
			if ( $best && '' !== trim( $best->textContent ) ) {
				return array( $best, false );
			}
		}

		// 3. Page-builder and theme containers, then wide ones.
		list( $specific, $wide ) = self::root_selectors();

		foreach ( $specific as $selector ) {
			$best = self::largest( $xpath->query( Markdown_Endpoints_AI_Selector::to_xpath( $selector ), $doc->documentElement ) );
			if ( $best && strlen( trim( $best->textContent ) ) >= self::MIN_TEXT ) {
				return array( $best, false );
			}
		}

		foreach ( $wide as $selector ) {
			$xpath_query = 'body' === $selector ? '//body' : Markdown_Endpoints_AI_Selector::to_xpath( $selector );
			$best        = self::largest( $xpath->query( $xpath_query, $doc->documentElement ) );
			if ( $best && '' !== trim( $best->textContent ) ) {
				return array( $best, true );
			}
		}

		return array( null, false );
	}

	/**
	 * Take the clutter out of the content area.
	 *
	 * @param DOMXPath                     $xpath  XPath.
	 * @param DOMElement                   $root   Content root.
	 * @param bool                         $wide   Root still holds page chrome.
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return void
	 */
	private static function clean_root( DOMXPath $xpath, DOMElement $root, $wide, Markdown_Endpoints_AI_Plugin $plugin ) {
		$selectors = array();

		if ( $plugin->get_setting( 'strip_junk' ) ) {
			$selectors = self::junk_selectors();
		}

		if ( $wide ) {
			$selectors = array_merge( $selectors, self::chrome_selectors() );
		}

		$extra = Markdown_Endpoints_AI_Selector::clean_list( (string) $plugin->get_setting( 'remove_selectors' ) );

		self::remove( $xpath, $root, array_merge( $selectors, $extra ) );
	}

	/**
	 * Remove everything matching the selectors from inside a node.
	 *
	 * @param DOMXPath $xpath     XPath.
	 * @param DOMNode  $root      Node.
	 * @param string[] $selectors CSS selectors.
	 * @return void
	 */
	private static function remove( DOMXPath $xpath, DOMNode $root, array $selectors ) {
		$parts = array();
		foreach ( $selectors as $selector ) {
			$part = Markdown_Endpoints_AI_Selector::to_xpath( $selector );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}

		// A few XPath unions at a time: one per selector is slow, one huge
		// expression can hit libxml's limits.
		foreach ( array_chunk( $parts, 40 ) as $chunk ) {
			$nodes = $xpath->query( implode( ' | ', $chunk ), $root );
			if ( ! $nodes ) {
				continue;
			}

			$found = array();
			foreach ( $nodes as $node ) {
				$found[] = $node;
			}
			foreach ( $found as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	/**
	 * The match with the most text.
	 *
	 * @param DOMNodeList|false $nodes Matches.
	 * @return DOMElement|null
	 */
	private static function largest( $nodes ) {
		$best   = null;
		$length = -1;

		if ( ! $nodes ) {
			return null;
		}

		foreach ( $nodes as $node ) {
			$size = strlen( $node->textContent );
			if ( $node instanceof DOMElement && $size > $length ) {
				$best   = $node;
				$length = $size;
			}
		}

		return $best;
	}

	/**
	 * Matches that aren't inside another match.
	 *
	 * @param DOMNodeList|false $nodes Matches.
	 * @return DOMElement[]
	 */
	private static function top_level( $nodes ) {
		$list = array();
		if ( ! $nodes ) {
			return $list;
		}

		foreach ( $nodes as $node ) {
			if ( $node instanceof DOMElement ) {
				$list[] = $node;
			}
		}

		return array_values(
			array_filter(
				$list,
				function ( $node ) use ( $list ) {
					foreach ( $list as $other ) {
						if ( $other !== $node && self::contains( $other, $node ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * Whether one node is inside another.
	 *
	 * @param DOMNode $outer Outer.
	 * @param DOMNode $inner Inner.
	 * @return bool
	 */
	private static function contains( DOMNode $outer, DOMNode $inner ) {
		for ( $node = $inner->parentNode; $node; $node = $node->parentNode ) {
			if ( $node->isSameNode( $outer ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One element holding every match, in page order.
	 *
	 * @param DOMDocument  $doc     Page.
	 * @param DOMElement[] $matches Matches.
	 * @return DOMElement
	 */
	private static function combine( DOMDocument $doc, array $matches ) {
		if ( 1 === count( $matches ) ) {
			return $matches[0];
		}

		$wrapper = $doc->createElement( 'div' );
		$doc->documentElement->appendChild( $wrapper );
		foreach ( $matches as $match ) {
			$wrapper->appendChild( $match );
		}

		return $wrapper;
	}

	/**
	 * The URL relative links on the page are resolved against.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function base_url( WP_Post $post ) {
		$url = (string) get_permalink( $post );

		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * Drop a heading at the very top that just repeats the title.
	 *
	 * @param string  $body Markdown.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function drop_title( $body, WP_Post $post ) {
		if ( ! preg_match( '/^#{1,2} ([^\n]+)\n*/', $body, $m ) ) {
			return $body;
		}

		if ( self::normalize( str_replace( '\\', '', $m[1] ) ) === self::normalize( self::title( $post ) ) ) {
			return ltrim( substr( $body, strlen( $m[0] ) ) );
		}

		return $body;
	}

	/**
	 * Letters and digits only, lower case.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize( $text ) {
		$text = strtolower( (string) $text );
		$out  = preg_replace( '/[^\p{L}\p{N}]+/u', '', $text );

		return null === $out ? $text : $out;
	}

	/**
	 * The post's title as plain text.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function title( WP_Post $post ) {
		$title = Markdown_Endpoints_AI_SEO::clean( $post->post_title );

		return '' !== $title ? $title : __( '(no title)', 'markdown-endpoints-ai' );
	}

	/**
	 * Put the file together: frontmatter, title, body, schema sections and
	 * the attribution line.
	 *
	 * @param WP_Post                      $post      Post.
	 * @param string                       $body      Converted content.
	 * @param string                       $extra     Schema sections.
	 * @param string                       $notes     Footnote definitions.
	 * @param array                        $head      What the page's head said.
	 * @param Markdown_Endpoints_AI_Plugin $plugin    Plugin.
	 * @param bool                         $paywalled Paid parts were left out.
	 * @return string
	 */
	private static function assemble( WP_Post $post, $body, $extra, $notes, array $head, Markdown_Endpoints_AI_Plugin $plugin, $paywalled ) {
		$canonical = '' !== $head['canonical'] ? $head['canonical'] : Markdown_Endpoints_AI_SEO::canonical( $post );
		$parts     = array();

		if ( $plugin->get_setting( 'frontmatter' ) ) {
			$parts[] = self::frontmatter( $post, $body, $head, $canonical, $plugin, $paywalled );
		}

		$parts[] = '# ' . Markdown_Endpoints_AI_Converter::escape( self::title( $post ) );

		if ( '' !== trim( $body ) ) {
			$parts[] = $body;
		}

		if ( '' !== trim( $extra ) ) {
			$parts[] = $extra;
		}

		if ( '' !== trim( $notes ) ) {
			$parts[] = $notes;
		}

		$footer = self::footer( $post, $canonical, $plugin );
		if ( '' !== $footer ) {
			$parts[] = "---\n\n" . $footer;
		}

		$markdown = implode( "\n\n", $parts ) . "\n";

		/**
		 * Filter a post's finished Markdown before it's cached and served.
		 *
		 * @param string  $markdown Markdown.
		 * @param WP_Post $post     Post.
		 */
		return (string) apply_filters( 'markdown_endpoints_ai_markdown', $markdown, $post );
	}

	/**
	 * YAML frontmatter.
	 *
	 * @param WP_Post                      $post      Post.
	 * @param string                       $body      Converted content (for a fallback description).
	 * @param array                        $head      What the page's head said.
	 * @param string                       $canonical Canonical URL.
	 * @param Markdown_Endpoints_AI_Plugin $plugin    Plugin.
	 * @param bool                         $paywalled Paid parts were left out.
	 * @return string
	 */
	private static function frontmatter( WP_Post $post, $body, array $head, $canonical, Markdown_Endpoints_AI_Plugin $plugin, $paywalled ) {
		$description = Markdown_Endpoints_AI_SEO::description( $post );
		if ( '' === $description ) {
			$description = '' !== $head['description'] ? $head['description'] : self::fallback_description( $post, $body );
		}

		$fields = array(
			'title'       => self::title( $post ),
			'description' => $description,
			'author'      => self::author( $post ),
			'published'   => get_post_time( 'c', false, $post ),
			'modified'    => get_post_modified_time( 'c', false, $post ),
		);

		$terms = self::terms( $post );
		if ( $terms['categories'] ) {
			$fields['categories'] = $terms['categories'];
		}
		if ( $terms['tags'] ) {
			$fields['tags'] = $terms['tags'];
		}

		$fields['canonical'] = $canonical;
		$fields['site']      = Markdown_Endpoints_AI_SEO::clean( get_bloginfo( 'name' ) );
		$fields['language']  = '' !== $head['lang'] ? $head['lang'] : get_bloginfo( 'language' );
		$fields['type']      = $post->post_type;

		$signals = self::signals( $post, $plugin );
		if ( $signals ) {
			$fields['content_signals'] = self::signal_header( $signals );
		}

		if ( $paywalled ) {
			$fields['access'] = 'free portion only';
		}

		/**
		 * Filter the frontmatter fields.
		 *
		 * @param array   $fields Field => string or list of strings.
		 * @param WP_Post $post   Post.
		 */
		$fields = apply_filters( 'markdown_endpoints_ai_frontmatter', $fields, $post );

		$lines = array( '---' );
		foreach ( (array) $fields as $key => $value ) {
			$key = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) );
			if ( '' === $key || '' === $value || array() === $value || null === $value || false === $value ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$items = array();
				foreach ( $value as $item ) {
					if ( is_scalar( $item ) && '' !== (string) $item ) {
						$items[] = self::yaml( (string) $item );
					}
				}
				if ( $items ) {
					$lines[] = $key . ': [' . implode( ', ', $items ) . ']';
				}
			} elseif ( is_scalar( $value ) ) {
				$lines[] = $key . ': ' . self::yaml( (string) $value );
			}
		}
		$lines[] = '---';

		return implode( "\n", $lines );
	}

	/**
	 * A YAML double-quoted string.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function yaml( $value ) {
		$value = str_replace(
			array( '\\', '"', "\n", "\r", "\t" ),
			array( '\\\\', '\\"', '\\n', '\\r', '\\t' ),
			$value
		);

		$value = preg_replace_callback(
			'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',
			function ( $m ) {
				return sprintf( '\\x%02x', ord( $m[0] ) );
			},
			$value
		);

		return '"' . $value . '"';
	}

	/**
	 * Author names (Co-Authors Plus aware).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function author( WP_Post $post ) {
		if ( ! post_type_supports( $post->post_type, 'author' ) ) {
			return '';
		}

		if ( function_exists( 'get_coauthors' ) ) {
			$names = array();
			foreach ( (array) get_coauthors( $post->ID ) as $author ) {
				if ( is_object( $author ) && ! empty( $author->display_name ) ) {
					$names[] = Markdown_Endpoints_AI_SEO::clean( $author->display_name );
				}
			}
			if ( $names ) {
				return implode( ', ', $names );
			}
		}

		return $post->post_author ? Markdown_Endpoints_AI_SEO::clean( get_the_author_meta( 'display_name', $post->post_author ) ) : '';
	}

	/**
	 * Category-like and tag-like terms, the SEO plugin's primary category first.
	 *
	 * @param WP_Post $post Post.
	 * @return array{categories: string[], tags: string[]}
	 */
	private static function terms( WP_Post $post ) {
		$out = array(
			'categories' => array(),
			'tags'       => array(),
		);

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public || ! $taxonomy->publicly_queryable || 'post_format' === $taxonomy->name ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy->name );
			if ( ! is_array( $terms ) ) {
				continue;
			}

			$primary = Markdown_Endpoints_AI_SEO::primary_term( $post, $taxonomy->name );
			usort(
				$terms,
				function ( $a, $b ) use ( $primary ) {
					return ( (int) $b->term_id === $primary ) - ( (int) $a->term_id === $primary );
				}
			);

			$key = $taxonomy->hierarchical ? 'categories' : 'tags';
			foreach ( $terms as $term ) {
				if ( 'uncategorized' !== $term->slug ) {
					$out[ $key ][] = Markdown_Endpoints_AI_SEO::clean( $term->name );
				}
			}
		}

		$out['categories'] = array_slice( array_values( array_unique( $out['categories'] ) ), 0, 15 );
		$out['tags']       = array_slice( array_values( array_unique( $out['tags'] ) ), 0, 20 );

		return $out;
	}

	/**
	 * A description when the SEO plugin has none: the excerpt, else the
	 * opening of the first paragraph.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $body Markdown body.
	 * @return string
	 */
	private static function fallback_description( WP_Post $post, $body ) {
		if ( has_excerpt( $post ) ) {
			$text = Markdown_Endpoints_AI_SEO::clean( $post->post_excerpt );
		} else {
			$text = '';
			foreach ( explode( "\n\n", $body ) as $block ) {
				$block = trim( $block );
				if ( '' !== $block && ! preg_match( '/^(?:#|!\[|\||-|\*|>|```|\d+\.|\[\^)/', $block ) ) {
					$text = $block;
					break;
				}
			}
			// Markdown links and emphasis back to plain words. Only the opening is
			// needed, and capping it keeps the regex fast on crafted text.
			$text = substr( $text, 0, 1500 );
			$text = preg_replace( '/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text );
			$text = trim( preg_replace( '/\s+/', ' ', str_replace( array( '**', '\\', '`' ), '', $text ) ) );
		}

		if ( strlen( $text ) > 200 ) {
			$cut  = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 );
			$text = preg_replace( '/\s+\S*$/u', '', $cut ) . '...';
		}

		return $text;
	}

	/**
	 * The attribution line, when switched on.
	 *
	 * @param WP_Post                      $post      Post.
	 * @param string                       $canonical Canonical URL.
	 * @param Markdown_Endpoints_AI_Plugin $plugin    Plugin.
	 * @return string
	 */
	private static function footer( WP_Post $post, $canonical, Markdown_Endpoints_AI_Plugin $plugin ) {
		if ( ! $plugin->get_setting( 'footer' ) ) {
			return '';
		}

		$template = trim( (string) $plugin->get_setting( 'footer_text' ) );
		if ( '' === $template ) {
			$template = self::default_footer();
		}

		return strtr(
			$template,
			array(
				'{title}' => Markdown_Endpoints_AI_Converter::escape( self::title( $post ) ),
				'{url}'   => $canonical,
				'{site}'  => Markdown_Endpoints_AI_Converter::escape( Markdown_Endpoints_AI_SEO::clean( get_bloginfo( 'name' ) ) ),
			)
		);
	}

	/**
	 * The attribution line used when the box is left empty.
	 *
	 * @return string
	 */
	public static function default_footer() {
		/* translators: Keep {title}, {url} and {site} as they are. */
		return __( 'Originally published as "{title}" by {site}: {url}', 'markdown-endpoints-ai' );
	}

	/**
	 * Content signals for a post: the site's choices, overridden per post.
	 *
	 * @param WP_Post|null                 $post   Post, or null for the site.
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return array<string,string> Signal => yes|no, only the ones stated.
	 */
	public static function signals( $post, Markdown_Endpoints_AI_Plugin $plugin ) {
		$map     = array(
			'search'   => 'signal_search',
			'ai-input' => 'signal_input',
			'ai-train' => 'signal_train',
		);
		$signals = array();

		foreach ( $map as $signal => $setting ) {
			$value = (string) $plugin->get_setting( $setting );

			if ( $post instanceof WP_Post ) {
				$own = (string) get_post_meta( $post->ID, '_mdai_' . $setting, true );
				if ( 'yes' === $own || 'no' === $own ) {
					$value = $own;
				}
			}

			if ( 'yes' === $value || 'no' === $value ) {
				$signals[ $signal ] = $value;
			}
		}

		return $signals;
	}

	/**
	 * Content-Signal header value ("search=yes, ai-input=yes, ai-train=no").
	 *
	 * @param array<string,string> $signals Signals.
	 * @return string
	 */
	public static function signal_header( array $signals ) {
		$parts = array();
		foreach ( $signals as $signal => $value ) {
			$parts[] = $signal . '=' . $value;
		}

		return implode( ', ', $parts );
	}

	/**
	 * The IETF AI-preferences Content-Usage value for the signals it covers
	 * ("search=y, train-ai=n").
	 *
	 * @param array<string,string> $signals Signals.
	 * @return string
	 */
	public static function usage_header( array $signals ) {
		$parts = array();
		if ( isset( $signals['search'] ) ) {
			$parts[] = 'search=' . ( 'yes' === $signals['search'] ? 'y' : 'n' );
		}
		if ( isset( $signals['ai-train'] ) ) {
			$parts[] = 'train-ai=' . ( 'yes' === $signals['ai-train'] ? 'y' : 'n' );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Package a result.
	 *
	 * @param string $markdown   Markdown ('' when not served).
	 * @param string $source     rendered or content.
	 * @param int    $html_bytes Size of the HTML it came from.
	 * @param string $blocked    Reason it can't be served, or ''.
	 * @return array{markdown: string, source: string, html_bytes: int, blocked: string}
	 */
	private static function result( $markdown, $source, $html_bytes, $blocked ) {
		return array(
			'markdown'   => (string) $markdown,
			'source'     => $source,
			'html_bytes' => (int) $html_bytes,
			'blocked'    => $blocked,
		);
	}
}
