<?php
/**
 * The REST API functionality of the plugin.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */
class Custom_Endpoints_Manager_REST_Controller {

    private $plugin_name;
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
    }

    public function register_routes() {
        register_rest_route( 'cem/v1', '/functions/library', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_function_library' ),
            'permission_callback' => function() { return current_user_can( 'read' ); },
        ) );

        register_rest_route( 'cem/v1', '/jobs', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'list_jobs' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ) );

        register_rest_route( 'cem/v1', '/jobs/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_job' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'args'                => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
        ) );

        register_rest_route( 'cem/v1', '/jobs/(?P<id>\d+)/retry', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'retry_job' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'args'                => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
        ) );

        $custom_endpoints = get_option( 'cem_custom_endpoints', array() );

        if ( empty( $custom_endpoints ) || ! is_array( $custom_endpoints ) ) {
            return;
        }

        foreach ( $custom_endpoints as $endpoint ) {
            if ( empty( $endpoint['slug'] ) || empty( $endpoint['methods'] ) || empty( $endpoint['microplugin_id'] ) ) {
                continue;
            }

            $args = array(
                'methods'             => sanitize_text_field( $endpoint['methods'] ),
                'callback'            => array( $this, 'handle_custom_endpoint_request' ),
                'permission_callback' => array( $this, 'check_endpoint_permissions' ),
            );

            if ( ! empty( $endpoint['args'] ) ) {
                $args['args'] = $this->parse_endpoint_args( $endpoint['args'] );
            }

            register_rest_route( 'cem/v1', '/' . sanitize_title( $endpoint['slug'] ), $args );
        }
    }

    public function handle_custom_endpoint_request( WP_REST_Request $request ) {
        $route         = $request->get_route();
        $endpoint_slug = basename( $route );

        $custom_endpoints = get_option( 'cem_custom_endpoints', array() );
        $target_endpoint  = null;

        foreach ( $custom_endpoints as $endpoint ) {
            if ( sanitize_title( $endpoint['slug'] ) === $endpoint_slug ) {
                $target_endpoint = $endpoint;
                break;
            }
        }

        if ( ! $target_endpoint || empty( $target_endpoint['microplugin_id'] ) ) {
            return new WP_Error(
                'endpoint_not_found',
                __( 'Custom endpoint configuration or microplugin ID not found.', 'custom-endpoints-manager' ),
                array( 'status' => 404 )
            );
        }

        $microplugin_post_id    = intval( $target_endpoint['microplugin_id'] );
        $microplugin_cache_file = Microplugins::get_microplugin_cache_file( $microplugin_post_id );

        if ( ! $microplugin_cache_file ) {
            return new WP_Error(
                'microplugin_not_found',
                __( 'Microplugin file not found or not published.', 'custom-endpoints-manager' ),
                array( 'status' => 500 )
            );
        }

        $endpoint_slug          = sanitize_title( $target_endpoint['slug'] );
        $raw_code               = file_get_contents( $microplugin_cache_file );
        $callback_function_name = 'cem_microplugin_callback_' . $microplugin_post_id;

        $payload = array(
            'query' => $request->get_query_params(),
            'body'  => $request->get_body_params(),
            'json'  => json_decode( $request->get_body(), true ),
        );

        // ── Async mode ───────────────────────────────────────────────────────
        if ( ! empty( $target_endpoint['async'] ) ) {
            $max_attempts = max( 1, (int) ( $target_endpoint['max_attempts'] ?? CEM_Execution_Logger::MAX_ATTEMPTS ) );
            $job_id       = CEM_Execution_Logger::queue_async(
                $endpoint_slug,
                $request->get_method(),
                $payload,
                $max_attempts
            );
            return new WP_REST_Response( array(
                'job_id'   => $job_id,
                'status'   => 'queued',
                'endpoint' => $endpoint_slug,
                'poll'     => rest_url( 'cem/v1/jobs/' . $job_id ),
            ), 202 );
        }

        // ── Sync mode (with execution logging) ────────────────────────────────
        $start = microtime( true );

        try {
            $executor      = new CEM_Code_Executor();
            $response_data = $executor->execute( $callback_function_name, $raw_code, $request );
            $ms            = ( microtime( true ) - $start ) * 1000;

            CEM_Execution_Logger::log_sync( $endpoint_slug, $request->get_method(), $payload, $response_data, $ms );

            if ( $response_data instanceof WP_REST_Response ) {
                return $response_data;
            }
            return new WP_REST_Response( $response_data, 200 );

        } catch ( Exception $e ) {
            $ms    = ( microtime( true ) - $start ) * 1000;
            $error = new WP_Error( 'endpoint_error', $e->getMessage(), array( 'status' => 500 ) );
            CEM_Execution_Logger::log_sync( $endpoint_slug, $request->get_method(), $payload, $error, $ms );
            return $error;
        }
    }

    public function list_jobs( WP_REST_Request $request ): WP_REST_Response {
        $logs = CEM_Execution_Logger::get_logs( array(
            'per_page' => min( (int) ( $request->get_param( 'per_page' ) ?? 50 ), 100 ),
            'offset'   => (int) ( $request->get_param( 'offset' ) ?? 0 ),
            'status'   => sanitize_key( $request->get_param( 'status' ) ?? '' ),
        ) );
        return new WP_REST_Response( array( 'jobs' => $logs, 'total' => CEM_Execution_Logger::count_logs() ), 200 );
    }

    public function get_job( WP_REST_Request $request ): WP_REST_Response {
        $job = CEM_Execution_Logger::get_job( (int) $request->get_param( 'id' ) );
        if ( ! $job ) {
            return new WP_REST_Response( new WP_Error( 'not_found', 'Job not found.', array( 'status' => 404 ) ), 404 );
        }
        return new WP_REST_Response( $job, 200 );
    }

    public function retry_job( WP_REST_Request $request ): WP_REST_Response {
        $id  = (int) $request->get_param( 'id' );
        $job = CEM_Execution_Logger::get_job( $id );
        if ( ! $job ) {
            return new WP_REST_Response( new WP_Error( 'not_found', 'Job not found.', array( 'status' => 404 ) ), 404 );
        }
        $ok = CEM_Execution_Logger::requeue( $id );
        return new WP_REST_Response( array( 'requeued' => $ok, 'job_id' => $id ), $ok ? 200 : 409 );
    }

    public function get_function_library( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( array(
            'version'   => $this->version,
            'functions' => CEM_Function_Library::get_catalog(),
        ), 200 );
    }

    public function check_endpoint_permissions( WP_REST_Request $request ) {
        $route         = $request->get_route();
        $endpoint_slug = basename( $route );

        $custom_endpoints = get_option( 'cem_custom_endpoints', array() );
        $target_endpoint  = null;

        foreach ( $custom_endpoints as $endpoint ) {
            if ( sanitize_title( $endpoint['slug'] ) === $endpoint_slug ) {
                $target_endpoint = $endpoint;
                break;
            }
        }

        if ( ! $target_endpoint || empty( $target_endpoint['capability'] ) ) {
            return false;
        }

        return current_user_can( sanitize_text_field( $target_endpoint['capability'] ) );
    }

    private function parse_endpoint_args( $args_string ) {
        $args  = array();
        $pairs = explode( ',', $args_string );

        foreach ( $pairs as $pair ) {
            $parts = array_map( 'trim', explode( ':', $pair ) );
            if ( count( $parts ) === 2 && ! empty( $parts[0] ) && ! empty( $parts[1] ) ) {
                $args[ $parts[0] ] = array(
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => 'rest_validate_request_arg',
                    'type'              => $parts[1],
                );
            }
        }

        return $args;
    }
}
