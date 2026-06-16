<?php
/**
 * Scheduler — timer-driven and URL-triggered mapping runs.
 *
 * Until now every job was capture-driven (an inbound webhook). The scheduler
 * adds two non-webhook triggers:
 *
 *   1. Timer (cron):   run a mapping on an interval (every 5 min … weekly).
 *   2. URL trigger:    hit a secret token URL to run a mapping on demand.
 *
 * A schedule supplies the payload one of two ways:
 *   - seed_payload: a fixed JSON object stored on the schedule, or
 *   - source_url:   an HTTP(S) endpoint fetched at run time (ETL pull). The
 *                   decoded JSON body becomes the payload.
 *
 * Runs flow through the normal capture -> mapper/job pipeline, so logging,
 * retries and the job queue all apply.
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Scheduler {

	const string TICK_HOOK = 'wrm_scheduler_tick';

	/** Interval key => seconds. 'manual' has no timer (URL-trigger only). */
	const array INTERVALS = array(
		'manual'           => 0,
		'every_minute'     => 60,
		'every_5_minutes'  => 300,
		'every_15_minutes' => 900,
		'every_30_minutes' => 1800,
		'hourly'           => 3600,
		'twicedaily'       => 43200,
		'daily'            => 86400,
		'weekly'           => 604800,
	);

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );
		add_action( self::TICK_HOOK, array( __CLASS__, 'tick' ) );

		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time(), 'wrm_every_minute', self::TICK_HOOK );
		}
	}

	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::TICK_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::TICK_HOOK );
		}
	}

	/**
	 * Register the one-minute cadence (shared with the job queue if present).
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

	// -------------------------------------------------------------------------
	// Tick — run due schedules
	// -------------------------------------------------------------------------

	/**
	 * Master tick: run every active schedule whose next_run has arrived.
	 * A transient mutex guards against overlapping cron runs.
	 */
	public static function tick(): void {
		$lock = 'wrm_scheduler_lock';
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 90 );

		try {
			global $wpdb;
			$table = $wpdb->prefix . WRM_Installer::SCHEDULES_TABLE;
			// UTC — next_run is stored in UTC (gmdate), so compare in UTC too.
			$now   = current_time( 'mysql', true );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$due = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					 WHERE status = 'active' AND interval_key <> 'manual'
					   AND ( next_run IS NULL OR next_run <= %s )
					 ORDER BY next_run ASC LIMIT 20",
					$now
				),
				ARRAY_A
			) ?? array();

			foreach ( $due as $schedule ) {
				self::run( $schedule, 'timer' );
			}
		} finally {
			delete_transient( $lock );
		}
	}

	// -------------------------------------------------------------------------
	// Run a single schedule
	// -------------------------------------------------------------------------

	/**
	 * Execute one schedule: resolve payload, store a capture, then map sync or
	 * enqueue async. Updates last_run / next_run / last_result bookkeeping.
	 *
	 * @param array  $schedule Schedule row (ARRAY_A).
	 * @param string $trigger  'timer' | 'url' | 'manual' — for logging only.
	 * @return array Result summary.
	 */
	public static function run( array $schedule, string $trigger = 'manual' ): array {
		global $wpdb;
		$table      = $wpdb->prefix . WRM_Installer::SCHEDULES_TABLE;
		$id         = (int) $schedule['id'];
		$mapping_id = (int) $schedule['mapping_id'];

		$result = array( 'schedule_id' => $id, 'trigger' => $trigger );

		$interval_key = (string) ( $schedule['interval_key'] ?? 'manual' );

		if ( ! $mapping_id ) {
			$result['status'] = 'skipped';
			$result['reason'] = 'no_mapping_id';
			self::record_run( $id, $interval_key, $result );
			return $result;
		}

		$payload = self::resolve_payload( $schedule, $result );

		$capture_id = WRM_Capture::store_internal( '_schedule', 'scheduler', $payload );
		if ( ! $capture_id ) {
			$result['status'] = 'failed';
			$result['reason'] = 'capture_store_failed';
			self::record_run( $id, $interval_key, $result );
			return $result;
		}
		$result['capture_id'] = $capture_id;

		if ( 'sync' === sanitize_key( $schedule['run_mode'] ?? 'async' ) ) {
			$apply = WRM_Mapper::apply( $capture_id, $mapping_id );
			if ( ! empty( $apply['success'] ) ) {
				WRM_Capture::mark_mapped( $capture_id, wp_json_encode( $apply ) );
			}
			$result['status']  = ! empty( $apply['success'] ) ? 'done' : 'failed';
			$result['post_id'] = $apply['post_id'] ?? 0;
		} else {
			$job_id            = WRM_Job_Queue::enqueue( '_schedule', $capture_id, $mapping_id );
			$result['status']  = 'queued';
			$result['job_id']  = $job_id;
		}

		WRM_Logger::info(
			'scheduler',
			"Schedule #{$id} ran ({$trigger}).",
			array( 'ref_id' => $id, 'capture_id' => $capture_id, 'status' => $result['status'] )
		);

		self::record_run( $id, $interval_key, $result );
		return $result;
	}

	/**
	 * Resolve the payload for a run: fetch source_url if set, else seed_payload.
	 */
	private static function resolve_payload( array $schedule, array &$result ): array {
		$url = trim( (string) ( $schedule['source_url'] ?? '' ) );

		if ( '' !== $url ) {
			// SSRF guard — reuse the mapper's private/reserved-IP rejection.
			// Operators can override per-URL via the wrm_schedule_url_allowed filter.
			if ( ! apply_filters( 'wrm_schedule_url_allowed', WRM_Mapper::is_url_safe( $url ), $url, $schedule ) ) {
				WRM_Logger::warning( 'scheduler', 'Blocked unsafe source_url', array( 'url' => $url ) );
				$result['source_error'] = 'unsafe_url';
				return array();
			}

			$method   = self::sanitize_method( $schedule['source_method'] ?? 'GET' );
			$response = wp_remote_request( $url, array( 'method' => $method, 'timeout' => 20 ) );

			if ( is_wp_error( $response ) ) {
				WRM_Logger::error( 'scheduler', 'Source URL fetch failed: ' . $response->get_error_message(), array( 'url' => $url ) );
				$result['source_error'] = $response->get_error_message();
				return array();
			}

			$raw = wp_remote_retrieve_body( $response );
			if ( $raw && json_validate( $raw ) ) {
				$decoded = json_decode( $raw, true );
				return is_array( $decoded ) ? $decoded : array( '_raw' => $raw );
			}
			// Non-JSON response — store a capped copy and warn so it's diagnosable.
			WRM_Logger::warning( 'scheduler', 'Source URL returned non-JSON body', array( 'url' => $url ) );
			return array( '_raw' => mb_substr( (string) $raw, 0, 10000 ) );
		}

		$seed = (string) ( $schedule['seed_payload'] ?? '' );
		if ( $seed && json_validate( $seed ) ) {
			$decoded = json_decode( $seed, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
	}

	/**
	 * Persist last_run / next_run / last_result for a schedule.
	 * Times are stored in UTC so the tick comparison is timezone-safe.
	 */
	private static function record_run( int $id, string $interval_key, array $result ): void {
		global $wpdb;
		$table    = $wpdb->prefix . WRM_Installer::SCHEDULES_TABLE;
		$seconds  = self::INTERVALS[ $interval_key ] ?? 0;
		$next_run = $seconds > 0 ? gmdate( 'Y-m-d H:i:s', time() + $seconds ) : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			array(
				'last_run'    => current_time( 'mysql', true ),
				'next_run'    => $next_run,
				'last_result' => wp_json_encode( $result ),
			),
			array( 'id' => $id )
		);
	}

	// -------------------------------------------------------------------------
	// CRUD helpers (used by the REST API)
	// -------------------------------------------------------------------------

	public static function get_all(): array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::SCHEDULES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A ) ?? array();
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::SCHEDULES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) ?: null;
	}

	public static function get_by_token( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::SCHEDULES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE trigger_token = %s", $token ), ARRAY_A ) ?: null;
	}

	/**
	 * Create a schedule. Generates a trigger token automatically.
	 *
	 * @return int New schedule ID (0 on failure).
	 */
	public static function create( array $data ): int {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::SCHEDULES_TABLE;

		$interval_key = self::sanitize_interval( $data['interval_key'] ?? 'manual' );
		$seconds      = self::INTERVALS[ $interval_key ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'label'         => sanitize_text_field( $data['label'] ?? '' ),
				'mapping_id'    => (int) ( $data['mapping_id'] ?? 0 ),
				'interval_key'  => $interval_key,
				'seed_payload'  => self::sanitize_json( $data['seed_payload'] ?? '' ),
				'source_url'    => esc_url_raw( (string) ( $data['source_url'] ?? '' ) ),
				'source_method' => self::sanitize_method( $data['source_method'] ?? 'GET' ),
				'trigger_token' => wp_generate_password( 32, false ),
				'run_mode'      => sanitize_key( $data['run_mode'] ?? 'async' ) === 'sync' ? 'sync' : 'async',
				'status'        => 'active',
				'next_run'      => $seconds > 0 ? gmdate( 'Y-m-d H:i:s', time() + $seconds ) : null,
				'created_at'    => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . WRM_Installer::SCHEDULES_TABLE;
		$fields = array();

		if ( isset( $data['label'] ) ) {
			$fields['label'] = sanitize_text_field( $data['label'] );
		}
		if ( isset( $data['mapping_id'] ) ) {
			$fields['mapping_id'] = (int) $data['mapping_id'];
		}
		if ( isset( $data['interval_key'] ) ) {
			$fields['interval_key'] = self::sanitize_interval( $data['interval_key'] );
			$seconds                = self::INTERVALS[ $fields['interval_key'] ];
			$fields['next_run']     = $seconds > 0 ? gmdate( 'Y-m-d H:i:s', time() + $seconds ) : null;
		}
		if ( isset( $data['seed_payload'] ) ) {
			$fields['seed_payload'] = self::sanitize_json( $data['seed_payload'] );
		}
		if ( isset( $data['source_url'] ) ) {
			$fields['source_url'] = esc_url_raw( (string) $data['source_url'] );
		}
		if ( isset( $data['source_method'] ) ) {
			$fields['source_method'] = self::sanitize_method( (string) $data['source_method'] );
		}
		if ( isset( $data['run_mode'] ) ) {
			$fields['run_mode'] = sanitize_key( $data['run_mode'] ) === 'sync' ? 'sync' : 'async';
		}
		if ( isset( $data['status'] ) ) {
			$fields['status'] = sanitize_key( $data['status'] ) === 'paused' ? 'paused' : 'active';
		}

		if ( ! $fields ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->update( $table, $fields, array( 'id' => $id ) );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::SCHEDULES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (bool) $wpdb->delete( $table, array( 'id' => $id ) );
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	private static function sanitize_interval( string $key ): string {
		$key = sanitize_key( $key );
		return isset( self::INTERVALS[ $key ] ) ? $key : 'manual';
	}

	private static function sanitize_method( string $method ): string {
		$method = strtoupper( sanitize_text_field( $method ) );
		return in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD' ), true ) ? $method : 'GET';
	}

	private static function sanitize_json( string $raw ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		return json_validate( $raw ) ? $raw : null;
	}
}
