<?php
/**
 * Admin side: the Content Powered > Markdown Endpoints for AI page and the
 * Plugins-screen link.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class Markdown_Endpoints_AI_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'markdown-endpoints-ai';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'file-code';

	/**
	 * Nonce action for the page's AJAX requests.
	 */
	const NONCE = 'markdown_endpoints_ai_admin';

	/**
	 * Settings tabs: id => Lucide icon.
	 *
	 * @var array<string,string>
	 */
	private static $tabs = array(
		'overview'   => 'layout-dashboard',
		'conversion' => 'wand-sparkles',
		'access'     => 'shield-check',
		'discovery'  => 'radar',
		'insights'   => 'chart-line',
		'tools'      => 'wrench',
	);

	/**
	 * Plugin instance.
	 *
	 * @var Markdown_Endpoints_AI_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Markdown_Endpoints_AI_Plugin $plugin Plugin instance.
	 */
	public function __construct( Markdown_Endpoints_AI_Plugin $plugin ) {
		$this->plugin = $plugin;

		Markdown_Endpoints_AI_Menu::init();
		Markdown_Endpoints_AI_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'update_option_' . Markdown_Endpoints_AI_Plugin::OPTION, array( $this, 'after_save' ), 20, 2 );
		add_action( 'admin_notices', array( $this, 'notices' ) );

		foreach ( array( 'check', 'rebuild_all', 'rebuild_state', 'clear_cache', 'clear_log' ) as $action ) {
			add_action( 'wp_ajax_markdown_endpoints_ai_' . $action, array( $this, 'ajax_' . $action ) );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( MARKDOWN_ENDPOINTS_AI_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'markdown-endpoints-ai' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Markdown_Endpoints_AI_Icons::get( self::ICON, 17, 'markdown-endpoints-ai-menu-icon' );
		$menu_title = $icon . '<span class="markdown-endpoints-ai-menu-label">' . esc_html__( 'Markdown Endpoints for AI', 'markdown-endpoints-ai' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Markdown_Endpoints_AI_Menu::SLUG,
			__( 'Markdown Endpoints for AI', 'markdown-endpoints-ai' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the settings page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' mdai-settings-page' : $classes;
	}

	/**
	 * Register the setting. The tabs are switched in the browser, so one
	 * form saves every tab at once.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Markdown_Endpoints_AI_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Markdown_Endpoints_AI_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$in       = is_array( $input ) ? $input : array();
		$defaults = Markdown_Endpoints_AI_Plugin::defaults();
		$flag     = function ( $key ) use ( $in ) {
			return empty( $in[ $key ] ) ? 0 : 1;
		};
		$number   = function ( $key, $min, $max ) use ( $in, $defaults ) {
			$value = isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? (int) $in[ $key ] : (int) $defaults[ $key ];
			return max( $min, min( $max, $value ) );
		};
		$choice   = function ( $key, array $choices ) use ( $in, $defaults ) {
			return isset( $in[ $key ] ) && is_string( $in[ $key ] ) && in_array( $in[ $key ], $choices, true ) ? $in[ $key ] : $defaults[ $key ];
		};
		$ids      = function ( $key, $limit ) use ( $in ) {
			$list = isset( $in[ $key ] ) && is_array( $in[ $key ] ) ? array_filter( $in[ $key ], 'is_scalar' ) : array();
			return array_slice( array_values( array_unique( array_filter( array_map( 'absint', $list ) ) ) ), 0, $limit );
		};
		// options.php has already unslashed the values.
		$text = function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : '';
		};

		$types = array();
		$given = isset( $in['post_types'] ) && is_array( $in['post_types'] ) ? $in['post_types'] : array();
		foreach ( array_keys( Markdown_Endpoints_AI_Plugin::available_post_types() ) as $type ) {
			if ( in_array( $type, $given, true ) ) {
				$types[] = $type;
			}
		}

		$cats = array();
		foreach ( $ids( 'exclude_cats', 200 ) as $term_id ) {
			if ( term_exists( $term_id, 'category' ) ) {
				$cats[] = $term_id;
			}
		}

		$pages = array();
		foreach ( $ids( 'llms_pages', 50 ) as $post_id ) {
			if ( 'publish' === get_post_status( $post_id ) ) {
				$pages[] = $post_id;
			}
		}

		$out = array(
			'enabled'          => $flag( 'enabled' ),
			'post_types'       => $types,
			'exclude_cats'     => $cats,
			'md_urls'          => $flag( 'md_urls' ),
			'negotiate'        => $flag( 'negotiate' ),
			'discovery'        => $flag( 'discovery' ),
			'source'           => $choice( 'source', array( 'rendered', 'content' ) ),
			'content_selector' => '',
			'strip_junk'       => $flag( 'strip_junk' ),
			'remove_selectors' => '',
			'images'           => $flag( 'images' ),
			'schema'           => $flag( 'schema' ),
			'frontmatter'      => $flag( 'frontmatter' ),
			'footer'           => $flag( 'footer' ),
			'footer_text'      => substr( sanitize_text_field( $text( 'footer_text' ) ), 0, 300 ),
			'llms'             => $choice( 'llms', array( 'virtual', 'file', 'off' ) ),
			'llms_intro'       => substr( sanitize_text_field( $text( 'llms_intro' ) ), 0, 500 ),
			'llms_pages'       => $pages,
			'llms_recent'      => $number( 'llms_recent', 0, 50 ),
			'llms_popular'     => $number( 'llms_popular', 0, 50 ),
			'sitemap'          => $flag( 'sitemap' ),
			'signal_search'    => $choice( 'signal_search', array( '', 'yes', 'no' ) ),
			'signal_input'     => $choice( 'signal_input', array( '', 'yes', 'no' ) ),
			'signal_train'     => $choice( 'signal_train', array( '', 'yes', 'no' ) ),
			'signals_html'     => $flag( 'signals_html' ),
			'rate_limit'       => $number( 'rate_limit', 0, 10000 ),
			'log'              => $flag( 'log' ),
			'retention'        => $number( 'retention', 1, 365 ),
			'max_age'          => $number( 'max_age', 1, 90 ),
		);

		if ( Markdown_Endpoints_AI_Document::default_footer() === $out['footer_text'] ) {
			$out['footer_text'] = '';
		}

		$selector = trim( sanitize_text_field( $text( 'content_selector' ) ) );
		if ( '' !== $selector && strlen( $selector ) <= Markdown_Endpoints_AI_Selector::MAX_LENGTH && Markdown_Endpoints_AI_Selector::is_valid( $selector ) ) {
			$out['content_selector'] = $selector;
		} elseif ( '' !== $selector ) {
			add_settings_error( self::PAGE, 'mdai-selector', __( 'The content area selector isn\'t one this plugin can use (tags, #ids, .classes, [attributes], spaces and > only), so it wasn\'t saved.', 'markdown-endpoints-ai' ), 'warning' );
		}

		$invalid                 = array();
		$valid                   = Markdown_Endpoints_AI_Selector::clean_list( wp_strip_all_tags( $text( 'remove_selectors' ) ), $invalid );
		$out['remove_selectors'] = implode( "\n", $valid );
		if ( $invalid ) {
			add_settings_error(
				self::PAGE,
				'mdai-remove',
				sprintf(
					/* translators: %s: rejected selectors. */
					__( 'These selectors aren\'t supported and were left out: %s', 'markdown-endpoints-ai' ),
					implode( ', ', array_map( 'sanitize_text_field', array_slice( $invalid, 0, 10 ) ) )
				),
				'warning'
			);
		}

		return $out;
	}

	/**
	 * After a save: write or remove the llms.txt section when that changed.
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 * @return void
	 */
	public function after_save( $old, $new ) {
		$was = is_array( $old ) && isset( $old['llms'] ) ? $old['llms'] : '';
		$now = is_array( $new ) && isset( $new['llms'] ) ? $new['llms'] : '';

		Markdown_Endpoints_AI_Access::reset();

		if ( 'file' === $now ) {
			if ( ! Markdown_Endpoints_AI_Discovery::write_file( $this->plugin ) ) {
				add_settings_error( self::PAGE, 'mdai-llms', __( 'The llms.txt file couldn\'t be written: its folder isn\'t writable. WordPress serves the generated llms.txt instead whenever there\'s no file.', 'markdown-endpoints-ai' ), 'warning' );
			}
		} elseif ( 'file' === $was ) {
			Markdown_Endpoints_AI_Discovery::remove_from_file();
		}
	}

	/**
	 * Warnings on this page that the settings alone don't show.
	 *
	 * @return void
	 */
	public function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || $screen->id !== $this->hook_suffix ) {
			return;
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Markdown Endpoints for AI needs PHP\'s DOM extension (php-xml), which this server doesn\'t have, so nothing is served. Ask your host to turn it on.', 'markdown-endpoints-ai' ) . '</p></div>';
		}
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="markdown-endpoints-ai-menu-icon-style">'
			. '#adminmenu a:has(>.markdown-endpoints-ai-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .markdown-endpoints-ai-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .markdown-endpoints-ai-menu-icon,#adminmenu a:hover .markdown-endpoints-ai-menu-icon,#adminmenu a:focus .markdown-endpoints-ai-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'markdown-endpoints-ai-admin', MARKDOWN_ENDPOINTS_AI_URL . 'assets/admin.css', array(), MARKDOWN_ENDPOINTS_AI_VERSION );
		wp_enqueue_script( 'markdown-endpoints-ai-admin', MARKDOWN_ENDPOINTS_AI_URL . 'assets/admin.js', array(), MARKDOWN_ENDPOINTS_AI_VERSION, true );

		wp_localize_script(
			'markdown-endpoints-ai-admin',
			'markdown_endpoints_ai_admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'checking'     => __( 'Checking...', 'markdown-endpoints-ai' ),
					'working'      => __( 'Working...', 'markdown-endpoints-ai' ),
					'failed'       => __( 'That didn\'t work. Reload the page and try again.', 'markdown-endpoints-ai' ),
					'copied'       => __( 'Copied', 'markdown-endpoints-ai' ),
					'confirmCache' => __( 'Delete every cached Markdown file? They\'re built again as agents ask for them.', 'markdown-endpoints-ai' ),
					'confirmLog'   => __( 'Clear the request log and every count in Insights?', 'markdown-endpoints-ai' ),
				),
			)
		);
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name Lucide icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return Markdown_Endpoints_AI_Icons::get( $name, $size );
	}

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'overview'   => __( 'Overview', 'markdown-endpoints-ai' ),
			'conversion' => __( 'Conversion', 'markdown-endpoints-ai' ),
			'access'     => __( 'Access', 'markdown-endpoints-ai' ),
			'discovery'  => __( 'Discovery', 'markdown-endpoints-ai' ),
			'insights'   => __( 'Insights', 'markdown-endpoints-ai' ),
			'tools'      => __( 'Tools', 'markdown-endpoints-ai' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * The page's boxes, in order: the tab each one is on, its icon, title,
	 * intro and what draws it.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function sections() {
		return array(
			'stats'     => array( 'overview', 'chart-column', __( 'Last 30 days', 'markdown-endpoints-ai' ), '', 'render_stats' ),
			'try'       => array( 'overview', 'square-terminal', __( 'Try it', 'markdown-endpoints-ai' ), __( 'This is what an AI agent gets from one of your pages. Ask for it either way:', 'markdown-endpoints-ai' ), 'render_try' ),
			'check'     => array( 'overview', 'stethoscope', __( 'Caching and endpoints check', 'markdown-endpoints-ai' ), __( 'Asks your site for a page the way an agent would, then the way a browser would, to make sure no cache mixes the two up. Also checks llms.txt and the sitemap.', 'markdown-endpoints-ai' ), 'render_check' ),
			'top'       => array( 'overview', 'trophy', __( 'Most read by AI', 'markdown-endpoints-ai' ), __( 'The pages AI crawlers and agents asked for most in the last 30 days: a fair guide to what\'s feeding AI answers about your topic.', 'markdown-endpoints-ai' ), 'render_top' ),

			'source'    => array( 'conversion', 'scan-text', __( 'Where the Markdown comes from', 'markdown-endpoints-ai' ), __( 'Converting the finished page instead of the raw post content means page builders (Elementor, Divi, Beaver Builder, Bricks) and shortcodes come out as real text, not shortcode soup.', 'markdown-endpoints-ai' ), 'render_source' ),
			'clean'     => array( 'conversion', 'brush-cleaning', __( 'Clutter removal', 'markdown-endpoints-ai' ), __( 'Taken out of the content before it\'s converted, so agents spend their tokens on the article.', 'markdown-endpoints-ai' ), 'render_clean' ),
			'include'   => array( 'conversion', 'list-checks', __( 'What\'s included', 'markdown-endpoints-ai' ), __( 'Tables stay real Markdown tables, links and images use absolute URLs, and code blocks keep their language either way.', 'markdown-endpoints-ai' ), 'render_include' ),
			'footer'    => array( 'conversion', 'signature', __( 'Attribution line', 'markdown-endpoints-ai' ), __( 'An optional last line in every file, so content that gets copied carries your link with it.', 'markdown-endpoints-ai' ), 'render_footer' ),

			'serve'     => array( 'access', 'route', __( 'How agents can ask', 'markdown-endpoints-ai' ), '', 'render_serve' ),
			'types'     => array( 'access', 'files', __( 'What gets a Markdown version', 'markdown-endpoints-ai' ), '', 'render_types' ),
			'signals'   => array( 'access', 'signpost', __( 'Content signals', 'markdown-endpoints-ai' ), __( 'Say how you\'re happy for AI companies to use your content. Sent as Content-Signal and Content-Usage headers with every Markdown response, and in its frontmatter. Each post can change these in its Markdown for AI panel.', 'markdown-endpoints-ai' ), 'render_signals' ),
			'limits'    => array( 'access', 'gauge', __( 'Rate limit', 'markdown-endpoints-ai' ), __( 'Slows down scrapers that hammer your Markdown URLs. Logged-in users and the plugin\'s own requests never count.', 'markdown-endpoints-ai' ), 'render_limits' ),

			'llms'      => array( 'discovery', 'file-text', __( 'llms.txt', 'markdown-endpoints-ai' ), __( 'A short Markdown guide to your site at /llms.txt, linking AI tools straight to the Markdown versions of your key pages.', 'markdown-endpoints-ai' ), 'render_llms' ),
			'sitemap'   => array( 'discovery', 'network', __( 'Markdown sitemap', 'markdown-endpoints-ai' ), '', 'render_sitemap' ),

			'activity'  => array( 'insights', 'chart-column-stacked', __( 'Requests per day', 'markdown-endpoints-ai' ), '', 'render_activity' ),
			'mostread'  => array( 'insights', 'trophy', __( 'Most read by AI', 'markdown-endpoints-ai' ), '', 'render_mostread' ),
			'agents'    => array( 'insights', 'bot', __( 'Agents', 'markdown-endpoints-ai' ), __( 'Who fetched your Markdown, with AI crawlers and agents kept apart from search engines and other bots.', 'markdown-endpoints-ai' ), 'render_agents' ),
			'savings'   => array( 'insights', 'trending-down', __( 'Token savings', 'markdown-endpoints-ai' ), __( 'How much lighter the Markdown is than the full HTML page an agent would otherwise download. Tokens are estimated at four characters each.', 'markdown-endpoints-ai' ), 'render_savings' ),
			'log'       => array( 'insights', 'scroll-text', __( 'Recent requests', 'markdown-endpoints-ai' ), '', 'render_log' ),

			'files'     => array( 'tools', 'hard-drive', __( 'Cached files', 'markdown-endpoints-ai' ), __( 'Each page\'s Markdown is built once and served as a static file until the post, the settings, the theme or your plugins change.', 'markdown-endpoints-ai' ), 'render_files' ),
			'logging'   => array( 'tools', 'database', __( 'Request log', 'markdown-endpoints-ai' ), __( 'Insights come from this log. It stores the time, page, agent name and user agent, never IP addresses.', 'markdown-endpoints-ai' ), 'render_logging' ),
			'jobs'      => array( 'tools', 'timer', __( 'Background building', 'markdown-endpoints-ai' ), '', 'render_jobs' ),
			'developer' => array( 'tools', 'code', __( 'For developers', 'markdown-endpoints-ai' ), '', 'render_developer' ),
		);
	}

	/**
	 * Render the settings page: a header with the tabs, then WordPress's
	 * two-column box layout (settings on the left, Save and status on the right).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="mdai-header">
			<div class="mdai-header-title">
				<span class="mdai-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="mdai-version"><?php echo esc_html( 'v' . MARKDOWN_ENDPOINTS_AI_VERSION ); ?></span>
			</div>
			<nav class="mdai-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'markdown-endpoints-ai' ); ?>">
				<?php foreach ( self::$tabs as $id => $icon ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="mdai-tab" role="tab" data-mdai-tab="<?php echo esc_attr( $id ); ?>">
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap mdai-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();
			?>

			<form action="options.php" method="post" class="mdai-form">
				<?php settings_fields( self::PAGE ); ?>

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<?php
							foreach ( $this->sections() as $id => $section ) {
								$this->render_section( $id, $section );
							}
							?>
						</div>

						<div id="postbox-container-1" class="postbox-container">
							<div class="mdai-sidebar">
								<?php $this->render_sidebar(); ?>
							</div>
						</div>
					</div>
					<br class="clear">
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * One settings box.
	 *
	 * @param string $id      Section ID.
	 * @param array  $section Panel, icon, title, intro, render method.
	 * @return void
	 */
	private function render_section( $id, array $section ) {
		?>
		<div class="postbox mdai-box" id="mdai-<?php echo esc_attr( $id ); ?>" data-mdai-panel="<?php echo esc_attr( $section[0] ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $section[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $section[2] ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php if ( '' !== $section[3] ) : ?>
					<p class="mdai-intro"><?php echo esc_html( $section[3] ); ?></p>
				<?php endif; ?>
				<?php call_user_func( array( $this, $section[4] ) ); ?>
			</div>
		</div>
		<?php
	}

	// Form helpers --------------------------------------------------------------

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return Markdown_Endpoints_AI_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Checkbox with a label.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description under it.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '' ) {
		printf(
			'<label class="mdai-check"><input type="checkbox" name="%1$s" value="1" %2$s> <span>%3$s</span></label>',
			esc_attr( self::name( $key ) ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * A toggle drawn as a card with an icon.
	 *
	 * @param string $key   Setting key.
	 * @param string $icon  Lucide icon.
	 * @param string $title Title.
	 * @param string $desc  Description.
	 * @return void
	 */
	private function option( $key, $icon, $title, $desc ) {
		?>
		<label class="mdai-option">
			<input type="checkbox" name="<?php echo esc_attr( self::name( $key ) ); ?>" value="1" <?php checked( 1, (int) $this->plugin->get_setting( $key ) ); ?>>
			<span class="mdai-option-icon"><?php echo self::icon( $icon, 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<span class="mdai-option-text"><strong><?php echo esc_html( $title ); ?></strong><span><?php echo esc_html( $desc ); ?></span></span>
		</label>
		<?php
	}

	/**
	 * A radio drawn as a card with an icon.
	 *
	 * @param string $key   Setting key.
	 * @param string $value Value.
	 * @param string $icon  Lucide icon.
	 * @param string $title Title.
	 * @param string $desc  Description.
	 * @return void
	 */
	private function radio( $key, $value, $icon, $title, $desc ) {
		?>
		<label class="mdai-option">
			<input type="radio" name="<?php echo esc_attr( self::name( $key ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $value, (string) $this->plugin->get_setting( $key ) ); ?>>
			<span class="mdai-option-icon"><?php echo self::icon( $icon, 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<span class="mdai-option-text"><strong><?php echo esc_html( $title ); ?></strong><span><?php echo esc_html( $desc ); ?></span></span>
		</label>
		<?php
	}

	/**
	 * Number input.
	 *
	 * @param string $key    Setting key.
	 * @param int    $min    Minimum.
	 * @param int    $max    Maximum.
	 * @param string $suffix Text after the box.
	 * @return void
	 */
	private function number( $key, $min, $max, $suffix = '' ) {
		printf(
			'<input type="number" class="small-text" id="mdai-%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1"> %6$s',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			(int) $this->plugin->get_setting( $key ),
			(int) $min,
			(int) $max,
			esc_html( $suffix )
		);
	}

	/**
	 * Form table row.
	 *
	 * @param string   $label     Row label.
	 * @param callable $content   Prints the row's controls.
	 * @param string   $label_for ID the label points at.
	 * @return void
	 */
	private function row( $label, $content, $label_for = '' ) {
		echo '<tr><th scope="row">';
		echo '' !== $label_for ? '<label for="' . esc_attr( $label_for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</th><td>';
		call_user_func( $content );
		echo '</td></tr>';
	}

	/**
	 * A callout line.
	 *
	 * @param string $text  Text.
	 * @param string $icon  Lucide icon.
	 * @param string $class Extra class (is-warning).
	 * @return void
	 */
	private static function callout( $text, $icon = 'info', $class = '' ) {
		echo '<p class="mdai-callout ' . esc_attr( $class ) . '">' . self::icon( $icon, 16 ) . '<span>' . esc_html( $text ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
	}

	/**
	 * "Nothing yet" line.
	 *
	 * @param string $text Text.
	 * @return void
	 */
	private static function empty_state( $text ) {
		echo '<p class="mdai-empty">' . self::icon( 'circle-dashed', 16 ) . '<span>' . esc_html( $text ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
	}

	/**
	 * Insights period from the URL (7, 30 or 90 days).
	 *
	 * @return int
	 */
	private static function period() {
		$period = isset( $_GET['period'] ) ? absint( $_GET['period'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.

		return in_array( $period, array( 7, 30, 90 ), true ) ? $period : 30;
	}

	/**
	 * Link back to this page with some view arguments.
	 *
	 * @param array  $args Query arguments.
	 * @param string $tab  Tab to open.
	 * @return string
	 */
	private static function page_url( array $args, $tab = 'insights' ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) ) . '#' . $tab;
	}

	/**
	 * A post's title linked to its Markdown preview.
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML.
	 */
	private static function post_link( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return '<span class="mdai-muted">' . esc_html__( '(deleted)', 'markdown-endpoints-ai' ) . '</span>';
		}

		return '<a href="' . esc_url( Markdown_Endpoints_AI_Editor::preview_url( $post->ID ) ) . '">' . esc_html( Markdown_Endpoints_AI_Document::title( $post ) ) . '</a>';
	}

	// Overview ------------------------------------------------------------------

	/**
	 * Overview: counts for the last 30 days.
	 *
	 * @return void
	 */
	public function render_stats() {
		$totals = Markdown_Endpoints_AI_Log::totals( 30 );
		$cache  = Markdown_Endpoints_AI_Cache::totals();
		$saved  = $cache['html'] > 0 && $cache['md'] > 0 ? (int) round( 100 - $cache['md'] / $cache['html'] * 100 ) : 0;
		$agents = 0;
		foreach ( Markdown_Endpoints_AI_Log::agents( 30 ) as $row ) {
			if ( Markdown_Endpoints_AI_Agents::AI === $row['grp'] ) {
				++$agents;
			}
		}
		$tiles = array(
			array( 'file-check', number_format_i18n( $cache['files'] ), __( 'Markdown files ready', 'markdown-endpoints-ai' ), '' ),
			array( 'bot', number_format_i18n( $totals['ai'] ), __( 'AI agent requests', 'markdown-endpoints-ai' ), 'accent' ),
			array( 'users', number_format_i18n( $agents ), __( 'AI agents seen', 'markdown-endpoints-ai' ), '' ),
			array( 'search', number_format_i18n( $totals['bot'] ), __( 'Search and other bots', 'markdown-endpoints-ai' ), '' ),
			array( 'trending-down', $saved . '%', __( 'Lighter than HTML', 'markdown-endpoints-ai' ), 'good' ),
		);
		?>
		<div class="mdai-stats">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="mdai-stat<?php echo '' !== $tile[3] ? ' is-' . esc_attr( $tile[3] ) : ''; ?>">
					<span class="mdai-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="mdai-stat-num"><?php echo esc_html( $tile[1] ); ?></span>
					<span class="mdai-stat-label"><?php echo esc_html( $tile[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		if ( ! $this->plugin->is_enabled() ) {
			self::callout( __( 'Markdown endpoints are switched off, so agents get your normal pages. Turn them on in the Save box.', 'markdown-endpoints-ai' ), 'power', 'is-warning' );
		} elseif ( '0' === (string) get_option( 'blog_public' ) ) {
			self::callout( __( 'Settings > Reading asks search engines not to index this site, so no Markdown is served. That\'s right for a staging site.', 'markdown-endpoints-ai' ), 'eye-off', 'is-warning' );
		} elseif ( 0 === array_sum( $totals ) ) {
			self::callout( __( 'No requests yet. Agents find the Markdown through the link in each page\'s head, llms.txt and the Markdown sitemap; it can take a little while before the first ones arrive.', 'markdown-endpoints-ai' ) );
		}
	}

	/**
	 * Overview: example URLs and commands for a real page.
	 *
	 * @return void
	 */
	public function render_try() {
		$post = Markdown_Endpoints_AI_Diagnostics::sample_post( $this->plugin );

		if ( ! $post ) {
			self::empty_state( __( 'Publish a post or page of a type that\'s switched on to try it.', 'markdown-endpoints-ai' ) );
			return;
		}

		$md        = Markdown_Endpoints_AI_Discovery::markdown_url( $post );
		$permalink = (string) get_permalink( $post );
		$commands  = array();

		if ( '' !== $md ) {
			$commands[] = array( __( 'Add .md to the address', 'markdown-endpoints-ai' ), 'curl ' . $md, $md );
		}
		if ( $this->plugin->get_setting( 'negotiate' ) ) {
			$commands[] = array( __( 'Or ask with a header', 'markdown-endpoints-ai' ), 'curl -H "Accept: text/markdown" ' . $permalink, '' );
		}

		if ( ! $commands ) {
			self::callout( __( 'Both ways of asking are switched off on the Access tab.', 'markdown-endpoints-ai' ), 'circle-slash', 'is-warning' );
			return;
		}
		?>
		<div class="mdai-commands">
			<?php foreach ( $commands as $command ) : ?>
				<div class="mdai-command">
					<span class="mdai-command-label"><?php echo esc_html( $command[0] ); ?></span>
					<code><?php echo esc_html( $command[1] ); ?></code>
					<button type="button" class="button button-small" data-mdai-copy-text="<?php echo esc_attr( $command[1] ); ?>"><?php echo self::icon( 'copy', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Copy', 'markdown-endpoints-ai' ); ?></span></button>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="mdai-links">
			<a class="button" href="<?php echo esc_url( Markdown_Endpoints_AI_Editor::preview_url( $post->ID ) ); ?>"><?php echo self::icon( 'columns-2', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Side-by-side preview', 'markdown-endpoints-ai' ); ?></a>
			<?php if ( '' !== $md ) : ?>
				<a class="button" href="<?php echo esc_url( $md ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'external-link', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Open .md', 'markdown-endpoints-ai' ); ?></a>
			<?php endif; ?>
			<?php if ( 'off' !== $this->plugin->get_setting( 'llms' ) ) : ?>
				<a class="button" href="<?php echo esc_url( Markdown_Endpoints_AI_Discovery::llms_url() ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'file-text', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>llms.txt</a>
			<?php endif; ?>
			<?php if ( $this->plugin->get_setting( 'sitemap' ) ) : ?>
				<a class="button" href="<?php echo esc_url( Markdown_Endpoints_AI_Discovery::sitemap_url() ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'network', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Markdown sitemap', 'markdown-endpoints-ai' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Overview: the last check's results and the button to run it.
	 *
	 * @return void
	 */
	public function render_check() {
		$results = Markdown_Endpoints_AI_Diagnostics::last();
		?>
		<div data-mdai-check-results>
			<?php
			if ( $results ) {
				self::check_results( $results );
			} else {
				self::empty_state( __( 'Not run yet. It takes a few seconds.', 'markdown-endpoints-ai' ) );
			}
			?>
		</div>
		<p><button type="button" class="button" data-mdai-check><?php echo self::icon( 'play', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Run the check', 'markdown-endpoints-ai' ); ?></span></button></p>
		<?php
	}

	/**
	 * The list of check results.
	 *
	 * @param array $results From Markdown_Endpoints_AI_Diagnostics.
	 * @return void
	 */
	public static function check_results( array $results ) {
		$icons = array(
			'ok'   => 'circle-check',
			'info' => 'info',
			'warn' => 'triangle-alert',
			'bad'  => 'octagon-x',
		);
		?>
		<ul class="mdai-results">
			<?php foreach ( $results['checks'] as $check ) : ?>
				<li class="is-<?php echo esc_attr( $check['status'] ); ?>">
					<?php echo self::icon( isset( $icons[ $check['status'] ] ) ? $icons[ $check['status'] ] : 'info', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span><strong><?php echo esc_html( $check['title'] ); ?></strong> <?php echo esc_html( $check['detail'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="mdai-muted mdai-small">
			<?php
			/* translators: %s: how long ago. */
			echo esc_html( sprintf( __( 'Checked %s ago.', 'markdown-endpoints-ai' ), human_time_diff( (int) $results['time'] ) ) );
			?>
		</p>
		<?php
	}

	/**
	 * Overview: top five pages for AI agents.
	 *
	 * @return void
	 */
	public function render_top() {
		$rows = Markdown_Endpoints_AI_Log::top_pages( 30, Markdown_Endpoints_AI_Agents::AI, 5 );

		if ( ! $rows ) {
			self::empty_state( __( 'No AI agent has read a Markdown page yet.', 'markdown-endpoints-ai' ) );
			return;
		}

		$this->pages_table( $rows );
		echo '<p><a href="#insights" data-mdai-goto="insights">' . esc_html__( 'All insights', 'markdown-endpoints-ai' ) . ' &rarr;</a></p>';
	}

	/**
	 * Table of pages with their AI request counts.
	 *
	 * @param array $rows From Markdown_Endpoints_AI_Log::top_pages().
	 * @return void
	 */
	private function pages_table( array $rows ) {
		$max = max( 1, (int) $rows[0]['hits'] );
		?>
		<table class="widefat striped mdai-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Page', 'markdown-endpoints-ai' ); ?></th>
					<th class="mdai-num"><?php esc_html_e( 'Requests', 'markdown-endpoints-ai' ); ?></th>
					<th><?php esc_html_e( 'Read most by', 'markdown-endpoints-ai' ); ?></th>
					<th><?php esc_html_e( 'Last read', 'markdown-endpoints-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<?php echo self::post_link( $row['post_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in post_link(). ?>
							<span class="mdai-bar"><span style="width:<?php echo esc_attr( (string) max( 2, round( $row['hits'] / $max * 100 ) ) ); ?>%"></span></span>
						</td>
						<td class="mdai-num"><?php echo esc_html( number_format_i18n( $row['hits'] ) ); ?></td>
						<td>
							<?php echo esc_html( Markdown_Endpoints_AI_Agents::name( $row['top'] ) ); ?>
							<?php if ( $row['agents'] > 1 ) : ?>
								<span class="mdai-muted">
									<?php
									/* translators: %d: number of other agents. */
									echo esc_html( sprintf( _n( '+ %d other', '+ %d others', $row['agents'] - 1, 'markdown-endpoints-ai' ), $row['agents'] - 1 ) );
									?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							/* translators: %s: how long ago. */
							echo $row['last'] ? esc_html( sprintf( __( '%s ago', 'markdown-endpoints-ai' ), human_time_diff( $row['last'] ) ) ) : '&mdash;';
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// Conversion ----------------------------------------------------------------

	/**
	 * Conversion: rendered page or post content, and the content selector.
	 *
	 * @return void
	 */
	public function render_source() {
		?>
		<div class="mdai-options">
			<?php
			$this->radio( 'source', 'rendered', 'monitor-check', __( 'The rendered page (recommended)', 'markdown-endpoints-ai' ), __( 'Exactly what a logged-out visitor sees, from your theme and page builder, then just the content area. Works with Elementor, Divi, Beaver Builder, Bricks, WPBakery and shortcodes.', 'markdown-endpoints-ai' ) );
			$this->radio( 'source', 'content', 'file-text', __( 'The post content only', 'markdown-endpoints-ai' ), __( 'The post\'s content through WordPress\'s content filters, without loading the theme. Lighter on the server, but page-builder layouts and theme templates may come out incomplete, and the page\'s structured data (facts sections, paywall markup) isn\'t read.', 'markdown-endpoints-ai' ) );
			?>
		</div>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Content area', 'markdown-endpoints-ai' ),
				function () {
					printf(
						'<input type="text" class="regular-text code" id="mdai-content_selector" name="%1$s" value="%2$s" placeholder=".entry-content">',
						esc_attr( self::name( 'content_selector' ) ),
						esc_attr( (string) $this->plugin->get_setting( 'content_selector' ) )
					);
					echo '<p class="description">' . esc_html__( 'Optional. Leave empty and the plugin finds the content itself: it marks the post\'s content while the page renders, then falls back to page-builder and theme containers. Set a CSS selector only if a page comes out with the wrong part.', 'markdown-endpoints-ai' ) . '</p>';
				},
				'mdai-content_selector'
			);
			?>
		</table>
		<?php
	}

	/**
	 * Conversion: clutter removal.
	 *
	 * @return void
	 */
	public function render_clean() {
		$groups = array(
			'megaphone'      => __( 'Ads', 'markdown-endpoints-ai' ),
			'share-2'        => __( 'Share buttons', 'markdown-endpoints-ai' ),
			'mail'           => __( 'Newsletter boxes and forms', 'markdown-endpoints-ai' ),
			'newspaper'      => __( 'Related posts', 'markdown-endpoints-ai' ),
			'list-tree'      => __( 'Tables of contents', 'markdown-endpoints-ai' ),
			'cookie'         => __( 'Cookie banners', 'markdown-endpoints-ai' ),
			'message-square' => __( 'Comments', 'markdown-endpoints-ai' ),
			'navigation'     => __( 'Breadcrumbs and in-post navigation', 'markdown-endpoints-ai' ),
		);
		?>
		<div class="mdai-options">
			<?php $this->option( 'strip_junk', 'brush-cleaning', __( 'Remove clutter', 'markdown-endpoints-ai' ), __( 'Recognises the common plugins and page-builder widgets for each of these:', 'markdown-endpoints-ai' ) ); ?>
		</div>
		<ul class="mdai-chips">
			<?php foreach ( $groups as $icon => $label ) : ?>
				<li><?php echo self::icon( $icon, 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( $label ); ?></li>
			<?php endforeach; ?>
		</ul>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Also remove', 'markdown-endpoints-ai' ),
				function () {
					printf(
						'<textarea class="large-text code" rows="4" id="mdai-remove_selectors" name="%1$s" placeholder=".author-box&#10;#promo-banner&#10;div[data-widget=&quot;upsell&quot;]">%2$s</textarea>',
						esc_attr( self::name( 'remove_selectors' ) ),
						esc_textarea( (string) $this->plugin->get_setting( 'remove_selectors' ) )
					);
					echo '<p class="description">' . esc_html__( 'CSS selectors for clutter that\'s particular to your site, one per line. Removed even when "Remove clutter" is off.', 'markdown-endpoints-ai' ) . '</p>';
				},
				'mdai-remove_selectors'
			);
			?>
		</table>
		<?php
	}

	/**
	 * Conversion: images, schema, frontmatter.
	 *
	 * @return void
	 */
	public function render_include() {
		?>
		<div class="mdai-options">
			<?php
			$this->option( 'frontmatter', 'braces', __( 'Frontmatter from your SEO plugin', 'markdown-endpoints-ai' ), $this->frontmatter_note() );
			$this->option( 'images', 'image', __( 'Images with their alt text and captions', 'markdown-endpoints-ai' ), __( 'As Markdown images with absolute URLs. Lazy-loaded images use their real source; tracking pixels are skipped.', 'markdown-endpoints-ai' ) );
			$this->option( 'schema', 'database-zap', __( 'Facts from structured data', 'markdown-endpoints-ai' ), __( 'Product prices and ratings, FAQ answers, opening hours, recipe times and event dates that only live in the page\'s schema become short readable sections. FAQ questions already on the page aren\'t repeated.', 'markdown-endpoints-ai' ) );
			?>
		</div>
		<?php
	}

	/**
	 * Which SEO plugin the frontmatter reads from.
	 *
	 * @return string
	 */
	private function frontmatter_note() {
		$seo = Markdown_Endpoints_AI_SEO::active_name();

		return '' !== $seo
			/* translators: %s: SEO plugin name. */
			? sprintf( __( 'Title, description, author, dates, categories, tags and canonical URL, with the description and canonical taken from %s. Nothing new to fill in.', 'markdown-endpoints-ai' ), $seo )
			: __( 'Title, description, author, dates, categories, tags and canonical URL. Descriptions come from Yoast SEO, Rank Math, SEOPress or All in One SEO when one is active, else the excerpt.', 'markdown-endpoints-ai' );
	}

	/**
	 * Conversion: attribution line.
	 *
	 * @return void
	 */
	public function render_footer() {
		$text = (string) $this->plugin->get_setting( 'footer_text' );
		?>
		<div class="mdai-options">
			<?php $this->option( 'footer', 'signature', __( 'Add an attribution line', 'markdown-endpoints-ai' ), __( 'Placed after a rule at the end of every file.', 'markdown-endpoints-ai' ) ); ?>
		</div>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Line', 'markdown-endpoints-ai' ),
				function () use ( $text ) {
					printf(
						'<input type="text" class="large-text" id="mdai-footer_text" name="%1$s" value="%2$s">',
						esc_attr( self::name( 'footer_text' ) ),
						esc_attr( '' !== $text ? $text : Markdown_Endpoints_AI_Document::default_footer() )
					);
					echo '<p class="description">' . esc_html__( 'Use {title}, {url} and {site}. Markdown links work too.', 'markdown-endpoints-ai' ) . '</p>';
				},
				'mdai-footer_text'
			);
			?>
		</table>
		<?php
	}

	// Access --------------------------------------------------------------------

	/**
	 * Access: .md URLs, Accept header, discovery link.
	 *
	 * @return void
	 */
	public function render_serve() {
		?>
		<div class="mdai-options">
			<?php
			$this->option( 'md_urls', 'link-2', __( '.md URLs', 'markdown-endpoints-ai' ), __( 'Add .md to any page\'s address (/my-post.md, /index.md for the front page). Sent with X-Robots-Tag: noindex and a canonical Link header to the HTML page, so it never shows up in Google as duplicate content.', 'markdown-endpoints-ai' ) );
			$this->option( 'negotiate', 'arrow-left-right', __( 'Accept: text/markdown requests', 'markdown-endpoints-ai' ), __( 'Agents that ask for the normal address with this header get Markdown. Sent with Vary: Accept and marked private, so caches don\'t hand it to people; the Caching check on the Overview tab makes sure of it.', 'markdown-endpoints-ai' ) );
			$this->option( 'discovery', 'radar', __( 'Point agents to the Markdown', 'markdown-endpoints-ai' ), __( 'A <link rel="alternate" type="text/markdown"> tag in each page\'s head, and a matching Link header.', 'markdown-endpoints-ai' ) );
			?>
		</div>
		<?php
	}

	/**
	 * Access: post types, excluded categories, what's never served.
	 *
	 * @return void
	 */
	public function render_types() {
		$chosen = (array) $this->plugin->get_setting( 'post_types' );
		$cats   = array_map( 'intval', (array) $this->plugin->get_setting( 'exclude_cats' ) );
		$terms  = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 200,
			)
		);
		$never  = array(
			'lock'       => __( 'Drafts, private, scheduled and password-protected posts', 'markdown-endpoints-ai' ),
			'eye-off'    => __( 'Anything set to noindex, in your SEO plugin or on the page itself', 'markdown-endpoints-ai' ),
			'key-round'  => __( 'Members-only content (Paid Memberships Pro, MemberPress, Restrict Content Pro, WooCommerce Memberships, Ultimate Member)', 'markdown-endpoints-ai' ),
			'badge-cent' => __( 'The paid part of paywalled posts (marked with Google\'s paywall schema): only the free portion is served', 'markdown-endpoints-ai' ),
			'user-x'     => __( 'Anything a logged-out visitor can\'t see: pages are always rendered as one', 'markdown-endpoints-ai' ),
		);
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Post types', 'markdown-endpoints-ai' ),
				function () use ( $chosen ) {
					echo '<div class="mdai-checks">';
					foreach ( Markdown_Endpoints_AI_Plugin::available_post_types() as $type => $object ) {
						printf(
							'<label class="mdai-check"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> <span>%4$s</span></label>',
							esc_attr( self::name( 'post_types' ) ),
							esc_attr( $type ),
							checked( in_array( $type, $chosen, true ), true, false ),
							esc_html( $object->labels->name )
						);
					}
					echo '</div>';
				}
			);
			$this->row(
				__( 'Leave out categories', 'markdown-endpoints-ai' ),
				function () use ( $terms, $cats ) {
					if ( ! is_array( $terms ) || ! $terms ) {
						echo '<p class="description">' . esc_html__( 'No categories yet.', 'markdown-endpoints-ai' ) . '</p>';
						return;
					}
					echo '<div class="mdai-checks mdai-checks-scroll">';
					foreach ( $terms as $term ) {
						printf(
							'<label class="mdai-check"><input type="checkbox" name="%1$s[]" value="%2$d" %3$s> <span>%4$s <span class="mdai-muted">(%5$s)</span></span></label>',
							esc_attr( self::name( 'exclude_cats' ) ),
							(int) $term->term_id,
							checked( in_array( (int) $term->term_id, $cats, true ), true, false ),
							esc_html( $term->name ),
							esc_html( number_format_i18n( (int) $term->count ) )
						);
					}
					echo '</div><p class="description">' . esc_html__( 'Posts in these categories (and the categories under them) get no Markdown version. Single posts can be left out from the editor.', 'markdown-endpoints-ai' ) . '</p>';
				}
			);
			?>
		</table>
		<h3 class="mdai-subhead"><?php esc_html_e( 'Never served, whatever the settings', 'markdown-endpoints-ai' ); ?></h3>
		<ul class="mdai-checklist">
			<?php foreach ( $never as $icon => $label ) : ?>
				<li class="is-ok"><?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $label ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Access: content signals.
	 *
	 * @return void
	 */
	public function render_signals() {
		$signals = array(
			'signal_search' => array( 'search', __( 'Search', 'markdown-endpoints-ai' ), __( 'Showing your pages and links in search results.', 'markdown-endpoints-ai' ) ),
			'signal_input'  => array( 'message-square-text', __( 'AI answers', 'markdown-endpoints-ai' ), __( 'Using a page to answer someone\'s question right now (AI search, chat assistants, agents).', 'markdown-endpoints-ai' ) ),
			'signal_train'  => array( 'brain', __( 'AI training', 'markdown-endpoints-ai' ), __( 'Using your content to train or fine-tune AI models.', 'markdown-endpoints-ai' ) ),
		);
		?>
		<table class="form-table" role="presentation">
			<?php foreach ( $signals as $key => $signal ) : ?>
				<?php
				$this->row(
					$signal[1],
					function () use ( $key, $signal ) {
						$value = (string) $this->plugin->get_setting( $key );
						?>
						<select id="mdai-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::name( $key ) ); ?>">
							<option value="" <?php selected( '', $value ); ?>><?php esc_html_e( 'Don\'t say', 'markdown-endpoints-ai' ); ?></option>
							<option value="yes" <?php selected( 'yes', $value ); ?>><?php esc_html_e( 'Allowed', 'markdown-endpoints-ai' ); ?></option>
							<option value="no" <?php selected( 'no', $value ); ?>><?php esc_html_e( 'Not allowed', 'markdown-endpoints-ai' ); ?></option>
						</select>
						<p class="description"><?php echo esc_html( $signal[2] ); ?></p>
						<?php
					},
					'mdai-' . $key
				);
				?>
			<?php endforeach; ?>
			<?php
			$this->row(
				__( 'HTML pages', 'markdown-endpoints-ai' ),
				function () {
					$this->checkbox( 'signals_html', __( 'Send the same headers with your normal pages', 'markdown-endpoints-ai' ) );
				}
			);
			?>
		</table>
		<?php
		$signals_now = Markdown_Endpoints_AI_Document::signals( null, $this->plugin );
		if ( $signals_now ) {
			echo '<p class="mdai-code-line"><code>Content-Signal: ' . esc_html( Markdown_Endpoints_AI_Document::signal_header( $signals_now ) ) . '</code>';
			$usage = Markdown_Endpoints_AI_Document::usage_header( $signals_now );
			if ( '' !== $usage ) {
				echo '<code>Content-Usage: ' . esc_html( $usage ) . '</code>';
			}
			echo '</p>';
		}
		self::callout( __( 'These state your preferences; crawlers decide whether to honour them. To actually block a crawler, use robots.txt or Content Powered\'s AI Crawler Controls.', 'markdown-endpoints-ai' ) );
	}

	/**
	 * Access: rate limit.
	 *
	 * @return void
	 */
	public function render_limits() {
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Requests per minute', 'markdown-endpoints-ai' ),
				function () {
					$this->number( 'rate_limit', 0, 10000, __( 'per IP address, across .md URLs, llms.txt and the sitemap', 'markdown-endpoints-ai' ) );
					echo '<p class="description">' . esc_html__( 'Past the limit, requests get a 429 "Too many requests" with a Retry-After header. 0 turns the limit off. Behind Cloudflare the visitor\'s real IP is used.', 'markdown-endpoints-ai' ) . '</p>';
				},
				'mdai-rate_limit'
			);
			?>
		</table>
		<?php
	}

	// Discovery -----------------------------------------------------------------

	/**
	 * Discovery: llms.txt.
	 *
	 * @return void
	 */
	public function render_llms() {
		$exists  = Markdown_Endpoints_AI_Discovery::file_exists();
		$section = $exists && Markdown_Endpoints_AI_Discovery::file_has_section();
		$mode    = (string) $this->plugin->get_setting( 'llms' );
		$chosen  = array_map( 'intval', (array) $this->plugin->get_setting( 'llms_pages' ) );
		$pages   = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'menu_order,post_title',
				'number'      => 200,
			)
		);
		$auto    = wp_list_pluck( Markdown_Endpoints_AI_Discovery::key_pages( $this->plugin ), 'ID' );
		?>
		<div class="mdai-options">
			<?php
			$this->radio( 'llms', 'virtual', 'file-text', __( 'Serve a generated llms.txt', 'markdown-endpoints-ai' ), __( 'WordPress answers /llms.txt, always up to date. Used whenever there\'s no real llms.txt file.', 'markdown-endpoints-ai' ) );
			$this->radio( 'llms', 'file', 'file-pen-line', __( 'Add a section to my llms.txt file', 'markdown-endpoints-ai' ), __( 'Writes the Markdown links into the real file between two marker lines and keeps them current, leaving the rest of the file alone. Use this when another plugin or you made the file.', 'markdown-endpoints-ai' ) );
			$this->radio( 'llms', 'off', 'circle-slash', __( 'Leave llms.txt alone', 'markdown-endpoints-ai' ), __( 'No llms.txt from this plugin.', 'markdown-endpoints-ai' ) );
			?>
		</div>
		<?php
		if ( $exists && 'virtual' === $mode && ! $section ) {
			self::callout( __( 'Your site already has a real llms.txt file, so your server sends that instead of the generated one. Choose "Add a section to my llms.txt file" to put the Markdown links in it.', 'markdown-endpoints-ai' ), 'triangle-alert', 'is-warning' );
		} elseif ( 'file' === $mode ) {
			$status = get_option( Markdown_Endpoints_AI_Discovery::FILE_STATUS );
			if ( is_array( $status ) && empty( $status['ok'] ) ) {
				self::callout( __( 'The file couldn\'t be written (its folder isn\'t writable), so WordPress serves the generated llms.txt for now.', 'markdown-endpoints-ai' ), 'triangle-alert', 'is-warning' );
			} elseif ( $section ) {
				/* translators: %s: how long ago. */
				self::callout( sprintf( __( 'The section in your llms.txt was last updated %s ago. It\'s refreshed when posts change and once a day.', 'markdown-endpoints-ai' ), human_time_diff( is_array( $status ) ? (int) $status['time'] : time() ) ), 'circle-check' );
			}
		}
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Summary', 'markdown-endpoints-ai' ),
				function () {
					printf(
						'<input type="text" class="large-text" id="mdai-llms_intro" name="%1$s" value="%2$s" placeholder="%3$s">',
						esc_attr( self::name( 'llms_intro' ) ),
						esc_attr( (string) $this->plugin->get_setting( 'llms_intro' ) ),
						esc_attr( (string) get_bloginfo( 'description' ) )
					);
					echo '<p class="description">' . esc_html__( 'One or two sentences on what your site is about. Empty uses your tagline.', 'markdown-endpoints-ai' ) . '</p>';
				},
				'mdai-llms_intro'
			);
			$this->row(
				__( 'Key pages', 'markdown-endpoints-ai' ),
				function () use ( $pages, $chosen, $auto ) {
					if ( ! $pages ) {
						echo '<p class="description">' . esc_html__( 'No published pages yet.', 'markdown-endpoints-ai' ) . '</p>';
						return;
					}
					echo '<div class="mdai-checks mdai-checks-scroll">';
					foreach ( $pages as $page ) {
						if ( ! Markdown_Endpoints_AI_Access::is_served( $page, $this->plugin ) && ! in_array( (int) $page->ID, $chosen, true ) ) {
							continue;
						}
						$depth = count( get_post_ancestors( $page ) );
						printf(
							'<label class="mdai-check" style="padding-left:%5$dpx"><input type="checkbox" name="%1$s[]" value="%2$d" %3$s> <span>%4$s</span></label>',
							esc_attr( self::name( 'llms_pages' ) ),
							(int) $page->ID,
							checked( in_array( (int) $page->ID, $chosen, true ), true, false ),
							esc_html( Markdown_Endpoints_AI_Document::title( $page ) ),
							(int) min( 4, $depth ) * 18
						);
					}
					echo '</div>';
					if ( ! $chosen ) {
						$titles = array();
						foreach ( array_slice( $auto, 0, 6 ) as $id ) {
							$titles[] = get_the_title( $id );
						}
						/* translators: %s: page titles. */
						echo '<p class="description">' . esc_html( sprintf( __( 'None picked, so your front page and top-level pages are used: %s.', 'markdown-endpoints-ai' ), implode( ', ', array_map( 'wp_strip_all_tags', $titles ) ) ) ) . '</p>';
					}
				}
			);
			$this->row(
				__( 'Also list', 'markdown-endpoints-ai' ),
				function () {
					echo '<p class="mdai-inline">';
					$this->number( 'llms_popular', 0, 50, __( 'pages AI agents read most', 'markdown-endpoints-ai' ) );
					echo '</p><p class="mdai-inline">';
					$this->number( 'llms_recent', 0, 50, __( 'latest posts', 'markdown-endpoints-ai' ) );
					echo '</p>';
				}
			);
			?>
		</table>
		<?php
	}

	/**
	 * Discovery: sitemap.
	 *
	 * @return void
	 */
	public function render_sitemap() {
		$url = Markdown_Endpoints_AI_Discovery::sitemap_url();
		?>
		<div class="mdai-options">
			<?php $this->option( 'sitemap', 'network', __( 'Serve a Markdown sitemap', 'markdown-endpoints-ai' ), __( 'An XML sitemap of every .md URL, linked from llms.txt for AI crawlers. It sends noindex and isn\'t added to robots.txt, so it stays out of Google Search Console.', 'markdown-endpoints-ai' ) ); ?>
		</div>
		<p class="mdai-code-line"><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $url ); ?></code></a></p>
		<?php
	}

	// Insights ------------------------------------------------------------------

	/**
	 * Period links (7, 30, 90 days).
	 *
	 * @return void
	 */
	private static function period_links() {
		$period = self::period();
		echo '<div class="mdai-segmented" role="group" aria-label="' . esc_attr__( 'Period', 'markdown-endpoints-ai' ) . '">';
		foreach ( array( 7, 30, 90 ) as $days ) {
			printf(
				'<a href="%1$s" class="%2$s">%3$s</a>',
				esc_url( self::page_url( array( 'period' => $days ) ) ),
				$days === $period ? 'is-active' : '',
				/* translators: %d: days. */
				esc_html( sprintf( __( '%d days', 'markdown-endpoints-ai' ), $days ) )
			);
		}
		echo '</div>';
	}

	/**
	 * Insights: stacked bar chart of requests per day.
	 *
	 * @return void
	 */
	public function render_activity() {
		$period = self::period();
		$days   = Markdown_Endpoints_AI_Log::by_day( $period );
		$totals = Markdown_Endpoints_AI_Log::totals( $period );
		$max    = 1;
		foreach ( $days as $groups ) {
			$max = max( $max, array_sum( $groups ) );
		}
		$labels = array(
			Markdown_Endpoints_AI_Agents::AI    => Markdown_Endpoints_AI_Agents::group_label( Markdown_Endpoints_AI_Agents::AI ),
			Markdown_Endpoints_AI_Agents::BOT   => Markdown_Endpoints_AI_Agents::group_label( Markdown_Endpoints_AI_Agents::BOT ),
			Markdown_Endpoints_AI_Agents::OTHER => Markdown_Endpoints_AI_Agents::group_label( Markdown_Endpoints_AI_Agents::OTHER ),
		);

		self::period_links();
		?>
		<div class="mdai-legend">
			<?php foreach ( $labels as $group => $label ) : ?>
				<span class="mdai-legend-item is-<?php echo esc_attr( $group ); ?>"><i></i><?php echo esc_html( $label ); ?> <strong><?php echo esc_html( number_format_i18n( $totals[ $group ] ) ); ?></strong></span>
			<?php endforeach; ?>
		</div>
		<div class="mdai-chart" role="img" aria-label="<?php esc_attr_e( 'Requests per day', 'markdown-endpoints-ai' ); ?>">
			<span class="mdai-chart-max"><?php echo esc_html( number_format_i18n( $max ) ); ?></span>
			<?php foreach ( $days as $day => $groups ) : ?>
				<?php
				$total = array_sum( $groups );
				/* translators: 1: date, 2: number of requests. */
				$title = sprintf( __( '%1$s: %2$s requests', 'markdown-endpoints-ai' ), date_i18n( get_option( 'date_format' ), strtotime( $day . ' 12:00:00' ) ), number_format_i18n( $total ) );
				?>
				<div class="mdai-chart-col" title="<?php echo esc_attr( $title ); ?>">
					<div class="mdai-chart-bar" style="height:<?php echo esc_attr( (string) round( $total / $max * 100, 1 ) ); ?>%">
						<?php foreach ( array( 'other', 'bot', 'ai' ) as $group ) : ?>
							<?php if ( $groups[ $group ] > 0 ) : ?>
								<span class="is-<?php echo esc_attr( $group ); ?>" style="flex-grow:<?php echo esc_attr( (string) (int) $groups[ $group ] ); ?>"></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<div class="mdai-chart-axis">
			<span><?php echo esc_html( date_i18n( 'M j', strtotime( (string) key( $days ) . ' 12:00:00' ) ) ); ?></span>
			<span><?php esc_html_e( 'Today', 'markdown-endpoints-ai' ); ?></span>
		</div>
		<?php
		if ( class_exists( 'AI_Crawler_Controls_Admin' ) ) {
			echo '<p class="mdai-callout">' . self::icon( 'bot', 16 ) . '<span>' . esc_html__( 'AI Crawler Controls is active, so agents are named the same way in both plugins.', 'markdown-endpoints-ai' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=ai-crawler-controls&tab=log' ) ) . '">' . esc_html__( 'See every visit, HTML pages included, in its crawl log', 'markdown-endpoints-ai' ) . '</a></span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
		}
	}

	/**
	 * Insights: top pages for AI.
	 *
	 * @return void
	 */
	public function render_mostread() {
		$rows = Markdown_Endpoints_AI_Log::top_pages( self::period(), Markdown_Endpoints_AI_Agents::AI, 20 );

		echo '<p class="mdai-intro">' . esc_html( sprintf( /* translators: %d: days. */ __( 'The pages AI crawlers and agents asked for most in the last %d days.', 'markdown-endpoints-ai' ), self::period() ) ) . '</p>';

		if ( ! $rows ) {
			self::empty_state( __( 'No AI agent has read a Markdown page in this period.', 'markdown-endpoints-ai' ) );
			return;
		}

		$this->pages_table( $rows );
	}

	/**
	 * Insights: requests per agent.
	 *
	 * @return void
	 */
	public function render_agents() {
		$rows = Markdown_Endpoints_AI_Log::agents( self::period(), 30 );

		if ( ! $rows ) {
			self::empty_state( __( 'No requests in this period.', 'markdown-endpoints-ai' ) );
			return;
		}
		?>
		<table class="widefat striped mdai-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Agent', 'markdown-endpoints-ai' ); ?></th>
					<th><?php esc_html_e( 'Kind', 'markdown-endpoints-ai' ); ?></th>
					<th class="mdai-num"><?php esc_html_e( 'Requests', 'markdown-endpoints-ai' ); ?></th>
					<th class="mdai-num"><?php esc_html_e( 'Pages', 'markdown-endpoints-ai' ); ?></th>
					<th><?php esc_html_e( 'Last seen', 'markdown-endpoints-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $operator = Markdown_Endpoints_AI_Agents::operator( $row['agent'] ); ?>
					<tr>
						<td><strong><?php echo esc_html( Markdown_Endpoints_AI_Agents::name( $row['agent'] ) ); ?></strong><?php echo '' !== $operator ? ' <span class="mdai-muted">' . esc_html( $operator ) . '</span>' : ''; ?></td>
						<td><span class="mdai-tag is-<?php echo esc_attr( $row['grp'] ); ?>"><?php echo esc_html( Markdown_Endpoints_AI_Agents::group_label( $row['grp'] ) ); ?></span></td>
						<td class="mdai-num"><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
						<td class="mdai-num"><?php echo esc_html( number_format_i18n( (int) $row['pages'] ) ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row['last'] . ' 12:00:00' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Insights: HTML versus Markdown size per page.
	 *
	 * @return void
	 */
	public function render_savings() {
		global $wpdb;

		$totals = Markdown_Endpoints_AI_Cache::totals();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Report across our own post meta; no API for it.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.post_id, CAST(h.meta_value AS SIGNED) AS html, CAST(m.meta_value AS SIGNED) AS md
				FROM {$wpdb->postmeta} h
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = h.post_id AND m.meta_key = %s
				WHERE h.meta_key = %s AND CAST(h.meta_value AS SIGNED) > 0
				ORDER BY CAST(h.meta_value AS SIGNED) - CAST(m.meta_value AS SIGNED) DESC
				LIMIT 15",
				Markdown_Endpoints_AI_Cache::META_MD,
				Markdown_Endpoints_AI_Cache::META_HTML
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! $rows ) {
			self::empty_state( __( 'Sizes appear here as Markdown files are built.', 'markdown-endpoints-ai' ) );
			return;
		}

		$saved = $totals['html'] > 0 ? (int) round( 100 - $totals['md'] / $totals['html'] * 100 ) : 0;
		?>
		<div class="mdai-savings-summary">
			<div><span class="mdai-big"><?php echo esc_html( $saved . '%' ); ?></span><span class="mdai-muted"><?php esc_html_e( 'lighter on average', 'markdown-endpoints-ai' ); ?></span></div>
			<div><span class="mdai-big"><?php echo esc_html( number_format_i18n( (int) ceil( $totals['html'] / 4 ) ) ); ?></span><span class="mdai-muted"><?php esc_html_e( 'tokens as HTML', 'markdown-endpoints-ai' ); ?></span></div>
			<div><span class="mdai-big"><?php echo esc_html( number_format_i18n( (int) ceil( $totals['md'] / 4 ) ) ); ?></span><span class="mdai-muted"><?php esc_html_e( 'tokens as Markdown', 'markdown-endpoints-ai' ); ?></span></div>
		</div>
		<table class="widefat striped mdai-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Page', 'markdown-endpoints-ai' ); ?></th>
					<th class="mdai-num"><?php esc_html_e( 'HTML', 'markdown-endpoints-ai' ); ?></th>
					<th class="mdai-num"><?php esc_html_e( 'Markdown', 'markdown-endpoints-ai' ); ?></th>
					<th class="mdai-num"><?php esc_html_e( 'Tokens saved', 'markdown-endpoints-ai' ); ?></th>
					<th class="mdai-num"><?php esc_html_e( 'Lighter', 'markdown-endpoints-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$html = (int) $row['html'];
					$md   = (int) $row['md'];
					?>
					<tr>
						<td><?php echo self::post_link( (int) $row['post_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in post_link(). ?></td>
						<td class="mdai-num"><?php echo esc_html( size_format( $html, 1 ) ); ?></td>
						<td class="mdai-num"><?php echo esc_html( size_format( $md, 1 ) ); ?></td>
						<td class="mdai-num"><?php echo esc_html( number_format_i18n( (int) max( 0, ceil( ( $html - $md ) / 4 ) ) ) ); ?></td>
						<td class="mdai-num"><strong><?php echo esc_html( ( $html > $md ? (int) round( 100 - $md / $html * 100 ) : 0 ) . '%' ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Insights: the detailed log.
	 *
	 * @return void
	 */
	public function render_log() {
		$group    = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$group    = in_array( $group, array( 'ai', 'bot', 'other' ), true ) ? $group : '';
		$page     = isset( $_GET['logpage'] ) ? max( 1, absint( $_GET['logpage'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging.
		$per_page = 25;
		$result   = Markdown_Endpoints_AI_Log::recent( $group, $page, $per_page );
		$pages    = max( 1, (int) ceil( $result['total'] / $per_page ) );
		$filters  = array(
			''      => __( 'All', 'markdown-endpoints-ai' ),
			'ai'    => __( 'AI', 'markdown-endpoints-ai' ),
			'bot'   => __( 'Bots', 'markdown-endpoints-ai' ),
			'other' => __( 'People and tools', 'markdown-endpoints-ai' ),
		);
		$via      = array(
			'md'      => __( '.md URL', 'markdown-endpoints-ai' ),
			'accept'  => __( 'Accept header', 'markdown-endpoints-ai' ),
			'llms'    => 'llms.txt',
			'sitemap' => __( 'Sitemap', 'markdown-endpoints-ai' ),
		);

		echo '<div class="mdai-segmented" role="group" aria-label="' . esc_attr__( 'Show', 'markdown-endpoints-ai' ) . '">';
		foreach ( $filters as $key => $label ) {
			printf(
				'<a href="%1$s" class="%2$s">%3$s</a>',
				esc_url(
					self::page_url(
						array(
							'group'  => $key,
							'period' => self::period(),
						)
					)
				),
				$key === $group ? 'is-active' : '',
				esc_html( $label )
			);
		}
		echo '</div>';

		if ( ! $result['rows'] ) {
			self::empty_state( __( 'No requests logged yet.', 'markdown-endpoints-ai' ) );
			return;
		}
		?>
		<table class="widefat striped mdai-table mdai-log">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'markdown-endpoints-ai' ); ?></th>
					<th><?php esc_html_e( 'Agent', 'markdown-endpoints-ai' ); ?></th>
					<th><?php esc_html_e( 'Page', 'markdown-endpoints-ai' ); ?></th>
					<th><?php esc_html_e( 'How', 'markdown-endpoints-ai' ); ?></th>
					<th class="mdai-num"><?php esc_html_e( 'Status', 'markdown-endpoints-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['rows'] as $row ) : ?>
					<?php
					$status = (int) $row['status'];
					$time   = (int) $row['hit_time'];
					?>
					<tr>
						<td title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ); ?>">
							<?php
							/* translators: %s: how long ago. */
							echo esc_html( sprintf( __( '%s ago', 'markdown-endpoints-ai' ), human_time_diff( $time ) ) );
							?>
						</td>
						<td title="<?php echo esc_attr( $row['user_agent'] ); ?>">
							<span class="mdai-dot is-<?php echo esc_attr( $row['grp'] ); ?>"></span><?php echo esc_html( Markdown_Endpoints_AI_Agents::name( $row['agent'] ) ); ?>
						</td>
						<td>
							<?php
							if ( (int) $row['post_id'] > 0 ) {
								echo self::post_link( (int) $row['post_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in post_link().
							} elseif ( 'llms' === $row['endpoint'] ) {
								echo '<code>/llms.txt</code>';
							} elseif ( 'sitemap' === $row['endpoint'] ) {
								echo '<code>/markdown-sitemap.xml</code>';
							} else {
								echo '<span class="mdai-muted">' . esc_html__( 'No such page', 'markdown-endpoints-ai' ) . '</span>';
							}
							?>
						</td>
						<td><?php echo esc_html( isset( $via[ $row['endpoint'] ] ) ? $via[ $row['endpoint'] ] : $row['endpoint'] ); ?></td>
						<td class="mdai-num"><span class="mdai-code <?php echo $status < 300 ? 'is-good' : ( 304 === $status ? 'is-none' : 'is-bad' ); ?>"><?php echo esc_html( (string) $status ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<div class="mdai-pager">
				<?php if ( $page > 1 ) : ?>
					<a class="button" href="
					<?php
					echo esc_url(
						self::page_url(
							array(
								'group'   => $group,
								'period'  => self::period(),
								'logpage' => $page - 1,
							)
						)
					);
					?>
											">&larr; <?php esc_html_e( 'Newer', 'markdown-endpoints-ai' ); ?></a>
				<?php endif; ?>
				<span class="mdai-muted">
					<?php
					/* translators: 1: page, 2: pages. */
					echo esc_html( sprintf( __( 'Page %1$s of %2$s', 'markdown-endpoints-ai' ), number_format_i18n( $page ), number_format_i18n( $pages ) ) );
					?>
				</span>
				<?php if ( $page < $pages ) : ?>
					<a class="button" href="
					<?php
					echo esc_url(
						self::page_url(
							array(
								'group'   => $group,
								'period'  => self::period(),
								'logpage' => $page + 1,
							)
						)
					);
					?>
											"><?php esc_html_e( 'Older', 'markdown-endpoints-ai' ); ?> &rarr;</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	// Tools ---------------------------------------------------------------------

	/**
	 * Tools: cached files.
	 *
	 * @return void
	 */
	public function render_files() {
		$totals = Markdown_Endpoints_AI_Cache::totals();
		$state  = Markdown_Endpoints_AI_Cache::rebuild_state();
		?>
		<table class="mdai-table-kv">
			<tr><th><?php esc_html_e( 'Files', 'markdown-endpoints-ai' ); ?></th><td><?php echo esc_html( number_format_i18n( $totals['files'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Total size', 'markdown-endpoints-ai' ); ?></th><td><?php echo esc_html( size_format( $totals['md'], 1 ) ); ?></td></tr>
			<tr>
				<th><?php esc_html_e( 'Rebuild', 'markdown-endpoints-ai' ); ?></th>
				<td data-mdai-rebuild-state>
					<?php echo esc_html( self::rebuild_text( $state ) ); ?>
				</td>
			</tr>
		</table>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Refresh files after', 'markdown-endpoints-ai' ),
				function () {
					$this->number( 'max_age', 1, 90, __( 'days, even if nothing changed', 'markdown-endpoints-ai' ) );
					echo '<p class="description">' . esc_html__( 'Catches things on a page that change without the post being edited, like a list of latest posts.', 'markdown-endpoints-ai' ) . '</p>';
				},
				'mdai-max_age'
			);
			?>
		</table>
		<p class="mdai-inline">
			<button type="button" class="button" data-mdai-action="rebuild_all"><?php echo self::icon( 'refresh-cw', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Rebuild every file now', 'markdown-endpoints-ai' ); ?></span></button>
			<button type="button" class="button" data-mdai-action="clear_cache" data-mdai-confirm="confirmCache"><?php echo self::icon( 'trash-2', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Delete cached files', 'markdown-endpoints-ai' ); ?></span></button>
			<span class="mdai-action-result" data-mdai-action-result></span>
		</p>
		<?php
	}

	/**
	 * Words for the rebuild progress.
	 *
	 * @param array|null $state Rebuild state.
	 * @return string
	 */
	private static function rebuild_text( $state ) {
		if ( ! $state ) {
			return __( 'Files are built as posts are published or updated, and on demand.', 'markdown-endpoints-ai' );
		}

		if ( ! empty( $state['done'] ) ) {
			/* translators: 1: files built, 2: how long ago. */
			return sprintf( __( 'Last full rebuild: %1$s files, %2$s ago.', 'markdown-endpoints-ai' ), number_format_i18n( (int) $state['built'] ), human_time_diff( isset( $state['finished'] ) ? (int) $state['finished'] : (int) $state['started'] ) );
		}

		/* translators: 1: posts done, 2: posts in total. */
		return sprintf( __( 'Rebuilding in the background: %1$s of %2$s done.', 'markdown-endpoints-ai' ), number_format_i18n( (int) $state['offset'] ), number_format_i18n( (int) $state['total'] ) );
	}

	/**
	 * Tools: logging.
	 *
	 * @return void
	 */
	public function render_logging() {
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Log requests', 'markdown-endpoints-ai' ),
				function () {
					$this->checkbox( 'log', __( 'Keep a log of who asks for Markdown', 'markdown-endpoints-ai' ) );
				}
			);
			$this->row(
				__( 'Keep the log for', 'markdown-endpoints-ai' ),
				function () {
					$this->number( 'retention', 1, 365, __( 'days', 'markdown-endpoints-ai' ) );
					echo '<p class="description">' . esc_html__( 'Daily totals (for the charts) are kept for 400 days. The detailed log keeps up to 5,000 requests a day.', 'markdown-endpoints-ai' ) . '</p>';
				},
				'mdai-retention'
			);
			?>
		</table>
		<p class="mdai-inline">
			<button type="button" class="button" data-mdai-action="clear_log" data-mdai-confirm="confirmLog"><?php echo self::icon( 'trash-2', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Clear the log', 'markdown-endpoints-ai' ); ?></span></button>
			<span class="mdai-action-result" data-mdai-action-result></span>
		</p>
		<?php
	}

	/**
	 * Tools: background jobs.
	 *
	 * @return void
	 */
	public function render_jobs() {
		$error = get_option( Markdown_Endpoints_AI_Cache::WARM_ERROR_OPTION );

		if ( Markdown_Endpoints_AI_Cache::has_action_scheduler() ) {
			self::callout( __( 'Files are built with Action Scheduler, which another plugin on your site provides. Its queue is under Tools > Scheduled Actions (group "markdown-endpoints-ai").', 'markdown-endpoints-ai' ), 'circle-check' );
		} else {
			self::callout( __( 'Files are built with WP-Cron. If a plugin that includes Action Scheduler is installed (WooCommerce, Rank Math and many others do), it\'s used instead.', 'markdown-endpoints-ai' ) );
		}

		if ( is_array( $error ) && ! empty( $error['time'] ) ) {
			/* translators: 1: how long ago, 2: error. */
			self::callout( sprintf( __( '%1$s ago the site couldn\'t load its own page to build a file (%2$s). Files are still built on their first request.', 'markdown-endpoints-ai' ), human_time_diff( (int) $error['time'] ), $error['error'] ), 'triangle-alert', 'is-warning' );
		}
	}

	/**
	 * Tools: filters and constants.
	 *
	 * @return void
	 */
	public function render_developer() {
		$hooks = array(
			'markdown_endpoints_ai_markdown'       => __( 'Change a post\'s finished Markdown before it\'s cached.', 'markdown-endpoints-ai' ),
			'markdown_endpoints_ai_frontmatter'    => __( 'Add or remove frontmatter fields.', 'markdown-endpoints-ai' ),
			'markdown_endpoints_ai_junk_selectors' => __( 'Change the built-in clutter selectors.', 'markdown-endpoints-ai' ),
			'markdown_endpoints_ai_why_not'        => __( 'Return a reason code to keep a post from being served.', 'markdown-endpoints-ai' ),
			'markdown_endpoints_ai_is_restricted'  => __( 'Mark posts as members-only for other membership plugins.', 'markdown-endpoints-ai' ),
			'markdown_endpoints_ai_agents'         => __( 'Add agents to recognise in Insights.', 'markdown-endpoints-ai' ),
			'markdown_endpoints_ai_client_ip'      => __( 'Give the real visitor IP behind a proxy other than Cloudflare.', 'markdown-endpoints-ai' ),
		);
		?>
		<table class="widefat striped mdai-table mdai-hooks">
			<tbody>
				<?php foreach ( $hooks as $hook => $desc ) : ?>
					<tr><td><code><?php echo esc_html( $hook ); ?></code></td><td><?php echo esc_html( $desc ); ?></td></tr>
				<?php endforeach; ?>
				<tr><td><code>MARKDOWN_ENDPOINTS_AI_DISABLED</code></td><td><?php esc_html_e( 'Define as true in wp-config.php to switch everything off.', 'markdown-endpoints-ai' ); ?></td></tr>
			</tbody>
		</table>
		<?php
	}

	// Sidebar -------------------------------------------------------------------

	/**
	 * Sidebar: Save, status, and where updates come from.
	 *
	 * @return void
	 */
	private function render_sidebar() {
		$plugin  = $this->plugin;
		$check   = Markdown_Endpoints_AI_Diagnostics::last();
		$counts  = $check ? Markdown_Endpoints_AI_Diagnostics::counts( $check ) : null;
		$llms    = (string) $plugin->get_setting( 'llms' );
		$cache   = Markdown_Endpoints_AI_Cache::totals();
		$llms_on = array(
			'virtual' => __( 'llms.txt served', 'markdown-endpoints-ai' ),
			'file'    => __( 'llms.txt file kept up to date', 'markdown-endpoints-ai' ),
			'off'     => __( 'No llms.txt', 'markdown-endpoints-ai' ),
		);
		$status  = array(
			array( $plugin->is_enabled(), $plugin->is_enabled() ? __( 'Serving Markdown', 'markdown-endpoints-ai' ) : __( 'Markdown endpoints are off', 'markdown-endpoints-ai' ) ),
			array( (bool) $plugin->get_setting( 'md_urls' ), $plugin->get_setting( 'md_urls' ) ? __( '.md URLs on', 'markdown-endpoints-ai' ) : __( '.md URLs off', 'markdown-endpoints-ai' ) ),
			array( (bool) $plugin->get_setting( 'negotiate' ), $plugin->get_setting( 'negotiate' ) ? __( 'Accept-header requests on', 'markdown-endpoints-ai' ) : __( 'Accept-header requests off', 'markdown-endpoints-ai' ) ),
			array( 'off' !== $llms, isset( $llms_on[ $llms ] ) ? $llms_on[ $llms ] : '' ),
			array( (bool) $plugin->get_setting( 'sitemap' ), $plugin->get_setting( 'sitemap' ) ? __( 'Markdown sitemap on', 'markdown-endpoints-ai' ) : __( 'Markdown sitemap off', 'markdown-endpoints-ai' ) ),
			/* translators: %s: number of files. */
			array( $cache['files'] > 0, sprintf( _n( '%s file ready', '%s files ready', $cache['files'], 'markdown-endpoints-ai' ), number_format_i18n( $cache['files'] ) ) ),
		);

		if ( null === $counts ) {
			$status[] = array( false, __( 'Caching check not run yet', 'markdown-endpoints-ai' ) );
		} elseif ( $counts['bad'] ) {
			/* translators: %d: number of problems. */
			$status[] = array( false, sprintf( _n( 'Caching check: %d problem', 'Caching check: %d problems', $counts['bad'], 'markdown-endpoints-ai' ), $counts['bad'] ), 'bad' );
		} elseif ( $counts['warn'] ) {
			/* translators: %d: number of warnings. */
			$status[] = array( false, sprintf( _n( 'Caching check: %d warning', 'Caching check: %d warnings', $counts['warn'], 'markdown-endpoints-ai' ), $counts['warn'] ), 'warn' );
		} else {
			$status[] = array( true, __( 'Caching check passed', 'markdown-endpoints-ai' ) );
		}
		?>
		<div class="postbox mdai-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'markdown-endpoints-ai' ); ?>
				</h2>
			</div>
			<div class="inside">
				<label class="mdai-master">
					<input type="checkbox" name="<?php echo esc_attr( self::name( 'enabled' ) ); ?>" value="1" <?php checked( 1, (int) $plugin->get_setting( 'enabled' ) ); ?>>
					<span><?php esc_html_e( 'Markdown endpoints on', 'markdown-endpoints-ai' ); ?></span>
				</label>
				<?php if ( defined( 'MARKDOWN_ENDPOINTS_AI_DISABLED' ) && MARKDOWN_ENDPOINTS_AI_DISABLED ) : ?>
					<p class="mdai-callout is-warning"><?php esc_html_e( 'Switched off by MARKDOWN_ENDPOINTS_AI_DISABLED in wp-config.php.', 'markdown-endpoints-ai' ); ?></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'Settings on every tab are saved together. Cached files rebuild with the new settings as they\'re next asked for.', 'markdown-endpoints-ai' ); ?></p>
				<?php submit_button( __( 'Save Changes', 'markdown-endpoints-ai' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'markdown-endpoints-ai' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="mdai-checklist">
					<?php foreach ( $status as $item ) : ?>
						<?php
						$state = $item[0] ? 'is-ok' : ( isset( $item[2] ) ? 'is-' . $item[2] : 'is-off' );
						$icon  = $item[0] ? 'circle-check' : ( isset( $item[2] ) ? 'triangle-alert' : 'circle-dot' );
						?>
						<li class="<?php echo esc_attr( $state ); ?>"><?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<?php
		$this->render_updates_box();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( MARKDOWN_ENDPOINTS_AI_REPO ) );
		$check_url  = Markdown_Endpoints_AI_Updater::check_now_url();
		?>
		<div class="postbox mdai-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'markdown-endpoints-ai' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'markdown-endpoints-ai' ), '<strong>' . esc_html( MARKDOWN_ENDPOINTS_AI_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Markdown_Endpoints_AI_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'markdown-endpoints-ai' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( MARKDOWN_ENDPOINTS_AI_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Markdown_Endpoints_AI_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'markdown-endpoints-ai' ),
							'<a href="' . esc_url( MARKDOWN_ENDPOINTS_AI_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>MARKDOWN_ENDPOINTS_AI_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'markdown-endpoints-ai' ),
							'<a href="' . esc_url( MARKDOWN_ENDPOINTS_AI_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'markdown-endpoints-ai' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// AJAX ----------------------------------------------------------------------

	/**
	 * Common checks for the page's AJAX requests.
	 *
	 * @return void
	 */
	private function verify_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you can\'t do that.', 'markdown-endpoints-ai' ) ), 403 );
		}
	}

	/**
	 * AJAX: run the caching and endpoints check.
	 *
	 * @return void
	 */
	public function ajax_check() {
		$this->verify_ajax();

		$results = Markdown_Endpoints_AI_Diagnostics::run( $this->plugin );

		ob_start();
		self::check_results( $results );

		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * AJAX: rebuild every file in the background.
	 *
	 * @return void
	 */
	public function ajax_rebuild_all() {
		$this->verify_ajax();

		$total = Markdown_Endpoints_AI_Cache::rebuild_all();

		wp_send_json_success(
			array(
				/* translators: %s: number of posts. */
				'message' => sprintf( __( 'Rebuilding %s pages in the background.', 'markdown-endpoints-ai' ), number_format_i18n( $total ) ),
				'state'   => self::rebuild_text( Markdown_Endpoints_AI_Cache::rebuild_state() ),
			)
		);
	}

	/**
	 * AJAX: rebuild progress.
	 *
	 * @return void
	 */
	public function ajax_rebuild_state() {
		$this->verify_ajax();

		$state = Markdown_Endpoints_AI_Cache::rebuild_state();

		wp_send_json_success(
			array(
				'state' => self::rebuild_text( $state ),
				'done'  => ! $state || ! empty( $state['done'] ),
			)
		);
	}

	/**
	 * AJAX: delete every cached file.
	 *
	 * @return void
	 */
	public function ajax_clear_cache() {
		$this->verify_ajax();

		$count = Markdown_Endpoints_AI_Cache::clear();

		/* translators: %s: number of files. */
		wp_send_json_success( array( 'message' => sprintf( _n( 'Deleted %s file.', 'Deleted %s files.', $count, 'markdown-endpoints-ai' ), number_format_i18n( $count ) ) ) );
	}

	/**
	 * AJAX: clear the log.
	 *
	 * @return void
	 */
	public function ajax_clear_log() {
		$this->verify_ajax();

		Markdown_Endpoints_AI_Log::clear();

		wp_send_json_success( array( 'message' => __( 'The log is empty.', 'markdown-endpoints-ai' ) ) );
	}
}

