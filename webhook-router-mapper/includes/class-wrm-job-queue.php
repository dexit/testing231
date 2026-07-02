<?php
/**
 * Job queue — enqueue, sweep, and process async mapping jobs.
 *
 * Jobs are stored in wrm_jobs. A WP-Cron sweep fires every minute and
 * picks up to BATCH_SIZE pending/failed jobs whose next_retry_at is due.
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Job_Queue {

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	const int   BATCH_SIZE   = 5;
	const int   MAX_ATTEMPTS = 3;
	const array RETRY_DELAYS = array( 2, 10, 30 ); // minutes per attempt (0-indexed)

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init(): void {
		add_action( 'wrm_sweep_queue', array( __CLASS__, 'sweep' ) );
		add_action( 'wrm_process_job', array( __CLASS__, 'process' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );

		if ( ! wp_next_scheduled( 'wrm_sweep_queue' ) ) {
			wp_schedule_event( time(), 'wrm_every_minute', 'wrm_sweep_queue' );
		}
	}

	/**
	 * Register a custom one-minute cron interval.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public static function add_schedules( array $schedules ): array {
		if ( ! isset( $schedules['wrm_every_minute'] ) ) {
			$schedules['wrm_every_minute'] = array(
				'interval' => 60,
				'display'  => __( 'Every Minute (WRM)', 'wrm' ),
			);
		}
		return $schedules;
	}

	/**
	 * Fire a non-blocking loopback HTTP request to wp-cron.php to trigger
	 * WP-Cron processing immediately without waiting for a site visitor.
	 *
	 * This is the same pattern WooCommerce uses in WC_Background_Process::dispatch().
	 * The request is intentionally non-blocking (timeout 0.01 s) so it returns
	 * instantly. SSL verification is disabled to avoid self-signed cert failures
	 * on local/staging environments.
	 */
	private static function spawn_cron(): void {
		if ( wp_doing_cron() ) {
			return; // Already inside a cron run — no need to re-spawn.
		}

		$url = site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) );

		wp_remote_get(
			$url,
			array(
				'blocking'   => false,
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
				'timeout'    => 0.01,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			)
		);
	}

	/**
	 * Clean up on plugin deactivation.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( 'wrm_sweep_queue' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wrm_sweep_queue' );
		}
	}

	// -------------------------------------------------------------------------
	// Enqueue
	// -------------------------------------------------------------------------

	/**
	 * Insert a new job into the queue and schedule a near-immediate process event.
	 *
	 * @param string $route_slug   Route slug.
	 * @param int    $capture_id   Capture row ID.
	 * @param int    $mapping_id   Mapping post ID.
	 * @param int    $max_attempts Maximum processing attempts.
	 * @return int Inserted job ID.
	 */
	public static function enqueue(
		string $route_slug,
		int $capture_id,
		int $mapping_id,
		int $max_attempts = self::MAX_ATTEMPTS
	): int {
		global $wpdb;

		$table = $wpdb->prefix . WRM_Installer::JOBS_TABLE;
		$now   = current_time( 'mysql' );

		// Deterministic key lets callers detect duplicate enqueues if needed.
		$job_key = md5( $route_slug . '|' . $capture_id . '|' . $mapping_id . '|' . microtime( true ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'job_key'       => $job_key,
				'route_slug'    => sanitize_title( $route_slug ),
				'capture_id'    => $capture_id,
				'mapping_id'    => $mapping_id,
				'status'        => 'queued',
				'attempt'       => 0,
				'max_attempts'  => max( 1, $max_attempts ),
				'queued_at'     => $now,
				'next_retry_at' => $now,
			)
		);

		$job_id = (int) $wpdb->insert_id;

		// Async loopback — non-blocking HTTP request to wp-cron.php so the job
		// fires within seconds even on low-traffic sites (WooCommerce pattern).
		self::spawn_cron();

		// Also register a single-event fallback in case the loopback is blocked.
		wp_schedule_single_event( time() + 5, 'wrm_process_job', array( $job_id ) );

		WRM_Logger::info(
			'queue',
			'Job enqueued.',
			array(
				'ref_id'     => $job_id,
				'route_slug' => $route_slug,
				'capture_id' => $capture_id,
				'mapping_id' => $mapping_id,
			)
		);

		return $job_id;
	}

	// -------------------------------------------------------------------------
	// Sweep
	// -------------------------------------------------------------------------

	/**
	 * Pick up pending jobs whose retry time has arrived and process each one.
	 *
	 * A transient-based mutex (2-minute TTL) prevents concurrent sweep runs on
	 * object-cache installs where multiple requests may fire WP-Cron simultaneously.
	 */
	public static function sweep(): void {
		global $wpdb;

		// Mutex: bail if another sweep is already running.
		$lock_key = 'wrm_sweep_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 120 );

		try {
			$table = $wpdb->prefix . WRM_Installer::JOBS_TABLE;
			$now   = current_time( 'mysql' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$jobs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id FROM {$table}
					 WHERE status IN ('queued','failed')
					   AND next_retry_at <= %s
					 ORDER BY id ASC
					 LIMIT %d",
					$now,
					self::BATCH_SIZE
				),
				ARRAY_A
			);

			if ( empty( $jobs ) ) {
				return;
			}

			$count = count( $jobs );

			WRM_Logger::debug(
				'queue',
				"Sweep: processing {$count} job(s).",
				array( 'ref_id' => 0 )
			);

			foreach ( $jobs as $job ) {
				self::process( (int) $job['id'] );
			}
		} finally {
			delete_transient( $lock_key );
		}
	}

	// -------------------------------------------------------------------------
	// Process
	// -------------------------------------------------------------------------

	/**
	 * Atomically claim a single job and run the mapping.
	 *
	 * @param int $job_id Job row ID.
	 */
	public static function process( int $job_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . WRM_Installer::JOBS_TABLE;

		// Atomic claim: only proceed if we flip the row to 'running'.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'running', started_at = %s
				 WHERE id = %d AND status IN ('queued','failed')",
				current_time( 'mysql' ),
				$job_id
			)
		);

		if ( ! $claimed ) {
			// Another process already claimed this job.
			return;
		}

		// Fetch the full job row now that we own it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$job = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ),
			ARRAY_A
		);

		if ( ! $job ) {
			return;
		}

		$attempt      = (int) $job['attempt'] + 1;
		$max_attempts = (int) $job['max_attempts'];
		$start_ms     = (int) ( microtime( true ) * 1000 );

		WRM_Logger::info(
			'queue',
			"Job #{$job_id} started (attempt {$attempt}/{$max_attempts}).",
			array(
				'ref_id'     => $job_id,
				'capture_id' => $job['capture_id'],
				'mapping_id' => $job['mapping_id'],
			)
		);

		try {
			$result = WRM_Mapper::apply( (int) $job['capture_id'], (int) $job['mapping_id'] );

			$duration_ms = (int) ( microtime( true ) * 1000 ) - $start_ms;

			if ( ! empty( $result['success'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update(
					$table,
					array(
						'status'      => 'done',
						'attempt'     => $attempt,
						'result'      => wp_json_encode( $result ),
						'finished_at' => current_time( 'mysql' ),
						'duration_ms' => $duration_ms,
					),
					array( 'id' => $job_id )
				);

				WRM_Capture::mark_mapped( (int) $job['capture_id'], wp_json_encode( $result ) );
				WRM_Metrics::increment( $job['route_slug'], 'jobs_ok' );

				WRM_Logger::info(
					'queue',
					"Job #{$job_id} completed successfully.",
					array(
						'ref_id'      => $job_id,
						'post_id'     => $result['post_id'] ?? 0,
						'duration_ms' => $duration_ms,
					)
				);
			} else {
				$error = $result['error'] ?? 'mapping_returned_failure';
				self::mark_failed( $job_id, $error, $attempt, $max_attempts, $job['route_slug'] ?? '' );
			}
		} catch ( \Throwable $e ) {
			WRM_Logger::exception(
				'queue',
				$e,
				array(
					'ref_id'  => $job_id,
					'attempt' => $attempt,
				)
			);
			self::mark_failed( $job_id, $e->getMessage(), $attempt, $max_attempts, $job['route_slug'] ?? '' );
		}
	}

	// -------------------------------------------------------------------------
	// Failure handling
	// -------------------------------------------------------------------------

	/**
	 * Mark a job as failed or dead and schedule a retry when appropriate.
	 *
	 * @param int    $id           Job ID.
	 * @param string $error        Error message.
	 * @param int    $attempt      Attempt number just completed.
	 * @param int    $max_attempts Maximum allowed attempts.
	 */
	public static function mark_failed(
		int $id,
		string $error,
		int $attempt,
		int $max_attempts,
		string $route_slug = ''
	): void {
		global $wpdb;

		$table   = $wpdb->prefix . WRM_Installer::JOBS_TABLE;
		$is_dead = $attempt >= $max_attempts;
		$status  = $is_dead ? 'dead' : 'failed';

		// Calculate next retry time from RETRY_DELAYS, falling back to the
		// last defined delay for any attempt beyond the array bounds.
		$delays        = self::RETRY_DELAYS;
		$delay_index   = min( $attempt, count( $delays ) - 1 );
		$delay_minutes = $delays[ $delay_index ] ?? 30;
		$next_retry    = gmdate( 'Y-m-d H:i:s', time() + ( $delay_minutes * 60 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			array(
				'status'        => $status,
				'attempt'       => $attempt,
				'error_message' => substr( $error, 0, 65535 ),
				'finished_at'   => current_time( 'mysql' ),
				'next_retry_at' => $is_dead ? null : $next_retry,
			),
			array( 'id' => $id )
		);

		if ( $is_dead ) {
			WRM_Logger::error(
				'queue',
				"Job #{$id} permanently failed after {$attempt} attempt(s).",
				array(
					'ref_id'  => $id,
					'attempt' => $attempt,
					'error'   => $error,
				)
			);
			self::dispatch_dead_letter( $id );
			if ( $route_slug ) {
				WRM_Metrics::increment( $route_slug, 'jobs_failed' );
			}
		} else {
			WRM_Logger::error(
				'queue',
				"Job #{$id} failed on attempt {$attempt}; retry in {$delay_minutes} min.",
				array(
					'ref_id'        => $id,
					'attempt'       => $attempt,
					'max_attempts'  => $max_attempts,
					'error'         => $error,
					'next_retry_at' => $next_retry,
				)
			);

			// Schedule a single event so retries fire promptly even if sweep
			// misses the window.
			wp_schedule_single_event(
				time() + ( $delay_minutes * 60 ),
				'wrm_process_job',
				array( $id )
			);
		}
	}

	public static function dispatch_dead_letter( int $job_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::JOBS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ), ARRAY_A );
		if ( ! $job ) {
			return; }

		$mapping_post = get_post( (int) $job['mapping_id'] );
		if ( ! $mapping_post ) {
			return; }

		$raw_config = get_post_meta( (int) $job['mapping_id'], 'wrm_config', true );
		$config     = json_decode( $raw_config ?: '{}', true ) ?? array();
		$dlq_route  = sanitize_title( $config['dead_letter_route'] ?? '' );
		if ( ! $dlq_route ) {
			return; }

		// Get the original capture payload
		$cap_table = $wpdb->prefix . WRM_Installer::CAPTURES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cap = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$cap_table} WHERE id = %d", (int) $job['capture_id'] ), ARRAY_A );
		if ( ! $cap ) {
			return; }

		$payload = json_validate( $cap['payload'] ) ? ( json_decode( $cap['payload'], true ) ?? array() ) : array();
		// Wrap in dead-letter envelope
		$dlq_payload = array(
			'_dlq'     => true,
			'_job_id'  => $job_id,
			'_route'   => $job['route_slug'],
			'_error'   => $job['error_message'],
			'_attempt' => $job['attempt'],
			'original' => $payload,
		);

		// Store a new capture on the DLQ route
		$dlq_capture_id = WRM_Capture::store_internal( $dlq_route, 'dlq', $dlq_payload );
		if ( ! $dlq_capture_id ) {
			return; }

		// Find the DLQ route's mapping_id
		$routes_table = $wpdb->prefix . WRM_Installer::ROUTES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dlq_mapping_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT mapping_id FROM {$routes_table} WHERE slug = %s", $dlq_route ) );

		if ( $dlq_mapping_id ) {
			self::enqueue( $dlq_route, $dlq_capture_id, $dlq_mapping_id );
		}

		WRM_Logger::info( 'queue', "Job #{$job_id} dispatched to dead-letter route '{$dlq_route}'.", array( 'ref_id' => $job_id ) );
	}

	// -------------------------------------------------------------------------
	// Manual operations
	// -------------------------------------------------------------------------

	/**
	 * Reset a dead or failed job back to queued and schedule immediate retry.
	 *
	 * @param int $id Job ID.
	 * @return bool True if the row was updated.
	 */
	public static function requeue( int $id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . WRM_Installer::JOBS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->update(
			$table,
			array(
				'status'        => 'queued',
				'attempt'       => 0,
				'error_message' => null,
				'finished_at'   => null,
				'next_retry_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		if ( ! $rows ) {
			return false;
		}

		wp_schedule_single_event( time() + 5, 'wrm_process_job', array( $id ) );

		WRM_Logger::info(
			'queue',
			"Job #{$id} requeued manually.",
			array( 'ref_id' => $id )
		);

		return true;
	}

	/**
	 * Pause all queued jobs for a route.
	 *
	 * @param string $slug Route slug.
	 * @return int Number of rows updated.
	 */
	public static function pause_route( string $slug ): int {
		global $wpdb;

		$table = $wpdb->prefix . WRM_Installer::JOBS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'paused' WHERE route_slug = %s AND status = 'queued'",
				sanitize_title( $slug )
			)
		);

		$count = (int) $wpdb->rows_affected;

		WRM_Logger::info(
			'queue',
			"Route '{$slug}' paused: {$count} job(s) suspended.",
			array(
				'ref_id'     => 0,
				'route_slug' => $slug,
				'count'      => $count,
			)
		);

		return $count;
	}

	/**
	 * Resume all paused jobs for a route.
	 *
	 * @param string $slug Route slug.
	 * @return int Number of rows updated.
	 */
	public static function resume_route( string $slug ): int {
		global $wpdb;

		$table = $wpdb->prefix . WRM_Installer::JOBS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'queued', next_retry_at = %s WHERE route_slug = %s AND status = 'paused'",
				current_time( 'mysql' ),
				sanitize_title( $slug )
			)
		);

		$count = (int) $wpdb->rows_affected;

		if ( $count > 0 ) {
			// Find the first resumed job and nudge it into processing.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$first_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE route_slug = %s AND status = 'queued' ORDER BY id ASC LIMIT 1",
					sanitize_title( $slug )
				)
			);

			if ( $first_id ) {
				wp_schedule_single_event( time() + 5, 'wrm_process_job', array( $first_id ) );
			}
		}

		WRM_Logger::info(
			'queue',
			"Route '{$slug}' resumed: {$count} job(s) re-queued.",
			array(
				'ref_id'     => 0,
				'route_slug' => $slug,
				'count'      => $count,
			)
		);

		return $count;
	}

	// -------------------------------------------------------------------------
	// Query helpers
	// -------------------------------------------------------------------------

	/**
	 * Paginated job list with optional filters.
	 *
	 * Recognised $args keys:
	 *   status     string   Filter by status enum value.
	 *   route_slug string   Filter by route.
	 *   per_page   int      Defaults to 20, max 100.
	 *   offset     int      Defaults to 0.
	 *
	 * @param array $args Filter/pagination arguments.
	 * @return array Array of job rows as associative arrays.
	 */
	public static function get_jobs( array $args = array() ): array {
		global $wpdb;

		$table    = $wpdb->prefix . WRM_Installer::JOBS_TABLE;
		$per_page = min( (int) ( $args['per_page'] ?? 20 ), 100 );
		$offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where    = '1=1';
		$params   = array();

		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		if ( ! empty( $args['route_slug'] ) ) {
			$where   .= ' AND route_slug = %s';
			$params[] = sanitize_title( $args['route_slug'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where   .= ' AND (error_message LIKE %s OR route_slug LIKE %s)';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				...$params
			),
			ARRAY_A
		) ?? array();
	}

	/**
	 * Fetch a single job by ID.
	 *
	 * @param int $id Job ID.
	 * @return array|null Job row or null if not found.
	 */
	public static function get_job( int $id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . WRM_Installer::JOBS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ?: null;
	}
}
