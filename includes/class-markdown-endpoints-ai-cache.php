<?php
/**
 * Pre-built Markdown files on disk, and the background jobs that build them.
 *
 * Each post's Markdown is saved as a static file in a folder with a random
 * name under uploads (with deny rules for Apache), and served from there
 * until the post, the settings, the theme or the active plugins change.
 * Publishing or updating a post queues a background job (Action Scheduler
 * when a plugin on the site provides it, WP-Cron otherwise) that asks the
 * site for the post's .md URL, so the file is ready before the first agent
 * arrives. Unpublishing, trashing or switching a post off deletes its file
 * straight away.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Disk cache and pre-generation.
 */
final class Markdown_Endpoints_AI_Cache {

	/**
	 * Post meta with the cached file's details.
	 */
	const META = '_mdai_cache';

	/**
	 * Post meta: size of the Markdown, for the token savings report.
	 */
	const META_MD = '_mdai_md_bytes';

	/**
	 * Post meta: size of the HTML page it came from.
	 */
	const META_HTML = '_mdai_html_bytes';

	/**
	 * Option: bumped to make every cached file stale.
	 */
	const VERSION_OPTION = 'markdown_endpoints_ai_cache_version';

	/**
	 * Option: the cache folder's random name.
	 */
	const KEY_OPTION = 'markdown_endpoints_ai_cache_key';

	/**
	 * Option: progress of "Rebuild all".
	 */
	const REBUILD_OPTION = 'markdown_endpoints_ai_rebuild';

	/**
	 * Option: the last background job that couldn't reach the site.
	 */
	const WARM_ERROR_OPTION = 'markdown_endpoints_ai_warm_error';

	/**
	 * Background job: build one post's file.
	 */
	const WARM_HOOK = 'markdown_endpoints_ai_warm';

	/**
	 * Background job: one batch of "Rebuild all".
	 */
	const REBUILD_HOOK = 'markdown_endpoints_ai_rebuild';

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'markdown-endpoints-ai';

	/**
	 * Seconds before a page that failed to render is tried again.
	 */
	const RETRY_AFTER = 600;

