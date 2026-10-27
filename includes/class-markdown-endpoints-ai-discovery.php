<?php
/**
 * How agents find the Markdown: a link in each page's head, llms.txt and a
 * sitemap of every .md URL.
 *
 * llms.txt is served by WordPress when there's no real file, or written into
 * the site's own llms.txt between two marker lines (so a file made by hand or
 * by an SEO plugin keeps everything else in it).
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Discovery: head link, llms.txt, sitemap.
 */
final class Markdown_Endpoints_AI_Discovery {

	/**
	 * Transient holding the generated llms.txt.
	 */
	const LLMS_CACHE = 'markdown_endpoints_ai_llms';

	/**
	 * Transient holding generated sitemap pages.
	 */
	const SITEMAP_CACHE = 'markdown_endpoints_ai_sitemap';

	/**
	 * Option: result of the last llms.txt file write.
	 */
	const FILE_STATUS = 'markdown_endpoints_ai_llms_file';

	/**
	 * Background job: rewrite the llms.txt file.
	 */
	const FILE_HOOK = 'markdown_endpoints_ai_llms_file';

	/**
	 * URLs per sitemap page.
	 */
	const SITEMAP_PAGE = 2000;

	/**
	 * Lines around this plugin's part of a real llms.txt.
	 */
	const BEGIN = '<!-- BEGIN Markdown Endpoints for AI -->';
	const END   = '<!-- END Markdown Endpoints for AI -->';

	/**
	 * Plugin instance.
	 *
	 * @var Markdown_Endpoints_AI_Plugin
	 */
	private static $plugin;

	/**
	 * Hook in.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return void
	 */
	public static function init( Markdown_Endpoints_AI_Plugin $plugin ) {
		self::$plugin = $plugin;

		add_action( self::FILE_HOOK, array( __CLASS__, 'run_file_job' ) );

		if ( ! is_admin() ) {
			add_action( 'wp_head', array( __CLASS__, 'head_link' ), 5 );
		}
	}

	/**
	 * The plugin, also when this class is used before init (uninstall, tests).
	 *
	 * @return Markdown_Endpoints_AI_Plugin
	 */
	private static function plugin() {
		return self::$plugin ? self::$plugin : Markdown_Endpoints_AI_Plugin::instance();
	}

	// URLs ----------------------------------------------------------------------

	/**
	 * A post's .md URL ("/my-post/" becomes "/my-post.md", the front page is
	 * "/index.md"; sites without pretty permalinks get ?markdown=1).
	 *
	 * @param WP_Post $post  Post.
	 * @param bool    $force Even when .md URLs are switched off (internal use).
	 * @return string
	 */
	public static function markdown_url( WP_Post $post, $force = false ) {
		if ( ! $force && ! self::plugin()->get_setting( 'md_urls' ) ) {
			return '';
		}

		$pretty = (bool) get_option( 'permalink_structure' );
		$front  = 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post->ID;

		if ( $front ) {
			return $pretty ? home_url( '/index.md' ) : add_query_arg( 'markdown', '1', home_url( '/' ) );
		}

		$permalink = (string) get_permalink( $post );

		// Posts whose link was pointed at another site (Page Links To and the
		// like) have no Markdown version here.
		if ( '' === $permalink || ! self::is_own_url( $permalink ) ) {
			return '';
		}

		if ( ! $pretty || false !== strpos( $permalink, '?' ) ) {
			return add_query_arg( 'markdown', '1', $permalink );
		}

		$parts = wp_parse_url( $permalink );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		$path = '' === $path ? '/index' : $path;

		return ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $path . '.md';
	}

	/**
	 * Whether a URL is on this site (same host as the home URL).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_own_url( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		return '' !== $host && $host === $home;
	}

	/**
	 * The URL to advertise: the .md URL, or the page itself when only
	 * Accept-header requests are on.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function discovery_url( WP_Post $post ) {
		$url = self::markdown_url( $post );

		if ( '' === $url && self::plugin()->get_setting( 'negotiate' ) ) {
			$url = (string) get_permalink( $post );
		}

		return $url;
	}

	/**
	 * URL of llms.txt.
	 *
	 * @return string
	 */
	public static function llms_url() {
		return home_url( '/llms.txt' );
	}

	/**
	 * URL of the Markdown sitemap.
	 *
	 * @param int $page Page (0 for the first or the index).
	 * @return string
	 */
	public static function sitemap_url( $page = 0 ) {
		return home_url( $page ? '/markdown-sitemap-' . (int) $page . '.xml' : '/markdown-sitemap.xml' );
	}

