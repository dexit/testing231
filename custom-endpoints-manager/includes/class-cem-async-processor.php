<?php
/**
 * Async Job Processor.
 *
 * WP Cron based async queue — picks up queued jobs, executes via CEM_Code_Executor,
 * handles retries with exponential back-off.
 *
 * @package Custom_Endpoints_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Cron async job processor.
 *
 * @since 1.0.0
 */
class CEM_Async_Processor {

	/**
	 * Max jobs processed per cron sweep.
	 */
	const BATCH_SIZE = 5;

	/**
	 * Register hooks and schedule the sweep event.
	 *
	 * @since 1.0.0
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_intervals' ) );
		add_action( 'cem_process_async_job', array( __CLASS__, 'process_job' ) );
		add_action( 'cem_sweep_async_queue', array( __CLASS__, 'sweep_queue' ) );

		if ( ! wp_next_scheduled( 'cem_sweep_async_queue' ) ) {
			// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- short interval required for near-real-time async processing.
			wp_schedule_event( time(), 'cem_every_minute', 'cem_sweep_async_queue' );
		}
	}

	/**
	 * Add the cem_every_minute cron schedule.
	 *
	 * @since 1.0.0
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public static function add_cron_intervals( array $schedules ): array {
		$schedules['cem_every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every Minute (CEM)', 'custom-endpoints-manager' ),
		);
		return $schedules;
	}

	/**
	 * Sweep: pick up any queued/failed jobs that were not triggered by their single-event cron.
	 *
	 * @since 1.0.0
	 */
	public static function sweep_queue(): void {
		$jobs = CEM_Execution_Logger::get_pending_async( self::BATCH_SIZE );
		foreach ( $jobs as $row ) {
			self::process_job( (int) $row->id );
		}
	}

	/**
	 * Execute one async job by ID.
	 *
	 * @since 1.0.0
	 * @param int $job_id Job record ID.
	 */
	public static function process_job( int $job_id ): void {
		$job = CEM_Execution_Logger::get_job( $job_id );

		if ( ! $job ) {
			return;
		}
		if ( ! in_array( $job->status, array( 'queued', 'failed' ), true ) ) {
			return;
		}

		// Respect scheduled retry window.
		if ( $job->next_retry_at && strtotime( $job->next_retry_at ) > time() ) {
			return;
		}

		// Atomically claim the job to prevent double-execution.
		if ( ! CEM_Execution_Logger::mark_running( $job_id ) ) {
			return;
		}

		$attempt = (int) $job->attempt + 1;

		// Locate the endpoint configuration.
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

		// Rebuild WP_REST_Request from stored payload.
		$payload      = json_decode( $job->payload, true );
		$payload      = is_array( $payload ) ? $payload : array();
		$request      = new WP_REST_Request( $job->http_method, '/cem/v1/' . $job->endpoint_slug );
		$query_params = isset( $payload['query'] ) ? $payload['query'] : array();
		$body_params  = isset( $payload['body'] ) ? $payload['body'] : array();
		$request->set_query_params( $query_params );
		$request->set_body_params( $body_params );
		if ( ! empty( $payload['json'] ) ) {
			$request->set_body( wp_json_encode( $payload['json'] ) );
		}

		$function_name = 'cem_microplugin_callback_' . $microplugin_id;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local cache file.
		$raw_code = file_get_contents( $microplugin_file );

		$start = microtime( true );

		try {
			$executor = new CEM_Code_Executor();
			$result   = $executor->execute( $function_name, $raw_code, $request );
			$ms       = ( microtime( true ) - $start ) * 1000;

			// Persist incremented attempt count.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . CEM_Execution_Logger::TABLE,
				array( 'attempt' => $attempt ),
				array( 'id' => $job_id )
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

	/**
	 * Clear scheduled hooks on plugin deactivation.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'cem_sweep_async_queue' );
	}
}
