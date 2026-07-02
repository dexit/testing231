<?php
/**
 * Dynamic REST route registration and request handling.
 *
 * Reads routes from wrm_routes table → registers REST endpoints →
 * normalizes via WRM_Providers → captures payload → executes mapping sync/async.
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Router {

	private static array $sig_status_cache = [];

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::ROUTES_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$routes = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active'" );

		foreach ( $routes as $route ) {
			register_rest_route(
				'wrm/v1',
				'/' . $route->slug,
				array(
					'methods'             => strtoupper( $route->methods ),
					'callback'            => static function ( WP_REST_Request $req ) use ( $route ) {
						return self::handle_request( $route, $req );
					},
					'permission_callback' => static function ( WP_REST_Request $req ) use ( $route ) {
						return self::check_auth( $route, $req );
					},
				)
			);
		}
	}

	private static function check_auth( object $route, WP_REST_Request $req ): bool {
		$verified = WRM_Providers::verify_signature( $route->provider, $req, $route->auth_token );
		if ( null !== $verified ) {
			self::$sig_status_cache[ $route->slug ] = $verified ? 'verified' : 'failed';
			return $verified;
		}
		self::$sig_status_cache[ $route->slug ] = 'skipped';

		if ( ! $route->auth_token ) {
			return true;
		}
		$header = $req->get_header( 'x-wrm-token' );
		if ( $header && hash_equals( $route->auth_token, $header ) ) {
			return true;
		}
		$query = sanitize_text_field( $req->get_param( 'wrm_token' ) ?? '' );
		return $query && hash_equals( $route->auth_token, $query );
	}

	private static function handle_request( object $route, WP_REST_Request $req ): WP_REST_Response {
		// IP access check
		if ( ! self::check_ip_access( $route, $req ) ) {
			WRM_Logger::warning(
				'router',
				'IP blocked',
				array(
					'ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
					'route' => $route->slug,
				)
			);
			return new WP_REST_Response( array( 'status' => 'forbidden' ), 403 );
		}

		// Rate limit check
		if ( (int) $route->rate_limit > 0 && ! self::check_rate_limit( $route ) ) {
			return new WP_REST_Response( array( 'status' => 'rate_limited' ), 429 );
		}

		$payload    = WRM_Providers::normalize( $route->provider, $req );
		$sig_status = self::$sig_status_cache[ $route->slug ] ?? 'skipped';
		unset( self::$sig_status_cache[ $route->slug ] );

		$capture_id = WRM_Capture::store(
			$route->slug,
			$req->get_method(),
			$route->provider,
			$payload,
			$req,
			$sig_status
		);

		WRM_Metrics::increment( $route->slug, 'captures' );

		if ( ! (int) $route->mapping_id ) {
			return new WP_REST_Response(
				array(
					'status'     => 'captured',
					'capture_id' => $capture_id,
				),
				200
			);
		}

		if ( 'sync' === $route->run_mode ) {
			$result = WRM_Mapper::apply( $capture_id, (int) $route->mapping_id );
			WRM_Capture::mark_mapped( $capture_id, wp_json_encode( $result ) );
			return new WP_REST_Response( $result, 200 );
		}

		$job_id = WRM_Job_Queue::enqueue( $route->slug, $capture_id, (int) $route->mapping_id );
		return new WP_REST_Response(
			array(
				'status'     => 'queued',
				'job_id'     => $job_id,
				'capture_id' => $capture_id,
			),
			202
		);
	}

	private static function check_rate_limit( object $route ): bool {
		$key     = 'wrm_rl_' . md5( $route->slug );
		$win_key = 'wrm_rlw_' . md5( $route->slug );
		$limit   = (int) $route->rate_limit;
		$window  = (int) $route->rate_window;

		// Window end stored as its own transient so it works on object-cache installs
		$win_end = (int) get_transient( $win_key );
		if ( ! $win_end || $win_end <= time() ) {
			// New window — reset counter
			set_transient( $key, 1, $window );
			set_transient( $win_key, time() + $window, $window + 5 );
			return true;
		}

		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}

		$remaining = $win_end - time();
		set_transient( $key, $count + 1, max( 1, $remaining ) );
		return true;
	}

	private static function check_ip_access( object $route, WP_REST_Request $req ): bool {
		$source_ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
		$allowlist = trim( $route->ip_allowlist ?? '' );
		$blocklist = trim( $route->ip_blocklist ?? '' );

		// Blocklist takes precedence
		if ( $blocklist ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', $blocklist ) ) ) as $cidr ) {
				if ( self::ip_in_cidr( $source_ip, $cidr ) ) {
					return false;
				}
			}
		}

		// Allowlist: if set, source IP must match at least one entry
		if ( $allowlist ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', $allowlist ) ) ) as $cidr ) {
				if ( self::ip_in_cidr( $source_ip, $cidr ) ) {
					return true;
				}
			}
			return false; // allowlist set but no match
		}

		return true; // no allowlist = allow all
	}

	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			return $ip === $cidr;
		}
		[ $subnet, $prefix ] = explode( '/', $cidr, 2 );
		$prefix              = (int) $prefix;
		$ip_bin              = inet_pton( $ip );
		$sub_bin             = inet_pton( $subnet );
		if ( false === $ip_bin || false === $sub_bin || strlen( $ip_bin ) !== strlen( $sub_bin ) ) {
			return false;
		}
		$bits   = strlen( $ip_bin ) * 8;
		$prefix = min( $prefix, $bits );
		// Compare bit-by-bit using XOR + mask
		$mask_bin = str_repeat( "\xff", (int) floor( $prefix / 8 ) );
		$rem      = $prefix % 8;
		if ( $rem > 0 ) {
			$mask_bin .= chr( 0xff & ( 0xff << ( 8 - $rem ) ) );
		}
		$mask_bin = str_pad( $mask_bin, strlen( $ip_bin ), "\x00" );
		return ( $ip_bin & $mask_bin ) === ( $sub_bin & $mask_bin );
	}

	public static function get_rate_usage( string $slug ): array {
		$key     = 'wrm_rl_' . md5( $slug );
		$win_key = 'wrm_rlw_' . md5( $slug );
		$route   = self::get_route_by_slug( $slug );
		if ( ! $route ) {
			return array();
		}
		$limit   = (int) $route['rate_limit'];
		$window  = (int) $route['rate_window'];
		$used    = max( 0, (int) get_transient( $key ) );
		$win_end = (int) get_transient( $win_key );
		return array(
			'route_slug'      => $slug,
			'rate_limit'      => $limit,
			'rate_window'     => $window,
			'used'            => $used,
			'pct'             => $limit > 0 ? round( $used / $limit * 100, 1 ) : 0,
			'window_reset_at' => $win_end ? gmdate( 'Y-m-d H:i:s', $win_end ) : null,
		);
	}

	// -------------------------------------------------------------------------
	// CRUD helpers used by WRM_Admin_API
	// -------------------------------------------------------------------------

	public static function get_routes( int $per_page = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::ROUTES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		) ?? array();
	}

	public static function get_route_by_slug( string $slug ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::ROUTES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", sanitize_title( $slug ) ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function insert_route( array $data ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . WRM_Installer::ROUTES_TABLE,
			array(
				'slug'         => sanitize_title( $data['slug'] ?? '' ),
				'label'        => sanitize_text_field( $data['label'] ?? '' ),
				'methods'      => strtoupper( sanitize_text_field( $data['methods'] ?? 'POST' ) ),
				'provider'     => sanitize_key( $data['provider'] ?? 'custom' ),
				'auth_token'   => sanitize_text_field( $data['auth_token'] ?? '' ),
				'rate_limit'   => (int) ( $data['rate_limit'] ?? 0 ),
				'rate_window'  => (int) ( $data['rate_window'] ?? 60 ),
				'run_mode'     => in_array( $data['run_mode'] ?? '', array( 'sync', 'async' ), true ) ? $data['run_mode'] : 'async',
				'mapping_id'   => (int) ( $data['mapping_id'] ?? 0 ),
				'status'       => 'active',
				'created_at'   => current_time( 'mysql' ),
				'ip_allowlist' => sanitize_textarea_field( $data['ip_allowlist'] ?? '' ),
				'ip_blocklist' => sanitize_textarea_field( $data['ip_blocklist'] ?? '' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function update_route( string $slug, array $data ): bool {
		global $wpdb;
		$allowed = array( 'label', 'methods', 'provider', 'auth_token', 'rate_limit', 'rate_window', 'run_mode', 'mapping_id', 'status', 'ip_allowlist', 'ip_blocklist' );
		$update  = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$update[ $key ] = match ( $key ) {
				'label'        => sanitize_text_field( $data[ $key ] ),
				'methods'      => strtoupper( sanitize_text_field( $data[ $key ] ) ),
				'provider'     => sanitize_key( $data[ $key ] ),
				'auth_token'   => sanitize_text_field( $data[ $key ] ),
				'rate_limit'   => (int) $data[ $key ],
				'rate_window'  => (int) $data[ $key ],
				'run_mode'     => in_array( $data[ $key ], array( 'sync', 'async' ), true ) ? $data[ $key ] : 'async',
				'mapping_id'   => (int) $data[ $key ],
				'status'       => in_array( $data[ $key ], array( 'active', 'paused' ), true ) ? $data[ $key ] : 'active',
				'ip_allowlist', 'ip_blocklist' => sanitize_textarea_field( $data[ $key ] ), // @phpstan-ignore-line
				default        => sanitize_text_field( $data[ $key ] ),
			};
		}
		if ( empty( $update ) ) {
			return false;
		}
		$rows = $wpdb->update(
			$wpdb->prefix . WRM_Installer::ROUTES_TABLE,
			$update,
			array( 'slug' => sanitize_title( $slug ) )
		);
		return false !== $rows;
	}

	public static function delete_route( string $slug ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (bool) $wpdb->delete(
			$wpdb->prefix . WRM_Installer::ROUTES_TABLE,
			array( 'slug' => sanitize_title( $slug ) ),
			array( '%s' )
		);
	}
}