	// Head link -------------------------------------------------------------------

	/**
	 * <link rel="alternate" type="text/markdown"> on pages with a Markdown version.
	 *
	 * @return void
	 */
	public static function head_link() {
		$plugin = self::plugin();

		if ( ! $plugin->get_setting( 'discovery' ) || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! Markdown_Endpoints_AI_Access::is_served( $post, $plugin ) ) {
			return;
		}

		$url = self::discovery_url( $post );
		if ( '' !== $url ) {
			printf( '<link rel="alternate" type="text/markdown" title="%s" href="%s">' . "\n", esc_attr( Markdown_Endpoints_AI_Document::title( $post ) ), esc_url( $url ) );
		}
	}

	// llms.txt --------------------------------------------------------------------

	/**
	 * Answer /llms.txt when WordPress gets the request (there's no real file).
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return void
	 */
	public static function maybe_serve_llms( Markdown_Endpoints_AI_Plugin $plugin ) {
		if ( 'off' === $plugin->get_setting( 'llms' ) ) {
			return;
		}

		self::guard_request( $plugin, 'llms' );

		$body = self::llms_text( $plugin );

		self::send( 'text/plain', $body, 'llms' );
	}

	/**
	 * The full llms.txt.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return string
	 */
	public static function llms_text( Markdown_Endpoints_AI_Plugin $plugin ) {
		$cached = get_transient( self::LLMS_CACHE );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$intro = trim( (string) $plugin->get_setting( 'llms_intro' ) );
		$intro = '' !== $intro ? $intro : Markdown_Endpoints_AI_SEO::clean( get_bloginfo( 'description' ) );

		$text = '# ' . self::line( get_bloginfo( 'name' ) ) . "\n\n";
		if ( '' !== $intro ) {
			$text .= '> ' . self::line( $intro ) . "\n\n";
		}
		$text .= self::sections( $plugin );

		set_transient( self::LLMS_CACHE, $text, 12 * HOUR_IN_SECONDS );

		return $text;
	}

