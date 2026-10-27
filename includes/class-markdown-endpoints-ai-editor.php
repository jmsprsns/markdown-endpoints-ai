<?php
/**
 * Editor tools: a "Markdown for AI" panel in the block editor (a box in the
 * classic editor), a "View Markdown" link on the Posts screen, and the
 * side-by-side preview page.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Editor integration and preview.
 */
final class Markdown_Endpoints_AI_Editor {

	/**
	 * Slug of the preview page.
	 */
	const PREVIEW = 'markdown-endpoints-ai-preview';

	/**
	 * Nonce action for the classic box and the panel's status requests.
	 */
	const NONCE = 'markdown_endpoints_ai_post';

	/**
	 * Per-post settings: meta key => allowed values ('' = site default).
	 *
	 * @var array<string,string[]>
	 */
	private static $signal_keys = array(
		'_mdai_signal_search' => array( '', 'yes', 'no' ),
		'_mdai_signal_input'  => array( '', 'yes', 'no' ),
		'_mdai_signal_train'  => array( '', 'yes', 'no' ),
	);

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

		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_panel' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_box' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'register_preview' ) );
		add_action( 'admin_post_markdown_endpoints_ai_rebuild_post', array( __CLASS__, 'rebuild_post' ) );
		add_action( 'wp_ajax_markdown_endpoints_ai_post_status', array( __CLASS__, 'ajax_status' ) );
	}

	/**
	 * Register the per-post settings for the REST API (the block editor
	 * saves them there). Only users who can edit the post may change them.
	 *
	 * @return void
	 */
	public static function register_meta() {
		$auth = function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};

		foreach ( array_keys( Markdown_Endpoints_AI_Plugin::available_post_types() ) as $type ) {
			register_post_meta(
				$type,
				Markdown_Endpoints_AI_Access::META_EXCLUDE,
				array(
					'type'              => 'boolean',
					'single'            => true,
					'default'           => false,
					'show_in_rest'      => true,
					'auth_callback'     => $auth,
					'sanitize_callback' => 'rest_sanitize_boolean',
				)
			);

			foreach ( self::$signal_keys as $key => $values ) {
				register_post_meta(
					$type,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'default'           => '',
						'show_in_rest'      => array(
							'schema' => array(
								'type' => 'string',
								'enum' => $values,
							),
						),
						'auth_callback'     => $auth,
						'sanitize_callback' => array( __CLASS__, 'sanitize_signal' ),
					)
				);
			}
		}
	}

	/**
	 * Keep a signal value to '', yes or no.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function sanitize_signal( $value ) {
		return in_array( $value, array( 'yes', 'no' ), true ) ? $value : '';
	}

	/**
	 * What the panel and box show about a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function status( WP_Post $post ) {
		$reason = Markdown_Endpoints_AI_Access::why_not( $post, self::$plugin );
		$info   = Markdown_Endpoints_AI_Cache::info( $post->ID );
		$served = '' === $reason;
		$md     = $served && ! empty( $info['md'] ) ? (int) $info['md'] : 0;
		$html   = $served && ! empty( $info['html'] ) ? (int) $info['html'] : 0;

		return array(
			'served'  => $served,
			'reason'  => $served ? '' : Markdown_Endpoints_AI_Access::reason_label( $reason ),
			'url'     => $served ? Markdown_Endpoints_AI_Discovery::markdown_url( $post ) : '',
			'preview' => self::preview_url( $post->ID ),
			'tokens'  => $md ? (int) ceil( $md / 4 ) : 0,
			'saved'   => $md && $html > $md ? (int) round( 100 - $md / $html * 100 ) : 0,
		);
	}

	/**
	 * Preview page URL for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function preview_url( $post_id ) {
		return add_query_arg(
			array(
				'page' => self::PREVIEW,
				'post' => absint( $post_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Whether the editor tools belong on this post type.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	private static function covers( $type ) {
		return in_array( $type, self::$plugin->post_types(), true );
	}

	// Block editor ------------------------------------------------------------

	/**
	 * Load the block editor panel.
	 *
	 * @return void
	 */
	public static function enqueue_panel() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();

		if ( ! $screen || ! $post instanceof WP_Post || ! self::covers( $screen->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		wp_enqueue_script(
			'markdown-endpoints-ai-editor',
			MARKDOWN_ENDPOINTS_AI_URL . 'assets/editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
			MARKDOWN_ENDPOINTS_AI_VERSION,
			true
		);

		wp_add_inline_style( 'wp-components', '.mdai-panel-status{display:flex;gap:6px;align-items:flex-start;margin:0 0 12px;line-height:1.45}.mdai-panel-status svg{flex:none;margin-top:1px}.mdai-panel-status.is-on svg{color:#00a32a}.mdai-panel-status.is-off svg{color:#a7aaad}.mdai-panel-stats{margin:-4px 0 12px;color:#646970;font-size:12px}.mdai-panel .components-toggle-control{margin-bottom:14px}.mdai-panel-buttons{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:0 0 16px}.mdai-panel-signals{margin-top:8px;padding-top:12px;border-top:1px solid #e0e0e0}.mdai-panel-signals h3{margin:0 0 10px;font-size:11px;font-weight:500;text-transform:uppercase}.mdai-panel-signals .components-base-control{margin-bottom:12px}' );

		wp_localize_script(
			'markdown-endpoints-ai-editor',
			'markdown_endpoints_ai_editor',
			array(
				'status'   => self::status( $post ),
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'postId'   => $post->ID,
				'defaults' => self::default_labels(),
				'icons'    => array(
					'on'  => Markdown_Endpoints_AI_Icons::get( 'circle-check', 16 ),
					'off' => Markdown_Endpoints_AI_Icons::get( 'circle-slash', 16 ),
				),
				'i18n'     => array(
					'title'       => __( 'Markdown for AI', 'markdown-endpoints-ai' ),
					'served'      => __( 'Agents get a Markdown version of this page.', 'markdown-endpoints-ai' ),
					/* translators: 1: tokens, 2: percent. */
					'stats'       => __( 'About %1$s tokens, %2$s%% lighter than the page.', 'markdown-endpoints-ai' ),
					'pending'     => __( 'Its file is built in the background after saving.', 'markdown-endpoints-ai' ),
					'toggle'      => __( 'Serve a Markdown version', 'markdown-endpoints-ai' ),
					'view'        => __( 'View Markdown', 'markdown-endpoints-ai' ),
					'open'        => __( 'Open .md', 'markdown-endpoints-ai' ),
					'signals'     => __( 'Content signals', 'markdown-endpoints-ai' ),
					'search'      => __( 'Search', 'markdown-endpoints-ai' ),
					'input'       => __( 'AI answers', 'markdown-endpoints-ai' ),
					'train'       => __( 'AI training', 'markdown-endpoints-ai' ),
					/* translators: %s: the site-wide choice. */
					'siteDefault' => __( 'Site default (%s)', 'markdown-endpoints-ai' ),
					'yes'         => __( 'Allowed', 'markdown-endpoints-ai' ),
					'no'          => __( 'Not allowed', 'markdown-endpoints-ai' ),
				),
			)
		);
	}

	/**
	 * The site-wide signal choices in words, for "Site default (...)".
	 *
	 * @return array<string,string>
	 */
	private static function default_labels() {
		$out = array();

		foreach ( array( 'search', 'input', 'train' ) as $key ) {
			$value       = (string) self::$plugin->get_setting( 'signal_' . $key );
			$out[ $key ] = 'yes' === $value ? __( 'Allowed', 'markdown-endpoints-ai' ) : ( 'no' === $value ? __( 'Not allowed', 'markdown-endpoints-ai' ) : __( 'not stated', 'markdown-endpoints-ai' ) );
		}

		return $out;
	}

	/**
	 * AJAX: a post's status, for the panel after a save.
	 *
	 * @return void
	 */
	public static function ajax_status() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$post = get_post( isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0 );

		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			wp_send_json_error( null, 403 );
		}

		wp_send_json_success( self::status( $post ) );
	}

	// Classic editor ----------------------------------------------------------

	/**
	 * The box for the classic editor (the block editor uses the panel).
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 * @return void
	 */
	public static function add_box( $post_type, $post = null ) {
		if ( ! self::covers( $post_type ) ) {
			return;
		}

		add_meta_box(
			'markdown-endpoints-ai',
			__( 'Markdown for AI', 'markdown-endpoints-ai' ),
			array( __CLASS__, 'render_box' ),
			$post_type,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Draw the classic box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_box( $post ) {
		$status  = self::status( $post );
		$labels  = self::default_labels();
		$signals = array(
			'_mdai_signal_search' => array( __( 'Search', 'markdown-endpoints-ai' ), $labels['search'] ),
			'_mdai_signal_input'  => array( __( 'AI answers', 'markdown-endpoints-ai' ), $labels['input'] ),
			'_mdai_signal_train'  => array( __( 'AI training', 'markdown-endpoints-ai' ), $labels['train'] ),
		);

		wp_nonce_field( self::NONCE, 'markdown_endpoints_ai_nonce' );
		?>
		<p class="mdai-box-status" style="display:flex;gap:6px;align-items:flex-start">
			<span style="flex:none;color:<?php echo $status['served'] ? '#00a32a' : '#a7aaad'; ?>"><?php echo Markdown_Endpoints_AI_Icons::get( $status['served'] ? 'circle-check' : 'circle-slash', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<span><?php echo esc_html( $status['served'] ? __( 'Agents get a Markdown version of this page.', 'markdown-endpoints-ai' ) : $status['reason'] ); ?></span>
		</p>
		<?php if ( $status['tokens'] ) : ?>
			<p class="description">
				<?php
				/* translators: 1: tokens, 2: percent. */
				echo esc_html( sprintf( __( 'About %1$s tokens, %2$s%% lighter than the page.', 'markdown-endpoints-ai' ), number_format_i18n( $status['tokens'] ), $status['saved'] ) );
				?>
			</p>
		<?php endif; ?>
		<p>
			<label>
				<input type="checkbox" name="markdown_endpoints_ai_serve" value="1" <?php checked( ! get_post_meta( $post->ID, Markdown_Endpoints_AI_Access::META_EXCLUDE, true ) ); ?>>
				<?php esc_html_e( 'Serve a Markdown version', 'markdown-endpoints-ai' ); ?>
			</label>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( $status['preview'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View Markdown', 'markdown-endpoints-ai' ); ?></a>
			<?php if ( '' !== $status['url'] ) : ?>
				<a class="button-link" href="<?php echo esc_url( $status['url'] ); ?>" target="_blank" rel="noopener" style="margin-left:8px"><?php esc_html_e( 'Open .md', 'markdown-endpoints-ai' ); ?></a>
			<?php endif; ?>
		</p>
		<p style="margin:14px 0 6px;font-weight:600"><?php esc_html_e( 'Content signals', 'markdown-endpoints-ai' ); ?></p>
		<?php foreach ( $signals as $key => $signal ) : ?>
			<p style="margin:0 0 8px">
				<label for="<?php echo esc_attr( 'mdai' . $key ); ?>" style="display:block;margin-bottom:2px"><?php echo esc_html( $signal[0] ); ?></label>
				<select id="<?php echo esc_attr( 'mdai' . $key ); ?>" name="markdown_endpoints_ai_signal[<?php echo esc_attr( $key ); ?>]" style="width:100%">
					<?php $current = (string) get_post_meta( $post->ID, $key, true ); ?>
					<option value="" <?php selected( '', $current ); ?>>
						<?php
						/* translators: %s: the site-wide choice. */
						echo esc_html( sprintf( __( 'Site default (%s)', 'markdown-endpoints-ai' ), $signal[1] ) );
						?>
					</option>
					<option value="yes" <?php selected( 'yes', $current ); ?>><?php esc_html_e( 'Allowed', 'markdown-endpoints-ai' ); ?></option>
					<option value="no" <?php selected( 'no', $current ); ?>><?php esc_html_e( 'Not allowed', 'markdown-endpoints-ai' ); ?></option>
				</select>
			</p>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Save the classic box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function save_box( $post_id, $post ) {
		if ( ! isset( $_POST['markdown_endpoints_ai_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['markdown_endpoints_ai_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( empty( $_POST['markdown_endpoints_ai_serve'] ) ) {
			update_post_meta( $post_id, Markdown_Endpoints_AI_Access::META_EXCLUDE, 1 );
		} else {
			delete_post_meta( $post_id, Markdown_Endpoints_AI_Access::META_EXCLUDE );
		}

		$signals = isset( $_POST['markdown_endpoints_ai_signal'] ) && is_array( $_POST['markdown_endpoints_ai_signal'] ) ? wp_unslash( $_POST['markdown_endpoints_ai_signal'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is checked against yes/no below.

		foreach ( array_keys( self::$signal_keys ) as $key ) {
			$value = isset( $signals[ $key ] ) ? self::sanitize_signal( $signals[ $key ] ) : '';
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	// Posts screen --------------------------------------------------------------

	/**
	 * "View Markdown" under each post on the Posts and Pages screens.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_action( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof WP_Post || ! self::covers( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) || 'trash' === $post->post_status ) {
			return $actions;
		}

		$actions['markdown_endpoints_ai'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( self::preview_url( $post->ID ) ),
			/* translators: %s: post title. */
			esc_attr( sprintf( __( 'View the Markdown version of "%s"', 'markdown-endpoints-ai' ), Markdown_Endpoints_AI_Document::title( $post ) ) ),
			esc_html__( 'View Markdown', 'markdown-endpoints-ai' )
		);

		return $actions;
	}

	// Preview -------------------------------------------------------------------

	/**
	 * Register the preview page (reached from the editor, not the menu).
	 *
	 * @return void
	 */
	public static function register_preview() {
		$hook = add_submenu_page( '', __( 'Markdown preview', 'markdown-endpoints-ai' ), '', 'edit_posts', self::PREVIEW, array( __CLASS__, 'render_preview' ) );

		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'load_preview' ) );
		}
	}

	/**
	 * Styles and the body class for the preview page.
	 *
	 * @return void
	 */
	public static function load_preview() {
		// Checked here, before any output, so the answer is a real 403.
		$post = get_post( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page, capability checked.
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			wp_die( esc_html__( 'Sorry, you can\'t preview this post.', 'markdown-endpoints-ai' ), 403 );
		}

		add_action(
			'admin_enqueue_scripts',
			function () {
				wp_enqueue_style( 'markdown-endpoints-ai-admin', MARKDOWN_ENDPOINTS_AI_URL . 'assets/admin.css', array(), MARKDOWN_ENDPOINTS_AI_VERSION );
				wp_enqueue_script( 'markdown-endpoints-ai-admin', MARKDOWN_ENDPOINTS_AI_URL . 'assets/admin.js', array(), MARKDOWN_ENDPOINTS_AI_VERSION, true );
				wp_localize_script(
					'markdown-endpoints-ai-admin',
					'markdown_endpoints_ai_admin',
					array(
						'i18n' => array(
							'copied' => __( 'Copied', 'markdown-endpoints-ai' ),
						),
					)
				);
			}
		);

		add_filter(
			'admin_body_class',
			function ( $classes ) {
				return $classes . ' mdai-settings-page mdai-preview-page';
			}
		);
	}

	/**
	 * The Markdown to show for a post: the served file (built now if
	 * needed), or for posts that aren't served, a build from the content.
	 *
	 * @param WP_Post $post Post.
	 * @return array{markdown: string, info: array, note: string}
	 */
	private static function preview_markdown( WP_Post $post ) {
		$plugin = self::$plugin;

		if ( Markdown_Endpoints_AI_Access::is_served( $post, $plugin ) ) {
			$lookup = Markdown_Endpoints_AI_Cache::lookup( $post );

			if ( 'fresh' !== $lookup['state'] ) {
				Markdown_Endpoints_AI_Cache::build( $post );
				$lookup = Markdown_Endpoints_AI_Cache::lookup( $post );
			}

			if ( 'fresh' === $lookup['state'] ) {
				return array(
					'markdown' => (string) file_get_contents( $lookup['file'] ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					'info'     => $lookup['info'],
					'note'     => '',
				);
			}

			if ( 'blocked' === $lookup['state'] ) {
				return array(
					'markdown' => '',
					'info'     => $lookup['info'],
					'note'     => Markdown_Endpoints_AI_Access::reason_label( $lookup['info']['blocked'] ),
				);
			}

			$result = Markdown_Endpoints_AI_Document::from_content( $post, $plugin );

			return array(
				'markdown' => $result['markdown'],
				'info'     => array(
					'source' => 'content',
					'html'   => $result['html_bytes'],
					'md'     => strlen( $result['markdown'] ),
				),
				'note'     => __( 'This server couldn\'t load its own page, so this preview was built from the post content. Agents get the full rendered version: it\'s built on their first request.', 'markdown-endpoints-ai' ),
			);
		}

		// Not served: show what it would look like, from the content, as the
		// person editing it. Nothing is saved.
		$result = Markdown_Endpoints_AI_Document::from_content( $post, $plugin, false );

		return array(
			'markdown' => $result['markdown'],
			'info'     => array(
				'source' => 'content',
				'html'   => $result['html_bytes'],
				'md'     => strlen( $result['markdown'] ),
			),
			'note'     => '',
		);
	}

	/**
	 * The side-by-side preview: the page on the left, its Markdown on the right.
	 *
	 * @return void
	 */
	public static function render_preview() {
		$post = get_post( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page, capability checked.

		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			wp_die( esc_html__( 'Sorry, you can\'t preview this post.', 'markdown-endpoints-ai' ), 403 );
		}

		$reason  = Markdown_Endpoints_AI_Access::why_not( $post, self::$plugin );
		$served  = '' === $reason;
		$preview = self::preview_markdown( $post );
		$info    = $preview['info'];
		$md      = strlen( $preview['markdown'] );
		$html    = isset( $info['html'] ) ? (int) $info['html'] : 0;
		$saved   = $md && $html > $md ? (int) round( 100 - $md / $html * 100 ) : 0;
		$url     = $served ? Markdown_Endpoints_AI_Discovery::markdown_url( $post ) : '';
		$page    = 'publish' === $post->post_status ? (string) get_permalink( $post ) : (string) get_preview_post_link( $post );
		$source  = isset( $info['source'] ) && 'rendered' === $info['source'] ? __( 'Rendered page', 'markdown-endpoints-ai' ) : __( 'Post content', 'markdown-endpoints-ai' );
		$notice  = isset( $_GET['mdai_rebuilt'] ) ? sanitize_key( wp_unslash( $_GET['mdai_rebuilt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks a message.
		$icon    = function ( $name, $size = 16 ) {
			return Markdown_Endpoints_AI_Icons::get( $name, $size );
		};
		$tiles   = array(
			array( 'file-code', size_format( $html, 1 ), isset( $info['source'] ) && 'rendered' === $info['source'] ? __( 'Page HTML', 'markdown-endpoints-ai' ) : __( 'Content HTML', 'markdown-endpoints-ai' ) ),
			array( 'file-text', size_format( $md, 1 ), __( 'Markdown', 'markdown-endpoints-ai' ) ),
			array( 'coins', number_format_i18n( (int) ceil( $md / 4 ) ), __( 'Tokens (about)', 'markdown-endpoints-ai' ) ),
			array( 'trending-down', $saved . '%', __( 'Lighter for agents', 'markdown-endpoints-ai' ) ),
			array( 'layers', $source, __( 'Built from', 'markdown-endpoints-ai' ) ),
		);
		?>
		<div class="mdai-header">
			<div class="mdai-header-title">
				<span class="mdai-header-icon"><?php echo $icon( Markdown_Endpoints_AI_Admin::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1>
					<?php
					/* translators: %s: post title. */
					echo esc_html( sprintf( __( 'Markdown preview: %s', 'markdown-endpoints-ai' ), Markdown_Endpoints_AI_Document::title( $post ) ) );
					?>
				</h1>
				<span class="mdai-pill <?php echo $served ? 'is-on' : 'is-off'; ?>"><?php echo $served ? esc_html__( 'Served to agents', 'markdown-endpoints-ai' ) : esc_html__( 'Not served', 'markdown-endpoints-ai' ); ?></span>
			</div>
			<div class="mdai-header-actions">
				<a class="button" href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php echo $icon( 'pencil' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Edit', 'markdown-endpoints-ai' ); ?></a>
				<?php if ( '' !== $url ) : ?>
					<a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo $icon( 'external-link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Open .md', 'markdown-endpoints-ai' ); ?></a>
				<?php endif; ?>
				<?php if ( $served ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="markdown_endpoints_ai_rebuild_post">
						<input type="hidden" name="post" value="<?php echo esc_attr( (string) $post->ID ); ?>">
						<?php wp_nonce_field( 'markdown_endpoints_ai_rebuild_' . $post->ID ); ?>
						<button type="submit" class="button"><?php echo $icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Rebuild', 'markdown-endpoints-ai' ); ?></button>
					</form>
				<?php endif; ?>
				<button type="button" class="button button-primary" data-mdai-copy="#mdai-preview-source"><?php echo $icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Copy Markdown', 'markdown-endpoints-ai' ); ?></span></button>
			</div>
		</div>

		<div class="wrap mdai-wrap">
			<hr class="wp-header-end">

			<?php if ( 'ok' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rebuilt from the live page.', 'markdown-endpoints-ai' ); ?></p></div>
			<?php elseif ( 'failed' === $notice ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'This server couldn\'t load its own page, so the file will be built on the next agent request.', 'markdown-endpoints-ai' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $served ) : ?>
				<p class="mdai-callout is-warning"><?php echo $icon( 'circle-slash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( Markdown_Endpoints_AI_Access::reason_label( $reason ) . ' ' . __( 'Agents get a 404. Below is what it would look like, built from the post content.', 'markdown-endpoints-ai' ) ); ?></span></p>
			<?php elseif ( '' !== $preview['note'] ) : ?>
				<p class="mdai-callout is-warning"><?php echo $icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $preview['note'] ); ?></span></p>
			<?php endif; ?>

			<div class="mdai-stats mdai-stats-compact">
				<?php foreach ( $tiles as $tile ) : ?>
					<div class="mdai-stat">
						<span class="mdai-stat-icon"><?php echo $icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
						<span class="mdai-stat-num"><?php echo esc_html( $tile[1] ); ?></span>
						<span class="mdai-stat-label"><?php echo esc_html( $tile[2] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mdai-compare">
				<div class="mdai-pane">
					<div class="mdai-pane-head"><?php echo $icon( 'monitor' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'What visitors see', 'markdown-endpoints-ai' ); ?></div>
					<?php if ( '' !== $page ) : ?>
						<iframe class="mdai-pane-frame" src="<?php echo esc_url( $page ); ?>" title="<?php esc_attr_e( 'The page', 'markdown-endpoints-ai' ); ?>" loading="lazy"></iframe>
					<?php endif; ?>
				</div>
				<div class="mdai-pane">
					<div class="mdai-pane-head"><?php echo $icon( 'bot' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'What agents get', 'markdown-endpoints-ai' ); ?></div>
					<pre class="mdai-pane-source" id="mdai-preview-source" tabindex="0"><?php echo esc_html( $preview['markdown'] ); ?></pre>
				</div>
			</div>

			<?php if ( $served ) : ?>
				<div class="postbox mdai-headers-box">
					<div class="postbox-header"><h2 class="hndle"><?php echo $icon( 'list', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Headers sent with it', 'markdown-endpoints-ai' ); ?></h2></div>
					<div class="inside">
						<table class="mdai-table-kv">
							<tr><th>Content-Type</th><td><code>text/markdown; charset=UTF-8</code></td></tr>
							<tr><th>Link</th><td><code>&lt;<?php echo esc_html( Markdown_Endpoints_AI_SEO::canonical( $post ) ); ?>&gt;; rel="canonical"</code></td></tr>
							<tr><th>X-Robots-Tag</th><td><code>noindex</code> <span class="mdai-muted"><?php esc_html_e( '(.md URLs; header requests come from the page\'s own URL, so they don\'t send it)', 'markdown-endpoints-ai' ); ?></span></td></tr>
							<tr><th>Vary</th><td><code>Accept</code> <span class="mdai-muted"><?php esc_html_e( '(header requests)', 'markdown-endpoints-ai' ); ?></span></td></tr>
							<?php
							$signals = Markdown_Endpoints_AI_Document::signals( $post, self::$plugin );
							if ( $signals ) :
								?>
								<tr><th>Content-Signal</th><td><code><?php echo esc_html( Markdown_Endpoints_AI_Document::signal_header( $signals ) ); ?></code></td></tr>
							<?php endif; ?>
						</table>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Preview's "Rebuild" button.
	 *
	 * @return void
	 */
	public static function rebuild_post() {
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;

		check_admin_referer( 'markdown_endpoints_ai_rebuild_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			wp_die( esc_html__( 'Sorry, you can\'t rebuild this post.', 'markdown-endpoints-ai' ), 403 );
		}

		$ok = Markdown_Endpoints_AI_Access::is_served( $post, self::$plugin ) && Markdown_Endpoints_AI_Cache::build( $post );

		wp_safe_redirect( add_query_arg( 'mdai_rebuilt', $ok ? 'ok' : 'failed', self::preview_url( $post->ID ) ) );
		exit;
	}
}
