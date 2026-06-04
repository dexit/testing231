<?php
/**
 * Admin pages: Routes, Captures, Jobs, Logs.
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_pages' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_shared' ) );
		WRM_Mapping_Editor::init();
	}

	public static function enqueue_shared( string $hook ): void {
		$wrm_pages = array( 'toplevel_page_wrm-routes', 'webhook-router_page_wrm-captures', 'webhook-router_page_wrm-jobs', 'webhook-router_page_wrm-logs' );
		if ( ! in_array( $hook, $wrm_pages, true ) ) {
			return;
		}
		wp_enqueue_style( 'wrm-admin', WRM_PLUGIN_URL . 'assets/css/wrm-admin.css', array(), WRM_VERSION );

		$asset_file = WRM_PLUGIN_DIR . 'build/wrm-admin-app.asset.php';
		if ( file_exists( $asset_file ) ) {
			$asset = require $asset_file;
			wp_enqueue_script(
				'wrm-admin-app',
				WRM_PLUGIN_URL . 'build/wrm-admin-app.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
			$tab_map = array(
				'toplevel_page_wrm-routes'         => 'routes',
				'webhook-router_page_wrm-captures' => 'captures',
				'webhook-router_page_wrm-jobs'     => 'jobs',
				'webhook-router_page_wrm-logs'     => 'logs',
			);
			wp_localize_script(
				'wrm-admin-app',
				'wrmAdminData',
				array(
					'apiRoot'    => esc_url_raw( rest_url() ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'initialTab' => $tab_map[ $hook ] ?? 'routes',
					'restBase'   => rest_url( 'wrm/v1' ),
				)
			);
		}
	}

	public static function register_pages(): void {
		add_menu_page(
			__( 'Webhook Router', 'wrm' ),
			__( 'Webhook Router', 'wrm' ),
			'manage_options',
			'wrm-routes',
			array( __CLASS__, 'page_routes' ),
			'dashicons-rest-api',
			56
		);
		add_submenu_page( 'wrm-routes', __( 'Routes', 'wrm' ),   __( 'Routes', 'wrm' ),   'manage_options', 'wrm-routes',   array( __CLASS__, 'page_routes' ) );
		add_submenu_page( 'wrm-routes', __( 'Mappings', 'wrm' ), __( 'Mappings', 'wrm' ), 'manage_options', 'edit.php?post_type=wrm_mapping' );
		add_submenu_page( 'wrm-routes', __( 'Captures', 'wrm' ), __( 'Captures', 'wrm' ), 'manage_options', 'wrm-captures', array( __CLASS__, 'page_captures' ) );
		add_submenu_page( 'wrm-routes', __( 'Jobs', 'wrm' ),     __( 'Jobs', 'wrm' ),     'manage_options', 'wrm-jobs',     array( __CLASS__, 'page_jobs' ) );
		add_submenu_page( 'wrm-routes', __( 'Logs', 'wrm' ),     __( 'Logs', 'wrm' ),     'manage_options', 'wrm-logs',     array( __CLASS__, 'page_logs' ) );
	}

	// -------------------------------------------------------------------------
	// ROUTES PAGE
	// -------------------------------------------------------------------------

	public static function page_routes(): void {
		// Handle form actions (PHP fallback — React app handles this via REST normally)
		if ( isset( $_POST['wrm_routes_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wrm_routes_nonce'] ), 'wrm_routes_action' ) ) {
			self::handle_route_action();
		}
		?>
		<div class="wrap">
		<div id="wrm-admin-app-root"></div>
		</div>
		<?php
	}

	private static function handle_route_action(): void {
		$action = sanitize_key( $_POST['wrm_action'] ?? '' );
		$slug   = sanitize_title( $_POST['wrm_slug'] ?? '' );

		switch ( $action ) {
			case 'create':
				WRM_Router::insert_route( array(
					'slug'        => sanitize_title( $_POST['new_slug'] ?? '' ),
					'label'       => sanitize_text_field( $_POST['new_label'] ?? '' ),
					'methods'     => sanitize_text_field( $_POST['new_methods'] ?? 'POST' ),
					'provider'    => sanitize_key( $_POST['new_provider'] ?? 'custom' ),
					'auth_token'  => sanitize_text_field( $_POST['new_auth_token'] ?? '' ),
					'rate_limit'  => (int) ( $_POST['new_rate_limit'] ?? 0 ),
					'rate_window' => (int) ( $_POST['new_rate_window'] ?? 60 ),
					'run_mode'    => sanitize_key( $_POST['new_run_mode'] ?? 'async' ),
					'mapping_id'  => (int) ( $_POST['new_mapping_id'] ?? 0 ),
				) );
				wp_safe_redirect( add_query_arg( 'wrm_saved', '1', menu_page_url( 'wrm-routes', false ) ) );
				exit;

			case 'pause':
				WRM_Router::update_route( $slug, array( 'status' => 'paused' ) );
				WRM_Job_Queue::pause_route( $slug );
				wp_safe_redirect( menu_page_url( 'wrm-routes', false ) );
				exit;

			case 'resume':
				WRM_Router::update_route( $slug, array( 'status' => 'active' ) );
				WRM_Job_Queue::resume_route( $slug );
				wp_safe_redirect( menu_page_url( 'wrm-routes', false ) );
				exit;

			case 'delete':
				WRM_Router::delete_route( $slug );
				wp_safe_redirect( add_query_arg( 'wrm_deleted', '1', menu_page_url( 'wrm-routes', false ) ) );
				exit;
		}
	}

	// -------------------------------------------------------------------------
	// CAPTURES PAGE
	// -------------------------------------------------------------------------

	public static function page_captures(): void {
		?>
		<div class="wrap">
		<div id="wrm-admin-app-root"></div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// JOBS PAGE
	// -------------------------------------------------------------------------

	public static function page_jobs(): void {
		?>
		<div class="wrap">
		<div id="wrm-admin-app-root"></div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// LOGS PAGE
	// -------------------------------------------------------------------------

	public static function page_logs(): void {
		?>
		<div class="wrap">
		<div id="wrm-admin-app-root"></div>
		</div>
		<?php
	}
}