	/**
	 * The H2 sections of llms.txt: how to get Markdown, key pages, the pages
	 * AI agents read most, latest posts, and the sitemap.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return string
	 */
	private static function sections( Markdown_Endpoints_AI_Plugin $plugin ) {
		$out  = '';
		$seen = array();

		$how = array();
		if ( $plugin->get_setting( 'md_urls' ) ) {
			$how[] = __( 'add .md to the end of a page\'s address', 'markdown-endpoints-ai' );
		}
		if ( $plugin->get_setting( 'negotiate' ) ) {
			$how[] = __( 'request the page with an Accept: text/markdown header', 'markdown-endpoints-ai' );
		}
		if ( $how ) {
			/* translators: %s: ways to get Markdown, joined with "or". */
			$out .= sprintf( __( 'Articles on this site have a clean Markdown version for AI agents: %s.', 'markdown-endpoints-ai' ), implode( __( ' or ', 'markdown-endpoints-ai' ), $how ) ) . "\n\n";
		}

		$groups = array(
			__( 'Key pages', 'markdown-endpoints-ai' )    => self::key_pages( $plugin ),
			__( 'Most read by AI agents', 'markdown-endpoints-ai' ) => self::popular( $plugin ),
			__( 'Latest posts', 'markdown-endpoints-ai' ) => self::latest( $plugin ),
		);

		foreach ( $groups as $title => $posts ) {
			$lines = array();
			foreach ( $posts as $post ) {
				if ( isset( $seen[ $post->ID ] ) ) {
					continue;
				}
				$seen[ $post->ID ] = true;
				$lines[]           = self::item( $post );
			}

			if ( $lines ) {
				$out .= '## ' . $title . "\n\n" . implode( "\n", $lines ) . "\n\n";
			}
		}

		if ( $plugin->get_setting( 'sitemap' ) ) {
			$out .= '## ' . __( 'Optional', 'markdown-endpoints-ai' ) . "\n\n- [" . __( 'Markdown sitemap', 'markdown-endpoints-ai' ) . '](' . self::sitemap_url() . '): ' . __( 'every page that has a Markdown version', 'markdown-endpoints-ai' ) . "\n";
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * One llms.txt list item.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function item( WP_Post $post ) {
		$url = self::markdown_url( $post );
		$url = '' !== $url ? $url : (string) get_permalink( $post );

		$description = Markdown_Endpoints_AI_SEO::description( $post );
		if ( '' === $description && has_excerpt( $post ) ) {
			$description = Markdown_Endpoints_AI_SEO::clean( $post->post_excerpt );
		}
		if ( '' === $description ) {
			$description = self::cached_description( $post );
		}
		if ( strlen( $description ) > 180 ) {
			$description = rtrim( preg_replace( '/\s+\S*$/u', '', function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 180 ) : substr( $description, 0, 180 ) ), ' ,.;:' ) . '...';
		}

		$title = str_replace( array( '[', ']' ), array( '(', ')' ), Markdown_Endpoints_AI_Document::title( $post ) );

		return '- [' . self::line( $title ) . '](' . Markdown_Endpoints_AI_Converter::url( $url ) . ')' . ( '' !== $description ? ': ' . self::line( $description ) : '' );
	}

	/**
	 * The description in a post's cached file, when it has one.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function cached_description( WP_Post $post ) {
		$file = Markdown_Endpoints_AI_Cache::file( $post->ID );
		if ( ! is_readable( $file ) ) {
			return '';
		}

		$head = (string) file_get_contents( $file, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! preg_match( '/^description: "((?:[^"\\\\]|\\\\.)*)"$/m', $head, $m ) ) {
			return '';
		}

		return stripcslashes( $m[1] );
	}

	/**
	 * Key pages: the ones chosen in the settings, else the front page and
	 * the top-level pages.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return WP_Post[]
	 */
	public static function key_pages( Markdown_Endpoints_AI_Plugin $plugin ) {
		$chosen = array_filter( array_map( 'absint', (array) $plugin->get_setting( 'llms_pages' ) ) );

		if ( $chosen ) {
			$posts = get_posts(
				array(
					'post_type'      => 'any',
					'post__in'       => $chosen,
					'orderby'        => 'post__in',
					'posts_per_page' => 50,
					'post_status'    => 'publish',
				)
			);
		} else {
			$posts = array();
			$front = 'page' === get_option( 'show_on_front' ) ? get_post( (int) get_option( 'page_on_front' ) ) : null;
			if ( $front instanceof WP_Post ) {
				$posts[] = $front;
			}

			$posts = array_merge(
				$posts,
				get_posts(
					array(
						'post_type'      => 'page',
						'post_parent'    => 0,
						'post_status'    => 'publish',
						'orderby'        => array(
							'menu_order' => 'ASC',
							'title'      => 'ASC',
						),
						'posts_per_page' => 30,
					)
				)
			);
		}

		return self::served( $posts, $plugin, 20 );
	}

	/**
	 * The pages AI agents asked for most in the last 30 days.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return WP_Post[]
	 */
	private static function popular( Markdown_Endpoints_AI_Plugin $plugin ) {
		$count = (int) $plugin->get_setting( 'llms_popular' );
		if ( $count <= 0 ) {
			return array();
		}

		$posts = array();
		foreach ( Markdown_Endpoints_AI_Log::top_pages( 30, Markdown_Endpoints_AI_Agents::AI, $count * 2 ) as $row ) {
			$post = get_post( $row['post_id'] );
			if ( $post instanceof WP_Post ) {
				$posts[] = $post;
			}
		}

		return self::served( $posts, $plugin, $count );
	}

	/**
	 * The latest posts (of the enabled types, pages left out).
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return WP_Post[]
	 */
	private static function latest( Markdown_Endpoints_AI_Plugin $plugin ) {
		$count = (int) $plugin->get_setting( 'llms_recent' );
		$types = array_values( array_diff( $plugin->post_types(), array( 'page' ) ) );

		if ( $count <= 0 || ! $types ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'has_password'   => false,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'posts_per_page' => $count * 2,
			)
		);

		return self::served( $posts, $plugin, $count );
	}

	/**
	 * Only the posts that are served, up to a count.
	 *
	 * @param WP_Post[]                    $posts  Posts.
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @param int                          $limit  Most to return.
	 * @return WP_Post[]
	 */
	private static function served( array $posts, Markdown_Endpoints_AI_Plugin $plugin, $limit ) {
		$out = array();

		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post && Markdown_Endpoints_AI_Access::is_served( $post, $plugin ) ) {
				$out[ $post->ID ] = $post;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return array_values( $out );
	}

