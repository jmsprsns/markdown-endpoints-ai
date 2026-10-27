<?php
/**
 * Answers Markdown requests.
 *
 * A request is for Markdown when its URL ends in .md (or has ?markdown=1),
 * or when it prefers text/markdown in its Accept header. From that moment
 * WordPress treats it as a logged-out visitor, before the main query runs,
 * so private posts, drafts and members' views never reach the output.
 *
 * A current file is sent straight from disk. Otherwise the page is rendered
 * normally by the theme into a buffer, converted, saved and sent. Every
 * response carries a canonical Link header to the HTML page; .md URLs also
 * send X-Robots-Tag: noindex, and header-negotiated responses send
 * Vary: Accept and are marked private so shared caches never keep them.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Request handler.
 */
final class Markdown_Endpoints_AI_Router {

	/**
	 * Plugin instance.
	 *
	 * @var Markdown_Endpoints_AI_Plugin
	 */
	private $plugin;

	/**
	 * How Markdown was asked for: md (URL), accept (header) or ''.
	 *
	 * @var string
	 */
	private $mode = '';

	/**
	 * The plugin's own signed header, if any (checked later).
	 *
	 * @var string
	 */
	private $internal = '';

	/**
	 * Purpose of a verified internal request: warm, check or ''.
	 *
	 * @var string
	 */
	private $purpose = '';

	/**
	 * Whether the visitor was logged in before being treated as logged out.
	 *
	 * @var bool
	 */
	private $was_logged_in = false;

	/**
	 * Post being captured.
	 *
	 * @var WP_Post|null
	 */
	private $post = null;

	/**
	 * Random value of the content marker for this render.
	 *
	 * @var string
	 */
	private $marker = '';

	/**
	 * Output buffer level of the capture.
	 *
	 * @var int
	 */
	private $capture_level = 0;

