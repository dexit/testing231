<?php
/**
 * Async Job Processor
 *
 * WP Cron based async queue — picks up queued jobs, executes via CEM_Code_Executor,
 * handles retries with exponential back-off.
 *
 * @package Custom_Endpoints_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CEM_Async_Processor {

    const BATCH_SIZE = 5;

    public static function init(): void {
        add_filter( 'cron_schedules',         array( __CLASS__, 'add_cron_intervals' ) );
        add_action( 'cem_process_async_job',  array( __CLASS__, 'process_job' ) );
        add_action( 'cem_sweep_async_queue',  array( __CLASS__, 'sweep_queue' ) );

        if ( ! wp_next_scheduled( 'cem_sweep_async_queue' ) ) {
            wp_schedule_event( time(), 'cem_every_minute', 'cem_sweep_async_queue' );
        }
    }

    public static function add_cron_intervals( array $schedules ): array {
        $schedules['cem_every_minute'] = array(
            'interval' => 60,
            'display'  => __( 'Every Minute (CEM)', 'custom-endpoints-manager' ),
        );
        return $schedules;
    }

    /**
     * Sweep: pick up any queued/failed jobs that weren't triggered by their single-event cron.
     */
    public static function sweep_queue(): void {
        $jobs = CEM_Execution_Logger::get_pending_async( self::BATCH_SIZE );
        foreach ( $jobs as $row ) {
            self::process_job( (int) $row->id );
        }
    }

    /**
     * Execute one async job.
     */
    public static function process_job( int $job_id ): void {
        $job = CEM_Execution_Logger::get_job( $job_id );

        if ( ! $job ) {
            return;
        }
        if ( ! in_array( $job->status, array( 'queued', 'failed' ), true ) ) {
            return;
        }
        // Check retry window
        if ( $job->next_retry_at && strtotime( $job->next_retry_at ) > time() ) {
            return;
        }

        // Atomically claim the job — prevents double-execution
        if ( ! CEM_Execution_Logger::mark_running( $job_id ) ) {
            return;
        }

        $attempt = (int) $job->attempt + 1;

        // Find endpoint config
        $endpoints = get_option( 'cem_custom_endpoints', array() );
        $target    = null;
        foreach ( $endpoints as $ep ) {
            if ( sanitize_title( $ep['slug'] ) === $job->endpoint_slug ) {
                $target = $ep;
                break;
            }
        }

        if ( ! $target ) {
            CEM_Execution_Logger::mark_failed( $job_id, 'Endpoint config not found.', $attempt, (int) $job->max_attempts );
            return;
        }

        $microplugin_id   = (int) $target['microplugin_id'];
        $microplugin_file = Microplugins::get_microplugin_cache_file( $microplugin_id );

        if ( ! $microplugin_file ) {
            CEM_Execution_Logger::mark_failed( $job_id, 'Microplugin cache file not found.', $attempt, (int) $job->max_attempts );
            return;
        }

        // Rebuild WP_REST_Request from stored payload
        $payload = json_decode( $job->payload, true ) ?: array();
        $request = new WP_REST_Request( $job->http_method, '/cem/v1/' . $job->endpoint_slug );
        $request->set_query_params( $payload['query'] ?? array() );
        $request->set_body_params( $payload['body']  ?? array() );
        if ( ! empty( $payload['json'] ) ) {
            $request->set_body( wp_json_encode( $payload['json'] ) );
        }

        $function_name = 'cem_microplugin_callback_' . $microplugin_id;
        $raw_code      = file_get_contents( $microplugin_file );

        $start = microtime( true );

        try {
            $executor = new CEM_Code_Executor();
            $result   = $executor->execute( $function_name, $raw_code, $request );

            $ms = ( microtime( true ) - $start ) * 1000;

            // Record attempt count
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . CEM_Execution_Logger::TABLE,
                array( 'attempt' => $attempt ),
                array( 'id'      => $job_id )
            );

            CEM_Execution_Logger::mark_done( $job_id, $result, $ms );

        } catch ( Exception $e ) {
            CEM_Execution_Logger::mark_failed(
                $job_id,
                $e->getMessage(),
                $attempt,
                (int) $job->max_attempts
            );
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'cem_sweep_async_queue' );
    }
}
