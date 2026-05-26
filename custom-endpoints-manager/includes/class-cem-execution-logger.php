<?php
/**
 * Execution Logger — DB table, sync/async job management, retry logic.
 *
 * @package Custom_Endpoints_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CEM_Execution_Logger {

    const TABLE         = 'cem_execution_logs';
    const DB_VERSION    = '1.0';

    const STATUS_QUEUED  = 'queued';
    const STATUS_RUNNING = 'running';
    const STATUS_DONE    = 'done';
    const STATUS_FAILED  = 'failed';
    const STATUS_DEAD    = 'dead';

    const MAX_ATTEMPTS   = 3;
    // Retry backoff in minutes per attempt index (0-based)
    const RETRY_DELAYS   = array( 2, 10, 30 );

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Sync logging
    // -------------------------------------------------------------------------

    public static function log_sync(
        string $endpoint_slug,
        string $method,
        array  $payload,
               $result,
        float  $duration_ms
    ): int {
        global $wpdb;

        $is_error = ( $result instanceof WP_Error );
        $status   = $is_error ? self::STATUS_FAILED : self::STATUS_DONE;
        $error    = $is_error ? $result->get_error_message() : null;
        $result_d = $is_error ? null : $result;
        $now      = current_time( 'mysql' );

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

    // -------------------------------------------------------------------------
    // Async queue
    // -------------------------------------------------------------------------

    public static function queue_async(
        string $endpoint_slug,
        string $method,
        array  $payload,
        int    $max_attempts = self::MAX_ATTEMPTS
    ): int {
        global $wpdb;

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

        // Schedule immediate single cron event (fires on next WP request)
        wp_schedule_single_event( time() + 5, 'cem_process_async_job', array( $id ) );

        return $id;
    }

    // -------------------------------------------------------------------------
    // Job state transitions
    // -------------------------------------------------------------------------

    /**
     * Atomically claim a job for processing.
     *
     * @return bool True if this process successfully claimed the job.
     */
    public static function mark_running( int $id ): bool {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}" . self::TABLE . "
                 SET status = 'running', started_at = %s
                 WHERE id = %d AND status IN ('queued','failed')",
                current_time( 'mysql' ),
                $id
            )
        );

        return $wpdb->rows_affected > 0;
    }

    public static function mark_done( int $id, $result, float $duration_ms ): void {
        global $wpdb;

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

    public static function mark_failed( int $id, string $error, int $attempt, int $max_attempts ): void {
        global $wpdb;

        if ( $attempt >= $max_attempts ) {
            $status        = self::STATUS_DEAD;
            $next_retry_at = null;
        } else {
            $status        = self::STATUS_FAILED;
            $delay_mins    = self::RETRY_DELAYS[ $attempt - 1 ] ?? 30;
            $next_retry_at = gmdate( 'Y-m-d H:i:s', time() + $delay_mins * 60 );
        }

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

        if ( $status === self::STATUS_FAILED && isset( $delay_mins ) ) {
            wp_schedule_single_event(
                time() + $delay_mins * 60,
                'cem_process_async_job',
                array( $id )
            );
        }
    }

    public static function requeue( int $id ): bool {
        global $wpdb;

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

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    public static function get_job( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d",
                $id
            )
        ) ?: null;
    }

    public static function get_pending_async( int $limit = 5 ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}" . self::TABLE . "
                 WHERE run_mode = 'async'
                 AND   status   IN ('queued','failed')
                 AND  (next_retry_at IS NULL OR next_retry_at <= %s)
                 ORDER BY id ASC
                 LIMIT %d",
                current_time( 'mysql' ),
                $limit
            )
        ) ?: array();
    }

    public static function get_logs( array $args = array() ): array {
        global $wpdb;

        $limit  = min( (int) ( $args['per_page'] ?? 50 ), 200 );
        $offset = (int) ( $args['offset'] ?? 0 );
        $status = $args['status'] ?? '';
        $slug   = $args['endpoint_slug'] ?? '';

        $where  = '1=1';
        $params = array();

        if ( $status ) {
            $where    .= ' AND status = %s';
            $params[]  = $status;
        }
        if ( $slug ) {
            $where    .= ' AND endpoint_slug = %s';
            $params[]  = $slug;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
                $params
            )
        ) ?: array();
    }

    public static function count_logs( string $status = '' ): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        if ( $status ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function make_key(): string {
        return substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 32 );
    }
}