	/**
	 * Constructor.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 */
	public function __construct( Markdown_Endpoints_AI_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_filter( 'do_parse_request', array( $this, 'parse_request' ), 1 );
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 1 );
	}

	// Detection ---------------------------------------------------------------

	/**
	 * Spot Markdown requests (and llms.txt and the sitemap) before WordPress
	 * parses the URL. A .md suffix is taken off so the normal rewrite rules
	 * find the post.
	 *
	 * @param bool $do Whether WordPress should parse the request.
	 * @return bool
	 */
	public function parse_request( $do ) {
		if ( ! $do || ! $this->plugin->is_enabled() ) {
			return $do;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return $do;
		}

		// Read and written back raw, the way WP::parse_request() reads it next:
		// unslashing or sanitizing here would change the URL WordPress resolves.
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$split = strcspn( $uri, '?' );
		$path  = substr( $uri, 0, $split );
		$query = (string) substr( $uri, $split );
		$local = self::relative_path( $path );

		if ( self::is_rest( $local ) ) {
			return $do;
		}

		if ( 'llms.txt' === $local ) {
			Markdown_Endpoints_AI_Discovery::maybe_serve_llms( $this->plugin );
			return $do;
		}

		if ( preg_match( '/^markdown-sitemap(?:-([1-9][0-9]{0,4}))?\.xml$/', $local, $m ) ) {
			Markdown_Endpoints_AI_Discovery::maybe_serve_sitemap( $this->plugin, isset( $m[1] ) ? (int) $m[1] : 0 );
			return $do;
		}

		if ( isset( $_SERVER['HTTP_X_MDAI_INTERNAL'] ) ) {
			$this->internal = substr( preg_replace( '/[^A-Za-z0-9.]/', '', (string) wp_unslash( $_SERVER['HTTP_X_MDAI_INTERNAL'] ) ), 0, 200 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reduced to letters, digits and dots.
		}

		$urls_on = (bool) $this->plugin->get_setting( 'md_urls' ) || '' !== $this->internal;

		if ( $urls_on && '.md' === substr( $path, -3 ) && strlen( $path ) > 3 ) {
			$stripped = substr( $path, 0, -3 );
			if ( '/index' === substr( $stripped, -6 ) ) {
				$stripped = substr( $stripped, 0, -5 );
			}

			$_SERVER['REQUEST_URI'] = ( '' === $stripped ? '/' : $stripped ) . $query;

			if ( isset( $_SERVER['PATH_INFO'] ) && '.md' === substr( (string) $_SERVER['PATH_INFO'], -3 ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Raw, as above.
				$_SERVER['PATH_INFO'] = substr( (string) $_SERVER['PATH_INFO'], 0, -3 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Raw, as above.
			}

			$this->mode = 'md';
		} elseif ( $urls_on && isset( $_GET['markdown'] ) && '1' === $_GET['markdown'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only switch.
			$this->mode = 'md';
		} elseif ( $this->plugin->get_setting( 'negotiate' ) && isset( $_SERVER['HTTP_ACCEPT'] ) && self::wants_markdown( (string) wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only parsed.
			$this->mode = 'accept';
		}

		if ( 'md' === $this->mode || '' !== $this->internal ) {
			$this->become_visitor();
		}

		return $do;
	}

	/**
	 * Treat the rest of the request as a logged-out visitor.
	 *
	 * @return void
	 */
	private function become_visitor() {
		if ( is_user_logged_in() ) {
			$this->was_logged_in = true;
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Whether an Accept header prefers Markdown to HTML.
	 *
	 * @param string $accept Accept header.
	 * @return bool
	 */
	public static function wants_markdown( $accept ) {
		$accept = strtolower( substr( $accept, 0, 1000 ) );

		if ( false === strpos( $accept, 'markdown' ) ) {
			return false;
		}

		$markdown = 0.0;
		$html     = 0.0;

		foreach ( explode( ',', $accept ) as $range ) {
			$params = explode( ';', $range );
			$type   = trim( array_shift( $params ) );
			$q      = 1.0;

			foreach ( $params as $param ) {
				$pair = explode( '=', $param, 2 );
				if ( 'q' === trim( $pair[0] ) && isset( $pair[1] ) && is_numeric( trim( $pair[1] ) ) ) {
					$q = max( 0.0, min( 1.0, (float) trim( $pair[1] ) ) );
				}
			}

			if ( 'text/markdown' === $type || 'text/x-markdown' === $type ) {
				$markdown = max( $markdown, $q );
			} elseif ( 'text/html' === $type || 'application/xhtml+xml' === $type ) {
				$html = max( $html, $q );
			}
		}

		return $markdown > 0 && $markdown >= $html;
	}

	/**
	 * Request path relative to the site's home, without slashes at the ends.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	public static function relative_path( $path ) {
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path = rawurldecode( $path );

		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return trim( $path, '/' );
	}

	/**
	 * Whether a request is for the REST API (left alone).
	 *
	 * @param string $local Path relative to home.
	 * @return bool
	 */
	private static function is_rest( $local ) {
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( rest_get_url_prefix(), '/' ) : 'wp-json';

		return $local === $prefix || 0 === strpos( $local, $prefix . '/' );
	}

	// Answering ---------------------------------------------------------------

	/**
	 * Send the Markdown, or add the discovery headers to an HTML page.
	 *
	 * @return void
	 */
	public function template_redirect() {
		$post = self::queried_post();

		if ( '' !== $this->internal ) {
			$this->purpose = $post ? Markdown_Endpoints_AI_Cache::check_token( $this->internal, $post->ID ) : '';

			if ( '' !== $this->purpose && '' === $this->mode ) {
				$this->mode = 'md';
			}
		}

		// A forged internal header doesn't open .md URLs that are switched off.
		if ( 'md' === $this->mode && '' === $this->purpose && ! $this->plugin->get_setting( 'md_urls' ) ) {
			$this->respond_error( 404 );
		}

		if ( '' === $this->mode ) {
			$this->html_headers( $post );
			return;
		}

		if ( 'accept' === $this->mode ) {
			if ( ! $post || ! Markdown_Endpoints_AI_Access::is_served( $post, $this->plugin ) ) {
				// Not something with a Markdown version: the normal page.
				$this->mode = '';
				$this->html_headers( $post );
				return;
			}
			$this->become_visitor();
		}

		if ( ! $post || ! Markdown_Endpoints_AI_Access::is_served( $post, $this->plugin ) ) {
			$this->respond_error( 404 );
		}

		$this->post = $post;

		if ( '' === $this->purpose && ! $this->was_logged_in ) {
			$wait = Markdown_Endpoints_AI_Log::rate_limited( Markdown_Endpoints_AI_Log::client_ip(), (int) $this->plugin->get_setting( 'rate_limit' ) );
			if ( $wait ) {
				$this->respond_error( 429, $wait );
			}
		}

		$lookup = Markdown_Endpoints_AI_Cache::lookup( $post );

		if ( 'fresh' === $lookup['state'] && 'warm' !== $this->purpose ) {
			$this->serve_file( $post, $lookup );
		}

		if ( 'content' === $this->plugin->get_setting( 'source' ) ) {
			$info = Markdown_Endpoints_AI_Cache::store( $post, Markdown_Endpoints_AI_Document::from_content( $post, $this->plugin ) );
			if ( '' !== $info['blocked'] ) {
				$this->respond_error( 404 );
			}
			$this->serve_file( $post, Markdown_Endpoints_AI_Cache::lookup( $post ) );
		}

		$this->start_capture( $post );
	}

	/**
	 * The singular post this request is for (a static front page counts).
	 *
	 * @return WP_Post|null
	 */
	private static function queried_post() {
		if ( ! is_singular() ) {
			return null;
		}

		$post = get_queried_object();

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * On HTML pages that have a Markdown version: a Link header pointing at
	 * it, Vary: Accept when header requests are on, and content signals when
	 * they're set to go on every page.
	 *
	 * @param WP_Post|null $post Post.
	 * @return void
	 */
	private function html_headers( $post ) {
		if ( ! $post || headers_sent() || ! Markdown_Endpoints_AI_Access::is_served( $post, $this->plugin ) ) {
			return;
		}

		if ( $this->plugin->get_setting( 'discovery' ) ) {
			$url = Markdown_Endpoints_AI_Discovery::discovery_url( $post );
			if ( '' !== $url ) {
				header( 'Link: <' . $url . '>; rel="alternate"; type="text/markdown"', false );
			}
		}

		if ( $this->plugin->get_setting( 'negotiate' ) ) {
			header( 'Vary: Accept', false );
		}

		if ( $this->plugin->get_setting( 'signals_html' ) ) {
			self::signal_headers( $post, $this->plugin );
		}
	}

	/**
	 * Content-Signal and Content-Usage headers, when any signal is set.
	 *
	 * @param WP_Post|null                 $post   Post.
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return void
	 */
	public static function signal_headers( $post, Markdown_Endpoints_AI_Plugin $plugin ) {
		$signals = Markdown_Endpoints_AI_Document::signals( $post, $plugin );

		if ( ! $signals ) {
			return;
		}

		header( 'Content-Signal: ' . Markdown_Endpoints_AI_Document::signal_header( $signals ) );

		$usage = Markdown_Endpoints_AI_Document::usage_header( $signals );
		if ( '' !== $usage ) {
			header( 'Content-Usage: ' . $usage );
		}
	}

	/**
	 * Headers for a Markdown response.
	 *
	 * @param WP_Post    $post Post.
	 * @param array|null $info Cached file details (null before it's built).
	 * @return void
	 */
	private function send_headers( WP_Post $post, $info ) {
		if ( headers_sent() ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );

		$name = preg_match( '/^[a-z0-9\-]{1,100}$/', $post->post_name ) ? $post->post_name : 'post-' . $post->ID;
		header( 'Content-Disposition: inline; filename="' . $name . '.md"' );

		// Only the canonical link (WordPress's REST API links don't belong on Markdown).
		header_remove( 'Link' );
		$canonical = Markdown_Endpoints_AI_SEO::canonical( $post );
		if ( '' !== $canonical ) {
			header( 'Link: <' . $canonical . '>; rel="canonical"' );
		}

		if ( 'accept' === $this->mode ) {
			// Same URL as the HTML page: never noindex it, and keep it out of shared caches.
			header( 'Vary: Accept' );
			header( 'Cache-Control: private, no-cache' );
		} else {
			header( 'X-Robots-Tag: noindex' );
			header( 'Cache-Control: no-cache' );
		}

		header_remove( 'Expires' );
		header_remove( 'Pragma' );

		if ( is_array( $info ) && ! empty( $info['etag'] ) ) {
			header( 'ETag: "' . $info['etag'] . '"' );
			header( 'X-Markdown-Tokens: ' . (int) ceil( (int) $info['md'] / 4 ) );
		}

		$modified = strtotime( $post->post_modified_gmt . ' UTC' );
		if ( $modified ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
		}

		self::signal_headers( $post, $this->plugin );
	}

	/**
	 * Send a cached file and stop.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $lookup From Markdown_Endpoints_AI_Cache::lookup().
	 * @return void
	 */
	private function serve_file( WP_Post $post, array $lookup ) {
		if ( ! is_readable( $lookup['file'] ) ) {
			$this->respond_error( 404 );
		}

		self::no_page_cache();
		$this->send_headers( $post, $lookup['info'] );

		$etag = isset( $lookup['info']['etag'] ) ? (string) $lookup['info']['etag'] : '';
		if ( '' !== $etag && self::etag_matches( $etag ) ) {
			status_header( 304 );
			$this->log( $post->ID, 304, 0 );
			exit;
		}

		$bytes = (int) filesize( $lookup['file'] );

		if ( ! self::is_head() ) {
			readfile( $lookup['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Our cache file.
		}

		$this->log( $post->ID, 200, $bytes );
		exit;
	}

	/**
	 * Whether the client already has this version (If-None-Match).
	 *
	 * @param string $etag ETag without quotes.
	 * @return bool
	 */
	private static function etag_matches( $etag ) {
		if ( empty( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
			return false;
		}

		$sent = (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.

		foreach ( explode( ',', $sent ) as $tag ) {
			if ( trim( preg_replace( '#^W/#', '', trim( $tag ) ), '"' ) === $etag ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the page through the theme into a buffer; the shutdown hook
	 * converts it.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	private function start_capture( WP_Post $post ) {
		$this->post   = $post;
		$this->marker = strtolower( wp_generate_password( 16, false, false ) );

		self::no_page_cache();

		add_filter( 'the_content', array( $this, 'mark_content' ), PHP_INT_MAX );
		add_filter( 'content_pagination', array( 'Markdown_Endpoints_AI_Document', 'join_pages' ), 99 );
		add_filter( 'show_admin_bar', '__return_false', 999 );

		// The .md suffix was taken off the URL, so WordPress would "fix" it with a redirect.
		if ( 'md' === $this->mode ) {
			remove_action( 'template_redirect', 'redirect_canonical' );
		}

		// Sent now in case something flushes output early; repeated at the end.
		$this->send_headers( $post, null );

		ob_start();
		$this->capture_level = ob_get_level();

		add_action( 'shutdown', array( $this, 'finish_capture' ), 0 );
	}

	/**
	 * Wrap the post's own content in a marker, so it can be found in the
	 * rendered page. The marker value is random per render, so content can't
	 * fake it.
	 *
	 * @param string $content Content HTML.
	 * @return string
	 */
	public function mark_content( $content ) {
		if ( ! $this->post || ! is_string( $content ) || (int) get_the_ID() !== (int) $this->post->ID ) {
			return $content;
		}

		return '<div data-mdai-content="' . esc_attr( $this->marker ) . '">' . $content . '</div>';
	}

	/**
	 * Convert the captured page, save it and send it.
	 *
	 * @return void
	 */
	public function finish_capture() {
		if ( ! $this->post || ob_get_level() < $this->capture_level ) {
			return;
		}

		while ( ob_get_level() > $this->capture_level ) {
			ob_end_flush();
		}

		$html     = (string) ob_get_clean();
		$redirect = false;
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'location:' ) ) {
				$redirect = true;
				break;
			}
		}

		$status = (int) http_response_code();
		$fatal  = error_get_last();
		$fatal  = is_array( $fatal ) && in_array( $fatal['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true );

		if ( $redirect && 'accept' === $this->mode ) {
			// A redirect to the page's real address: pass it on as it is.
			if ( ! headers_sent() ) {
				header( 'Content-Type: text/html; charset=UTF-8' );
			}
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The page's own output.
			return;
		}

		if ( $redirect || $fatal || $status >= 300 ) {
			if ( ! headers_sent() ) {
				header_remove( 'Location' );
			}
			Markdown_Endpoints_AI_Cache::store(
				$this->post,
				array(
					'markdown'   => '',
					'source'     => 'rendered',
					'html_bytes' => strlen( $html ),
					'blocked'    => $redirect ? 'render_redirect' : 'render_error',
				)
			);
			$this->respond_error( $fatal ? 503 : 404, 0, false );
			return;
		}

		$result = Markdown_Endpoints_AI_Document::from_page( $this->post, $html, $this->marker, $this->plugin );
		$info   = Markdown_Endpoints_AI_Cache::store( $this->post, $result );

		if ( '' !== $info['blocked'] ) {
			$this->respond_error( 404, 0, false );
			return;
		}

		$this->send_headers( $this->post, $info );

		if ( ! self::is_head() ) {
			echo $result['markdown']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown served as text/markdown with nosniff.
		}

		$this->log( $this->post->ID, 200, strlen( $result['markdown'] ) );
	}

	/**
	 * A short Markdown error response.
	 *
	 * @param int  $code HTTP status.
	 * @param int  $wait Seconds to wait (429).
	 * @param bool $exit Stop afterwards (not from inside shutdown).
	 * @return void
	 */
	private function respond_error( $code, $wait = 0, $exit = true ) {
		if ( ! headers_sent() ) {
			status_header( $code );
			nocache_headers();
			header_remove( 'Link' );
			header( 'Content-Type: text/markdown; charset=UTF-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Robots-Tag: noindex' );
			if ( 'accept' === $this->mode ) {
				header( 'Vary: Accept' );
			}
			if ( $wait ) {
				header( 'Retry-After: ' . (int) $wait );
			}
		}

		if ( 429 === $code ) {
			/* translators: %d: seconds. */
			$body = "# Too many requests\n\n" . sprintf( __( 'Slow down a little: try again in %d seconds.', 'markdown-endpoints-ai' ), (int) $wait ) . "\n";
		} elseif ( 503 === $code ) {
			$body = "# Temporarily unavailable\n\n" . __( 'This page couldn\'t be prepared right now. Try again later.', 'markdown-endpoints-ai' ) . "\n";
		} else {
			$body = "# Not found\n\n" . __( 'There\'s no Markdown version of this page.', 'markdown-endpoints-ai' ) . "\n";
		}

		if ( ! self::is_head() ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text built here.
		}

		$this->log( $this->post ? $this->post->ID : 0, $code, 0 );

		if ( $exit ) {
			exit;
		}
	}

	/**
	 * Count the request in Insights (not the plugin's own requests or
	 * logged-in users checking their pages).
	 *
	 * @param int $post_id Post ID.
	 * @param int $status  HTTP status.
	 * @param int $bytes   Bytes sent.
	 * @return void
	 */
	private function log( $post_id, $status, $bytes ) {
		if ( '' !== $this->purpose || $this->was_logged_in ) {
			return;
		}

		Markdown_Endpoints_AI_Log::record_request( 'accept' === $this->mode ? 'accept' : 'md', $post_id, $status, $bytes );
	}

	/**
	 * Keep page caches and optimizers away from a Markdown response.
	 *
	 * @return void
	 */
	public static function no_page_cache() {
		foreach ( array( 'DONOTCACHEPAGE', 'DONOTROCKETOPTIMIZE', 'DONOTMINIFY' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				define( $constant, true );
			}
		}

		do_action( 'litespeed_control_set_nocache', 'Markdown Endpoints for AI' );
		add_filter( 'autoptimize_filter_noptimize', '__return_true' );

		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/**
	 * Whether this is a HEAD request.
	 *
	 * @return bool
	 */
	private static function is_head() {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}
}
