<?php
/**
 * Plugin Name: WP Site Migrator
 * Description: Export and import a complete single-site WordPress install with database, media, themes, and plugins.
 * Version: 0.2.0
 * Author: Oninova
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wp-site-migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WSM_VERSION', '0.2.0' );
define( 'WSM_PLUGIN_FILE', __FILE__ );
define( 'WSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WSM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WSM_PLUGIN_DIR . 'includes/class-wsm-plugin.php';

register_activation_hook( __FILE__, array( 'WSM_Plugin', 'activate' ) );

WSM_Plugin::instance();
