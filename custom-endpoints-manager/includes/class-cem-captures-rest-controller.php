<?php
/**
 * CEM Captures REST Controller — CRUD + apply-mapping endpoints.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */

/**
 * Registers /cem/v1/captures/* REST routes.
 *
 * @since 1.2.0
 */
class CEM_Captures_REST_Controller {

	/**
	 * Register all captures-related REST routes.
	 *
	 * @since 1.2.0
	 */
	public function register_routes() {
		$auth = function () {
			return current_user_can( 'manage_options' );
		};

		// List captures (GET /cem/v1/captures).
		register_rest_route(
			'cem/v1',
			'/captures',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_captures' ),
				'permission_callback' => $auth,
				'args'                => array(
					'endpoint_slug' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_title',
					),
					'per_page'      => array(
						'type'    => 'integer',
						'default' => 30,
					),
					'offset'        => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);

		// Bulk delete captures for an endpoint (DELETE /cem/v1/captures?endpoint_slug=X).
		register_rest_route(
			'cem/v1',
			'/captures',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'bulk_delete' ),
				'permission_callback' => $auth,
				'args'                => array(
					'endpoint_slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);

		// Get single capture (GET /cem/v1/captures/{id}).
		register_rest_route(
			'cem/v1',
			'/captures/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_capture' ),
				'permission_callback' => $auth,
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		// Delete single capture (DELETE /cem/v1/captures/{id}).
		register_rest_route(
			'cem/v1',
			'/captures/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_capture' ),
				'permission_callback' => $auth,
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		// Apply mapping to a capture (POST /cem/v1/captures/{id}/apply).
		register_rest_route(
			'cem/v1',
			'/captures/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_mapping' ),
				'permission_callback' => $auth,
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		// Get detected paths from latest capture for an endpoint.
		register_rest_route(
			'cem/v1',
			'/captures/paths',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_paths' ),
				'permission_callback' => $auth,
				'args'                => array(
					'endpoint_slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);
	}

	/**
	 * List captures.
	 *
	 * @since 1.2.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function list_captures( WP_REST_Request $request ): WP_REST_Response {
		$slug     = $request->get_param( 'endpoint_slug' );
		$per_page = min( (int) $request->get_param( 'per_page' ), 100 );
		$offset   = max( 0, (int) $request->get_param( 'offset' ) );

		$captures = CEM_Data_Capture::get_captures(
			array(
				'endpoint_slug' => $slug ?: '',
				'per_page'      => $per_page,
				'offset'        => $offset,
			)
		);

		// Parse payload for lightweight preview (first 200 chars).
		$captures_len = count( $captures );
		for ( $i = 0; $i < $captures_len; $i++ ) {
			$decoded                   = json_decode( $captures[ $i ]['payload'], true );
			$captures[ $i ]['preview'] = is_array( $decoded )
				? substr( wp_json_encode( $decoded ), 0, 200 )
				: '';
		}

		return new WP_REST_Response(
			array(
				'captures' => $captures,
				'total'    => CEM_Data_Capture::count_captures( $slug ?: '' ),
			),
			200
		);
	}

	/**
	 * Get a single capture.
	 *
	 * @since 1.2.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_capture( WP_REST_Request $request ) {
		$capture = CEM_Data_Capture::get_capture( (int) $request->get_param( 'id' ) );
		if ( ! $capture ) {
			return new WP_Error( 'not_found', __( 'Capture not found.', 'custom-endpoints-manager' ), array( 'status' => 404 ) );
		}

		// Decode payload for the response.
		$capture['payload_data'] = json_decode( $capture['payload'], true ) ?: array();

		return new WP_REST_Response( $capture, 200 );
	}

	/**
	 * Delete a single capture.
	 *
	 * @since 1.2.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_capture( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! CEM_Data_Capture::get_capture( $id ) ) {
			return new WP_Error( 'not_found', __( 'Capture not found.', 'custom-endpoints-manager' ), array( 'status' => 404 ) );
		}

		$ok = CEM_Data_Capture::delete_capture( $id );
		return new WP_REST_Response( array( 'deleted' => $ok ), $ok ? 200 : 500 );
	}

	/**
	 * Bulk delete captures for an endpoint.
	 *
	 * @since 1.2.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function bulk_delete( WP_REST_Request $request ): WP_REST_Response {
		$slug    = $request->get_param( 'endpoint_slug' );
		$deleted = CEM_Data_Capture::delete_for_endpoint( $slug );
		return new WP_REST_Response( array( 'deleted' => $deleted ), 200 );
	}

	/**
	 * Apply the saved endpoint mapping to a specific capture.
	 *
	 * @since 1.2.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_mapping( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$capture = CEM_Data_Capture::get_capture( $id );
		if ( ! $capture ) {
			return new WP_Error( 'not_found', __( 'Capture not found.', 'custom-endpoints-manager' ), array( 'status' => 404 ) );
		}

		$mappings = get_option( 'cem_endpoint_mappings', array() );
		$slug     = $capture['endpoint_slug'];

		if ( empty( $mappings[ $slug ] ) ) {
			return new WP_Error(
				'no_mapping',
				/* translators: %s: endpoint slug */
				sprintf( __( 'No mapping configured for endpoint "%s".', 'custom-endpoints-manager' ), $slug ),
				array( 'status' => 422 )
			);
		}

		$result = CEM_Mapping_Engine::apply( $id, $mappings[ $slug ] );

		if ( ! $result['success'] ) {
			return new WP_Error( 'mapping_failed', $result['error'] ?? 'Unknown error.', array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Return the detected JSON paths from the latest capture for an endpoint.
	 *
	 * @since 1.2.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_paths( WP_REST_Request $request ): WP_REST_Response {
		$slug  = $request->get_param( 'endpoint_slug' );
		$paths = CEM_Data_Capture::get_detected_paths( $slug );
		return new WP_REST_Response( array( 'paths' => $paths ), 200 );
	}
}
