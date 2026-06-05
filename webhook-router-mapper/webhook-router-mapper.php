<?php
/**
 * Plugin Name:       Webhook Router & Mapper
 * Plugin URI:        https://example.com/webhook-router-mapper
 * Description:       Register dynamic REST webhook endpoints, normalize multi-provider payloads (Twilio, HubSpot, WhatsApp, custom), map to CPT/meta/taxonomy via visual builder, chain actions, and async queue with rate-limiting and retry.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Author:            OWL
 * License:           GPL v2 or later
 * Text Domain:       wrm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WRM_VERSION', '1.0.0' );
define( 'WRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WRM_DB_VERSION', '1.0' );

spl_autoload_register( static function ( string $class ): void {
	$map = array(
		'WRM_Installer'      => 'includes/class-wrm-installer.php',
		'WRM_Logger'         => 'includes/class-wrm-logger.php',
		'WRM_Router'         => 'includes/class-wrm-router.php',
		'WRM_Capture'        => 'includes/class-wrm-capture.php',
		'WRM_Merge_Tags'     => 'includes/class-wrm-merge-tags.php',
		'WRM_Mapper'         => 'includes/class-wrm-mapper.php',
		'WRM_Job_Queue'      => 'includes/class-wrm-job-queue.php',
		'WRM_Providers'      => 'includes/class-wrm-providers.php',
		'WRM_Admin_API'      => 'includes/class-wrm-admin-api.php',
		'WRM_Admin'          => 'admin/class-wrm-admin.php',
		'WRM_Mapping_Editor' => 'admin/class-wrm-mapping-editor.php',
	);
	if ( isset( $map[ $class ] ) ) {
		require_once WRM_PLUGIN_DIR . $map[ $class ];
	}
} );

register_activation_hook( __FILE__, array( 'WRM_Installer', 'install' ) );
register_deactivation_hook( __FILE__, array( 'WRM_Job_Queue', 'deactivate' ) );

add_action( 'plugins_loaded', static function (): void {
	WRM_Installer::register_cpt_and_tax();
	WRM_Router::init();
	WRM_Job_Queue::init();
	WRM_Admin_API::init();
	if ( is_admin() ) {
		WRM_Admin::init();
	}
} );
