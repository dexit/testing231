<?php
/**
 * CEM Data Capture — stores incoming REST request payloads for visual mapping.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */

/**
 * Creates and manages the cem_captures database table.
 *
 * @since 1.2.0
 */
class CEM_Data_Capture {

	const TABLE   = 'cem_captures';
	const VERSION = '1.0';

	/**
	 * Return the fully qualified table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or upgrade the captures table. Called from activation hook.
	 *
	 * @since 1.2.0
	 */
	public static function install(): void {
		global $wpdb;
		$table           = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			endpoint_slug varchar(200) NOT NULL DEFAULT '',
			method        varchar(10)  NOT NULL DEFAULT 'POST',
			payload       longtext     NOT NULL,
			mapped        tinyint(1)   NOT NULL DEFAULT 0,
			mapping_log   text         DEFAULT NULL,
			source_ip     varchar(45)  DEFAULT NULL,
			created_at    datetime     NOT NULL,
			PRIMARY KEY (id),
			KEY endpoint_slug (endpoint_slug),
			KEY created_at (created_at),
			KEY mapped (mapped)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'cem_captures_db_version', self::VERSION );
	}

	/**
	 * Store an incoming request payload.
	 *
	 * @since 1.2.0
	 * @param string $endpoint_slug Endpoint slug.
	 * @param string $method        HTTP method.
	 * @param array  $payload       Request payload array.
	 * @return int Inserted row ID (0 on failure).
	 */
	public static function store( string $endpoint_slug, string $method, array $payload ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::get_table_name(),
			array(
				'endpoint_slug' => sanitize_title( $endpoint_slug ),
				'method'        => strtoupper( sanitize_text_field( $method ) ),
				'payload'       => wp_json_encode( $payload ),
				'mapped'        => 0,
				'created_at'    => current_time( 'mysql', true ),
				'source_ip'     => isset( $_SERVER['REMOTE_ADDR'] )
					? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
					: '',
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Retrieve a paginated list of captures.
	 *
	 * @since 1.2.0
	 * @param array $args {
	 *   @type string $endpoint_slug Filter by endpoint.
	 *   @type int    $per_page      Rows per page (max 100).
	 *   @type int    $offset        Row offset.
	 *   @type int    $mapped        Filter by mapped status (0 or 1, -1 for all).
	 * }
	 * @return array
	 */
	public static function get_captures( array $args = array() ): array {
		global $wpdb;

		$table    = self::get_table_name();
		$per_page = min( (int) ( $args['per_page'] ?? 30 ), 100 );
		$offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$slug     = isset( $args['endpoint_slug'] ) ? sanitize_title( $args['endpoint_slug'] ) : '';
		$mapped   = isset( $args['mapped'] ) ? (int) $args['mapped'] : -1;

		$where_parts = array( '1=1' );
		$params      = array();

		if ( $slug ) {
			$where_parts[] = 'endpoint_slug = %s';
			$params[]      = $slug;
		}
		if ( $mapped >= 0 ) {
			$where_parts[] = 'mapped = %d';
			$params[]      = $mapped;
		}

		$where    = implode( ' AND ', $where_parts );
		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id, endpoint_slug, method, payload, mapped, mapping_log, source_ip, created_at FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...$params ),
			ARRAY_A
		) ?? array();
	}

	/**
	 * Retrieve a single capture by ID.
	 *
	 * @since 1.2.0
	 * @param int $id Capture ID.
	 * @return array|null
	 */
	public static function get_capture( int $id ): ?array {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Return the distinct endpoint slugs that have captures.
	 *
	 * @since 1.2.0
	 * @return array
	 */
	public static function get_distinct_endpoints(): array {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			"SELECT DISTINCT endpoint_slug FROM {$table} ORDER BY endpoint_slug ASC"
		) ?? array();
	}

	/**
	 * Count captures.
	 *
	 * @since 1.2.0
	 * @param string $endpoint_slug Optional filter by slug.
	 * @return int
	 */
	public static function count_captures( string $endpoint_slug = '' ): int {
		global $wpdb;
		$table = self::get_table_name();

		if ( $endpoint_slug ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE endpoint_slug = %s", sanitize_title( $endpoint_slug ) )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count captures per endpoint slug (for sidebar badges).
	 *
	 * @since 1.2.0
	 * @return array Map of slug → count.
	 */
	public static function count_by_endpoint(): array {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			"SELECT endpoint_slug, COUNT(*) AS cnt FROM {$table} GROUP BY endpoint_slug",
			ARRAY_A
		) ?? array();

		$map      = array();
		$rows_len = count( $rows );
		for ( $i = 0; $i < $rows_len; $i++ ) {
			$map[ $rows[ $i ]['endpoint_slug'] ] = (int) $rows[ $i ]['cnt'];
		}
		return $map;
	}

	/**
	 * Extract all dot-notation paths from the most recent capture for an endpoint.
	 *
	 * @since 1.2.0
	 * @param string $endpoint_slug Endpoint slug.
	 * @return array List of dot-notation paths.
	 */
	public static function get_detected_paths( string $endpoint_slug ): array {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$payload_json = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT payload FROM {$table} WHERE endpoint_slug = %s ORDER BY created_at DESC LIMIT 1", sanitize_title( $endpoint_slug ) )
		);

		if ( ! $payload_json ) {
			return array();
		}

		$data = json_decode( $payload_json, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		return self::extract_paths( $data, '' );
	}

	/**
	 * Recursively extract all dot-notation paths from a data array.
	 *
	 * @param array  $data   Data array.
	 * @param string $prefix Current path prefix.
	 * @return array
	 */
	private static function extract_paths( array $data, string $prefix ): array {
		$paths = array();
		foreach ( $data as $key => $value ) {
			$path    = $prefix ? $prefix . '.' . $key : $key;
			$paths[] = $path;
			if ( is_array( $value ) && ! empty( $value ) && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				$nested      = self::extract_paths( $value, $path );
				$nested_len  = count( $nested );
				for ( $i = 0; $i < $nested_len; $i++ ) {
					$paths[] = $nested[ $i ];
				}
			}
		}
		return $paths;
	}

	/**
	 * Mark a capture as mapped and store the mapping result log.
	 *
	 * @since 1.2.0
	 * @param int    $id  Capture ID.
	 * @param string $log JSON log of what was created/updated.
	 * @return bool
	 */
	public static function mark_mapped( int $id, string $log = '' ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			self::get_table_name(),
			array(
				'mapped'      => 1,
				'mapping_log' => $log,
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Delete a single capture.
	 *
	 * @since 1.2.0
	 * @param int $id Capture ID.
	 * @return bool
	 */
	public static function delete_capture( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->delete( self::get_table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Delete all captures for an endpoint.
	 *
	 * @since 1.2.0
	 * @param string $endpoint_slug Endpoint slug.
	 * @return int Number of rows deleted.
	 */
	public static function delete_for_endpoint( string $endpoint_slug ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->delete(
			self::get_table_name(),
			array( 'endpoint_slug' => sanitize_title( $endpoint_slug ) ),
			array( '%s' )
		);
	}
}
