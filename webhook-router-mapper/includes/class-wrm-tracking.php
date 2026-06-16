<?php
/**
 * Message tracking — opens, clicks, and deliverability for outbound
 * emails / SMS / WhatsApp messages.
 *
 * Each sent message gets a row in wrm_messages keyed by a random token.
 * - Email opens: a 1x1 pixel <img> pointing at /track/open/{token} is
 *   injected; the hit records an 'open' event.
 * - Clicks: links in HTML emails are rewritten through /track/click/{token}
 *   which records a 'click' event then 302-redirects to the original URL.
 * - Deliverability: providers POST status callbacks to
 *   /track/status/{provider} which are normalized into delivered/bounced/
 *   failed/complaint events and reflected on the message status.
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Tracking {

	const string CONTEXT = 'tracking';

	/** Transparent 1x1 GIF. */
	const string PIXEL_GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	// -------------------------------------------------------------------------
	// Message records
	// -------------------------------------------------------------------------

	/**
	 * Create a message row and return [ id, token ].
	 *
	 * @return array{id:int,token:string}
	 */
	public static function create_message( array $data ): array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::MESSAGES_TABLE;
		$token = wp_generate_password( 32, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'token'               => $token,
				'channel'             => sanitize_key( $data['channel'] ?? 'email' ),
				'provider'            => sanitize_text_field( $data['provider'] ?? '' ),
				'recipient'           => sanitize_text_field( $data['recipient'] ?? '' ),
				'subject'             => sanitize_text_field( $data['subject'] ?? '' ),
				'mapping_id'          => (int) ( $data['mapping_id'] ?? 0 ),
				'capture_id'          => (int) ( $data['capture_id'] ?? 0 ),
				'provider_message_id' => sanitize_text_field( $data['provider_message_id'] ?? '' ),
				'status'              => sanitize_key( $data['status'] ?? 'queued' ),
				'sent_at'             => ( $data['status'] ?? '' ) === 'sent' ? current_time( 'mysql', true ) : null,
				'created_at'          => current_time( 'mysql', true ),
			)
		);

		return array( 'id' => (int) $wpdb->insert_id, 'token' => $token );
	}

	/**
	 * Update a message's status and/or provider_message_id.
	 */
	public static function update_message( int $id, array $fields ): void {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::MESSAGES_TABLE;
		$allowed = array();
		if ( isset( $fields['status'] ) ) {
			$allowed['status'] = sanitize_key( $fields['status'] );
		}
		if ( isset( $fields['provider_message_id'] ) ) {
			$allowed['provider_message_id'] = sanitize_text_field( $fields['provider_message_id'] );
		}
		if ( isset( $fields['sent_at'] ) ) {
			$allowed['sent_at'] = $fields['sent_at'];
		}
		if ( $allowed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $table, $allowed, array( 'id' => $id ) );
		}
	}

	public static function get_message( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::MESSAGES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) ?: null;
	}

	public static function get_by_token( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::MESSAGES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ), ARRAY_A ) ?: null;
	}

	public static function get_by_provider_message_id( string $provider, string $pmid ): ?array {
		if ( '' === $pmid ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::MESSAGES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE provider = %s AND provider_message_id = %s ORDER BY id DESC LIMIT 1", $provider, $pmid ),
			ARRAY_A
		) ?: null;
	}

	/**
	 * Record an engagement/deliverability event and roll up counters on the
	 * parent message.
	 */
	public static function record_event( int $message_id, string $event, array $extra = array() ): void {
		global $wpdb;
		$events_table   = $wpdb->prefix . WRM_Installer::MESSAGE_EVENTS_TABLE;
		$messages_table = $wpdb->prefix . WRM_Installer::MESSAGES_TABLE;
		$event          = sanitize_key( $event );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$events_table,
			array(
				'message_id' => $message_id,
				'event'      => $event,
				'url'        => esc_url_raw( (string) ( $extra['url'] ?? '' ) ),
				'ip'         => sanitize_text_field( (string) ( $extra['ip'] ?? '' ) ),
				'user_agent' => substr( sanitize_text_field( (string) ( $extra['user_agent'] ?? '' ) ), 0, 255 ),
				'data'       => empty( $extra['data'] ) ? null : wp_json_encode( $extra['data'] ),
				'created_at' => current_time( 'mysql', true ),
			)
		);

		// Roll up counters / status transitions.
		if ( 'open' === $event ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$messages_table} SET open_count = open_count + 1, first_open_at = COALESCE(first_open_at, %s), status = IF(status IN ('clicked','bounced','failed','complaint'), status, 'opened') WHERE id = %d", current_time( 'mysql', true ), $message_id ) );
		} elseif ( 'click' === $event ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$messages_table} SET click_count = click_count + 1, status = 'clicked' WHERE id = %d", $message_id ) );
		} elseif ( in_array( $event, array( 'delivered', 'bounced', 'failed', 'complaint' ), true ) ) {
			self::update_message( $message_id, array( 'status' => $event ) );
		}
	}

	// -------------------------------------------------------------------------
	// HTML rewriting (open pixel + click tracking)
	// -------------------------------------------------------------------------

	/**
	 * Inject the open-tracking pixel and rewrite anchor hrefs to the click
	 * tracker. Returns the modified HTML.
	 */
	public static function instrument_html( string $html, string $token ): string {
		$html = self::rewrite_links( $html, $token );

		$pixel = '<img src="' . esc_url( self::open_url( $token ) ) . '" width="1" height="1" alt="" style="display:none;width:1px;height:1px;" />';

		if ( stripos( $html, '</body>' ) !== false ) {
			return str_ireplace( '</body>', $pixel . '</body>', $html );
		}
		return $html . $pixel;
	}

	/**
	 * Rewrite http(s) anchor hrefs through the click tracker, preserving the
	 * original destination in the `u` query arg.
	 */
	private static function rewrite_links( string $html, string $token ): string {
		return (string) preg_replace_callback(
			'/(<a\b[^>]*\bhref=)(["\'])(https?:\/\/[^"\']+)(\2)/i',
			static function ( array $m ) use ( $token ): string {
				$tracked = self::click_url( $token, $m[3] );
				return $m[1] . $m[2] . esc_url( $tracked ) . $m[4];
			},
			$html
		);
	}

	// -------------------------------------------------------------------------
	// URL builders
	// -------------------------------------------------------------------------

	public static function open_url( string $token ): string {
		return rest_url( WRM_Admin_API::NAMESPACE . '/track/open/' . rawurlencode( $token ) );
	}

	public static function click_url( string $token, string $target ): string {
		return add_query_arg(
			'u',
			rawurlencode( $target ),
			rest_url( WRM_Admin_API::NAMESPACE . '/track/click/' . rawurlencode( $token ) )
		);
	}

	// -------------------------------------------------------------------------
	// REST handlers (registered by WRM_Admin_API)
	// -------------------------------------------------------------------------

	/**
	 * Open pixel: record the open and emit a 1x1 GIF. Always 200.
	 */
	public static function handle_open( WP_REST_Request $req ): void {
		$message = self::get_by_token( (string) $req->get_param( 'token' ) );
		if ( $message ) {
			self::record_event(
				(int) $message['id'],
				'open',
				array(
					'ip'         => self::client_ip(),
					'user_agent' => (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
				)
			);
		}

		// Emit the pixel directly (bypass REST JSON serialization).
		if ( ! headers_sent() ) {
			header( 'Content-Type: image/gif' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
		}
		echo base64_decode( self::PIXEL_GIF ); // phpcs:ignore
		exit;
	}

	/**
	 * Click tracker: record the click, then redirect to the original URL.
	 */
	public static function handle_click( WP_REST_Request $req ): void {
		$message = self::get_by_token( (string) $req->get_param( 'token' ) );
		$raw     = (string) $req->get_param( 'u' );

		// Validate the raw value (absolute http(s)) BEFORE normalizing, then
		// redirect to it. NOTE: click tracking must reach arbitrary EXTERNAL
		// hosts, so wp_redirect (not wp_safe_redirect, which forces same-host).
		$target = wp_http_validate_url( $raw ) ? $raw : home_url( '/' );

		if ( $message ) {
			self::record_event(
				(int) $message['id'],
				'click',
				array(
					'url'        => esc_url_raw( $target ),
					'ip'         => self::client_ip(),
					'user_agent' => (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
				)
			);
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		wp_redirect( $target, 302 );
		exit;
	}

	/**
	 * Provider deliverability webhook. Normalizes a handful of well-known
	 * provider payload shapes (Twilio, SendGrid, Meta/WhatsApp) into events.
	 * Integrators can fully customize via the `wrm_track_status_event` filter.
	 */
	public static function handle_status( WP_REST_Request $req ): WP_REST_Response {
		$provider = sanitize_key( (string) $req->get_param( 'provider' ) );

		// Trust model: this endpoint is public so providers can reach it.
		// Two opt-in protections — (1) a shared secret in the option
		// wrm_track_status_secret matched against ?secret=, and (2) a
		// wrm_verify_status_signature filter for full provider HMAC checks.
		// Both default to allow so the feature works out of the box.
		$secret = (string) get_option( 'wrm_track_status_secret', '' );
		if ( '' !== $secret && ! hash_equals( $secret, (string) $req->get_param( 'secret' ) ) ) {
			return new WP_REST_Response( array( 'error' => 'Forbidden.' ), 403 );
		}
		if ( ! apply_filters( 'wrm_verify_status_signature', true, $provider, $req ) ) {
			WRM_Logger::warning( self::CONTEXT, "Status webhook rejected by signature filter ({$provider}).", array( 'provider' => $provider ) );
			return new WP_REST_Response( array( 'error' => 'Invalid signature.' ), 403 );
		}

		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			// Twilio posts form-encoded.
			$body = $req->get_body_params() ?: array();
		}

		$events = self::normalize_status( $provider, $body );
		$events = (array) apply_filters( 'wrm_track_status_event', $events, $provider, $body, $req );

		$applied = 0;
		foreach ( $events as $evt ) {
			$pmid    = (string) ( $evt['provider_message_id'] ?? '' );
			$status  = sanitize_key( (string) ( $evt['event'] ?? '' ) );
			$message = self::get_by_provider_message_id( $provider, $pmid );
			if ( $message && $status ) {
				self::record_event( (int) $message['id'], $status, array( 'data' => $body ) );
				++$applied;
			}
		}

		WRM_Logger::info( self::CONTEXT, "Status webhook from {$provider}: {$applied} event(s) applied.", array( 'provider' => $provider ) );
		return new WP_REST_Response( array( 'ok' => true, 'applied' => $applied ), 200 );
	}

	/**
	 * Map a provider status payload to normalized events.
	 *
	 * @return array<int,array{provider_message_id:string,event:string}>
	 */
	private static function normalize_status( string $provider, array $body ): array {
		$map_status = static function ( string $raw ): string {
			$raw = strtolower( $raw );
			return match ( true ) {
				in_array( $raw, array( 'delivered', 'delivery', 'read' ), true )            => 'delivered',
				in_array( $raw, array( 'bounce', 'bounced', 'dropped' ), true )             => 'bounced',
				in_array( $raw, array( 'undelivered', 'failed', 'error' ), true )           => 'failed',
				in_array( $raw, array( 'complaint', 'spamreport', 'complained' ), true )    => 'complaint',
				default                                                                      => '',
			};
		};

		switch ( $provider ) {
			case 'twilio':
				$status = $map_status( (string) ( $body['MessageStatus'] ?? $body['SmsStatus'] ?? '' ) );
				$sid    = (string) ( $body['MessageSid'] ?? $body['SmsSid'] ?? '' );
				return $status && $sid ? array( array( 'provider_message_id' => $sid, 'event' => $status ) ) : array();

			case 'sendgrid':
				// SendGrid posts an array of event objects.
				$out = array();
				$rows = isset( $body[0] ) ? $body : array( $body );
				foreach ( $rows as $row ) {
					$status = $map_status( (string) ( $row['event'] ?? '' ) );
					$pmid   = (string) ( $row['sg_message_id'] ?? $row['smtp-id'] ?? '' );
					if ( $status && $pmid ) {
						$out[] = array( 'provider_message_id' => $pmid, 'event' => $status );
					}
				}
				return $out;

			case 'whatsapp':
				// Meta WhatsApp Cloud status webhook.
				$out = array();
				$entries = $body['entry'] ?? array();
				foreach ( (array) $entries as $entry ) {
					foreach ( (array) ( $entry['changes'] ?? array() ) as $change ) {
						foreach ( (array) ( $change['value']['statuses'] ?? array() ) as $st ) {
							$status = $map_status( (string) ( $st['status'] ?? '' ) );
							$pmid   = (string) ( $st['id'] ?? '' );
							if ( $status && $pmid ) {
								$out[] = array( 'provider_message_id' => $pmid, 'event' => $status );
							}
						}
					}
				}
				return $out;

			default:
				// Generic shape: { provider_message_id, status }
				$status = $map_status( (string) ( $body['status'] ?? '' ) );
				$pmid   = (string) ( $body['provider_message_id'] ?? $body['message_id'] ?? '' );
				return $status && $pmid ? array( array( 'provider_message_id' => $pmid, 'event' => $status ) ) : array();
		}
	}

	// -------------------------------------------------------------------------
	// Queries for the admin UI
	// -------------------------------------------------------------------------

	public static function list_messages( array $args = array() ): array {
		global $wpdb;
		$table    = $wpdb->prefix . WRM_Installer::MESSAGES_TABLE;
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 25 ) ) );
		$offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where    = '1=1';
		$params   = array();

		if ( ! empty( $args['channel'] ) ) {
			$where   .= ' AND channel = %s';
			$params[] = sanitize_key( $args['channel'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = sanitize_key( $args['status'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where   .= ' AND (recipient LIKE %s OR subject LIKE %s)';
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", ...$params ), ARRAY_A ) ?? array();
	}

	public static function get_events( int $message_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::MESSAGE_EVENTS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE message_id = %d ORDER BY id ASC", $message_id ), ARRAY_A ) ?? array();
	}

	public static function stats(): array {
		$cached = get_transient( 'wrm_message_stats' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::MESSAGES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status", ARRAY_A ) ?? array();
		$counts = array_fill_keys( array( 'queued', 'sent', 'delivered', 'opened', 'clicked', 'bounced', 'failed', 'complaint' ), 0 );
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['cnt'];
		}
		$counts['total'] = array_sum( $counts );

		set_transient( 'wrm_message_stats', $counts, 60 );
		return $counts;
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	private static function client_ip(): string {
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}
}
