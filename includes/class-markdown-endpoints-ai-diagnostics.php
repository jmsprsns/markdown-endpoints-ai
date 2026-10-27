<?php
/**
 * The "Caching and endpoints" check on the settings page.
 *
 * It asks the site for a sample page the way an agent would (the .md URL,
 * then the normal URL with Accept: text/markdown) and then the way a browser
 * would. If a page cache or CDN kept the Markdown and handed it to the
 * browser request, or answered the agent with cached HTML, it says so. It
 * also checks llms.txt and the sitemap, and names the caching plugins and
 * CDN it can see.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Self-test.
 */
final class Markdown_Endpoints_AI_Diagnostics {

	/**
	 * Option holding the last results.
	 */
	const OPTION = 'markdown_endpoints_ai_check';

	/**
	 * Response headers that show a CDN or server cache in front of the site.
	 *
	 * @var array<string,string>
	 */
	private static $cdn_headers = array(
		'cf-cache-status'   => 'Cloudflare',
		'cdn-cache'         => 'Bunny CDN',
		'x-sucuri-cache'    => 'Sucuri',
		'x-kinsta-cache'    => 'Kinsta',
		'x-litespeed-cache' => 'LiteSpeed',
		'x-varnish'         => 'Varnish',
		'x-proxy-cache'     => 'Nginx proxy cache',
		'x-fastcgi-cache'   => 'Nginx FastCGI cache',
		'x-served-by'       => 'Fastly',
		'x-nf-request-id'   => 'Netlify',
		'x-vercel-cache'    => 'Vercel',
		'x-ac'              => 'Pressable / WordPress.com edge',
		'x-wpe-request-id'  => 'WP Engine',
		'x-sg-cache'        => 'SiteGround',
		'x-cache'           => 'a CDN or proxy cache',
	);

	/**
	 * Run every check and save the results.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return array{time: int, checks: array}
	 */
	public static function run( Markdown_Endpoints_AI_Plugin $plugin ) {
		$checks = array();
		$post   = self::sample_post( $plugin );
		$cdn    = array();

		if ( ! class_exists( 'DOMDocument' ) ) {
			$checks[] = self::item( 'bad', __( 'PHP\'s DOM extension is missing', 'markdown-endpoints-ai' ), __( 'The plugin needs it to read pages. Ask your host to turn on php-dom (php-xml).', 'markdown-endpoints-ai' ) );
		}

		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$checks[] = self::item( 'bad', __( 'Search engines are discouraged', 'markdown-endpoints-ai' ), __( 'Settings > Reading asks search engines not to index this site, so no Markdown is served. That\'s right for a staging site.', 'markdown-endpoints-ai' ) );
		}

		if ( ! $post ) {
			$checks[] = self::item( 'warn', __( 'No page to test with', 'markdown-endpoints-ai' ), __( 'Nothing published is served yet, so the live checks were skipped. Publish a post or page of a type that\'s switched on.', 'markdown-endpoints-ai' ) );
		} else {
			$checks = array_merge( $checks, self::live_checks( $post, $plugin, $cdn ) );
		}

		$checks   = array_merge( $checks, self::discovery_checks( $plugin ) );
		$checks   = array_merge( $checks, self::cache_plugins( $plugin, $cdn ) );
		$checks[] = self::jobs_check();

		$results = array(
			'time'   => time(),
			'post'   => $post ? $post->ID : 0,
			'checks' => $checks,
		);

		update_option( self::OPTION, $results, false );

