<?php
/**
 * Execution Logger — DB table, sync/async job management, retry logic.
 *
 * @package Custom_Endpoints_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DB-backed execution log and async job queue.
 *
 * @since 1.0.0
 */
class CEM_Execution_Logger {

	const TABLE      = 'cem_execution_logs';
	const DB_VERSION = '1.0';

	const STATUS_QUEUED  = 'queued';
	const STATUS_RUNNING = 'running';
	const STATUS_DONE    = 'done';
	const STATUS_FAILED  = 'failed';
	const STATUS_DEAD    = 'dead';

	const MAX_ATTEMPTS = 3;

	/**
	 * Retry back-off in minutes per attempt index (0-based).
	 */
	const RETRY_DELAYS = array( 2, 10, 30 );

	/**
	 * Create or upgrade the DB table.
	 *
	 * @since 1.0.0
	 */
	public static function install(): void {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
            id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_key        varchar(64)         NOT NULL DEFAULT '',
            endpoint_slug  varchar(200)        NOT NULL,
            http_method    varchar(10)         NOT NULL DEFAULT 'GET',
            run_mode       enum('sync','async') NOT NULL DEFAULT 'sync',
            status         enum('queued','running','done','failed','dead') NOT NULL DEFAULT 'queued',
            payload        longtext,
            result         longtext,
            error_message  text,
            attempt        tinyint unsigned    NOT NULL DEFAULT 0,
            max_attempts   tinyint unsigned    NOT NULL DEFAULT 3,
            duration_ms    int unsigned        DEFAULT NULL,
            queued_at      datetime            NOT NULL,
            started_at     datetime            DEFAULT NULL,
            finished_at    datetime            DEFAULT NULL,
            next_retry_at  datetime            DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status_idx       (status),
            KEY endpoint_idx     (endpoint_slug),
            KEY queued_at_idx    (queued_at),
            KEY next_retry_idx   (next_retry_at)
        ) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'cem_db_version', self::DB_VERSION );
	}

	/**
	 * Write a completed synchronous execution record.
	 *
	 * @since 1.0.0
	 * @param string $endpoint_slug Endpoint slug.
	 * @param string $method        HTTP method.
	 * @param array  $payload       Request payload snapshot.
	 * @param mixed  $result        Execution result or WP_Error.
	 * @param float  $duration_ms   Wall-clock duration in milliseconds.
	 * @return int Inserted row ID.
	 */
	public static function log_sync(
		string $endpoint_slug,
		string $method,
		array $payload,
		$result,
		float $duration_ms
	): int {
		global $wpdb;

		$is_error = ( $result instanceof WP_Error );
		$status   = $is_error ? self::STATUS_FAILED : self::STATUS_DONE;
		$error    = $is_error ? $result->get_error_message() : null;
		$result_d = $is_error ? null : $result;
		$now      = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'job_key'       => self::make_key(),
				'endpoint_slug' => $endpoint_slug,
				'http_method'   => strtoupper( $method ),
				'run_mode'      => 'sync',
				'status'        => $status,
				'payload'       => wp_json_encode( $payload ),
				'result'        => wp_json_encode( $result_d ),
				'error_message' => $error,
				'attempt'       => 1,
				'max_attempts'  => 1,
				'duration_ms'   => (int) $duration_ms,
				'queued_at'     => $now,
				'started_at'    => $now,
				'finished_at'   => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert an async job record and schedule a single cron event for it.
	 *
	 * @since 1.0.0
	 * @param string $endpoint_slug Endpoint slug.
	 * @param string $method        HTTP method.
	 * @param array  $payload       Request payload snapshot.
	 * @param int    $max_attempts  Maximum retry attempts.
	 * @return int Inserted row ID.
	 */
	public static function queue_async(
		string $endpoint_slug,
		string $method,
		array $payload,
		int $max_attempts = self::MAX_ATTEMPTS
	): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'job_key'       => self::make_key(),
				'endpoint_slug' => $endpoint_slug,
				'http_method'   => strtoupper( $method ),
				'run_mode'      => 'async',
				'status'        => self::STATUS_QUEUED,
				'payload'       => wp_json_encode( $payload ),
				'attempt'       => 0,
				'max_attempts'  => $max_attempts,
				'queued_at'     => current_time( 'mysql' ),
			)
		);

		$id = (int) $wpdb->insert_id;

		// Schedule immediate single cron event (fires on next WP request).
		wp_schedule_single_event( time() + 5, 'cem_process_async_job', array( $id ) );

		return $id;
	}

	/**
	 * Atomically claim a job for processing.
	 *
	 * @since 1.0.0
	 * @param int $id Job row ID.
	 * @return bool True if this process successfully claimed the job.
	 */
	public static function mark_running( int $id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'running', started_at = %s WHERE id = %d AND status IN ('queued','failed')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql' ),
				$id
			)
		);

		return $wpdb->rows_affected > 0;
	}

	/**
	 * Mark a job as successfully completed.
	 *
	 * @since 1.0.0
	 * @param int   $id          Job row ID.
	 * @param mixed $result      Execution result.
	 * @param float $duration_ms Wall-clock duration in milliseconds.
	 */
	public static function mark_done( int $id, $result, float $duration_ms ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			array(
				'status'      => self::STATUS_DONE,
				'result'      => wp_json_encode( $result ),
				'duration_ms' => (int) $duration_ms,
				'finished_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Record a job failure and schedule a retry or mark it dead.
	 *
	 * @since 1.0.0
	 * @param int    $id           Job row ID.
	 * @param string $error        Error message.
	 * @param int    $attempt      Current attempt number (1-based).
	 * @param int    $max_attempts Maximum retry attempts.
	 */
	public static function mark_failed( int $id, string $error, int $attempt, int $max_attempts ): void {
		global $wpdb;

		if ( $attempt >= $max_attempts ) {
			$status        = self::STATUS_DEAD;
			$next_retry_at = null;
		} else {
			$status        = self::STATUS_FAILED;
			$delay_mins    = isset( self::RETRY_DELAYS[ $attempt - 1 ] ) ? self::RETRY_DELAYS[ $attempt - 1 ] : 30;
			$next_retry_at = gmdate( 'Y-m-d H:i:s', time() + $delay_mins * 60 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			array(
				'status'        => $status,
				'error_message' => $error,
				'attempt'       => $attempt,
				'next_retry_at' => $next_retry_at,
				'finished_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		if ( self::STATUS_FAILED === $status && isset( $delay_mins ) ) {
			wp_schedule_single_event(
				time() + $delay_mins * 60,
				'cem_process_async_job',
				array( $id )
			);
		}
	}

	/**
	 * Reset a failed or dead job back to queued state.
	 *
	 * @since 1.0.0
	 * @param int $id Job row ID.
	 * @return bool True if the row was updated.
	 */
	public static function requeue( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->update(
			$wpdb->prefix . self::TABLE,
			array(
				'status'        => self::STATUS_QUEUED,
				'error_message' => null,
				'started_at'    => null,
				'finished_at'   => null,
				'next_retry_at' => null,
				'attempt'       => 0,
				'queued_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		if ( $rows ) {
			wp_schedule_single_event( time() + 5, 'cem_process_async_job', array( $id ) );
		}

		return (bool) $rows;
	}

	/**
	 * Fetch a single job row by ID.
	 *
	 * @since 1.0.0
	 * @param int $id Job row ID.
	 * @return object|null
	 */
	public static function get_job( int $id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Fetch pending async jobs eligible for immediate processing.
	 *
	 * @since 1.0.0
	 * @param int $limit Max rows to return.
	 * @return array
	 */
	public static function get_pending_async( int $limit = 5 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE run_mode = 'async' AND status IN ('queued','failed') AND (next_retry_at IS NULL OR next_retry_at <= %s) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql' ),
				$limit
			)
		);

		return $results ? $results : array();
	}

	/**
	 * Fetch a page of log rows with optional status/slug filtering.
	 *
	 * @since 1.0.0
	 * @param array $args {
	 *     Optional query arguments.
	 *     @type int    $per_page      Rows per page (max 200). Default 50.
	 *     @type int    $offset        Row offset. Default 0.
	 *     @type string $status        Filter by status. Default '' (all).
	 *     @type string $endpoint_slug Filter by endpoint slug. Default '' (all).
	 * }
	 * @return array
	 */
	public static function get_logs( array $args = array() ): array {
		global $wpdb;

		$limit  = min( (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 50 ), 200 );
		$offset = (int) ( isset( $args['offset'] ) ? $args['offset'] : 0 );
		$status = isset( $args['status'] ) ? $args['status'] : '';
		$slug   = isset( $args['endpoint_slug'] ) ? $args['endpoint_slug'] : '';
		$table  = $wpdb->prefix . self::TABLE;

		$where  = '1=1';
		$params = array();

		if ( $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		if ( $slug ) {
			$where   .= ' AND endpoint_slug = %s';
			$params[] = $slug;
		}

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params )
		);

		return $results ? $results : array();
	}

	/**
	 * Count log rows, optionally filtered by status.
	 *
	 * @since 1.0.0
	 * @param string $status Status to filter by, or '' for all.
	 * @return int
	 */
	public static function count_logs( string $status = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Generate a unique job key.
	 *
	 * @since 1.0.0
	 * @return string 32-character hex string.
	 */
	private static function make_key(): string {
		return substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 32 );
	}
}
