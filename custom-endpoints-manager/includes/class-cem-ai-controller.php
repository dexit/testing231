<?php
/**
 * CEM AI Controller — REST endpoints powered by the WP 7.0 AI Client API.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */

/**
 * Provides /cem/v1/ai/* REST routes for code generation and input suggestions.
 *
 * All routes are guarded by a runtime check for wp_ai_client_prompt() so the
 * plugin stays compatible with WordPress < 7.0 installations.
 *
 * @since 1.1.0
 */
class CEM_AI_Controller {

	/**
	 * System instruction shared across all code-generation prompts.
	 *
	 * @var string
	 */
	private static $system_instruction = '';

	/**
	 * Return (lazily building) the shared system instruction string.
	 *
	 * @return string
	 */
	private static function get_system_instruction() {
		if ( '' !== self::$system_instruction ) {
			return self::$system_instruction;
		}

		self::$system_instruction = 'You are a WordPress PHP developer assistant for the Custom Endpoints Manager (CEM) plugin.
CEM microplugins are PHP snippets executed as REST API endpoint handlers.
The snippet must define (or redefine) a function whose name is provided to you.
The function signature is always: function <callback_name>(WP_REST_Request $request): mixed

Available CEM helper methods (all static on the CEM class):
- CEM::param(string $name): mixed        — query-string or body parameter
- CEM::params(): array                   — all request parameters
- CEM::body(): array                     — parsed JSON body
- CEM::header(string $name): string      — request header value
- CEM::method(): string                  — HTTP verb (GET, POST, …)
- CEM::route(): string                   — matched route path
- CEM::user(): WP_User|false             — current user object
- CEM::user_id(): int                    — current user ID (0 = unauthenticated)
- CEM::option(string $key): mixed        — get_option() wrapper
- CEM::now(): string                     — current UTC datetime (Y-m-d H:i:s)
- CEM::site_url(string $path): string    — home_url() wrapper
- CEM::response(mixed $data, int $code): WP_REST_Response
- CEM::error(string $code, string $msg, int $status): WP_Error
- CEM::http_get(string $url, array $args): array|WP_Error
- CEM::http_post(string $url, array $body, array $args): array|WP_Error
- CEM::store(string $key, mixed $value): void   — transient store (1 h TTL)
- CEM::retrieve(string $key): mixed             — transient retrieve
- CEM::queue(callable $cb, array $args): void   — async background job

Security rules:
- Never use eval(), exec(), shell_exec(), system(), passthru(), or popen().
- Never expose sensitive data (passwords, keys) in responses.
- Always validate and sanitize inputs before database queries.
- Return WP_Error on failure, WP_REST_Response or plain array on success.

Output rules:
- Return ONLY valid PHP code, no markdown fences, no prose.
- The function must be self-contained (no external require/include).
- Keep code concise and readable.';

		return self::$system_instruction;
	}

