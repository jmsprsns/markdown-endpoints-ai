<?php
/**
 * Main plugin orchestrator: owns the settings, installs the tables and wires
 * up every part.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Markdown_Endpoints_AI_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'markdown_endpoints_ai_settings';

	/**
	 * Option holding the installed database schema version.
	 */
	const DB_OPTION = 'markdown_endpoints_ai_db_version';

	/**
	 * Current database schema version.
	 */
	const DB_VERSION = '1';

	/**
	 * Daily maintenance hook (WP-Cron).
	 */
	const DAILY_HOOK = 'markdown_endpoints_ai_daily';

	/**
	 * Single shared instance.
	 *
	 * @var Markdown_Endpoints_AI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings, once read.
	 *
	 * @var array<string,mixed>|null
	 */
	private $settings = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Markdown_Endpoints_AI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 1 );
		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_settings' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush_settings' ) );
		add_action( self::DAILY_HOOK, array( $this, 'daily' ) );

		Markdown_Endpoints_AI_Cache::init( $this );
		Markdown_Endpoints_AI_Discovery::init( $this );
		Markdown_Endpoints_AI_Editor::init( $this );

		if ( ! is_admin() ) {
			new Markdown_Endpoints_AI_Router( $this );
		}

		if ( is_admin() ) {
			new Markdown_Endpoints_AI_Admin( $this );
		}
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'          => 1,
			'post_types'       => array( 'post', 'page', 'product' ),
			'exclude_cats'     => array(),
			'md_urls'          => 1,
			'negotiate'        => 1,
			'discovery'        => 1,
			'source'           => 'rendered',
			'content_selector' => '',
			'strip_junk'       => 1,
			'remove_selectors' => '',
			'images'           => 1,
			'schema'           => 1,
			'frontmatter'      => 1,
			'footer'           => 0,
			'footer_text'      => '',
			'llms'             => 'virtual',
			'llms_intro'       => '',
			'llms_pages'       => array(),
			'llms_recent'      => 10,
			'llms_popular'     => 10,
			'sitemap'          => 1,
			'signal_search'    => '',
			'signal_input'     => '',
			'signal_train'     => '',
			'signals_html'     => 0,
			'rate_limit'       => 60,
			'log'              => 1,
			'retention'        => 90,
			'max_age'          => 7,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Forget the settings read earlier in this request (after a save).
	 *
	 * @return void
	 */
	public function flush_settings() {
		$this->settings = null;
	}

	/**
	 * Whether Markdown is being served at all. The constant lets a host
	 * switch everything off from wp-config.php.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		if ( defined( 'MARKDOWN_ENDPOINTS_AI_DISABLED' ) && MARKDOWN_ENDPOINTS_AI_DISABLED ) {
			return false;
		}

		return (bool) $this->get_setting( 'enabled' ) && class_exists( 'DOMDocument' );
	}

	/**
	 * Post types Markdown is served for: the chosen ones that still exist and
	 * are public. Attachments never are.
	 *
	 * @return string[]
	 */
	public function post_types() {
		$chosen = (array) $this->get_setting( 'post_types' );
		$types  = array();

		foreach ( $chosen as $type ) {
			$object = is_string( $type ) ? get_post_type_object( $type ) : null;

			if ( $object && $object->public && 'attachment' !== $type ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * Public post types the settings page can offer.
	 *
	 * @return WP_Post_Type[]
	 */
	public static function available_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );

		return $types;
	}

	// Install -----------------------------------------------------------------

	/**
	 * Create or upgrade the tables, the cache folder and the daily job.
	 *
	 * @return void
	 */
	public static function install() {
		Markdown_Endpoints_AI_Log::install();
		Markdown_Endpoints_AI_Cache::ensure_dir();

		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}

		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Install on the first request after an update or a network activation.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Stop the scheduled jobs and take this plugin's section out of llms.txt.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		Markdown_Endpoints_AI_Cache::unschedule_all();

		$saved = get_option( self::OPTION, array() );
		if ( is_array( $saved ) && isset( $saved['llms'] ) && 'file' === $saved['llms'] ) {
			Markdown_Endpoints_AI_Discovery::remove_from_file();
		}
	}

	/**
	 * Daily: prune the log and rate-limit rows, refresh a written llms.txt.
	 *
	 * @return void
	 */
	public function daily() {
		Markdown_Endpoints_AI_Log::prune( (int) $this->get_setting( 'retention' ) );

		if ( 'file' === $this->get_setting( 'llms' ) && $this->is_enabled() ) {
			Markdown_Endpoints_AI_Discovery::write_file( $this );
		}
	}
}
