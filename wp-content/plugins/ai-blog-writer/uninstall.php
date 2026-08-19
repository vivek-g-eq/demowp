<?php
/**
 * Fired when the plugin is deleted via the WordPress admin.
 *
 * Removes plugin options and post meta added by this plugin.
 * Does NOT delete any generated posts, only the plugin's own metadata.
 *
 * @package AI_Blog_Writer
 */

// Exit if accessed directly, or not triggered by WP's uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'aibw_settings' );

global $wpdb;
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_aibw_generated' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
