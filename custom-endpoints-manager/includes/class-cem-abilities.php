<?php
/**
 * CEM Abilities — registers plugin capabilities with the WordPress 7.0 Abilities API.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */

/**
 * Registers CEM abilities so they are discoverable by AI agents.
 *
 * Abilities are only registered when the Abilities API is present (WP 7.0+).
 *
 * @since 1.1.0
 */
class CEM_Abilities {

	/**
	 * Hook into the Abilities API initialisation action.
	 *
	 * @since 1.1.0
	 */
	public static function init() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register all CEM abilities.
	 *
	 * @since 1.1.0
	 */
	public static function register_abilities() {
		self::register_list_endpoints_ability();
		self::register_list_microplugins_ability();
		self::register_generate_code_ability();
		self::register_suggest_args_ability();
		self::register_suggest_schema_ability();
	}

	/**
	 * Ability: list all registered CEM custom endpoints.
	 */
	private static function register_list_endpoints_ability() {
		wp_register_ability(
			'custom-endpoints-manager/list-endpoints',
			array(
				'label'            => __( 'List CEM Endpoints', 'custom-endpoints-manager' ),
				'description'      => __( 'Returns all registered Custom Endpoints Manager REST API endpoints with their configuration.', 'custom-endpoints-manager' ),
				'thinking_message' => __( 'Loading endpoint list…', 'custom-endpoints-manager' ),
				'success_message'  => __( 'Endpoint list retrieved.', 'custom-endpoints-manager' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'          => array( 'type' => 'string' ),
							'methods'       => array( 'type' => 'string' ),
							'capability'    => array( 'type' => 'string' ),
							'async'         => array( 'type' => 'boolean' ),
							'microplugin_id'=> array( 'type' => 'integer' ),
							'callback_mode' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback' => array( __CLASS__, 'execute_list_endpoints' ),
				'permission_callback' => 'manage_options',
				'meta'             => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Ability: list all published microplugins with their metadata.
	 */
	private static function register_list_microplugins_ability() {
		wp_register_ability(
			'custom-endpoints-manager/list-microplugins',
			array(
				'label'            => __( 'List CEM Microplugins', 'custom-endpoints-manager' ),
				'description'      => __( 'Returns all published CEM microplugins with their output variable definitions.', 'custom-endpoints-manager' ),
				'thinking_message' => __( 'Loading microplugin list…', 'custom-endpoints-manager' ),
				'success_message'  => __( 'Microplugin list retrieved.', 'custom-endpoints-manager' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'           => array( 'type' => 'integer' ),
							'title'        => array( 'type' => 'string' ),
							'output_vars'  => array( 'type' => 'array' ),
						),
					),
				),
				'execute_callback' => array( __CLASS__, 'execute_list_microplugins' ),
				'permission_callback' => 'manage_options',
				'meta'             => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Ability: generate PHP callback code for a microplugin via AI.
	 */
	private static function register_generate_code_ability() {
		wp_register_ability(
			'custom-endpoints-manager/generate-microplugin-code',
			array(
				'label'            => __( 'Generate Microplugin Code', 'custom-endpoints-manager' ),
				'description'      => __( 'Uses the WordPress AI Client to generate PHP callback code for a CEM microplugin based on a description.', 'custom-endpoints-manager' ),
				'thinking_message' => __( 'Generating PHP code…', 'custom-endpoints-manager' ),
				'success_message'  => __( 'Code generated successfully.', 'custom-endpoints-manager' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'description'    => array(
							'type'        => 'string',
							'description' => __( 'Plain-language description of what the endpoint should do.', 'custom-endpoints-manager' ),
						),
						'callback_name'  => array(
							'type'        => 'string',
							'description' => __( 'PHP function name for the generated callback (optional).', 'custom-endpoints-manager' ),
						),
						'microplugin_id' => array(
							'type'        => 'integer',
							'description' => __( 'Existing microplugin post ID to use as context (optional).', 'custom-endpoints-manager' ),
						),
					),
					'required'   => array( 'description' ),
				),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'code'          => array( 'type' => 'string' ),
						'callback_name' => array( 'type' => 'string' ),
					),
				),
				'execute_callback' => array( __CLASS__, 'execute_generate_code' ),
				'permission_callback' => 'manage_options',
				'meta'             => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Ability: suggest REST API args from a description.
	 */
	private static function register_suggest_args_ability() {
		wp_register_ability(
			'custom-endpoints-manager/suggest-args',
			array(
				'label'            => __( 'Suggest Endpoint Args', 'custom-endpoints-manager' ),
				'description'      => __( 'Uses the WordPress AI Client to suggest REST API arguments for a CEM endpoint.', 'custom-endpoints-manager' ),
				'thinking_message' => __( 'Thinking about parameters…', 'custom-endpoints-manager' ),
				'success_message'  => __( 'Arguments suggested.', 'custom-endpoints-manager' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'description'    => array( 'type' => 'string' ),
						'endpoint_slug'  => array( 'type' => 'string' ),
						'microplugin_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'description' ),
				),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'args' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name' => array( 'type' => 'string' ),
									'type' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback' => array( __CLASS__, 'execute_suggest_args' ),
				'permission_callback' => 'manage_options',
				'meta'             => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Ability: suggest a response schema mapping.
	 */
	private static function register_suggest_schema_ability() {
		wp_register_ability(
			'custom-endpoints-manager/suggest-schema',
			array(
				'label'            => __( 'Suggest Response Schema', 'custom-endpoints-manager' ),
				'description'      => __( 'Uses the WordPress AI Client to suggest a response schema mapping for a CEM endpoint.', 'custom-endpoints-manager' ),
				'thinking_message' => __( 'Analysing response structure…', 'custom-endpoints-manager' ),
				'success_message'  => __( 'Schema suggested.', 'custom-endpoints-manager' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'description' => array( 'type' => 'string' ),
						'output_vars' => array( 'type' => 'array' ),
					),
					'required'   => array( 'description' ),
				),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'schema' => array(
							'type'       => 'object',
							'properties' => array(
								'root'        => array( 'type' => 'string' ),
								'items'       => array( 'type' => 'string' ),
								'total_count' => array( 'type' => 'string' ),
								'page'        => array( 'type' => 'string' ),
								'total_pages' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'execute_callback' => array( __CLASS__, 'execute_suggest_schema' ),
				'permission_callback' => 'manage_options',
				'meta'             => array( 'show_in_rest' => true ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Execute callbacks
	// -------------------------------------------------------------------------

	/**
	 * Execute: list endpoints.
	 *
	 * @param array $input Ability input (unused).
	 * @return array
	 */
	public static function execute_list_endpoints( array $input ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$endpoints = get_option( 'cem_custom_endpoints', array() );
		if ( ! is_array( $endpoints ) ) {
			return array();
		}
		return array_values( $endpoints );
	}

	/**
	 * Execute: list microplugins.
	 *
	 * @param array $input Ability input (unused).
	 * @return array
	 */
	public static function execute_list_microplugins( array $input ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$posts = get_posts(
			array(
				'post_type'      => 'microplugin',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$result     = array();
		$posts_count = count( $posts );
		for ( $i = 0; $i < $posts_count; $i++ ) {
			$post     = $posts[ $i ];
			$result[] = array(
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'output_vars' => get_post_meta( $post->ID, 'microplugin_output_vars', true ) ?: array(),
			);
		}

		return $result;
	}

	/**
	 * Execute: generate code (delegates to CEM_AI_Controller via REST).
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_generate_code( array $input ) {
		if ( ! class_exists( 'CEM_AI_Controller' ) ) {
			return new WP_Error( 'class_missing', 'CEM_AI_Controller not loaded.' );
		}

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'description', sanitize_textarea_field( $input['description'] ?? '' ) );

		if ( ! empty( $input['callback_name'] ) ) {
			$request->set_param( 'callback_name', sanitize_key( $input['callback_name'] ) );
		}

		if ( ! empty( $input['microplugin_id'] ) ) {
			$request->set_param( 'microplugin_id', (int) $input['microplugin_id'] );
		}

		$controller = new CEM_AI_Controller();
		$response   = $controller->generate_code( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response->get_data();
	}

	/**
	 * Execute: suggest args (delegates to CEM_AI_Controller via REST).
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_suggest_args( array $input ) {
		if ( ! class_exists( 'CEM_AI_Controller' ) ) {
			return new WP_Error( 'class_missing', 'CEM_AI_Controller not loaded.' );
		}

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'description', sanitize_textarea_field( $input['description'] ?? '' ) );

		if ( ! empty( $input['endpoint_slug'] ) ) {
			$request->set_param( 'endpoint_slug', sanitize_title( $input['endpoint_slug'] ) );
		}

		if ( ! empty( $input['microplugin_id'] ) ) {
			$request->set_param( 'microplugin_id', (int) $input['microplugin_id'] );
		}

		$controller = new CEM_AI_Controller();
		$response   = $controller->suggest_args( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response->get_data();
	}

	/**
	 * Execute: suggest schema (delegates to CEM_AI_Controller via REST).
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_suggest_schema( array $input ) {
		if ( ! class_exists( 'CEM_AI_Controller' ) ) {
			return new WP_Error( 'class_missing', 'CEM_AI_Controller not loaded.' );
		}

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'description', sanitize_textarea_field( $input['description'] ?? '' ) );

		if ( ! empty( $input['output_vars'] ) && is_array( $input['output_vars'] ) ) {
			$request->set_param( 'output_vars', $input['output_vars'] );
		}

		$controller = new CEM_AI_Controller();
		$response   = $controller->suggest_schema( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response->get_data();
	}
}
