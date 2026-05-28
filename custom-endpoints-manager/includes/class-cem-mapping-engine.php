<?php
/**
 * CEM Mapping Engine — applies field mapping rules to captured payloads.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */

/**
 * Reads a mapping configuration and creates or updates CPT posts from capture data.
 *
 * @since 1.2.0
 */
class CEM_Mapping_Engine {

	/**
	 * Apply a mapping configuration to a specific capture.
	 *
	 * @since 1.2.0
	 * @param int   $capture_id ID of the capture row.
	 * @param array $mapping    Mapping configuration array.
	 * @return array Result with 'success', 'post_id', 'action', and optional 'error'.
	 */
	public static function apply( int $capture_id, array $mapping ): array {
		$capture = CEM_Data_Capture::get_capture( $capture_id );
		if ( ! $capture ) {
			return array(
				'success' => false,
				'error'   => __( 'Capture not found.', 'custom-endpoints-manager' ),
			);
		}

		$payload = json_decode( $capture['payload'], true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$cpt             = sanitize_key( $mapping['cpt'] ?? 'post' );
		$fields          = is_array( $mapping['fields'] ?? null ) ? $mapping['fields'] : array();
		$chains          = is_array( $mapping['chains'] ?? null ) ? $mapping['chains'] : array();
		$update_existing = ! empty( $mapping['update_existing'] );
		$match_source    = sanitize_text_field( $mapping['match_source'] ?? '' );
		$match_field     = sanitize_text_field( $mapping['match_field'] ?? '' );

		// Determine whether to update an existing post.
		$existing_id = 0;
		if ( $update_existing && $match_source && $match_field ) {
			$match_value = self::get_by_path( $payload, $match_source );
			if ( null !== $match_value ) {
				$existing_id = self::find_existing_post( $cpt, $match_field, $match_value );
			}
		}

		// Collect data from mapping rules.
		$post_data = array(
			'post_type'   => $cpt,
			'post_status' => 'publish',
		);
		$meta_data = array();
		$tax_data  = array();

		$fields_len = count( $fields );
		for ( $i = 0; $i < $fields_len; $i++ ) {
			$rule   = $fields[ $i ];
			$source = sanitize_text_field( $rule['source'] ?? '' );
			$target = sanitize_text_field( $rule['target'] ?? '' );

			if ( ! $source || ! $target ) {
				continue;
			}

			$value = self::get_by_path( $payload, $source );
			if ( null === $value ) {
				continue;
			}

			if ( str_starts_with( $target, 'meta:' ) ) {
				$meta_data[ sanitize_key( substr( $target, 5 ) ) ] = $value;
			} elseif ( str_starts_with( $target, 'taxonomy:' ) ) {
				$tax_data[ sanitize_key( substr( $target, 9 ) ) ] = $value;
			} else {
				// Standard post field.
				$post_data[ $target ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			}
		}

		// Create or update post.
		if ( $existing_id > 0 ) {
			$post_data['ID'] = $existing_id;
			$result_id       = wp_update_post( wp_slash( $post_data ), true );
			$action          = 'updated';
		} else {
			$result_id = wp_insert_post( wp_slash( $post_data ), true );
			$action    = 'created';
		}

		if ( is_wp_error( $result_id ) ) {
			return array(
				'success' => false,
				'error'   => $result_id->get_error_message(),
			);
		}

		// Save meta.
		foreach ( $meta_data as $key => $val ) {
			update_post_meta( $result_id, $key, $val );
		}

		// Save taxonomies.
		foreach ( $tax_data as $tax => $terms ) {
			if ( ! is_array( $terms ) ) {
				$terms = array( (string) $terms );
			}
			wp_set_object_terms( $result_id, $terms, $tax );
		}

		// Execute action chains.
		$chain_log  = array();
		$chains_len = count( $chains );
		for ( $i = 0; $i < $chains_len; $i++ ) {
			$chain_log[] = self::execute_chain( $chains[ $i ], $result_id, $payload );
		}

		$log = wp_json_encode(
			array(
				'post_id'    => $result_id,
				'action'     => $action,
				'meta_count' => count( $meta_data ),
				'tax_count'  => count( $tax_data ),
				'chains'     => $chain_log,
			)
		);

		CEM_Data_Capture::mark_mapped( $capture_id, $log );

		return array(
			'success' => true,
			'post_id' => $result_id,
			'action'  => $action,
			'chains'  => $chain_log,
		);
	}

	/**
	 * Retrieve a value from a nested array via dot-notation path.
	 *
	 * @since 1.2.0
	 * @param array  $data Payload data.
	 * @param string $path Dot-notation path (e.g. "body.user.email").
	 * @return mixed|null Null if path not found.
	 */
	public static function get_by_path( array $data, string $path ) {
		$parts     = explode( '.', $path );
		$cur       = $data;
		$parts_len = count( $parts );
		for ( $i = 0; $i < $parts_len; $i++ ) {
			if ( ! is_array( $cur ) || ! array_key_exists( $parts[ $i ], $cur ) ) {
				return null;
			}
			$cur = $cur[ $parts[ $i ] ];
		}
		return $cur;
	}

	/**
	 * Find an existing post of the given CPT matching a field value.
	 *
	 * @param string $cpt   Post type.
	 * @param string $field Target field (post_title or meta:key).
	 * @param mixed  $value Value to match.
	 * @return int Post ID or 0.
	 */
	private static function find_existing_post( string $cpt, string $field, $value ): int {
		if ( str_starts_with( $field, 'meta:' ) ) {
			$meta_key = sanitize_key( substr( $field, 5 ) );
			$posts    = get_posts(
				array(
					'post_type'      => $cpt,
					'posts_per_page' => 1,
					'meta_key'       => $meta_key,
					'meta_value'     => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'         => 'ids',
				)
			);
			return $posts ? (int) $posts[0] : 0;
		}

		if ( 'post_title' === $field ) {
			$existing = get_page_by_title( sanitize_text_field( (string) $value ), OBJECT, $cpt );
			return $existing ? (int) $existing->ID : 0;
		}

		return 0;
	}

	/**
	 * Execute a single chain action.
	 *
	 * @param array $chain     Chain action config.
	 * @param int   $post_id   Resulting post ID.
	 * @param array $payload   Original capture payload.
	 * @return array Log entry for the action.
	 */
	private static function execute_chain( array $chain, int $post_id, array $payload ): array {
		$type = sanitize_key( $chain['type'] ?? '' );

		if ( 'webhook' === $type ) {
			$url    = esc_url_raw( $chain['url'] ?? '' );
			$method = strtoupper( sanitize_text_field( $chain['method'] ?? 'POST' ) );

			if ( ! $url ) {
				return array(
					'type'   => 'webhook',
					'status' => 'skipped',
					'reason' => 'no url configured',
				);
			}

			$body     = array_merge( $payload, array( '_cem_post_id' => $post_id ) );
			$response = wp_remote_request(
				$url,
				array(
					'method'  => $method,
					'body'    => wp_json_encode( $body ),
					'headers' => array( 'Content-Type' => 'application/json' ),
					'timeout' => 10,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'type'  => 'webhook',
					'url'   => $url,
					'error' => $response->get_error_message(),
				);
			}

			return array(
				'type'        => 'webhook',
				'url'         => $url,
				'status_code' => wp_remote_retrieve_response_code( $response ),
			);
		}

		return array(
			'type'   => $type,
			'status' => 'unknown_chain_type',
		);
	}
}
