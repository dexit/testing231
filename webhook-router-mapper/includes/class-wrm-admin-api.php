<?php
/**
 * Admin REST API — full CRUD over routes, mappings, captures, jobs, and logs.
 *
 * All endpoints sit under the wrm/v1 namespace and require the
 * manage_options capability.
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Admin_API {

	const string NAMESPACE = 'wrm/v1';

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$ns  = self::NAMESPACE;
		$cap = array( __CLASS__, 'require_manage_options' );

		// -----------------------------------------------------------------
		// Routes
		// -----------------------------------------------------------------
		register_rest_route(
			$ns,
			'/routes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_routes' ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_route' ),
					'permission_callback' => $cap,
				),
			)
		);

		register_rest_route(
			$ns,
			'/routes/(?P<slug>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_route' ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_route' ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_route' ),
					'permission_callback' => $cap,
				),
			)
		);

		register_rest_route(
			$ns,
			'/routes/(?P<slug>[a-z0-9_-]+)/pause',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'pause_route' ),
				'permission_callback' => $cap,
			)
		);

		register_rest_route(
			$ns,
			'/routes/(?P<slug>[a-z0-9_-]+)/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'resume_route' ),
				'permission_callback' => $cap,
			)
		);

		register_rest_route(
			$ns,
			'/routes/(?P<slug>[a-z0-9_-]+)/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_stats' ),
				'permission_callback' => $cap,
			)
		);

		// -----------------------------------------------------------------
		// Mappings
		// -----------------------------------------------------------------
		register_rest_route(
			$ns,
			'/mappings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_mappings' ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_mapping' ),
					'permission_callback' => $cap,
				),
			)
		);

		register_rest_route(
			$ns,
			'/mappings/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_mapping' ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_mapping' ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_mapping' ),
					'permission_callback' => $cap,
				),
			)
		);

		// -----------------------------------------------------------------
		// Captures
		// -----------------------------------------------------------------
		register_rest_route(
			$ns,
			'/captures',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_captures' ),
				'permission_callback' => $cap,
			)
		);

		register_rest_route(
			$ns,
			'/captures/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_capture' ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_capture' ),
					'permission_callback' => $cap,
				),
			)
		);

		register_rest_route(
			$ns,
			'/captures/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'apply_capture' ),
				'permission_callback' => $cap,
			)
		);

		register_rest_route(
			$ns,
			'/captures/(?P<id>\d+)/paths',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'capture_paths' ),
				'permission_callback' => $cap,
			)
		);

		register_rest_route(
			$ns,
			'/captures/(?P<id>\d+)/replay',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'replay_capture' ),
				'permission_callback' => $cap,
			)
		);

		register_rest_route(
			$ns,
			'/captures/(?P<id>\d+)/resubmit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'resubmit_capture' ),
				'permission_callback' => $cap,
			)
		);

		// -----------------------------------------------------------------
		// Jobs
		// -----------------------------------------------------------------
		register_rest_route(
			$ns,
			'/jobs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_jobs' ),
				'permission_callback' => $cap,
			)
		);

		register_rest_route(
			$ns,
			'/jobs/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'job_stats' ),
				'permission_callback' => $cap,
			)
		);

		register_rest_route(
			$ns,
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_job' ),
				'permission_callback' => $cap,
			)
		);

		register_rest_route(
			$ns,
			'/jobs/(?P<id>\d+)/retry',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'retry_job' ),
				'permission_callback' => $cap,
			)
		);

		// -----------------------------------------------------------------
		// Logs
		// -----------------------------------------------------------------
		register_rest_route(
			$ns,
			'/logs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_logs' ),
					'permission_callback' => $cap,
					'args'                => array(
						'level'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
						'context'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
						'per_page' => array( 'type' => 'integer', 'default' => 50 ),
						'offset'   => array( 'type' => 'integer', 'default' => 0 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'purge_logs' ),
					'permission_callback' => $cap,
				),
			)
		);

		// -----------------------------------------------------------------
		// Schedules (timer + URL trigger)
		// -----------------------------------------------------------------
		register_rest_route(
			$ns,
			'/schedules',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_schedules' ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_schedule' ),
					'permission_callback' => $cap,
				),
			)
		);
		register_rest_route(
			$ns,
			'/schedules/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_schedule' ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_schedule' ),
					'permission_callback' => $cap,
				),
			)
		);
		register_rest_route(
			$ns,
			'/schedules/(?P<id>\d+)/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'run_schedule' ),
				'permission_callback' => $cap,
			)
		);

		// Public token-triggered run — auth is the secret token, NOT manage_options.
		register_rest_route(
			$ns,
			'/trigger/(?P<token>[A-Za-z0-9]+)',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'trigger_schedule' ),
				'permission_callback' => '__return_true',
			)
		);

		// -----------------------------------------------------------------
		// Function / hook allowlists (PHP callback UI)
		// -----------------------------------------------------------------
		register_rest_route(
			$ns,
			'/functions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_functions' ),
				'permission_callback' => $cap,
			)
		);
		// Add (POST) and remove (DELETE ?name=) share the base path so namespaced
		// callback names with backslashes survive — they go in the query string,
		// not the URL path where backslashes are not routable.
		register_rest_route(
			$ns,
			'/functions/callbacks',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'add_callback' ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'remove_callback' ),
					'permission_callback' => $cap,
				),
			)
		);
		register_rest_route(
			$ns,
			'/functions/hooks',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'add_hook' ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'remove_hook' ),
					'permission_callback' => $cap,
				),
			)
		);

		// -----------------------------------------------------------------
		// Message tracking (admin views)
		// -----------------------------------------------------------------
		register_rest_route(
			$ns,
			'/messages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_messages' ),
				'permission_callback' => $cap,
			)
		);
		register_rest_route(
			$ns,
			'/messages/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'message_stats' ),
				'permission_callback' => $cap,
			)
		);
		register_rest_route(
			$ns,
			'/messages/(?P<id>\d+)/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'message_events' ),
				'permission_callback' => $cap,
			)
		);

		// Route metrics
		register_rest_route(
			$ns,
			'/routes/(?P<slug>[a-z0-9_-]+)/metrics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_metrics' ),
				'permission_callback' => $cap,
			)
		);

		// Rate usage
		register_rest_route(
			$ns,
			'/routes/(?P<slug>[a-z0-9_-]+)/rate-usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_rate_usage' ),
				'permission_callback' => $cap,
			)
		);

		// Log tail
		register_rest_route(
			$ns,
			'/logs/tail',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'tail_logs' ),
				'permission_callback' => $cap,
			)
		);

		// -----------------------------------------------------------------
		// Dashboard
		// -----------------------------------------------------------------
		register_rest_route(
			$ns,
			'/dashboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'dashboard' ),
				'permission_callback' => $cap,
			)
		);

		// -----------------------------------------------------------------
		// Public tracking endpoints (pixel / click / provider status)
		// -----------------------------------------------------------------
		register_rest_route(
			$ns,
			'/track/open/(?P<token>[A-Za-z0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'WRM_Tracking', 'handle_open' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/track/click/(?P<token>[A-Za-z0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'WRM_Tracking', 'handle_click' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/track/status/(?P<provider>[a-z0-9_]+)',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( 'WRM_Tracking', 'handle_status' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Message tracking handlers
	// -------------------------------------------------------------------------

	public static function list_messages( WP_REST_Request $req ): WP_REST_Response {
		$messages = WRM_Tracking::list_messages(
			array(
				'channel'  => sanitize_key( (string) ( $req->get_param( 'channel' ) ?? '' ) ),
				'status'   => sanitize_key( (string) ( $req->get_param( 'status' ) ?? '' ) ),
				'search'   => sanitize_text_field( (string) ( $req->get_param( 'search' ) ?? '' ) ),
				'per_page' => (int) ( $req->get_param( 'per_page' ) ?? 25 ),
				'offset'   => (int) ( $req->get_param( 'offset' ) ?? 0 ),
			)
		);
		return new WP_REST_Response( $messages, 200 );
	}

	public static function message_stats(): WP_REST_Response {
		return new WP_REST_Response( WRM_Tracking::stats(), 200 );
	}

	public static function message_events( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		return new WP_REST_Response( WRM_Tracking::get_events( $id ), 200 );
	}

	// -------------------------------------------------------------------------
	// Permission callback
	// -------------------------------------------------------------------------

	public static function require_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// Route handlers
	// -------------------------------------------------------------------------

	public static function list_routes( WP_REST_Request $req ): WP_REST_Response {
		$per_page = (int) ( $req->get_param( 'per_page' ) ?? 50 );
		$offset   = (int) ( $req->get_param( 'offset' )   ?? 0 );
		$routes   = WRM_Router::get_routes( max( 1, min( $per_page, 100 ) ), max( 0, $offset ) );
		return new WP_REST_Response( $routes, 200 );
	}

	public static function create_route( WP_REST_Request $req ): WP_REST_Response {
		$data = $req->get_json_params() ?: array();

		if ( empty( $data['slug'] ) ) {
			return new WP_REST_Response( array( 'error' => 'slug is required' ), 400 );
		}

		$data['ip_allowlist'] = sanitize_textarea_field( (string) ( $req->get_param( 'ip_allowlist' ) ?? '' ) );
		$data['ip_blocklist']  = sanitize_textarea_field( (string) ( $req->get_param( 'ip_blocklist' )  ?? '' ) );

		$id = WRM_Router::insert_route( $data );
		if ( ! $id ) {
			return new WP_REST_Response( array( 'error' => 'Failed to create route.' ), 500 );
		}

		WRM_Logger::info( 'admin', "Route created: {$data['slug']}.", array( 'ref_id' => $id ) );

		$route = WRM_Router::get_route_by_slug( sanitize_title( $data['slug'] ) );
		return new WP_REST_Response( $route, 201 );
	}

	public static function get_route( WP_REST_Request $req ): WP_REST_Response {
		$slug  = sanitize_title( $req->get_param( 'slug' ) );
		$route = WRM_Router::get_route_by_slug( $slug );

		if ( ! $route ) {
			return new WP_REST_Response( array( 'error' => 'Route not found.' ), 404 );
		}

		return new WP_REST_Response( $route, 200 );
	}

	public static function update_route( WP_REST_Request $req ): WP_REST_Response {
		$slug = sanitize_title( $req->get_param( 'slug' ) );
		$data = $req->get_json_params() ?: array();

		$existing = WRM_Router::get_route_by_slug( $slug );
		if ( ! $existing ) {
			return new WP_REST_Response( array( 'error' => 'Route not found.' ), 404 );
		}

		WRM_Router::update_route( $slug, $data );

		WRM_Logger::info( 'admin', "Route updated: {$slug}.", array( 'ref_id' => (int) $existing['id'] ) );

		return new WP_REST_Response( WRM_Router::get_route_by_slug( $slug ), 200 );
	}

	public static function delete_route( WP_REST_Request $req ): WP_REST_Response {
		$slug     = sanitize_title( $req->get_param( 'slug' ) );
		$existing = WRM_Router::get_route_by_slug( $slug );

		if ( ! $existing ) {
			return new WP_REST_Response( array( 'error' => 'Route not found.' ), 404 );
		}

		WRM_Router::delete_route( $slug );

		WRM_Logger::info( 'admin', "Route deleted: {$slug}.", array( 'ref_id' => (int) $existing['id'] ) );

		return new WP_REST_Response( array( 'deleted' => true, 'slug' => $slug ), 200 );
	}

	public static function pause_route( WP_REST_Request $req ): WP_REST_Response {
		$slug = sanitize_title( $req->get_param( 'slug' ) );

		$existing = WRM_Router::get_route_by_slug( $slug );
		if ( ! $existing ) {
			return new WP_REST_Response( array( 'error' => 'Route not found.' ), 404 );
		}

		// Pause the route record itself.
		WRM_Router::update_route( $slug, array( 'status' => 'paused' ) );

		// Suspend queued jobs.
		$jobs_paused = WRM_Job_Queue::pause_route( $slug );

		WRM_Logger::info( 'admin', "Route paused: {$slug}.", array( 'ref_id' => (int) $existing['id'], 'jobs_paused' => $jobs_paused ) );

		return new WP_REST_Response( array( 'paused' => true, 'slug' => $slug, 'jobs_paused' => $jobs_paused ), 200 );
	}

	public static function resume_route( WP_REST_Request $req ): WP_REST_Response {
		$slug = sanitize_title( $req->get_param( 'slug' ) );

		$existing = WRM_Router::get_route_by_slug( $slug );
		if ( ! $existing ) {
			return new WP_REST_Response( array( 'error' => 'Route not found.' ), 404 );
		}

		// Re-activate the route record.
		WRM_Router::update_route( $slug, array( 'status' => 'active' ) );

		// Resume suspended jobs.
		$jobs_resumed = WRM_Job_Queue::resume_route( $slug );

		WRM_Logger::info( 'admin', "Route resumed: {$slug}.", array( 'ref_id' => (int) $existing['id'], 'jobs_resumed' => $jobs_resumed ) );

		return new WP_REST_Response( array( 'resumed' => true, 'slug' => $slug, 'jobs_resumed' => $jobs_resumed ), 200 );
	}

	/**
	 * Return capture and job counts for a route.
	 */
	public static function route_stats( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;

		$slug = sanitize_title( $req->get_param( 'slug' ) );

		$existing = WRM_Router::get_route_by_slug( $slug );
		if ( ! $existing ) {
			return new WP_REST_Response( array( 'error' => 'Route not found.' ), 404 );
		}

		$captures_table = $wpdb->prefix . WRM_Installer::CAPTURES_TABLE;
		$jobs_table     = $wpdb->prefix . WRM_Installer::JOBS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$capture_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$captures_table} WHERE route_slug = %s", $slug )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$job_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE route_slug = %s", $slug )
		);

		// Per-status breakdown for jobs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$job_statuses_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS cnt FROM {$jobs_table} WHERE route_slug = %s GROUP BY status",
				$slug
			),
			ARRAY_A
		) ?? array();

		$job_by_status = array();
		foreach ( $job_statuses_raw as $row ) {
			$job_by_status[ $row['status'] ] = (int) $row['cnt'];
		}

		return new WP_REST_Response(
			array(
				'slug'          => $slug,
				'captures'      => $capture_count,
				'jobs'          => $job_count,
				'jobs_by_status' => $job_by_status,
			),
			200
		);
	}

	// -------------------------------------------------------------------------
	// Mapping handlers
	// -------------------------------------------------------------------------

	public static function list_mappings( WP_REST_Request $req ): WP_REST_Response {
		$per_page = (int) ( $req->get_param( 'per_page' ) ?? 50 );
		$offset   = (int) ( $req->get_param( 'offset' )   ?? 0 );

		$posts = get_posts(
			array(
				'post_type'      => 'wrm_mapping',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( $per_page, 100 ) ),
				'offset'         => max( 0, $offset ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$mappings = array_map( array( __CLASS__, 'format_mapping' ), $posts );

		return new WP_REST_Response( $mappings, 200 );
	}

	public static function create_mapping( WP_REST_Request $req ): WP_REST_Response {
		$data = $req->get_json_params() ?: array();

		if ( empty( $data['title'] ) ) {
			return new WP_REST_Response( array( 'error' => 'title is required' ), 400 );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'wrm_mapping',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( $data['title'] ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			WRM_Logger::wp_error( 'admin', $post_id );
			return new WP_REST_Response( array( 'error' => $post_id->get_error_message() ), 500 );
		}

		if ( isset( $data['config'] ) ) {
			update_post_meta( $post_id, 'wrm_config', wp_json_encode( $data['config'] ) );
		}

		if ( ! empty( $data['provider'] ) ) {
			wp_set_object_terms( $post_id, sanitize_key( $data['provider'] ), 'wrm_provider' );
		}

		WRM_Logger::info( 'admin', "Mapping created: {$data['title']}.", array( 'ref_id' => $post_id ) );

		$post = get_post( $post_id );
		return new WP_REST_Response( self::format_mapping( $post ), 201 );
	}

	public static function get_mapping( WP_REST_Request $req ): WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'wrm_mapping' !== $post->post_type ) {
			return new WP_REST_Response( array( 'error' => 'Mapping not found.' ), 404 );
		}

		return new WP_REST_Response( self::format_mapping( $post ), 200 );
	}

	public static function update_mapping( WP_REST_Request $req ): WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'wrm_mapping' !== $post->post_type ) {
			return new WP_REST_Response( array( 'error' => 'Mapping not found.' ), 404 );
		}

		$data    = $req->get_json_params() ?: array();
		$update  = array( 'ID' => $id );

		if ( ! empty( $data['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $data['title'] );
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			WRM_Logger::wp_error( 'admin', $result, array( 'ref_id' => $id ) );
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
		}

		if ( isset( $data['config'] ) ) {
			update_post_meta( $id, 'wrm_config', wp_json_encode( $data['config'] ) );
		}

		if ( isset( $data['provider'] ) ) {
			wp_set_object_terms( $id, sanitize_key( $data['provider'] ), 'wrm_provider' );
		}

		WRM_Logger::info( 'admin', "Mapping updated: #{$id}.", array( 'ref_id' => $id ) );

		return new WP_REST_Response( self::format_mapping( get_post( $id ) ), 200 );
	}

	public static function delete_mapping( WP_REST_Request $req ): WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'wrm_mapping' !== $post->post_type ) {
			return new WP_REST_Response( array( 'error' => 'Mapping not found.' ), 404 );
		}

		$result = wp_delete_post( $id, true );
		if ( ! $result ) {
			return new WP_REST_Response( array( 'error' => 'Failed to delete mapping.' ), 500 );
		}

		WRM_Logger::info( 'admin', "Mapping deleted: #{$id}.", array( 'ref_id' => $id ) );

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	// -------------------------------------------------------------------------
	// Capture handlers
	// -------------------------------------------------------------------------

	public static function list_captures( WP_REST_Request $req ): WP_REST_Response {
		$args = array(
			'per_page'   => (int) ( $req->get_param( 'per_page' ) ?? 30 ),
			'offset'     => (int) ( $req->get_param( 'offset' )   ?? 0 ),
			'route_slug' => sanitize_title( (string) ( $req->get_param( 'route_slug' ) ?? '' ) ),
			'provider'   => sanitize_key( (string) ( $req->get_param( 'provider' )   ?? '' ) ),
		);

		if ( '' !== ( $req->get_param( 'mapped' ) ?? '' ) ) {
			$args['mapped'] = (int) $req->get_param( 'mapped' );
		}

		$captures = WRM_Capture::list( array_filter( $args, static fn( $v ) => '' !== $v && null !== $v ) );
		return new WP_REST_Response( $captures, 200 );
	}

	public static function get_capture( WP_REST_Request $req ): WP_REST_Response {
		$id      = (int) $req->get_param( 'id' );
		$capture = WRM_Capture::get( $id );

		if ( ! $capture ) {
			return new WP_REST_Response( array( 'error' => 'Capture not found.' ), 404 );
		}

		return new WP_REST_Response( $capture, 200 );
	}

	public static function delete_capture( WP_REST_Request $req ): WP_REST_Response {
		$id      = (int) $req->get_param( 'id' );
		$capture = WRM_Capture::get( $id );

		if ( ! $capture ) {
			return new WP_REST_Response( array( 'error' => 'Capture not found.' ), 404 );
		}

		WRM_Capture::delete( $id );

		WRM_Logger::info( 'admin', "Capture deleted: #{$id}.", array( 'ref_id' => $id ) );

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	/**
	 * Manually apply a mapping to a capture.
	 * Expects JSON body: { "mapping_id": int }
	 */
	public static function apply_capture( WP_REST_Request $req ): WP_REST_Response {
		$id      = (int) $req->get_param( 'id' );
		$capture = WRM_Capture::get( $id );

		if ( ! $capture ) {
			return new WP_REST_Response( array( 'error' => 'Capture not found.' ), 404 );
		}

		$data       = $req->get_json_params() ?: array();
		$mapping_id = (int) ( $data['mapping_id'] ?? 0 );

		if ( ! $mapping_id ) {
			return new WP_REST_Response( array( 'error' => 'mapping_id is required' ), 400 );
		}

		$result = WRM_Mapper::apply( $id, $mapping_id );

		if ( ! empty( $result['success'] ) ) {
			WRM_Capture::mark_mapped( $id, wp_json_encode( $result ) );
			WRM_Logger::info( 'admin', "Capture #{$id} applied manually.", array( 'ref_id' => $id, 'mapping_id' => $mapping_id ) );
		}

		return new WP_REST_Response( $result, 200 );
	}

	public static function capture_paths( WP_REST_Request $req ): WP_REST_Response {
		$id    = (int) $req->get_param( 'id' );
		$paths = WRM_Capture::get_paths( $id );

		if ( null === $paths ) {
			return new WP_REST_Response( array( 'error' => 'Capture not found.' ), 404 );
		}

		return new WP_REST_Response( array( 'id' => $id, 'paths' => $paths ), 200 );
	}

	/**
	 * Async-replay a capture through a mapping via the job queue.
	 * Unlike /apply (synchronous), this enqueues a job and returns immediately.
	 * Expects JSON body: { "mapping_id": int }
	 */
	public static function replay_capture( WP_REST_Request $req ): WP_REST_Response {
		$id      = (int) $req->get_param( 'id' );
		$capture = WRM_Capture::get( $id );
		if ( ! $capture ) {
			return new WP_REST_Response( array( 'error' => 'Capture not found.' ), 404 );
		}

		$data       = $req->get_json_params() ?: array();
		$mapping_id = (int) ( $data['mapping_id'] ?? 0 );
		if ( ! $mapping_id ) {
			return new WP_REST_Response( array( 'error' => 'mapping_id is required.' ), 400 );
		}

		$job_id = WRM_Job_Queue::enqueue( $capture['route_slug'], $id, $mapping_id );
		WRM_Logger::info( 'admin', "Capture #{$id} replayed as job #{$job_id}.", array( 'ref_id' => $id, 'mapping_id' => $mapping_id ) );

		return new WP_REST_Response( array( 'job_id' => $job_id, 'capture_id' => $id, 'mapping_id' => $mapping_id ), 201 );
	}

	/**
	 * Store an edited payload as a new capture and enqueue it for async processing.
	 * Expects JSON body: { "mapping_id": int, "payload": object }
	 */
	public static function resubmit_capture( WP_REST_Request $req ): WP_REST_Response {
		$id      = (int) $req->get_param( 'id' );
		$original = WRM_Capture::get( $id );
		if ( ! $original ) {
			return new WP_REST_Response( array( 'error' => 'Capture not found.' ), 404 );
		}

		$data       = $req->get_json_params() ?: array();
		$mapping_id = (int) ( $data['mapping_id'] ?? 0 );
		$payload    = is_array( $data['payload'] ?? null ) ? $data['payload'] : array();

		if ( ! $mapping_id ) {
			return new WP_REST_Response( array( 'error' => 'mapping_id is required.' ), 400 );
		}

		// Store edited payload as a new capture so the original is preserved.
		$new_id = WRM_Capture::store_internal(
			$original['route_slug'],
			$original['provider'],
			$payload
		);

		if ( ! $new_id ) {
			return new WP_REST_Response( array( 'error' => 'Failed to store resubmit capture.' ), 500 );
		}

		$job_id = WRM_Job_Queue::enqueue( $original['route_slug'], $new_id, $mapping_id );
		WRM_Logger::info(
			'admin',
			"Capture #{$id} resubmitted (edited) as capture #{$new_id}, job #{$job_id}.",
			array( 'ref_id' => $new_id, 'original_id' => $id, 'mapping_id' => $mapping_id )
		);

		return new WP_REST_Response(
			array( 'job_id' => $job_id, 'new_capture_id' => $new_id, 'original_capture_id' => $id, 'mapping_id' => $mapping_id ),
			201
		);
	}

	// -------------------------------------------------------------------------
	// Job handlers
	// -------------------------------------------------------------------------

	public static function list_jobs( WP_REST_Request $req ): WP_REST_Response {
		$args = array(
			'per_page'   => (int) ( $req->get_param( 'per_page' )   ?? 20 ),
			'offset'     => (int) ( $req->get_param( 'offset' )     ?? 0 ),
			'status'     => sanitize_key( (string) ( $req->get_param( 'status' )     ?? '' ) ),
			'route_slug' => sanitize_title( (string) ( $req->get_param( 'route_slug' ) ?? '' ) ),
			'search'     => sanitize_text_field( (string) ( $req->get_param( 'search' ) ?? '' ) ),
		);

		$jobs = WRM_Job_Queue::get_jobs( array_filter( $args, static fn( $v ) => '' !== $v ) );
		return new WP_REST_Response( $jobs, 200 );
	}

	public static function get_job( WP_REST_Request $req ): WP_REST_Response {
		$id  = (int) $req->get_param( 'id' );
		$job = WRM_Job_Queue::get_job( $id );

		if ( ! $job ) {
			return new WP_REST_Response( array( 'error' => 'Job not found.' ), 404 );
		}

		return new WP_REST_Response( $job, 200 );
	}

	public static function retry_job( WP_REST_Request $req ): WP_REST_Response {
		$id  = (int) $req->get_param( 'id' );
		$job = WRM_Job_Queue::get_job( $id );

		if ( ! $job ) {
			return new WP_REST_Response( array( 'error' => 'Job not found.' ), 404 );
		}

		$success = WRM_Job_Queue::requeue( $id );

		if ( ! $success ) {
			return new WP_REST_Response( array( 'error' => 'Could not requeue job.' ), 409 );
		}

		WRM_Logger::info( 'admin', "Job #{$id} retried via admin API.", array( 'ref_id' => $id ) );

		return new WP_REST_Response( array( 'requeued' => true, 'id' => $id ), 200 );
	}

	/**
	 * Return global job counts grouped by status — used by the React status-summary cards.
	 */
	public static function job_stats(): WP_REST_Response {
		global $wpdb;

		$table = $wpdb->prefix . WRM_Installer::JOBS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status",
			ARRAY_A
		) ?? array();

		$counts = array_fill_keys( array( 'queued', 'running', 'done', 'failed', 'dead', 'paused' ), 0 );
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['cnt'];
		}
		$counts['total'] = array_sum( $counts );

		return new WP_REST_Response( $counts, 200 );
	}

	// -------------------------------------------------------------------------
	// Log handlers
	// -------------------------------------------------------------------------

	public static function list_logs( WP_REST_Request $req ): WP_REST_Response {
		$args = array(
			'level'    => sanitize_key( (string) ( $req->get_param( 'level' )   ?? '' ) ),
			'context'  => sanitize_key( (string) ( $req->get_param( 'context' ) ?? '' ) ),
			'per_page' => (int) ( $req->get_param( 'per_page' ) ?? 50 ),
			'offset'   => (int) ( $req->get_param( 'offset' )   ?? 0 ),
			'search'   => sanitize_text_field( (string) ( $req->get_param( 'search' ) ?? '' ) ),
		);

		$logs = WRM_Logger::get_logs( array_filter( $args, static fn( $v ) => '' !== $v && 0 !== $v ) );
		return new WP_REST_Response( $logs, 200 );
	}

	public static function purge_logs(): WP_REST_Response {
		$deleted = WRM_Logger::purge( 0 );
		WRM_Logger::info( 'admin', "All logs purged via API ({$deleted} rows).", array( 'ref_id' => 0 ) );
		return new WP_REST_Response( array( 'purged' => true, 'deleted' => $deleted ), 200 );
	}

	// -------------------------------------------------------------------------
	// Schedule handlers
	// -------------------------------------------------------------------------

	public static function list_schedules(): WP_REST_Response {
		$rows     = WRM_Scheduler::get_all();
		$base     = rest_url( self::NAMESPACE . '/trigger/' );
		$schedules = array_map(
			static function ( array $row ) use ( $base ): array {
				$row['trigger_url'] = $base . $row['trigger_token'];
				unset( $row['trigger_token'] ); // expose only via the full URL
				return $row;
			},
			$rows
		);
		return new WP_REST_Response( $schedules, 200 );
	}

	public static function create_schedule( WP_REST_Request $req ): WP_REST_Response {
		$data = $req->get_json_params() ?: array();
		$id   = WRM_Scheduler::create( $data );
		if ( ! $id ) {
			return new WP_REST_Response( array( 'error' => 'Failed to create schedule.' ), 500 );
		}
		WRM_Logger::info( 'admin', "Schedule #{$id} created.", array( 'ref_id' => $id ) );
		return new WP_REST_Response( WRM_Scheduler::get( $id ), 201 );
	}

	public static function update_schedule( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		if ( ! WRM_Scheduler::get( $id ) ) {
			return new WP_REST_Response( array( 'error' => 'Schedule not found.' ), 404 );
		}
		WRM_Scheduler::update( $id, $req->get_json_params() ?: array() );
		return new WP_REST_Response( WRM_Scheduler::get( $id ), 200 );
	}

	public static function delete_schedule( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		if ( ! WRM_Scheduler::get( $id ) ) {
			return new WP_REST_Response( array( 'error' => 'Schedule not found.' ), 404 );
		}
		WRM_Scheduler::delete( $id );
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	public static function run_schedule( WP_REST_Request $req ): WP_REST_Response {
		$id       = (int) $req->get_param( 'id' );
		$schedule = WRM_Scheduler::get( $id );
		if ( ! $schedule ) {
			return new WP_REST_Response( array( 'error' => 'Schedule not found.' ), 404 );
		}
		$result = WRM_Scheduler::run( $schedule, 'manual' );
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Public token-triggered schedule run. The secret token IS the auth.
	 *
	 * A not-found token and a paused schedule return an identical 404 so the
	 * endpoint cannot be used as an existence oracle, plus a light per-IP rate
	 * limit blunts brute-force attempts.
	 */
	public static function trigger_schedule( WP_REST_Request $req ): WP_REST_Response {
		$ip       = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$rl_key   = 'wrm_trigger_rl_' . md5( $ip );
		$hits     = (int) get_transient( $rl_key );
		if ( $hits >= 30 ) {
			return new WP_REST_Response( array( 'error' => 'Too many requests.' ), 429 );
		}
		set_transient( $rl_key, $hits + 1, 60 );

		$token    = (string) $req->get_param( 'token' );
		$schedule = WRM_Scheduler::get_by_token( $token );

		// Collapse "no such token" and "paused" into one indistinguishable 404.
		if ( ! $schedule || 'active' !== ( $schedule['status'] ?? '' ) ) {
			return new WP_REST_Response( array( 'error' => 'Not found.' ), 404 );
		}

		$result = WRM_Scheduler::run( $schedule, 'url' );
		return new WP_REST_Response( array( 'triggered' => true, 'result' => $result ), 200 );
	}

	// -------------------------------------------------------------------------
	// Function / hook allowlist handlers
	// -------------------------------------------------------------------------

	public static function list_functions(): WP_REST_Response {
		return new WP_REST_Response( WRM_Mapper::get_allowlists(), 200 );
	}

	public static function add_callback( WP_REST_Request $req ): WP_REST_Response {
		$data = $req->get_json_params() ?: array();
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );

		if ( '' === $name || ! preg_match( '/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $name ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid function name.' ), 400 );
		}
		if ( ! function_exists( $name ) ) {
			return new WP_REST_Response( array( 'error' => "Function '{$name}' does not exist on this site." ), 400 );
		}

		$list = (array) get_option( WRM_Mapper::OPT_CALLBACKS, array() );
		if ( ! in_array( $name, $list, true ) ) {
			$list[] = $name;
			update_option( WRM_Mapper::OPT_CALLBACKS, array_values( $list ) );
		}
		WRM_Mapper::flush_allowlist_cache();
		WRM_Logger::warning( 'admin', "Callback allow-listed via UI: {$name}", array( 'function' => $name ) );
		return new WP_REST_Response( WRM_Mapper::get_allowlists(), 201 );
	}

	public static function remove_callback( WP_REST_Request $req ): WP_REST_Response {
		$name = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$list = (array) get_option( WRM_Mapper::OPT_CALLBACKS, array() );
		if ( ! in_array( $name, $list, true ) ) {
			// Not a UI entry (may be code-registered, which is read-only) or unknown.
			return new WP_REST_Response( array( 'error' => 'Not a UI-managed callback.' ), 400 );
		}
		update_option( WRM_Mapper::OPT_CALLBACKS, array_values( array_filter( $list, static fn( $n ) => $n !== $name ) ) );
		WRM_Mapper::flush_allowlist_cache();
		WRM_Logger::info( 'admin', "Callback removed from allowlist: {$name}", array( 'function' => $name ) );
		return new WP_REST_Response( array( 'removed' => true, 'name' => $name ), 200 );
	}

	public static function add_hook( WP_REST_Request $req ): WP_REST_Response {
		$data = $req->get_json_params() ?: array();
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );

		if ( '' === $name || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid hook name.' ), 400 );
		}

		$list = (array) get_option( WRM_Mapper::OPT_HOOKS, array() );
		if ( ! in_array( $name, $list, true ) ) {
			$list[] = $name;
			update_option( WRM_Mapper::OPT_HOOKS, array_values( $list ) );
		}
		WRM_Mapper::flush_allowlist_cache();
		WRM_Logger::warning( 'admin', "Hook allow-listed via UI: {$name}", array( 'hook' => $name ) );
		return new WP_REST_Response( WRM_Mapper::get_allowlists(), 201 );
	}

	public static function remove_hook( WP_REST_Request $req ): WP_REST_Response {
		$name = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$list = (array) get_option( WRM_Mapper::OPT_HOOKS, array() );
		if ( ! in_array( $name, $list, true ) ) {
			return new WP_REST_Response( array( 'error' => 'Not a UI-managed hook.' ), 400 );
		}
		update_option( WRM_Mapper::OPT_HOOKS, array_values( array_filter( $list, static fn( $n ) => $n !== $name ) ) );
		WRM_Mapper::flush_allowlist_cache();
		WRM_Logger::info( 'admin', "Hook removed from allowlist: {$name}", array( 'hook' => $name ) );
		return new WP_REST_Response( array( 'removed' => true, 'name' => $name ), 200 );
	}

	// -------------------------------------------------------------------------
	// Metrics / rate-usage / log-tail handlers
	// -------------------------------------------------------------------------

	public static function route_metrics( WP_REST_Request $req ): WP_REST_Response {
		$slug  = sanitize_title( $req->get_param( 'slug' ) );
		$hours = max( 1, min( 168, (int) ( $req->get_param( 'hours' ) ?? 24 ) ) );
		return new WP_REST_Response( array(
			'route_slug' => $slug,
			'hours'      => $hours,
			'buckets'    => WRM_Metrics::get_hourly( $slug, $hours ),
		), 200 );
	}

	public static function route_rate_usage( WP_REST_Request $req ): WP_REST_Response {
		$slug = sanitize_title( $req->get_param( 'slug' ) );
		$data = WRM_Router::get_rate_usage( $slug );
		if ( empty( $data ) ) {
			return new WP_REST_Response( array( 'error' => 'route_not_found' ), 404 );
		}
		return new WP_REST_Response( $data, 200 );
	}

	public static function tail_logs( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$table    = $wpdb->prefix . 'wrm_logs';
		$since_id = max( 0, (int) ( $req->get_param( 'since_id' ) ?? 0 ) );
		$limit    = max( 1, min( 200, (int) ( $req->get_param( 'limit' ) ?? 50 ) ) );
		$level    = sanitize_key( $req->get_param( 'level' ) ?? '' );

		$where  = 'id > %d';
		$params = array( $since_id );

		if ( $level ) {
			$where   .= ' AND level = %s';
			$params[] = $level;
		}
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d",
				...$params
			),
			ARRAY_A
		) ?? array();

		return new WP_REST_Response( $rows, 200 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Dashboard
	// -------------------------------------------------------------------------

	public static function dashboard(): WP_REST_Response {
		global $wpdb;

		$captures_table = $wpdb->prefix . WRM_Installer::CAPTURES_TABLE;
		$jobs_table     = $wpdb->prefix . WRM_Installer::JOBS_TABLE;
		$msgs_table     = $wpdb->prefix . WRM_Installer::MESSAGES_TABLE;
		$logs_table     = $wpdb->prefix . 'wrm_logs';

		$today_start = current_time( 'Y-m-d' ) . ' 00:00:00';

		// --- aggregate stats ---
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$routes_table  = $wpdb->prefix . WRM_Installer::ROUTES_TABLE;
		$active_routes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$routes_table} WHERE status = 'active'" );

		$captures_today = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$captures_table} WHERE created_at >= %s", $today_start )
		);

		$job_rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS cnt FROM {$jobs_table} GROUP BY status",
			ARRAY_A
		) ?? array();
		$job_counts = array_fill_keys( array( 'queued', 'running', 'done', 'failed', 'dead', 'paused' ), 0 );
		foreach ( $job_rows as $r ) {
			if ( isset( $job_counts[ $r['status'] ] ) ) {
				$job_counts[ $r['status'] ] = (int) $r['cnt'];
			}
		}

		$messages_today = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$msgs_table} WHERE created_at >= %s", $today_start )
		);

		$error_today = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$logs_table} WHERE level IN ('error','exception') AND created_at >= %s",
				$today_start
			)
		);

		// --- recent failures ---
		$recent_failures = $wpdb->get_results(
			"SELECT id, route_slug, error_message, queued_at FROM {$jobs_table} WHERE status IN ('failed','dead') ORDER BY id DESC LIMIT 5",
			ARRAY_A
		) ?? array();

		// --- recent errors ---
		$recent_errors = $wpdb->get_results(
			"SELECT id, context, message, created_at FROM {$logs_table} WHERE level IN ('error','exception') ORDER BY id DESC LIMIT 5",
			ARRAY_A
		) ?? array();

		// --- pipeline view ---
		$all_routes = $wpdb->get_results( "SELECT * FROM {$routes_table} ORDER BY id ASC", ARRAY_A ) ?? array();
		// phpcs:enable

		$pipelines = array();
		foreach ( $all_routes as $route ) {
			$mapping_id    = (int) ( $route['mapping_id'] ?? 0 );
			$mapping_title = null;
			$chain_types   = array();

			if ( $mapping_id ) {
				$post = get_post( $mapping_id );
				if ( $post && 'wrm_mapping' === $post->post_type ) {
					$mapping_title = $post->post_title;
					$raw           = get_post_meta( $mapping_id, 'wrm_config', true );
					if ( $raw && json_validate( $raw ) ) {
						$cfg    = json_decode( $raw, true );
						$chains = $cfg['chains'] ?? array();
						foreach ( $chains as $chain ) {
							$type = sanitize_key( $chain['type'] ?? '' );
							if ( $type && ! in_array( $type, $chain_types, true ) ) {
								$chain_types[] = $type;
							}
						}
					}
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$captures_ct = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$captures_table} WHERE route_slug = %s AND created_at >= %s",
					$route['slug'],
					$today_start
				)
			);

			$pipelines[] = array(
				'route'          => $route,
				'mapping_id'     => $mapping_id ?: null,
				'mapping_title'  => $mapping_title,
				'chain_types'    => $chain_types,
				'captures_today' => $captures_ct,
				'metrics_24h'    => WRM_Metrics::get_hourly( $route['slug'], 24 ),
			);
		}

		return new WP_REST_Response(
			array(
				'stats'           => array(
					'active_routes'       => $active_routes,
					'captures_today'      => $captures_today,
					'jobs'                => $job_counts,
					'messages_today'      => $messages_today,
					'errors_today'        => $error_today,
					'sig_verified_today'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$captures_table} WHERE sig_status = 'verified' AND created_at >= %s", $today_start ) ),
					'sig_failed_today'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$captures_table} WHERE sig_status = 'failed' AND created_at >= %s", $today_start ) ),
				),
				'pipelines'       => $pipelines,
				'recent_failures' => $recent_failures,
				'recent_errors'   => $recent_errors,
			),
			200
		);
	}

	/**
	 * Format a wrm_mapping post into a consistent API shape.
	 *
	 * @param WP_Post|null $post The mapping post object.
	 * @return array
	 */
	private static function format_mapping( ?WP_Post $post ): array {
		if ( ! $post ) {
			return array();
		}

		$raw_config = get_post_meta( $post->ID, 'wrm_config', true );
		$config     = ( $raw_config && json_validate( $raw_config ) )
			? ( json_decode( $raw_config, true ) ?? array() )
			: array();

		$terms    = get_the_terms( $post->ID, 'wrm_provider' );
		$provider = ( ! empty( $terms ) && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';

		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'config'   => is_array( $config ) ? $config : array(),
			'provider' => $provider,
			'modified' => $post->post_modified,
		);
	}
}