		return $results;
	}

	/**
	 * The last saved results.
	 *
	 * @return array|null
	 */
	public static function last() {
		$results = get_option( self::OPTION );

		return is_array( $results ) && isset( $results['checks'] ) ? $results : null;
	}

	/**
	 * Count of warnings and problems in a result set.
	 *
	 * @param array $results Results.
	 * @return array{bad: int, warn: int}
	 */
	public static function counts( array $results ) {
		$out = array(
			'bad'  => 0,
			'warn' => 0,
		);

		foreach ( $results['checks'] as $check ) {
			if ( isset( $out[ $check['status'] ] ) ) {
				++$out[ $check['status'] ];
			}
		}

		return $out;
	}

	/**
	 * The newest post or page that's served, to test with.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return WP_Post|null
	 */
	public static function sample_post( Markdown_Endpoints_AI_Plugin $plugin ) {
		$types = $plugin->post_types();
		if ( ! $types || ! $plugin->is_enabled() ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $posts as $post ) {
			if ( Markdown_Endpoints_AI_Access::is_served( $post, $plugin ) ) {
				return $post;
			}
		}

		return null;
	}

	/**
	 * Ask for the sample page as an agent and as a browser.
	 *
	 * @param WP_Post                      $post   Sample post.
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @param string[]                     $cdn    Filled with caches seen in the replies.
	 * @return array
	 */
	private static function live_checks( WP_Post $post, Markdown_Endpoints_AI_Plugin $plugin, array &$cdn ) {
		$checks = array();
		$title  = Markdown_Endpoints_AI_Document::title( $post );

		// 1. The .md URL.
		if ( $plugin->get_setting( 'md_urls' ) ) {
			$response = Markdown_Endpoints_AI_Cache::internal_request( $post, 'check' );
			self::note_cdn( $response, $cdn );

			if ( is_wp_error( $response ) ) {
				$checks[] = self::item(
					'warn',
					__( 'This server can\'t reach its own site', 'markdown-endpoints-ai' ),
					/* translators: %s: error message. */
					sprintf( __( 'The test request failed (%s), so files can\'t be built ahead of time and this check can\'t see the live response. Markdown still works: each file is built on its first request.', 'markdown-endpoints-ai' ), $response->get_error_message() )
				);
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
				$robo = (string) wp_remote_retrieve_header( $response, 'x-robots-tag' );
				$link = self::header_string( wp_remote_retrieve_header( $response, 'link' ) );

				if ( 200 === $code && 0 === stripos( $type, 'text/markdown' ) && false !== stripos( $robo, 'noindex' ) && false !== strpos( $link, 'rel="canonical"' ) ) {
					$checks[] = self::item(
						'ok',
						__( '.md URLs work', 'markdown-endpoints-ai' ),
						/* translators: %s: post title. */
						sprintf( __( '"%s" came back as Markdown, with noindex and a canonical link to the page.', 'markdown-endpoints-ai' ), $title )
					);
				} else {
					$checks[] = self::item(
						'bad',
						__( '.md URLs aren\'t answered as expected', 'markdown-endpoints-ai' ),
						/* translators: 1: HTTP status, 2: content type. */
						sprintf( __( 'Got status %1$d with content type "%2$s". A security plugin, firewall or server rule may be blocking .md URLs, or the server sends them to a static file handler instead of WordPress.', 'markdown-endpoints-ai' ), $code, $type )
					);
				}
			}
		}

		// 2. The normal URL with Accept: text/markdown, without cache busting,
		// so a page cache in the way shows up.
		$permalink = (string) get_permalink( $post );
		if ( $plugin->get_setting( 'negotiate' ) ) {
			$response = Markdown_Endpoints_AI_Cache::internal_request( $post, 'check', array(), $permalink, false );
			self::note_cdn( $response, $cdn );

			if ( ! is_wp_error( $response ) ) {
				$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
				$vary = self::header_string( wp_remote_retrieve_header( $response, 'vary' ) );

				if ( 0 === stripos( $type, 'text/markdown' ) ) {
					$checks[] = self::item( 'ok', __( 'Accept-header requests get Markdown', 'markdown-endpoints-ai' ), __( 'The page answered an Accept: text/markdown request with Markdown, marked Vary: Accept and private so shared caches don\'t keep it.', 'markdown-endpoints-ai' ) . ( false === stripos( $vary, 'accept' ) ? ' ' . __( 'The Vary header didn\'t come through, though; something between WordPress and visitors removes it.', 'markdown-endpoints-ai' ) : '' ) );
				} else {
					$checks[] = self::item( 'warn', __( 'Accept-header requests got HTML', 'markdown-endpoints-ai' ), __( 'A page cache answered before WordPress could, so agents that ask by header get the cached HTML page for cached pages. That\'s harmless, and .md URLs aren\'t affected. To serve those agents too, exclude requests with "Accept: text/markdown" in your cache or CDN, if it allows that.', 'markdown-endpoints-ai' ) );
				}
			}
		}

		// 3. The same URL as a browser, right after: Markdown here means a
		// cache stored the agent's version and is handing it to people.
		$response = wp_safe_remote_get(
			$permalink,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
				'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ),
			)
		);
		self::note_cdn( $response, $cdn );

		if ( ! is_wp_error( $response ) ) {
			$type = (string) wp_remote_retrieve_header( $response, 'content-type' );

			if ( 0 === stripos( $type, 'text/markdown' ) ) {
				$checks[] = self::item( 'bad', __( 'A cache gave Markdown to a browser', 'markdown-endpoints-ai' ), __( 'The page came back as Markdown to a normal browser request. A cache or CDN is ignoring Cache-Control and Vary. Turn off Accept-header requests below, then purge the cache, until it\'s set to respect them.', 'markdown-endpoints-ai' ) );
			} else {
				$link = self::header_string( wp_remote_retrieve_header( $response, 'link' ) );
				$body = (string) wp_remote_retrieve_body( $response );
				$seen = false !== strpos( $link, 'text/markdown' ) || false !== strpos( $body, 'type="text/markdown"' );

				$checks[] = self::item( 'ok', __( 'Browsers get the normal page', 'markdown-endpoints-ai' ), $plugin->get_setting( 'discovery' ) && ! $seen ? __( 'The HTML came back as it should, but without the Markdown link. A page cache may have saved it before the plugin was on; purge it to add the link.', 'markdown-endpoints-ai' ) : __( 'The HTML came back as it should, with the link to its Markdown version.', 'markdown-endpoints-ai' ) );
			}
		}

		return $checks;
	}

	/**
	 * llms.txt and the sitemap.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return array
	 */
	private static function discovery_checks( Markdown_Endpoints_AI_Plugin $plugin ) {
		$checks = array();
		$mode   = (string) $plugin->get_setting( 'llms' );

		if ( 'off' !== $mode ) {
			$file = Markdown_Endpoints_AI_Discovery::file_exists();

			if ( 'virtual' === $mode && $file && ! Markdown_Endpoints_AI_Discovery::file_has_section() ) {
				$checks[] = self::item( 'warn', __( 'A real llms.txt file is in the way', 'markdown-endpoints-ai' ), __( 'Your server sends the existing file, so the generated one is never seen. Switch llms.txt to "Add a section to my llms.txt file" on the Discovery tab.', 'markdown-endpoints-ai' ) );
			} else {
				$response = self::fetch( Markdown_Endpoints_AI_Discovery::llms_url() );
				$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
				$body     = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );

				if ( 200 === $code && ( 'file' !== $mode || false !== strpos( $body, Markdown_Endpoints_AI_Discovery::BEGIN ) ) ) {
					$checks[] = self::item( 'ok', __( 'llms.txt is live', 'markdown-endpoints-ai' ), 'file' === $mode ? __( 'Your llms.txt file includes the Markdown section.', 'markdown-endpoints-ai' ) : __( 'WordPress answers /llms.txt with the generated file.', 'markdown-endpoints-ai' ) );
				} elseif ( is_wp_error( $response ) ) {
					$checks[] = self::item( 'info', __( 'llms.txt wasn\'t checked', 'markdown-endpoints-ai' ), __( 'This server can\'t reach its own site.', 'markdown-endpoints-ai' ) );
				} else {
					/* translators: %d: HTTP status. */
					$checks[] = self::item( 'bad', __( 'llms.txt isn\'t reachable', 'markdown-endpoints-ai' ), sprintf( __( '/llms.txt returned status %d. Some servers send every .txt address to a static file handler; ask your host to pass /llms.txt to WordPress, or use "Add a section to my llms.txt file".', 'markdown-endpoints-ai' ), $code ) );
				}
			}
		}

		if ( $plugin->get_setting( 'sitemap' ) ) {
			$response = self::fetch( Markdown_Endpoints_AI_Discovery::sitemap_url() );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			if ( 200 === $code ) {
				$checks[] = self::item( 'ok', __( 'The Markdown sitemap is live', 'markdown-endpoints-ai' ), __( 'It lists every .md URL, and sends noindex so search engines don\'t show it.', 'markdown-endpoints-ai' ) );
			} elseif ( ! is_wp_error( $response ) ) {
				/* translators: %d: HTTP status. */
				$checks[] = self::item( 'bad', __( 'The Markdown sitemap isn\'t reachable', 'markdown-endpoints-ai' ), sprintf( __( 'It returned status %d.', 'markdown-endpoints-ai' ), $code ) );
			}
		}

		return $checks;
	}

	/**
	 * Caching plugins and hosts, and what they mean here.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @param string[]                     $cdn    Caches seen in the replies.
	 * @return array
	 */
	private static function cache_plugins( Markdown_Endpoints_AI_Plugin $plugin, array $cdn ) {
		$found = self::page_caches();
		$names = array_values( array_unique( array_merge( $found, $cdn ) ) );

		if ( ! $names ) {
			return array( self::item( 'ok', __( 'No page cache found', 'markdown-endpoints-ai' ), __( 'Every request reaches WordPress, so both .md URLs and Accept-header requests are answered fresh.', 'markdown-endpoints-ai' ) ) );
		}

		$detail = $plugin->get_setting( 'negotiate' )
			? __( 'Markdown responses tell page caches not to keep them (DONOTCACHEPAGE, LiteSpeed\'s no-cache flag, Cache-Control). Pages a cache already holds are answered before WordPress runs, so agents that ask by header get HTML for those; .md URLs always work. Purge the cache once after setting up, so cached pages pick up the Markdown link.', 'markdown-endpoints-ai' )
			: __( 'Markdown responses tell page caches not to keep them, and .md URLs never collide with your HTML pages. Purge the cache once after setting up, so cached pages pick up the Markdown link.', 'markdown-endpoints-ai' );

		/* translators: %s: list of caches. */
		return array( self::item( 'info', sprintf( __( 'Caching: %s', 'markdown-endpoints-ai' ), implode( ', ', $names ) ), $detail ) );
	}

	/**
	 * Page caching plugins and managed-host caches that are active.
	 *
	 * @return string[]
	 */
	public static function page_caches() {
		$found = array();
		$known = array(
			'WP_ROCKET_VERSION'       => 'WP Rocket',
			'W3TC'                    => 'W3 Total Cache',
			'WPCACHEHOME'             => 'WP Super Cache',
			'LSCWP_V'                 => 'LiteSpeed Cache',
			'WPFC_WP_CONTENT_URL'     => 'WP Fastest Cache',
			'CACHE_ENABLER_VERSION'   => 'Cache Enabler',
			'BREEZE_VERSION'          => 'Breeze',
			'WPHB_VERSION'            => 'Hummingbird',
			'SWCFPC_PLUGIN_PATH'      => 'Super Page Cache',
			'FLYING_PRESS_VERSION'    => 'FlyingPress',
			'NITROPACK_VERSION'       => 'NitroPack',
			'COMET_CACHE_PLUGIN_FILE' => 'Comet Cache',
			'WPO_VERSION'             => 'WP-Optimize',
			'CLOUDFLARE_PLUGIN_DIR'   => 'Cloudflare',
			'KINSTAMU_VERSION'        => 'Kinsta',
			'WPE_APIKEY'              => 'WP Engine',
			'PANTHEON_ENVIRONMENT'    => 'Pantheon',
		);

		foreach ( $known as $constant => $name ) {
			if ( defined( $constant ) ) {
				$found[] = $name;
			}
		}

		if ( class_exists( 'SiteGround_Optimizer\\Loader\\Loader' ) || defined( 'SG_OPTIMIZER_VERSION' ) ) {
			$found[] = 'SiteGround Optimizer';
		}

		if ( ! $found && defined( 'WP_CACHE' ) && WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			$found[] = __( 'a page cache (advanced-cache.php)', 'markdown-endpoints-ai' );
		}

		return $found;
	}

	/**
	 * Background building: Action Scheduler or WP-Cron, and the last failure.
	 *
	 * @return array
	 */
	private static function jobs_check() {
		$error = get_option( Markdown_Endpoints_AI_Cache::WARM_ERROR_OPTION );

		if ( is_array( $error ) && ! empty( $error['time'] ) && $error['time'] > time() - DAY_IN_SECONDS ) {
			/* translators: %s: error message. */
			return self::item( 'warn', __( 'Background building had a problem', 'markdown-endpoints-ai' ), sprintf( __( 'The last attempt failed (%s). Files are still built on their first request.', 'markdown-endpoints-ai' ), $error['error'] ) );
		}

		if ( Markdown_Endpoints_AI_Cache::has_action_scheduler() ) {
			return self::item( 'ok', __( 'Files are built in the background with Action Scheduler', 'markdown-endpoints-ai' ), __( 'Publishing or updating a post queues its Markdown file, so it\'s ready before the first agent asks.', 'markdown-endpoints-ai' ) );
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return self::item( 'info', __( 'Files are built in the background with WP-Cron', 'markdown-endpoints-ai' ), __( 'WP-Cron is switched off in wp-config.php, so make sure your server runs it on a schedule. Files are also built on their first request.', 'markdown-endpoints-ai' ) );
		}

		return self::item( 'ok', __( 'Files are built in the background with WP-Cron', 'markdown-endpoints-ai' ), __( 'Install a plugin that includes Action Scheduler (WooCommerce, Rank Math and others do) and it\'s used instead.', 'markdown-endpoints-ai' ) );
	}

	/**
	 * GET a URL on this site, marked as the plugin's own (not logged).
	 *
	 * @param string $url URL.
	 * @return array|WP_Error
	 */
	private static function fetch( $url ) {
		return wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'X-MDAI-Internal' => Markdown_Endpoints_AI_Cache::token( 'check', 0 ) ),
			)
		);
	}

	/**
	 * Remember which caches answered.
	 *
	 * @param array|WP_Error $response Response.
	 * @param string[]       $cdn      Caches seen.
	 * @return void
	 */
	private static function note_cdn( $response, array &$cdn ) {
		if ( is_wp_error( $response ) ) {
			return;
		}

		foreach ( self::$cdn_headers as $header => $name ) {
			if ( '' !== self::header_string( wp_remote_retrieve_header( $response, $header ) ) ) {
				$cdn[] = $name;
				break;
			}
		}
	}

	/**
	 * A header that may come back as a list, as one string.
	 *
	 * @param mixed $value Header value(s).
	 * @return string
	 */
	private static function header_string( $value ) {
		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}

	/**
	 * One result.
	 *
	 * @param string $status ok, info, warn or bad.
	 * @param string $title  Headline.
	 * @param string $detail Explanation.
	 * @return array
	 */
	private static function item( $status, $title, $detail ) {
		return array(
			'status' => $status,
			'title'  => $title,
			'detail' => $detail,
		);
	}
}