	/**
	 * Text on one line, without Markdown-breaking line breaks.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function line( $text ) {
		return trim( preg_replace( '/\s+/', ' ', Markdown_Endpoints_AI_SEO::clean( $text ) ) );
	}

	// llms.txt file -----------------------------------------------------------------

	/**
	 * Path of the site's llms.txt.
	 *
	 * @return string
	 */
	public static function llms_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return trailingslashit( get_home_path() ) . 'llms.txt';
	}

	/**
	 * Whether a real llms.txt file exists.
	 *
	 * @return bool
	 */
	public static function file_exists() {
		return file_exists( self::llms_path() );
	}

	/**
	 * Whether the real file has this plugin's section.
	 *
	 * @return bool
	 */
	public static function file_has_section() {
		$path = self::llms_path();

		return is_readable( $path ) && false !== strpos( (string) file_get_contents( $path ), self::BEGIN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Write this plugin's section into the real llms.txt (creating the file
	 * with a full llms.txt when there's none).
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return bool
	 */
	public static function write_file( Markdown_Endpoints_AI_Plugin $plugin ) {
		$path    = self::llms_path();
		$exists  = file_exists( $path );
		$current = $exists && is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		delete_transient( self::LLMS_CACHE );

		if ( $exists && '' !== trim( self::without_section( $current ) ) ) {
			$block   = self::BEGIN . "\n" . self::sections( $plugin ) . self::END;
			$content = false !== strpos( $current, self::BEGIN ) ? self::replace_section( $current, $block ) : rtrim( $current ) . "\n\n" . $block . "\n";
		} else {
			$content = self::BEGIN . "\n" . self::llms_text( $plugin ) . self::END . "\n";
		}

		$ok = ( $exists ? wp_is_writable( $path ) : wp_is_writable( dirname( $path ) ) )
			&& false !== file_put_contents( $path, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		update_option(
			self::FILE_STATUS,
			array(
				'time' => time(),
				'ok'   => $ok ? 1 : 0,
			),
			false
		);

		return $ok;
	}

	/**
	 * Take this plugin's section out of the real llms.txt; delete the file if
	 * nothing else is left in it.
	 *
	 * @return void
	 */
	public static function remove_from_file() {
		$path = self::llms_path();

		if ( ! is_readable( $path ) || ! wp_is_writable( $path ) ) {
			return;
		}

		$current = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === strpos( $current, self::BEGIN ) ) {
			return;
		}

		$rest = trim( self::without_section( $current ) );

		if ( '' === $rest ) {
			wp_delete_file( $path );
		} else {
			file_put_contents( $path, $rest . "\n", LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		delete_option( self::FILE_STATUS );
	}

	/**
	 * A file's text with this plugin's section taken out.
	 *
	 * @param string $text File contents.
	 * @return string
	 */
	private static function without_section( $text ) {
		$start = strpos( $text, self::BEGIN );
		$end   = strpos( $text, self::END );

		if ( false === $start || false === $end || $end < $start ) {
			return $text;
		}

		return substr( $text, 0, $start ) . substr( $text, $end + strlen( self::END ) );
	}

	/**
	 * Replace this plugin's section in a file's text.
	 *
	 * @param string $text  File contents.
	 * @param string $block New section, markers included.
	 * @return string
	 */
	private static function replace_section( $text, $block ) {
		$start = strpos( $text, self::BEGIN );
		$end   = strpos( $text, self::END );

		if ( false === $start || false === $end || $end < $start ) {
			return rtrim( $text ) . "\n\n" . $block . "\n";
		}

		return substr( $text, 0, $start ) . $block . substr( $text, $end + strlen( self::END ) );
	}

	/**
	 * Job: rewrite the llms.txt file (queued a minute after content changes).
	 *
	 * @return void
	 */
	public static function run_file_job() {
		$plugin = self::plugin();

		if ( 'file' === $plugin->get_setting( 'llms' ) && $plugin->is_enabled() ) {
			self::write_file( $plugin );
		}
	}

	// Sitemap ---------------------------------------------------------------------

	/**
	 * Answer /markdown-sitemap.xml (and its numbered pages).
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @param int                          $page   Page, 0 for the first or the index.
	 * @return void
	 */
	public static function maybe_serve_sitemap( Markdown_Endpoints_AI_Plugin $plugin, $page ) {
		if ( ! $plugin->get_setting( 'sitemap' ) ) {
			return;
		}

		self::guard_request( $plugin, 'sitemap' );

		$cache = get_transient( self::SITEMAP_CACHE );
		$cache = is_array( $cache ) ? $cache : array();
		$key   = 'p' . (int) $page;

		if ( ! isset( $cache[ $key ] ) ) {
			$xml = self::sitemap_xml( $plugin, (int) $page );
			if ( null === $xml ) {
				self::send( 'text/plain', "Not found\n", 'sitemap', 404 );
			}
			$cache[ $key ] = $xml;
			set_transient( self::SITEMAP_CACHE, $cache, 6 * HOUR_IN_SECONDS );
		}

		self::send( 'application/xml', $cache[ $key ], 'sitemap' );
	}

	/**
	 * Build a sitemap page, or the index when there's more than one page.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @param int                          $page   Page.
	 * @return string|null Null for a page that doesn't exist.
	 */
	private static function sitemap_xml( Markdown_Endpoints_AI_Plugin $plugin, $page ) {
		$ids   = Markdown_Endpoints_AI_Cache::served_ids( 0, 50000 );
		$pages = max( 1, (int) ceil( count( $ids ) / self::SITEMAP_PAGE ) );

		if ( $page > $pages || ( 1 === $pages && $page > 1 ) ) {
			return null;
		}

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

		if ( 0 === $page && $pages > 1 ) {
			$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
			for ( $i = 1; $i <= $pages; $i++ ) {
				$xml .= "\t<sitemap><loc>" . esc_xml( self::sitemap_url( $i ) ) . "</loc></sitemap>\n";
			}
			return $xml . "</sitemapindex>\n";
		}

		$slice = array_slice( $ids, ( max( 1, $page ) - 1 ) * self::SITEMAP_PAGE, self::SITEMAP_PAGE );
		$xml  .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( array_chunk( $slice, 200 ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );

			foreach ( $chunk as $id ) {
				$post = get_post( $id );
				if ( ! $post instanceof WP_Post || ! Markdown_Endpoints_AI_Access::is_served( $post, $plugin ) ) {
					continue;
				}

				$url = self::markdown_url( $post, true );
				if ( '' === $url ) {
					continue;
				}

				$xml .= "\t<url><loc>" . esc_xml( $url ) . '</loc><lastmod>' . esc_xml( get_post_modified_time( 'c', true, $post ) ) . "</lastmod></url>\n";
			}
		}

		return $xml . "</urlset>\n";
	}

	// Shared ------------------------------------------------------------------------

	/**
	 * Rate-limit llms.txt and sitemap requests like .md ones.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin   Plugin.
	 * @param string                       $endpoint llms or sitemap.
	 * @return void
	 */
	private static function guard_request( Markdown_Endpoints_AI_Plugin $plugin, $endpoint ) {
		if ( is_user_logged_in() || Markdown_Endpoints_AI_Cache::is_internal_request( 0 ) ) {
			return;
		}

		$wait = Markdown_Endpoints_AI_Log::rate_limited( Markdown_Endpoints_AI_Log::client_ip(), (int) $plugin->get_setting( 'rate_limit' ) );
		if ( $wait ) {
			status_header( 429 );
			header( 'Retry-After: ' . (int) $wait );
			self::send( 'text/plain', "Too many requests\n", $endpoint, 429 );
		}
	}

	/**
	 * Send a text response and stop.
	 *
	 * @param string $type     Content type.
	 * @param string $body     Body.
	 * @param string $endpoint llms or sitemap.
	 * @param int    $status   HTTP status.
	 * @return void
	 */
	private static function send( $type, $body, $endpoint, $status = 200 ) {
		Markdown_Endpoints_AI_Router::no_page_cache();

		if ( ! headers_sent() ) {
			status_header( $status );
			header( 'Content-Type: ' . $type . '; charset=UTF-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Robots-Tag: noindex' );
			header( 'Cache-Control: ' . ( 200 === $status ? 'public, max-age=3600' : 'no-cache' ) );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'HEAD' !== $method ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text / XML built and escaped here.
		}

		Markdown_Endpoints_AI_Log::record_request( $endpoint, 0, $status, 'HEAD' === $method ? 0 : strlen( $body ) );
		exit;
	}

	/**
	 * Forget the generated llms.txt and sitemap, and queue a rewrite of a
	 * real llms.txt file.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::LLMS_CACHE );
		delete_transient( self::SITEMAP_CACHE );

		$plugin = self::plugin();
		if ( 'file' === $plugin->get_setting( 'llms' ) && ! wp_next_scheduled( self::FILE_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::FILE_HOOK );
		}

		Markdown_Endpoints_AI_Access::reset();
	}
}
