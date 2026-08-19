<?php
/**
 * Plugin Name:       AI Blog Writer
 * Plugin URI:        https://example.com/ai-blog-writer
 * Description:       Generate SEO-friendly blog posts using an AI (OpenAI-compatible) API directly from your WordPress dashboard.
 * Version:           1.0.0
 * Author:            Your Name
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-blog-writer
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 *
 * @package AI_Blog_Writer
 */

defined( 'ABSPATH' ) || exit; // Block direct access.

/**
 * Plugin constants.
 */
define( 'AIBW_VERSION', '1.0.0' );
define( 'AIBW_PLUGIN_FILE', __FILE__ );
define( 'AIBW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIBW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIBW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load required class files.
 *
 * Order matters: settings and helpers first, core orchestrator last.
 */
require_once AIBW_PLUGIN_DIR . 'includes/class-aibw-settings.php';
require_once AIBW_PLUGIN_DIR . 'includes/class-aibw-api-handler.php';
require_once AIBW_PLUGIN_DIR . 'includes/class-aibw-post-generator.php';
require_once AIBW_PLUGIN_DIR . 'includes/class-aibw-admin-page.php';
require_once AIBW_PLUGIN_DIR . 'includes/class-aibw-core.php';

/**
 * Boot the plugin once all plugins are loaded.
 *
 * @return AIBW_Core
 */
function aibw_init() {
	return AIBW_Core::get_instance();
}
add_action( 'plugins_loaded', 'aibw_init' );

/**
 * Runs once on plugin activation. Sets sane defaults.
 */
function aibw_activate() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	if ( false === get_option( 'aibw_settings' ) ) {
		add_option(
			'aibw_settings',
			array(
				'api_key'      => '',
				'api_endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
				'api_model'    => 'llama-3.3-70b-versatile',
				'default_tone' => 'professional',
			)
		);
	}
}
register_activation_hook( __FILE__, 'aibw_activate' );

/**
 * Runs on plugin deactivation. Options are intentionally kept
 * (they are only removed via uninstall.php on deletion).
 */
function aibw_deactivate() {
	// Nothing to clean up on simple deactivation.
}
register_deactivation_hook( __FILE__, 'aibw_deactivate' );
