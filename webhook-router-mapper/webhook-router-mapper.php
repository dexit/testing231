<?php
/**
 * Plugin Name:       Webhook Router & Mapper
 * Plugin URI:        https://github.com/dexit/testing231
 * Description:       Register dynamic REST webhook endpoints, normalize multi-provider payloads (Twilio, HubSpot, WhatsApp, custom), map to CPT/meta/taxonomy via visual builder, chain actions, and async queue with rate-limiting and retry.
 * Version:           1.3.0
 * Requires at least: 6.4
 * Tested up to:      6.8
 * Requires PHP:      8.1
 * Author:            OWL
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wrm
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WRM_VERSION', '1.3.0' );
define( 'WRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WRM_DB_VERSION', '1.3' );

require_once WRM_PLUGIN_DIR . 'includes/class-wrm-demo.php';

spl_autoload_register( static function ( string $class ): void {
	$map = array(
		'WRM_Installer'      => 'includes/class-wrm-installer.php',
		'WRM_Logger'         => 'includes/class-wrm-logger.php',
		'WRM_Router'         => 'includes/class-wrm-router.php',
		'WRM_Capture'        => 'includes/class-wrm-capture.php',
		'WRM_Merge_Tags'     => 'includes/class-wrm-merge-tags.php',
		'WRM_Mapper'         => 'includes/class-wrm-mapper.php',
		'WRM_Job_Queue'      => 'includes/class-wrm-job-queue.php',
		'WRM_Scheduler'      => 'includes/class-wrm-scheduler.php',
		'WRM_Providers'      => 'includes/class-wrm-providers.php',
		'WRM_Messaging'      => 'includes/class-wrm-messaging.php',
		'WRM_MJML'           => 'includes/class-wrm-mjml.php',
		'WRM_Tracking'       => 'includes/class-wrm-tracking.php',
		'WRM_Admin_API'      => 'includes/class-wrm-admin-api.php',
		'WRM_Metrics'        => 'includes/class-wrm-metrics.php',
		'WRM_Elementor'      => 'includes/class-wrm-elementor.php',
		'WRM_Admin'          => 'admin/class-wrm-admin.php',
		'WRM_Mapping_Editor' => 'admin/class-wrm-mapping-editor.php',
	);
	if ( isset( $map[ $class ] ) ) {
		require_once WRM_PLUGIN_DIR . $map[ $class ];
	}
} );

register_activation_hook( __FILE__, array( 'WRM_Installer', 'install' ) );
register_deactivation_hook( __FILE__, static function (): void {
	WRM_Job_Queue::deactivate();
	WRM_Scheduler::deactivate();
} );

add_action( 'plugins_loaded', static function (): void {
	WRM_Installer::maybe_upgrade();
	WRM_Installer::register_cpt_and_tax();
	WRM_Router::init();
	WRM_Job_Queue::init();
	WRM_Scheduler::init();
	WRM_Admin_API::init();
	WRM_Elementor::init();
	if ( is_admin() ) {
		WRM_Admin::init();
	}
} );
