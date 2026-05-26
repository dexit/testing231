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

        $raw_code               = file_get_contents( $microplugin_cache_file );
        $callback_function_name = 'cem_microplugin_callback_' . $microplugin_post_id;

        try {
            $executor      = new CEM_Code_Executor();
            $response_data = $executor->execute( $callback_function_name, $raw_code, $request );
            return new WP_REST_Response( $response_data, 200 );
        } catch ( Exception $e ) {
            return new WP_Error( 'endpoint_error', $e->getMessage(), array( 'status' => 500 ) );
        }
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
