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
	// Helpers
	// -------------------------------------------------------------------------

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
