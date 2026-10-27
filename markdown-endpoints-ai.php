<?php
/**
 * Plugin Name:       Markdown Endpoints for AI
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Gives every post and page a clean Markdown version for AI agents and crawlers, at a .md URL or through an Accept: text/markdown header, with noindex and canonical headers so it never competes with your HTML pages.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       markdown-endpoints-ai
 * Update URI:        https://github.com/jmsprsns/markdown-endpoints-ai
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$markdown_endpoints_ai_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'MARKDOWN_ENDPOINTS_AI_VERSION', $markdown_endpoints_ai_headers['version'] );
define( 'MARKDOWN_ENDPOINTS_AI_REPO', $markdown_endpoints_ai_headers['repo'] );
define( 'MARKDOWN_ENDPOINTS_AI_FILE', __FILE__ );
define( 'MARKDOWN_ENDPOINTS_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'MARKDOWN_ENDPOINTS_AI_URL', plugin_dir_url( __FILE__ ) );

unset( $markdown_endpoints_ai_headers );

require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-icons.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-updater.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-menu.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-feedback.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-selector.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-converter.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-schema.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-seo.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-access.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-document.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-cache.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-agents.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-log.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-router.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-discovery.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-diagnostics.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-editor.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-admin.php';
require_once MARKDOWN_ENDPOINTS_AI_DIR . 'includes/class-markdown-endpoints-ai-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Markdown_Endpoints_AI_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Markdown_Endpoints_AI_Plugin
 */
function markdown_endpoints_ai_plugin() {
	return Markdown_Endpoints_AI_Plugin::instance();
}
add_action( 'plugins_loaded', 'markdown_endpoints_ai_plugin' );

/**
 * Create the tables and the cache folder, and schedule the daily clean-up.
 *
 * @param bool $network_wide Activated for the whole network.
 * @return void
 */
function markdown_endpoints_ai_activate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		// Each site sets itself up on its next request (see maybe_install()).
		return;
	}

	Markdown_Endpoints_AI_Plugin::install();
}
register_activation_hook( MARKDOWN_ENDPOINTS_AI_FILE, 'markdown_endpoints_ai_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings, the request log and the cached files stay put (uninstall.php
 * removes them). Scheduled jobs stop, and a section this plugin added to a
 * physical llms.txt is taken out again so it can't go stale.
 *
 * @return void
 */
function markdown_endpoints_ai_deactivate() {
	Markdown_Endpoints_AI_Plugin::deactivate();
}
register_deactivation_hook( MARKDOWN_ENDPOINTS_AI_FILE, 'markdown_endpoints_ai_deactivate' );
