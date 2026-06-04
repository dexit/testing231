<?php
/**
 * Mapping engine — applies a wrm_mapping post config to a captured payload.
 *
 * Mapping config (stored as JSON in post meta 'wrm_config'):
 * {
 *   "cpt": "post",
 *   "update_existing": true,
 *   "match_source": "email",         // dot-path in payload to match on
 *   "match_field": "meta:user_email",// post field or meta:key to match against
 *   "fields": [
 *     { "source": "name",            // dot-path or {{merge.tag}}
 *       "target": "post_title" },    // post field | meta:key | taxonomy:tax
 *     { "source": "tags",
 *       "target": "taxonomy:post_tag" }
 *   ],
 *   "chains": [
 *     { "type": "webhook",   "url": "https://...", "method": "POST", "body_template": "{...}" },
 *     { "type": "action",    "hook": "my_custom_action" },
 *     { "type": "function",  "function": "my_callback_fn" },
 *     { "type": "mapping",   "mapping_id": 42 }
 *   ]
 * }
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security note for integrators:
 *
 * Before using chain type "function", call:
 *   WRM_Mapper::register_callback( 'my_fn' );
 * on the `plugins_loaded` hook to opt the function into the allowlist.
 *
 * Before using chain type "action", call:
 *   WRM_Mapper::register_hook( 'my_hook' );
 * on the `plugins_loaded` hook to opt the hook into the allowlist.
 *
 * Unregistered callbacks and hooks will be blocked and a warning logged.
 */
class WRM_Mapper {

	/** @var array<string,true> Allowlist of callable function names permitted in "function" chains. */
	private static array $allowed_callbacks = [];

	public static function register_callback( string $fn ): void {
		self::$allowed_callbacks[ $fn ] = true;
	}

	/** @var array<string,true> Allowlist of action hook names permitted in "action" chains. */
	private static array $allowed_hooks = [];

	public static function register_hook( string $hook ): void {
		self::$allowed_hooks[ $hook ] = true;
	}

	public static function apply( int $capture_id, int $mapping_id ): array {
		WRM_Logger::info( 'mapper', 'Applying mapping', array( 'capture_id' => $capture_id, 'mapping_id' => $mapping_id, 'ref_id' => $capture_id ) );

		try {
			return self::_apply_inner( $capture_id, $mapping_id );
		} catch ( \Throwable $e ) {
			WRM_Logger::exception( 'mapper', $e, array( 'capture_id' => $capture_id, 'mapping_id' => $mapping_id, 'ref_id' => $capture_id ) );
			return array( 'success' => false, 'error' => $e->getMessage(), 'exception' => get_class( $e ) );
		}
	}

