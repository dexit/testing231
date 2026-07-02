<?php
/**
 * WRM Logger — structured logging to wrm_logs DB table.
 *
 * Levels: debug | info | warning | error | exception
 * Contexts: mapper | router | queue | provider | admin
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Logger {

	const string TABLE    = 'wrm_logs';
	const int    MAX_ROWS = 5000;

	// Severity levels (ascending)
	const string DEBUG     = 'debug';
	const string INFO      = 'info';
	const string WARNING   = 'warning';
	const string ERROR     = 'error';
	const string EXCEPTION = 'exception';

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	public static function debug( string $context, string $message, array $data = array() ): void {
		self::write( self::DEBUG, $context, $message, $data );
	}

	public static function info( string $context, string $message, array $data = array() ): void {
		self::write( self::INFO, $context, $message, $data );
	}

	public static function warning( string $context, string $message, array $data = array() ): void {
		self::write( self::WARNING, $context, $message, $data );
	}

	public static function error( string $context, string $message, array $data = array() ): void {
		self::write( self::ERROR, $context, $message, $data );
	}

	public static function exception( string $context, \Throwable $e, array $data = array() ): void {
		self::write(
			self::EXCEPTION,
			$context,
			$e->getMessage(),
			array_merge(
				$data,
				array(
					'exception_class' => get_class( $e ),
					'file'            => $e->getFile(),
					'line'            => $e->getLine(),
					'trace'           => array_slice(
						array_map(
							static fn( array $f ) => ( $f['file'] ?? '' ) . ':' . ( $f['line'] ?? '' ) . ' ' . $f['function'],
							$e->getTrace()
						),
						0,
						10
					),
				)
			)
		);
	}

	/**
	 * Log a WP_Error as an error entry.
	 */
	public static function wp_error( string $context, WP_Error $err, array $data = array() ): void {
		self::write(
			self::ERROR,
			$context,
			$err->get_error_message(),
			array_merge(
				$data,
				array(
					'code' => $err->get_error_code(),
					'data' => $err->get_error_data(),
				)
			)
		);
	}

	// -------------------------------------------------------------------------
	// Query
	// -------------------------------------------------------------------------

	public static function get_logs( array $args = array() ): array {
		global $wpdb;
		$table    = $wpdb->prefix . self::TABLE;
		$per_page = min( (int) ( $args['per_page'] ?? 100 ), 500 );
		$offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where    = '1=1';
		$params   = array();

		if ( ! empty( $args['level'] ) ) {
			$where   .= ' AND level = %s';
			$params[] = $args['level'];
		}
		if ( ! empty( $args['context'] ) ) {
			$where   .= ' AND context = %s';
			$params[] = $args['context'];
		}
		if ( ! empty( $args['ref_id'] ) ) {
			$where   .= ' AND ref_id = %d';
			$params[] = (int) $args['ref_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where   .= ' AND message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name is an internal constant, not user input. Spread operator confuses placeholder count.
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", ...$params ),
			ARRAY_A
		) ?? array();
	}

	public static function count( string $level = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		if ( $level ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE level = %s", $level ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public static function purge( int $keep_rows = 1000 ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is an internal constant, not user input.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$table} ORDER BY id DESC LIMIT %d) t)",
				$keep_rows
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->rows_affected;
	}

	// -------------------------------------------------------------------------
	// Install
	// -------------------------------------------------------------------------

	public static function install(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table} (
				id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				level      enum('debug','info','warning','error','exception') NOT NULL DEFAULT 'info',
				context    varchar(50) NOT NULL DEFAULT '',
				ref_id     bigint(20) unsigned NOT NULL DEFAULT 0,
				message    text NOT NULL,
				data       longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY level (level),
				KEY context (context),
				KEY created_at (created_at)
			) {$charset};"
		);
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	private static function write( string $level, string $context, string $message, array $data ): void {
		// Skip debug logs unless WP_DEBUG is active to avoid noise
		if ( self::DEBUG === $level && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'level'      => $level,
				'context'    => sanitize_key( $context ),
				'ref_id'     => (int) ( $data['ref_id'] ?? 0 ),
				'message'    => $message,
				'data'       => empty( $data ) ? null : wp_json_encode( $data ),
				'created_at' => current_time( 'mysql' ),
			)
		);

		// Periodic auto-prune to prevent table bloat
		if ( wp_rand( 1, 200 ) === 1 ) {
			self::purge( self::MAX_ROWS );
		}
	}
}