	/**
	 * Register all /cem/v1/ai/* REST routes.
	 *
	 * @since 1.1.0
	 */
	public function register_routes() {
		register_rest_route(
			'cem/v1',
			'/ai/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ai_status' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			'cem/v1',
			'/ai/generate-code',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_code' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'description'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'callback_name'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'microplugin_id'  => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			'cem/v1',
			'/ai/improve-code',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'improve_code' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'code'          => array(
						'type'     => 'string',
						'required' => true,
					),
					'callback_name' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'cem/v1',
			'/ai/suggest-args',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'suggest_args' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'description'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'endpoint_slug'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_title',
					),
					'microplugin_id' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			'cem/v1',
			'/ai/suggest-schema',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'suggest_schema' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'description'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'output_vars'  => array(
						'type'     => 'array',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Check whether the WP AI Client is available and functional.
	 *
	 * @since 1.1.0
	 * @param WP_REST_Request $request Incoming request (unused).
	 * @return WP_REST_Response
	 */
	public function get_ai_status( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_REST_Response(
				array(
					'available' => false,
					'reason'    => 'wp_ai_client_prompt() not available — requires WordPress 7.0+.',
				),
				200
			);
		}

		$supported = wp_ai_client_prompt( 'test' )->is_supported_for_text_generation();

		return new WP_REST_Response(
			array(
				'available' => $supported,
				'reason'    => $supported ? '' : 'No AI provider configured in WordPress settings.',
			),
			200
		);
	}

	/**
	 * Generate PHP callback code from a natural-language description.
	 *
	 * @since 1.1.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_code( WP_REST_Request $request ) {
		$not_available = $this->require_ai_client();
		if ( is_wp_error( $not_available ) ) {
			return $not_available;
		}

		$description   = $request->get_param( 'description' );
		$callback_name = $request->get_param( 'callback_name' );
		$mp_id         = (int) $request->get_param( 'microplugin_id' );

		if ( empty( $callback_name ) ) {
			$callback_name = 'cem_microplugin_callback';
			if ( $mp_id > 0 ) {
				$callback_name = 'cem_microplugin_callback_' . $mp_id;
			}
		}

		$existing_vars = '';
		if ( $mp_id > 0 ) {
			$existing_vars = $this->get_output_vars_context( $mp_id );
		}

		$prompt = sprintf(
			"Generate a CEM microplugin PHP callback function named `%s`.\n\nEndpoint purpose: %s\n%s\nReturn only valid PHP code.",
			$callback_name,
			$description,
			$existing_vars ? "\nDocumented output variables:\n" . $existing_vars : ''
		);

		$result = wp_ai_client_prompt( $prompt )
			->using_system_instruction( self::get_system_instruction() )
			->using_temperature( 0.3 )
			->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'code'          => $this->clean_code_fence( $result ),
				'callback_name' => $callback_name,
			),
			200
		);
	}

	/**
	 * Improve / document existing microplugin code.
	 *
	 * @since 1.1.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function improve_code( WP_REST_Request $request ) {
		$not_available = $this->require_ai_client();
		if ( is_wp_error( $not_available ) ) {
			return $not_available;
		}

		$code          = $request->get_param( 'code' );
		$callback_name = $request->get_param( 'callback_name' );

		$name_hint = $callback_name ? " (function name: `{$callback_name}`)" : '';
		$prompt    = "Improve the following CEM microplugin PHP callback{$name_hint}.\n" .
			"- Add concise inline comments where the logic is non-obvious.\n" .
			"- Replace any direct WordPress calls with CEM:: equivalents where appropriate.\n" .
			"- Ensure inputs are validated and sanitized.\n" .
			"- Keep the function signature unchanged.\n" .
			"- Return ONLY valid PHP code, no markdown fences.\n\n" .
			"Existing code:\n" . $code;

		$result = wp_ai_client_prompt( $prompt )
			->using_system_instruction( self::get_system_instruction() )
			->using_temperature( 0.2 )
			->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array( 'code' => $this->clean_code_fence( $result ) ),
			200
		);
	}

	/**
	 * Suggest REST API args for an endpoint.
	 *
	 * @since 1.1.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function suggest_args( WP_REST_Request $request ) {
		$not_available = $this->require_ai_client();
		if ( is_wp_error( $not_available ) ) {
			return $not_available;
		}

		$description = $request->get_param( 'description' );
		$slug        = $request->get_param( 'endpoint_slug' );
		$mp_id       = (int) $request->get_param( 'microplugin_id' );

		$mp_context = '';
		if ( $mp_id > 0 ) {
			$mp_context = $this->get_microplugin_params_context( $mp_id );
		}

		$prompt = "Suggest REST API query/body parameters for a WordPress REST endpoint.\n" .
			( $slug ? "Endpoint slug: /{$slug}\n" : '' ) .
			"Purpose: {$description}\n" .
			( $mp_context ? "Parameters detected in microplugin code: {$mp_context}\n" : '' ) .
			"\nReturn a JSON array of objects with keys: name (string), type (one of: string, integer, boolean, number, array, object). " .
			"Only include parameters genuinely needed. Maximum 10 items. No extra prose.";

		$schema = array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'name' => array( 'type' => 'string' ),
					'type' => array(
						'type' => 'string',
						'enum' => array( 'string', 'integer', 'boolean', 'number', 'array', 'object' ),
					),
				),
				'required'   => array( 'name', 'type' ),
			),
		);

		$result = wp_ai_client_prompt( $prompt )
			->using_system_instruction( 'You are a WordPress REST API parameter design assistant. Always return valid JSON only, no prose.' )
			->as_json_response( $schema )
			->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$args = json_decode( $result, true );
		if ( ! is_array( $args ) ) {
			return new WP_Error( 'ai_parse_error', __( 'AI returned an unexpected format.', 'custom-endpoints-manager' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'args' => $args ), 200 );
	}

	/**
	 * Suggest a response schema mapping from a natural-language description.
	 *
	 * @since 1.1.0
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function suggest_schema( WP_REST_Request $request ) {
		$not_available = $this->require_ai_client();
		if ( is_wp_error( $not_available ) ) {
			return $not_available;
		}

		$description = $request->get_param( 'description' );
		$output_vars = $request->get_param( 'output_vars' );

		$vars_hint = '';
		if ( ! empty( $output_vars ) && is_array( $output_vars ) ) {
			$var_names  = array_column( $output_vars, 'key' );
			$vars_hint  = "\nDocumented output variables from the microplugin: " . implode( ', ', $var_names );
		}

		$prompt = "Suggest a response schema mapping for a REST API endpoint.\n" .
			"Purpose: {$description}{$vars_hint}\n\n" .
			"Return a JSON object with these keys (values are dot-notation paths into the response, leave empty string if not applicable):\n" .
			"- root: path to the top-level wrapper object (e.g. \"data\", leave empty if response is the root)\n" .
			"- items: path to the array of result items (e.g. \"data.posts\" or \"items\")\n" .
			"- total_count: path to the total count integer (e.g. \"meta.total\")\n" .
			"- page: path to the current page number (e.g. \"meta.page\")\n" .
			"- total_pages: path to the total pages count (e.g. \"meta.total_pages\")\n" .
			"No extra prose, just the JSON object.";

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'root'        => array( 'type' => 'string' ),
				'items'       => array( 'type' => 'string' ),
				'total_count' => array( 'type' => 'string' ),
				'page'        => array( 'type' => 'string' ),
				'total_pages' => array( 'type' => 'string' ),
			),
			'required'   => array( 'root', 'items', 'total_count', 'page', 'total_pages' ),
		);

		$result = wp_ai_client_prompt( $prompt )
			->using_system_instruction( 'You are a REST API response schema assistant. Return only the requested JSON object.' )
			->as_json_response( $schema )
			->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$map = json_decode( $result, true );
		if ( ! is_array( $map ) ) {
			return new WP_Error( 'ai_parse_error', __( 'AI returned an unexpected format.', 'custom-endpoints-manager' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'schema' => $map ), 200 );
	}

	/**
	 * Return a WP_Error if wp_ai_client_prompt() is unavailable.
	 *
	 * @return null|WP_Error
	 */
	private function require_ai_client() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'ai_unavailable',
				__( 'AI features require WordPress 7.0 or later with an AI provider configured.', 'custom-endpoints-manager' ),
				array( 'status' => 501 )
			);
		}

		if ( ! wp_ai_client_prompt( 'test' )->is_supported_for_text_generation() ) {
			return new WP_Error(
				'ai_no_provider',
				__( 'No AI provider is configured. Please configure one in WordPress Settings > AI.', 'custom-endpoints-manager' ),
				array( 'status' => 503 )
			);
		}

		return null;
	}

	/**
	 * Build a human-readable string of a microplugin's output variables.
	 *
	 * @param int $post_id Microplugin post ID.
	 * @return string
	 */
	private function get_output_vars_context( int $post_id ): string {
		$vars = get_post_meta( $post_id, 'microplugin_output_vars', true );
		if ( empty( $vars ) || ! is_array( $vars ) ) {
			return '';
		}

		$lines      = array();
		$vars_count = count( $vars );
		for ( $i = 0; $i < $vars_count; $i++ ) {
			$v = $vars[ $i ];
			if ( ! empty( $v['key'] ) ) {
				$line = '- ' . $v['key'] . ' (' . ( $v['type'] ?? 'string' ) . ')';
				if ( ! empty( $v['notes'] ) ) {
					$line .= ': ' . $v['notes'];
				}
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Extract CEM::param() call names from a microplugin's code.
	 *
	 * @param int $post_id Microplugin post ID.
	 * @return string
	 */
	private function get_microplugin_params_context( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return '';
		}

		preg_match_all(
			'/CEM::(?:param)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
			$post->post_content,
			$matches
		);

		$params = array_unique( $matches[1] );
		return implode( ', ', $params );
	}

	/**
	 * Strip markdown code fences from AI-generated code if present.
	 *
	 * @param string $code Raw AI output.
	 * @return string
	 */
	private function clean_code_fence( string $code ): string {
		$code = trim( $code );
		if ( preg_match( '/^```(?:php)?\s*([\s\S]+?)\s*```$/i', $code, $m ) ) {
			return trim( $m[1] );
		}
		return $code;
	}
}
