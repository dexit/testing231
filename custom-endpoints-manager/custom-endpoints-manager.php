<?php
/**
 * Plugin Name:       Custom Endpoints Manager & Microplugins
 * Plugin URI:        https://example.com/custom-endpoints-manager
 * Description:       A robust, native WordPress solution for dynamically registering, securing, and managing custom REST API endpoints, now with integrated Microplugins for ultimate extensibility, specifically designed for WP 6.9+ and the WP REST API handbook.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            OWL
 * Author URI:        https://zoo.com/owl
 * License:           GPL v2 or later
 * Text Domain:       custom-endpoints-manager
 * Domain Path:       /languages
 *
 * @package Custom_Endpoints_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CEM_VERSION', '1.0.0' );
define( 'CEM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CEM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CEM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

define( 'MICROPLUGINS_DIR', CEM_PLUGIN_DIR . 'microplugins' );
define( 'MICROPLUGINS_CACHE_DIR', MICROPLUGINS_DIR . '/cache' );
define( 'MICROPLUGINS_URI', CEM_PLUGIN_URL . 'microplugins/' );

require_once CEM_PLUGIN_DIR . 'includes/class-custom-endpoints-manager.php';
require_once MICROPLUGINS_DIR . '/class-microplugins.php';

/**
 * Bootstrap the plugin.
 *
 * @since 1.0.0
 */
function run_custom_endpoints_manager() {
	$plugin = new Custom_Endpoints_Manager();
	$plugin->run();
	Microplugins::get_instance();
}
run_custom_endpoints_manager();

register_activation_hook( __FILE__, array( 'Microplugins', 'activation_hook' ) );