	private static function _apply_inner( int $capture_id, int $mapping_id ): array {
		$capture = WRM_Capture::get( $capture_id );
		if ( ! $capture ) {
			WRM_Logger::warning( 'mapper', 'Capture not found', array( 'capture_id' => $capture_id ) );
			return array( 'success' => false, 'error' => 'capture_not_found' );
		}

		$mapping_post = get_post( $mapping_id );
		if ( ! $mapping_post || 'wrm_mapping' !== $mapping_post->post_type ) {
			WRM_Logger::warning( 'mapper', 'Mapping not found', array( 'mapping_id' => $mapping_id ) );
			return array( 'success' => false, 'error' => 'mapping_not_found' );
		}

		$raw_config = get_post_meta( $mapping_id, 'wrm_config', true );
		$config     = json_decode( $raw_config ?: '{}', true );
		if ( ! is_array( $config ) ) {
			WRM_Logger::error( 'mapper', 'Mapping config invalid JSON', array( 'mapping_id' => $mapping_id ) );
			return array( 'success' => false, 'error' => 'mapping_config_invalid' );
		}

		$payload = json_decode( $capture['payload'], true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}
		$payload = apply_filters( 'wrm_normalize_payload', $payload, $capture_id, $mapping_id );

		$context = array(
			'payload' => $payload,
			'post'    => null,
			'meta'    => array(),
			'now'     => time(),
		);

		$cpt             = sanitize_key( $config['cpt'] ?? 'post' );
		$fields          = (array) ( $config['fields'] ?? array() );
		$chains          = (array) ( $config['chains'] ?? array() );
		$update_existing = ! empty( $config['update_existing'] );
		$match_source    = sanitize_text_field( $config['match_source'] ?? '' );
		$match_field     = sanitize_text_field( $config['match_field'] ?? '' );

		// Find existing post to update
		$existing_id = 0;
		if ( $update_existing && $match_source && $match_field ) {
			$match_val = WRM_Merge_Tags::get_by_path( $payload, $match_source );
			if ( null !== $match_val ) {
				$existing_id = self::find_post( $cpt, $match_field, $match_val );
			}
		}

		// Build post data from field mapping rules
		$post_data = array(
			'post_type'   => $cpt,
			'post_status' => sanitize_key( $config['post_status'] ?? 'publish' ),
		);
		$meta_data = array();
		$tax_data  = array();

		foreach ( $fields as $rule ) {
			$source = sanitize_text_field( $rule['source'] ?? '' );
			$target = sanitize_text_field( $rule['target'] ?? '' );
			if ( ! $source || ! $target ) {
				continue;
			}

			$value = str_contains( $source, '{{' )
				? WRM_Merge_Tags::resolve( $source, $context )
				: WRM_Merge_Tags::get_by_path( $payload, $source );

			if ( null === $value || '' === $value ) {
				continue;
			}

			if ( str_starts_with( $target, 'meta:' ) ) {
				$meta_data[ sanitize_key( substr( $target, 5 ) ) ] = $value;
			} elseif ( str_starts_with( $target, 'taxonomy:' ) ) {
				$tax_data[ sanitize_key( substr( $target, 9 ) ) ] = $value;
			} else {
				$post_data[ $target ] = is_array( $value )
					? implode( ', ', array_map( 'strval', $value ) )
					: (string) $value;
			}
		}

		// Create or update the post
		if ( $existing_id > 0 ) {
			$post_data['ID'] = $existing_id;
			$result_id       = wp_update_post( wp_slash( $post_data ), true );
			$action          = 'updated';
		} else {
			$result_id = wp_insert_post( wp_slash( $post_data ), true );
			$action    = 'created';
		}

		if ( is_wp_error( $result_id ) ) {
			WRM_Logger::wp_error( 'mapper', $result_id, array( 'capture_id' => $capture_id, 'mapping_id' => $mapping_id ) );
			return array( 'success' => false, 'error' => $result_id->get_error_message() );
		}

		foreach ( $meta_data as $key => $val ) {
			update_post_meta( $result_id, $key, $val );
		}
		foreach ( $tax_data as $tax => $terms ) {
			wp_set_object_terms( $result_id, is_array( $terms ) ? $terms : array( (string) $terms ), $tax );
		}

		// Update context with created post for chain merge tags
		$context['post'] = get_post( $result_id );
		$context['meta'] = $meta_data;

		// Execute action chains
		$chain_log = array();
		foreach ( $chains as $chain ) {
			$chain_log[] = self::execute_chain( $chain, $result_id, $payload, $context );
		}

		WRM_Logger::info( 'mapper', 'Mapping applied', array( 'post_id' => $result_id, 'action' => $action, 'capture_id' => $capture_id, 'ref_id' => $capture_id ) );

		return array(
			'success'   => true,
			'post_id'   => $result_id,
			'action'    => $action,
			'meta_keys' => array_keys( $meta_data ),
			'tax_keys'  => array_keys( $tax_data ),
			'chains'    => $chain_log,
		);
	}

