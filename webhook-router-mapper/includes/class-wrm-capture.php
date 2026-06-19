<?php
/**
 * Incoming payload capture — wrm_captures table.
 *
 * Stores every normalized incoming webhook payload for review,
 * replay, and visual mapping configuration.
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Capture {

	public static function store(
		string $route_slug,
		string $method,
		string $provider,
		array $payload,
		WP_REST_Request $req,
		string $sig_status = 'skipped'
	): int {
		global $wpdb;

		$headers = array();
		foreach ( array( 'content-type', 'x-twilio-signature', 'x-hub-signature-256', 'x-hubspot-signature', 'user-agent' ) as $h ) {
			$val = $req->get_header( $h );
			if ( $val ) {
				$headers[ $h ] = $val;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . WRM_Installer::CAPTURES_TABLE,
			array(
				'route_slug' => sanitize_title( $route_slug ),
				'method'     => strtoupper( sanitize_text_field( $method ) ),
				'provider'   => sanitize_key( $provider ),
				'payload'    => wp_json_encode( $payload ),
				'headers'    => wp_json_encode( $headers ),
				'source_ip'  => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
				'mapped'     => 0,
				'sig_status' => sanitize_key( $sig_status ),
				'created_at' => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Store a synthetic capture from an internal chain (no HTTP request context).
	 */
	public static function store_internal( string $route_slug, string $provider, array $payload ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . WRM_Installer::CAPTURES_TABLE,
			array(
				'route_slug' => sanitize_title( $route_slug ),
				'method'     => 'INTERNAL',
				'provider'   => sanitize_key( $provider ),
				'payload'    => wp_json_encode( $payload ),
				'mapped'     => 0,
				'created_at' => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::CAPTURES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function list( array $args = array() ): array {
		global $wpdb;
		$table    = $wpdb->prefix . WRM_Installer::CAPTURES_TABLE;
		$per_page = min( (int) ( $args['per_page'] ?? 30 ), 100 );
		$offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where    = '1=1';
		$params   = array();

		if ( ! empty( $args['route_slug'] ) ) {
			$where   .= ' AND route_slug = %s';
			$params[] = sanitize_title( $args['route_slug'] );
		}
		if ( isset( $args['mapped'] ) && (int) $args['mapped'] >= 0 ) {
			$where   .= ' AND mapped = %d';
			$params[] = (int) $args['mapped'];
		}
		if ( ! empty( $args['provider'] ) ) {
			$where   .= ' AND provider = %s';
			$params[] = sanitize_key( $args['provider'] );
		}

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...$params ),
			ARRAY_A
		) ?? array();
	}

	public static function mark_mapped( int $id, string $log = '' ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->update(
			$wpdb->prefix . WRM_Installer::CAPTURES_TABLE,
			array(
				'mapped'      => 1,
				'mapping_log' => $log,
			),
			array( 'id' => $id )
		);
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->delete( $wpdb->prefix . WRM_Installer::CAPTURES_TABLE, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Extract all dot-notation paths from a capture's payload.
	 */
	public static function get_paths( int $id ): array {
		$capture = self::get( $id );
		if ( ! $capture ) {
			return array();
		}
		$data = json_decode( $capture['payload'], true );
		return is_array( $data ) ? self::extract_paths( $data, '' ) : array();
	}

	private static function extract_paths( array $data, string $prefix ): array {
		$paths = array();
		foreach ( $data as $key => $val ) {
			$path    = $prefix ? "{$prefix}.{$key}" : (string) $key;
			$paths[] = $path;
			if ( is_array( $val ) && ! empty( $val ) && array_keys( $val ) !== range( 0, count( $val ) - 1 ) ) {
				$paths = array_merge( $paths, self::extract_paths( $val, $path ) );
			}
		}
		return $paths;
	}
}
