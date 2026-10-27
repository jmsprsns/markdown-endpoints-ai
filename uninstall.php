<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * Settings, the request log tables, the per-post meta, the cached Markdown
 * files, queued jobs, and the section this plugin added to a real llms.txt.
 * On multisite, every site's data.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove one site's data.
 *
 * @return void
 */
function markdown_endpoints_ai_uninstall_site() {
	global $wpdb;

	$llms = get_option( 'markdown_endpoints_ai_settings' );
	if ( is_array( $llms ) && isset( $llms['llms'] ) && 'file' === $llms['llms'] ) {
		markdown_endpoints_ai_uninstall_llms();
	}

	foreach ( array( 'settings', 'db_version', 'cache_version', 'cache_key', 'rebuild', 'warm_error', 'check', 'llms_file' ) as $option ) {
		delete_option( 'markdown_endpoints_ai_' . $option );
	}
	delete_transient( 'markdown_endpoints_ai_llms' );
	delete_transient( 'markdown_endpoints_ai_sitemap' );

	foreach ( array( '_mdai_cache', '_mdai_md_bytes', '_mdai_html_bytes', '_mdai_exclude', '_mdai_signal_search', '_mdai_signal_input', '_mdai_signal_train' ) as $key ) {
		delete_post_meta_by_key( $key );
	}

	foreach ( array( 'mdai_log', 'mdai_daily', 'mdai_rate' ) as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table names.
	}

	foreach ( array( 'markdown_endpoints_ai_daily', 'markdown_endpoints_ai_warm', 'markdown_endpoints_ai_rebuild', 'markdown_endpoints_ai_llms_file' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'markdown-endpoints-ai' );
	}

	markdown_endpoints_ai_uninstall_files();

	// Plugin Update Checker's cached update state.
	delete_site_option( 'external_updates-markdown-endpoints-ai' );
}

/**
 * Delete the cached Markdown files: only uploads/markdown-endpoints-ai.
 *
 * @return void
 */
function markdown_endpoints_ai_uninstall_files() {
	$uploads = wp_upload_dir( null, false );
	$base    = realpath( $uploads['basedir'] );
	$dir     = $base ? realpath( $base . '/markdown-endpoints-ai' ) : false;

	if ( ! $dir || 0 !== strpos( $dir, $base . DIRECTORY_SEPARATOR ) || 'markdown-endpoints-ai' !== basename( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isLink() || $item->isFile() ) {
			wp_delete_file( $item->getPathname() );
		} elseif ( $item->isDir() ) {
			rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/**
 * Take this plugin's section out of a real llms.txt, and delete the file if
 * nothing else is in it.
 *
 * @return void
 */
function markdown_endpoints_ai_uninstall_llms() {
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$path  = trailingslashit( get_home_path() ) . 'llms.txt';
	$begin = '<!-- BEGIN Markdown Endpoints for AI -->';
	$end   = '<!-- END Markdown Endpoints for AI -->';

	if ( ! is_readable( $path ) || ! wp_is_writable( $path ) ) {
		return;
	}

	$text  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$start = strpos( $text, $begin );
	$stop  = strpos( $text, $end );

	if ( false === $start || false === $stop || $stop < $start ) {
		return;
	}

	$rest = trim( substr( $text, 0, $start ) . substr( $text, $stop + strlen( $end ) ) );

	if ( '' === $rest ) {
		wp_delete_file( $path );
	} else {
		file_put_contents( $path, $rest . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 10000,
		)
	) as $markdown_endpoints_ai_site ) {
		switch_to_blog( (int) $markdown_endpoints_ai_site );
		markdown_endpoints_ai_uninstall_site();
		restore_current_blog();
	}
} else {
	markdown_endpoints_ai_uninstall_site();
}