	private static function find_post( string $cpt, string $field, $value ): int {
		if ( str_starts_with( $field, 'meta:' ) ) {
			$posts = get_posts(
				array(
					'post_type'      => $cpt,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => sanitize_key( substr( $field, 5 ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			return $posts ? (int) $posts[0] : 0;
		}
		if ( 'post_title' === $field ) {
			$posts = get_posts( array(
				'post_type'      => $cpt,
				'title'          => sanitize_text_field( (string) $value ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post_status'    => 'any',
				'no_found_rows'  => true,
			) );
			return $posts ? (int) $posts[0] : 0;
		}
		return 0;
	}

	private static function execute_chain( array $chain, int $post_id, array $payload, array $context ): array {
		$type = sanitize_key( $chain['type'] ?? '' );

		switch ( $type ) {
			case 'webhook':
				return self::chain_webhook( $chain, $post_id, $payload, $context );

			case 'action':
				$hook = sanitize_text_field( $chain['hook'] ?? '' );
				if ( ! $hook || ! isset( self::$allowed_hooks[ $hook ] ) ) {
					WRM_Logger::warning( 'mapper', 'Blocked unregistered hook', array( 'hook' => $hook ) );
					return array( 'type' => 'action', 'status' => 'skipped', 'reason' => 'not_registered', 'hook' => $hook );
				}
				do_action( $hook, $post_id, $payload, $context );
				return array( 'type' => 'action', 'hook' => $hook, 'status' => 'fired' );

			case 'function':
				$fn = sanitize_text_field( $chain['function'] ?? '' );
				if ( ! $fn || ! function_exists( $fn ) || ! isset( self::$allowed_callbacks[ $fn ] ) ) {
					WRM_Logger::warning( 'mapper', 'Blocked unregistered callback', array( 'function' => $fn ) );
					return array( 'type' => 'function', 'status' => 'skipped', 'reason' => 'not_registered', 'function' => $fn );
				}
				try {
					$fn_result = call_user_func( $fn, $post_id, $payload, $context );
					return array( 'type' => 'function', 'function' => $fn, 'result' => $fn_result );
				} catch ( \Throwable $e ) {
					return array( 'type' => 'function', 'function' => $fn, 'error' => $e->getMessage() );
				}

			case 'mapping':
				$next_id = (int) ( $chain['mapping_id'] ?? 0 );
				if ( ! $next_id ) {
					return array( 'type' => 'mapping', 'status' => 'skipped', 'reason' => 'no_mapping_id' );
				}
				$syn_capture_id = WRM_Capture::store_internal( '_chain', 'custom', $payload );
				$chain_result   = self::apply( $syn_capture_id, $next_id );
				return array( 'type' => 'mapping', 'mapping_id' => $next_id, 'result' => $chain_result );

			default:
				return array( 'type' => $type, 'status' => 'unknown_type' );
		}
	}

	/**
	 * Validate a webhook URL against SSRF risks.
	 *
	 * Rejects URLs that do not pass wp_http_validate_url, have no resolvable host,
	 * or resolve to a private/reserved IP range.
	 * Operators may override via the `wrm_webhook_url_allowed` filter.
	 *
	 * @param string $url URL to validate.
	 * @return bool True if the URL is safe to request.
	 */
	private static function is_safe_url( string $url ): bool {
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}
		$host = (string) parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		// Block if host resolves to private/reserved IP
		$ip = gethostbyname( $host );
		if ( $ip === $host && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false; // DNS lookup failed
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			if (
				filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false
			) {
				return false; // Private/reserved range
			}
		}
		return true;
	}

	private static function chain_webhook( array $chain, int $post_id, array $payload, array $context ): array {
		$url    = WRM_Merge_Tags::resolve( esc_url_raw( $chain['url'] ?? '' ), $context );
		$method = strtoupper( sanitize_text_field( $chain['method'] ?? 'POST' ) );

		if ( ! $url ) {
			return array( 'type' => 'webhook', 'status' => 'skipped', 'reason' => 'no_url' );
		}

		if ( ! apply_filters( 'wrm_webhook_url_allowed', self::is_safe_url( $url ), $url, $chain ) ) {
			WRM_Logger::warning( 'mapper', 'Blocked unsafe webhook URL', array( 'url' => $url ) );
			return array( 'type' => 'webhook', 'status' => 'skipped', 'reason' => 'unsafe_url', 'url' => $url );
		}

		// Body resolution priority: body_builder > body_template > raw payload
		if ( ! empty( $chain['body_builder'] ) && is_array( $chain['body_builder'] ) ) {
			$body = self::render_body_builder( $chain['body_builder'], $payload, $context );
		} elseif ( ! empty( $chain['body_template'] ) ) {
			$rendered = WRM_Merge_Tags::resolve( (string) $chain['body_template'], $context );
			$decoded  = json_decode( $rendered, true );
			$body     = is_array( $decoded ) ? $decoded : array_merge( $payload, array( '_wrm_post_id' => $post_id ) );
		} else {
			$body = array_merge( $payload, array( '_wrm_post_id' => $post_id ) );
		}

		$extra_headers = is_array( $chain['headers'] ?? null ) ? $chain['headers'] : array();

		$body          = apply_filters( 'wrm_webhook_chain_body', $body, $chain, $post_id, $payload );
		$extra_headers = apply_filters( 'wrm_webhook_chain_headers', $extra_headers, $chain, $post_id );

		$body_json = wp_json_encode( $body );

		// Outbound HMAC signing — adds X-WRM-Signature: sha256=<hex> when signing_secret configured.
		if ( ! empty( $chain['signing_secret'] ) ) {
			$extra_headers['X-WRM-Signature'] = 'sha256=' . hash_hmac( 'sha256', (string) $body_json, $chain['signing_secret'] );
		}

		WRM_Logger::debug( 'mapper', 'Firing webhook chain', array( 'url' => $url, 'method' => $method ) );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'body'    => $body_json,
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $extra_headers ),
				'timeout' => (int) ( $chain['timeout'] ?? 15 ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WRM_Logger::error( 'mapper', 'Webhook chain error: ' . $response->get_error_message(), array( 'url' => $url ) );
			return array( 'type' => 'webhook', 'url' => $url, 'error' => $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		WRM_Logger::debug( 'mapper', 'Webhook chain response', array( 'url' => $url, 'status_code' => $status_code ) );

		return array(
			'type'        => 'webhook',
			'url'         => $url,
			'status_code' => $status_code,
		);
	}

	/**
	 * Recursively render a body_builder node tree, resolving merge tags and loops.
	 *
	 * Nodes with __wrm_loop__ marker are expanded by iterating the source array
	 * from the payload and rendering the template for each item.
	 *
	 * @param mixed $node    Body builder node (array|string|int|bool|null).
	 * @param array $payload Normalized payload.
	 * @param array $context Merge tag context.
	 * @return mixed Rendered value.
	 */
	private static function render_body_builder( $node, array $payload, array $context ) {
		if ( ! is_array( $node ) ) {
			// Scalar — resolve merge tags in strings
			if ( is_string( $node ) && str_contains( $node, '{{' ) ) {
				return WRM_Merge_Tags::resolve( $node, $context );
			}
			return $node;
		}

		// Loop marker
		if ( ! empty( $node['__wrm_loop__'] ) ) {
			$source   = sanitize_text_field( $node['source'] ?? '' );
			$template = (string) ( $node['template'] ?? '' );
			$join     = (string) ( $node['join'] ?? '' );
			$items    = WRM_Merge_Tags::get_by_path( $payload, $source );

			if ( ! is_array( $items ) ) {
				return $join && strtoupper( $join ) !== 'ARRAY' ? '' : array();
			}

			$rendered = array();
			foreach ( $items as $item ) {
				$item_context          = $context;
				$item_context['item']  = is_array( $item ) ? $item : array( '__value__' => $item );
				// Support {{item.field}} by injecting item into payload context
				$item_context['payload']['item'] = $item_context['item'];
				$rendered[] = WRM_Merge_Tags::resolve( $template, $item_context );
			}

			if ( strtoupper( $join ) === 'ARRAY' || '' === $join ) {
				return $rendered;
			}
			return implode( $join, $rendered );
		}

		// Plain array → recurse each element
		if ( array_is_list( $node ) ) {
			return array_map( fn( $v ) => self::render_body_builder( $v, $payload, $context ), $node );
		}

		// Object → recurse each value
		$out = array();
		foreach ( $node as $key => $val ) {
			$resolved_key    = WRM_Merge_Tags::resolve( $key, $context );
			$out[ $resolved_key ] = self::render_body_builder( $val, $payload, $context );
		}
		return $out;
	}
}
