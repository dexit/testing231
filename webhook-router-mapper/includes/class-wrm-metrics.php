<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class WRM_Metrics {

	public static function increment( string $route_slug, string $field, int $amount = 1 ): void {
		global $wpdb;
		$allowed = array( 'captures', 'jobs_ok', 'jobs_failed' );
		if ( ! in_array( $field, $allowed, true ) ) {
			return;
		}
		$table  = $wpdb->prefix . WRM_Installer::METRICS_TABLE;
		$bucket = gmdate( 'Y-m-d H:00:00' );
		$slug   = sanitize_title( $route_slug );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names are internal constants, not user input.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (route_slug, bucket, {$field})
				 VALUES (%s, %s, %d)
				 ON DUPLICATE KEY UPDATE {$field} = {$field} + %d",
				$slug,
				$bucket,
				$amount,
				$amount
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get_hourly( string $route_slug, int $hours = 24 ): array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::METRICS_TABLE;
		$slug  = sanitize_title( $route_slug );
		$since = gmdate( 'Y-m-d H:00:00', time() - ( $hours * 3600 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bucket, captures, jobs_ok, jobs_failed
				 FROM {$table}
				 WHERE route_slug = %s AND bucket >= %s
				 ORDER BY bucket ASC",
				$slug,
				$since
			),
			ARRAY_A
		);

		// Build a full 24-hour map with zeros for missing buckets.
		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row['bucket'] ] = $row;
		}

		$result = array();
		for ( $i = $hours - 1; $i >= 0; $i-- ) {
			$key      = gmdate( 'Y-m-d H:00:00', time() - ( $i * 3600 ) );
			$result[] = isset( $map[ $key ] ) ? $map[ $key ] : array(
				'bucket'      => $key,
				'captures'    => 0,
				'jobs_ok'     => 0,
				'jobs_failed' => 0,
			);
		}

		return $result;
	}
}
