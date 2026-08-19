<?php
/**
 * Plugin Name: Core Cloudflare
 * Description: Cloudflare zone management and cache purge for WordPress.
 * Version: 1.0.0
 * Author: Core Cloudflare
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$core_cloudflare_dir = __DIR__ . '/core-cloudflare/';

define('CORE_CLOUDFLARE_VERSION', '1.0.0');
define('CORE_CLOUDFLARE_DIR', $core_cloudflare_dir);
define('CORE_CLOUDFLARE_URL', content_url('mu-plugins/core-cloudflare/'));
define('CORE_CLOUDFLARE_INCLUDES_DIR', CORE_CLOUDFLARE_DIR . 'includes/');
define('CORE_CLOUDFLARE_LOG_DIR', CORE_CLOUDFLARE_DIR . 'logs/');
define('CORE_CLOUDFLARE_CAPABILITY', is_multisite() ? 'manage_network' : 'manage_options');

require_once CORE_CLOUDFLARE_INCLUDES_DIR . 'class-loader.php';
\Core_Cloudflare\Loader::instance()->boot();
