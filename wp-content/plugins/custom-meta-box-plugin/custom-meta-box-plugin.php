<?php
/**
 * Plugin Name: Custom Meta Box Plugin
 * Plugin URI:  https://example.com
 * Description: Build your own custom fields (like ACF) from an admin screen — no code editing required. Create field groups, add fields (text, textarea, number, url, email, select, radio, checkbox, date, image), choose which post types they appear on, and they'll render automatically in the editor.
 * Version:     2.0.0
 * Author:      Your Name
 * License:     GPL v2 or later
 * Text Domain: custom-meta-box-plugin
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CMBP_VERSION', '2.0.0' );
define( 'CMBP_PLUGIN_FILE', __FILE__ );
define( 'CMBP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CMBP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CMBP_OPTION_KEY', 'cmbp_field_groups' );

require_once CMBP_PLUGIN_DIR . 'includes/class-cmbp-field-groups.php';
require_once CMBP_PLUGIN_DIR . 'includes/class-cmbp-admin.php';
require_once CMBP_PLUGIN_DIR . 'includes/class-cmbp-meta-box.php';

/**
 * Boot the plugin.
 */
function cmbp_init() {
	new CMBP_Admin();
	new CMBP_Meta_Box();
}
add_action( 'plugins_loaded', 'cmbp_init' );