	/**
	 * Post meta keys that change what a post's Markdown says.
	 *
	 * @var string[]
	 */
	private static $post_settings = array( '_mdai_exclude', '_mdai_signal_search', '_mdai_signal_input', '_mdai_signal_train' );

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

		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'forget' ) );
		add_action( 'trashed_post', array( __CLASS__, 'forget' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );

		// Anything that changes how pages render makes every file stale.
		add_action( 'update_option_' . Markdown_Endpoints_AI_Plugin::OPTION, array( __CLASS__, 'bump' ) );
		add_action( 'switch_theme', array( __CLASS__, 'bump' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'bump' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'bump' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'bump' ) );

		add_action( self::WARM_HOOK, array( __CLASS__, 'warm' ) );
		add_action( self::REBUILD_HOOK, array( __CLASS__, 'rebuild_batch' ) );
	}

	// Files -------------------------------------------------------------------

	/**
	 * The cache folder, with a trailing slash.
	 *
	 * @return string
	 */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );

		return trailingslashit( $uploads['basedir'] ) . 'markdown-endpoints-ai/' . self::key() . '/';
	}

	/**
	 * The folder's random name, made on first use.
	 *
	 * @return string
	 */
	private static function key() {
		$key = (string) get_option( self::KEY_OPTION, '' );

		if ( ! preg_match( '/^[a-z0-9]{20}$/', $key ) ) {
			$key = strtolower( wp_generate_password( 20, false, false ) );
			update_option( self::KEY_OPTION, $key, false );
		}

		return $key;
	}

	/**
	 * Create the folder with its deny rules and empty index files.
	 *
	 * @return bool Whether it's writable.
	 */
	public static function ensure_dir() {
		$dir  = self::dir();
		$base = dirname( $dir ) . '/';

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Cache files, written directly like other page caches.
		if ( ! file_exists( $base . '.htaccess' ) ) {
			@file_put_contents( $base . '.htaccess', "# Markdown Endpoints for AI: served through WordPress, never directly.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		foreach ( array( $base, $dir ) as $folder ) {
			if ( ! file_exists( $folder . 'index.php' ) ) {
				@file_put_contents( $folder . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		// phpcs:enable

		return wp_is_writable( $dir );
	}

	/**
	 * Path of a post's file.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function file( $post_id ) {
		return self::dir() . absint( $post_id ) . '.md';
	}

	/**
	 * What makes a file current: the post's last change, the settings, and
	 * the plugin version.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function signature( WP_Post $post ) {
		return md5( $post->post_modified_gmt . '|' . (int) get_option( self::VERSION_OPTION, 1 ) . '|' . MARKDOWN_ENDPOINTS_AI_VERSION );
	}

	/**
	 * The stored details of a post's file.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function info( $post_id ) {
		$info = get_post_meta( $post_id, self::META, true );

		return is_array( $info ) ? $info : array();
	}

	/**
	 * Whether a post has a current file, has to be built, or was found
	 * unservable when it was last rendered.
	 *
	 * @param WP_Post $post Post.
	 * @return array{state: string, info: array, file: string} State is
	 *         fresh, blocked, stale or missing.
	 */
	public static function lookup( WP_Post $post ) {
		$info = self::info( $post->ID );
		$file = self::file( $post->ID );
		$out  = array(
			'state' => 'missing',
			'info'  => $info,
			'file'  => $file,
		);

		if ( ! $info ) {
			return $out;
		}

		$current = isset( $info['sig'] ) && hash_equals( (string) $info['sig'], self::signature( $post ) );
		$time    = isset( $info['time'] ) ? (int) $info['time'] : 0;

		if ( $current && ! empty( $info['blocked'] ) ) {
			$retry        = in_array( $info['blocked'], array( 'render_error', 'render_redirect' ), true ) && $time < time() - self::RETRY_AFTER;
			$out['state'] = $retry ? 'stale' : 'blocked';
			return $out;
		}

		$max_age = max( 1, (int) self::$plugin->get_setting( 'max_age' ) ) * DAY_IN_SECONDS;

		if ( $current && $time > time() - $max_age && is_readable( $file ) ) {
			$out['state'] = 'fresh';
		} elseif ( is_readable( $file ) ) {
			$out['state'] = 'stale';
		}

		return $out;
	}

	/**
	 * Why the last render said a post can't be served, while that still applies.
	 *
	 * @param WP_Post                      $post   Post.
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin.
	 * @return string Reason code or ''.
	 */
	public static function blocked_reason( WP_Post $post, Markdown_Endpoints_AI_Plugin $plugin ) {
		self::$plugin = self::$plugin ? self::$plugin : $plugin;
		$lookup       = self::lookup( $post );

		return 'blocked' === $lookup['state'] ? (string) $lookup['info']['blocked'] : '';
	}

	/**
	 * Save a build result: the file when it can be served, the reason when
	 * it can't.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $result From Markdown_Endpoints_AI_Document.
	 * @return array The stored details.
	 */
	public static function store( WP_Post $post, array $result ) {
		$markdown = (string) $result['markdown'];
		$blocked  = (string) $result['blocked'];
		$info     = array(
			'sig'     => self::signature( $post ),
			'time'    => time(),
			'source'  => (string) $result['source'],
			'html'    => (int) $result['html_bytes'],
			'md'      => 0,
			'etag'    => '',
			'blocked' => $blocked,
		);

		if ( '' === $blocked && '' === trim( $markdown ) ) {
			$info['blocked'] = 'render_error';
		}

		if ( '' === $info['blocked'] && self::write( $post->ID, $markdown ) ) {
			$info['md']   = strlen( $markdown );
			$info['etag'] = md5( $markdown );
			update_post_meta( $post->ID, self::META_MD, $info['md'] );
			update_post_meta( $post->ID, self::META_HTML, $info['html'] );
		} else {
			self::delete_file( $post->ID );
			delete_post_meta( $post->ID, self::META_MD );
			delete_post_meta( $post->ID, self::META_HTML );
		}

		update_post_meta( $post->ID, self::META, $info );

		return $info;
	}

	/**
	 * Write a file atomically (to a temporary name, then renamed), so a
	 * request never reads half a file.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $markdown Markdown.
	 * @return bool
	 */
	private static function write( $post_id, $markdown ) {
		if ( ! self::ensure_dir() ) {
			return false;
		}

		$file = self::file( $post_id );
		$temp = $file . '.' . strtolower( wp_generate_password( 8, false, false ) ) . '.tmp';

		// phpcs:disable WordPress.WP.AlternativeFunctions -- Cache files, written directly like other page caches.
		if ( false === @file_put_contents( $temp, $markdown, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		if ( ! @rename( $temp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		// phpcs:enable

		return true;
	}

	/**
	 * Delete a post's file.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function delete_file( $post_id ) {
		$file = self::file( $post_id );

		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Delete a post's file and everything stored about it.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function forget( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}

		self::delete_file( $post_id );
		delete_post_meta( $post_id, self::META );
		delete_post_meta( $post_id, self::META_MD );
		delete_post_meta( $post_id, self::META_HTML );
		Markdown_Endpoints_AI_Discovery::flush();
	}

	/**
	 * Make every file stale (they rebuild as they're next asked for).
	 *
	 * @return void
	 */
	public static function bump() {
		update_option( self::VERSION_OPTION, (int) get_option( self::VERSION_OPTION, 1 ) + 1, true );
		Markdown_Endpoints_AI_Discovery::flush();
	}

	/**
	 * Delete every cached file and its details.
	 *
	 * @return int Files deleted.
	 */
	public static function clear() {
		$count = 0;
		$files = glob( self::dir() . '*.md' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			wp_delete_file( $file );
			++$count;
		}

		delete_post_meta_by_key( self::META );
		delete_post_meta_by_key( self::META_MD );
		delete_post_meta_by_key( self::META_HTML );
		self::bump();

		return $count;
	}

	/**
	 * Totals for the settings page.
	 *
	 * @return array{files: int, md: int, html: int}
	 */
	public static function totals() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$md   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS files, COALESCE(SUM(CAST(meta_value AS UNSIGNED)), 0) AS bytes FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_MD ), ARRAY_A );
		$html = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)), 0) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_HTML ) );
		// phpcs:enable

		return array(
			'files' => $md ? (int) $md['files'] : 0,
			'md'    => $md ? (int) $md['bytes'] : 0,
			'html'  => (int) $html,
		);
	}

	// Changes -----------------------------------------------------------------

	/**
	 * A post was saved: drop its file if it's no longer public, else queue
	 * a fresh build.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function on_save( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		Markdown_Endpoints_AI_Discovery::flush();

		if ( ! Markdown_Endpoints_AI_Access::is_served( $post, self::$plugin ) ) {
			if ( self::info( $post_id ) || file_exists( self::file( $post_id ) ) ) {
				self::forget( $post_id );
			}
			return;
		}

		self::queue( $post_id );
	}

	/**
	 * A post left (or reached) "published".
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || $new_status === $old_status ) {
			return;
		}

		if ( 'publish' === $old_status ) {
			self::forget( $post->ID );
		} elseif ( 'publish' === $new_status ) {
			Markdown_Endpoints_AI_Discovery::flush();
		}
	}

	/**
	 * A per-post Markdown setting changed (after save_post when the block
	 * editor saves it).
	 *
	 * @param mixed  $meta_id   Meta ID(s).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public static function on_meta( $meta_id, $object_id, $meta_key ) {
		if ( ! in_array( $meta_key, self::$post_settings, true ) ) {
			return;
		}

		$post = get_post( $object_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$info = self::info( $post->ID );
		if ( $info ) {
			$info['sig'] = '';
			update_post_meta( $post->ID, self::META, $info );
		}

		Markdown_Endpoints_AI_Discovery::flush();

		if ( Markdown_Endpoints_AI_Access::is_served( $post, self::$plugin ) ) {
			self::queue( $post->ID );
		} else {
			self::forget( $post->ID );
		}
	}

	// Background jobs ---------------------------------------------------------

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	public static function has_action_scheduler() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Queue a build of one post's file.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function queue( $post_id ) {
		if ( ! self::$plugin->is_enabled() ) {
			return;
		}

		$args = array( absint( $post_id ) );

		if ( self::has_action_scheduler() && did_action( 'action_scheduler_init' ) ) {
			$pending = function_exists( 'as_has_scheduled_action' )
				? as_has_scheduled_action( self::WARM_HOOK, $args, self::GROUP )
				: false !== as_next_scheduled_action( self::WARM_HOOK, $args, self::GROUP );

			if ( ! $pending ) {
				as_enqueue_async_action( self::WARM_HOOK, $args, self::GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::WARM_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 10, self::WARM_HOOK, $args );
		}
	}

	/**
	 * Schedule the next batch of "Rebuild all".
	 *
	 * @param int $delay Seconds.
	 * @return void
	 */
	private static function queue_batch( $delay = 0 ) {
		if ( self::has_action_scheduler() && did_action( 'action_scheduler_init' ) ) {
			as_enqueue_async_action( self::REBUILD_HOOK, array(), self::GROUP );
			return;
		}

		wp_schedule_single_event( time() + $delay, self::REBUILD_HOOK );
	}

	/**
	 * Stop every queued job.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		wp_unschedule_hook( self::WARM_HOOK );
		wp_unschedule_hook( self::REBUILD_HOOK );

		if ( self::has_action_scheduler() ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}

		delete_option( self::REBUILD_OPTION );
	}

	/**
	 * Job: build one post's file.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function warm( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! Markdown_Endpoints_AI_Access::is_served( $post, self::$plugin ) ) {
			self::forget( $post->ID );
			return;
		}

		self::build( $post );
	}

	/**
	 * Build a post's file now. With "Rendered page" the site is asked for
	 * the post's .md URL, which renders and saves it in that request.
	 *
	 * @param WP_Post $post Post.
	 * @return bool Whether a file was built.
	 */
	public static function build( WP_Post $post ) {
		if ( 'content' === self::$plugin->get_setting( 'source' ) ) {
			$info = self::store( $post, Markdown_Endpoints_AI_Document::from_content( $post, self::$plugin ) );
			return '' === $info['blocked'];
		}

		$response = self::internal_request( $post, 'warm' );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$code = is_wp_error( $response ) ? $response->get_error_message() : (string) wp_remote_retrieve_response_code( $response );

			// 404 just means the render found it can't be served; that's recorded already.
			if ( '404' !== $code ) {
				update_option(
					self::WARM_ERROR_OPTION,
					array(
						'time'  => time(),
						'error' => substr( sanitize_text_field( $code ), 0, 200 ),
					),
					false
				);
			}
			return false;
		}

		delete_option( self::WARM_ERROR_OPTION );

		// The other request saved the file's details; drop this request's stale copy.
		wp_cache_delete( $post->ID, 'post_meta' );

		return true;
	}

	/**
	 * Ask this site for a post's Markdown, as a logged-out visitor, with a
	 * signed header that marks the request as the plugin's own.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $purpose warm (rebuild it) or check (diagnostics).
	 * @param array   $headers Extra headers.
	 * @param string  $url     URL to ask for (default: the post's .md URL).
	 * @param bool    $bust    Add a query string so no page cache answers.
	 * @return array|WP_Error
	 */
	public static function internal_request( WP_Post $post, $purpose, array $headers = array(), $url = '', $bust = true ) {
		$url = '' !== $url ? $url : Markdown_Endpoints_AI_Discovery::markdown_url( $post, true );

		// The signed header only ever goes to this site.
		if ( '' === $url || ! Markdown_Endpoints_AI_Discovery::is_own_url( $url ) ) {
			return new WP_Error( 'mdai_no_url', 'The post\'s address isn\'t on this site.' );
		}

		return wp_safe_remote_get(
			$bust ? add_query_arg( 'mdai_nocache', time(), $url ) : $url,
			array(
				'timeout'             => 30,
				'redirection'         => 0,
				// Only our own site is asked, and nothing is read from the reply but its status.
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
				'limit_response_size' => 4194304,
				'user-agent'          => 'Markdown Endpoints for AI/' . MARKDOWN_ENDPOINTS_AI_VERSION . '; ' . home_url( '/' ),
				'headers'             => array_merge(
					array(
						'Accept'          => 'text/markdown',
						'Cache-Control'   => 'no-cache',
						'X-MDAI-Internal' => self::token( $purpose, $post->ID ),
					),
					$headers
				),
			)
		);
	}

	/**
	 * Signed header for the plugin's own requests.
	 *
	 * @param string   $purpose warm or check.
	 * @param int      $post_id Post ID.
	 * @param int|null $window  Ten-minute window (default: now).
	 * @return string
	 */
	public static function token( $purpose, $post_id, $window = null ) {
		$window = null === $window ? (int) floor( time() / 600 ) : (int) $window;
		$data   = $purpose . '|' . absint( $post_id ) . '|' . $window;

		return $purpose . '.' . absint( $post_id ) . '.' . $window . '.' . hash_hmac( 'sha256', $data, wp_salt( 'nonce' ) );
	}

	/**
	 * Check a signed header against the post being requested.
	 *
	 * @param string $header  Header value.
	 * @param int    $post_id Post the request resolved to.
	 * @return string The purpose (warm, check), or '' when it doesn't check out.
	 */
	public static function check_token( $header, $post_id ) {
		$parts = explode( '.', (string) $header );

		if ( 4 !== count( $parts ) || ! in_array( $parts[0], array( 'warm', 'check' ), true ) || absint( $parts[1] ) !== absint( $post_id ) ) {
			return '';
		}

		$now = (int) floor( time() / 600 );
		if ( ! in_array( (int) $parts[2], array( $now, $now - 1 ), true ) ) {
			return '';
		}

		return hash_equals( self::token( $parts[0], $post_id, (int) $parts[2] ), (string) $header ) ? $parts[0] : '';
	}

	/**
	 * Whether the current request is one of the plugin's own, signed for
	 * this post (0 for llms.txt and the sitemap).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_internal_request( $post_id ) {
		if ( empty( $_SERVER['HTTP_X_MDAI_INTERNAL'] ) ) {
			return false;
		}

		$header = preg_replace( '/[^A-Za-z0-9.]/', '', (string) wp_unslash( $_SERVER['HTTP_X_MDAI_INTERNAL'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reduced to letters, digits and dots.

		return '' !== self::check_token( substr( $header, 0, 200 ), $post_id );
	}

	// Rebuild all -----------------------------------------------------------------

	/**
	 * Start rebuilding every file in the background.
	 *
	 * @return int Posts to go through.
	 */
	public static function rebuild_all() {
		$total = count( self::served_ids( 0, 100000 ) );

		update_option(
			self::REBUILD_OPTION,
			array(
				'offset'  => 0,
				'total'   => $total,
				'built'   => 0,
				'started' => time(),
				'done'    => 0,
			),
			false
		);

		self::bump();
		self::queue_batch();

		return $total;
	}

	/**
	 * Progress of "Rebuild all".
	 *
	 * @return array|null
	 */
	public static function rebuild_state() {
		$state = get_option( self::REBUILD_OPTION );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Job: build the next few files, for up to 20 seconds, then queue the rest.
	 *
	 * @return void
	 */
	public static function rebuild_batch() {
		$state = self::rebuild_state();
		if ( ! $state || ! empty( $state['done'] ) ) {
			return;
		}

		$started = microtime( true );
		$ids     = self::served_ids( (int) $state['offset'], 25 );

		foreach ( $ids as $id ) {
			++$state['offset'];
			$post = get_post( $id );

			if ( $post instanceof WP_Post && Markdown_Endpoints_AI_Access::is_served( $post, self::$plugin ) ) {
				$lookup = self::lookup( $post );
				if ( 'fresh' === $lookup['state'] || self::build( $post ) ) {
					++$state['built'];
				}
			}

			if ( microtime( true ) - $started > 20 ) {
				break;
			}
		}

		if ( ! $ids || $state['offset'] >= $state['total'] ) {
			$state['done']     = 1;
			$state['finished'] = time();
			update_option( self::REBUILD_OPTION, $state, false );
			return;
		}

		update_option( self::REBUILD_OPTION, $state, false );
		self::queue_batch( 5 );
	}

	/**
	 * IDs of published, unprotected posts of the enabled types.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  How many.
	 * @return int[]
	 */
	public static function served_ids( $offset, $limit ) {
		$types = self::$plugin->post_types();
		if ( ! $types ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'has_password'           => false,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'offset'                 => max( 0, (int) $offset ),
				'posts_per_page'         => max( 1, (int) $limit ),
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => true,
				'update_post_term_cache' => false,
			)
		);

		return array_map( 'intval', $query->posts );
	}
}
