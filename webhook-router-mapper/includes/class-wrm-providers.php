<?php
/**
 * Provider normalisation and signature verification.
 *
 * Supported providers: twilio, hubspot, whatsapp, generic (fallback).
 *
 * normalize() returns a structured array for each provider so the rest of
 * the plugin always works with a consistent shape regardless of origin.
 *
 * verify_signature() returns:
 *   null  — provider has no signature mechanism (skip)
 *   true  — signature is valid
 *   false — signature is invalid (reject request)
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Providers {

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Normalize an incoming request for the given provider.
	 *
	 * @param string          $provider Provider slug (twilio|hubspot|whatsapp|*).
	 * @param WP_REST_Request $req      Incoming REST request.
	 * @return array Normalized payload array; contains 'error' key on failure.
	 */
	public static function normalize( string $provider, WP_REST_Request $req ): array {
		try {
			switch ( sanitize_key( $provider ) ) {
				case 'twilio':
					return self::normalize_twilio( $req );

				case 'hubspot':
					return self::normalize_hubspot( $req );

				case 'whatsapp':
					return self::normalize_whatsapp( $req );

				default:
					return self::normalize_generic( $req );
			}
		} catch ( \Throwable $e ) {
			WRM_Logger::exception(
				'provider',
				$e,
				array( 'provider' => $provider )
			);

			return array(
				'provider' => $provider,
				'raw'      => array(),
				'error'    => $e->getMessage(),
			);
		}
	}

	/**
	 * Verify the request signature for a provider.
	 *
	 * @param string          $provider Provider slug.
	 * @param WP_REST_Request $req      Incoming REST request.
	 * @param string          $secret   Shared secret / auth token configured on the route.
	 * @return bool|null True=valid, false=invalid, null=no mechanism for this provider.
	 */
	public static function verify_signature(
		string $provider,
		WP_REST_Request $req,
		string $secret
	): ?bool {
		if ( ! $secret ) {
			return null;
		}

		switch ( sanitize_key( $provider ) ) {
			case 'twilio':
				$valid = self::verify_twilio( $req, $secret );
				break;

			case 'hubspot':
				$valid = self::verify_hubspot( $req, $secret );
				break;

			case 'whatsapp':
				$valid = self::verify_whatsapp( $req, $secret );
				break;

			default:
				// No provider-specific mechanism; fall through to generic token auth.
				return null;
		}

		if ( ! $valid ) {
			WRM_Logger::warning(
				'provider',
				"Signature verification failed for provider '{$provider}'.",
				array( 'provider' => $provider )
			);
		}

		return $valid;
	}

	// -------------------------------------------------------------------------
	// Provider normalisers (private)
	// -------------------------------------------------------------------------

	/**
	 * Normalise a Twilio webhook (application/x-www-form-urlencoded).
	 *
	 * @param WP_REST_Request $req Incoming request.
	 * @return array Normalised payload.
	 */
	private static function normalize_twilio( WP_REST_Request $req ): array {
		// Twilio sends form-encoded bodies.
		$body = array();
		$raw  = $req->get_body();
		wp_parse_str( $raw, $body );

		$from   = sanitize_text_field( $body['From']       ?? '' );
		$to     = sanitize_text_field( $body['To']         ?? '' );
		$text   = sanitize_text_field( $body['Body']       ?? '' );
		$sid    = sanitize_text_field( $body['MessageSid'] ?? $body['SmsSid'] ?? '' );
		$status = sanitize_text_field( $body['SmsStatus']  ?? $body['MessageStatus'] ?? '' );

		return array(
			'provider' => 'twilio',
			'from'     => $from,
			'to'       => $to,
			'body'     => $text,
			'sid'      => $sid,
			'status'   => $status,
			'raw'      => $body,
		);
	}

	/**
	 * Normalise a HubSpot webhook (JSON array of event objects).
	 *
	 * @param WP_REST_Request $req Incoming request.
	 * @return array Normalised payload.
	 */
	private static function normalize_hubspot( WP_REST_Request $req ): array {
		$raw_body = $req->get_body();
		$events   = json_decode( $raw_body, true );

		if ( ! is_array( $events ) ) {
			$events = array();
		}

		// Use the first event for top-level convenience fields.
		$first = isset( $events[0] ) && is_array( $events[0] ) ? $events[0] : array();

		return array(
			'provider'       => 'hubspot',
			'event_type'     => sanitize_text_field( $first['subscriptionType'] ?? '' ),
			'object_id'      => (int) ( $first['objectId']      ?? 0 ),
			'property_name'  => sanitize_text_field( $first['propertyName']  ?? '' ),
			'property_value' => sanitize_text_field( $first['propertyValue'] ?? '' ),
			'portal_id'      => (int) ( $first['portalId']      ?? 0 ),
			'events'         => $events,
			'raw'            => $events,
		);
	}

	/**
	 * Normalise a Meta/WhatsApp Cloud API webhook.
	 *
	 * @param WP_REST_Request $req Incoming request.
	 * @return array Normalised payload.
	 */
	private static function normalize_whatsapp( WP_REST_Request $req ): array {
		$raw_body = $req->get_body();
		$data     = json_decode( $raw_body, true );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		// Dig through the nested Meta Cloud API structure.
		$entry   = $data['entry'][0]     ?? array();
		$changes = $entry['changes'][0]  ?? array();
		$value   = $changes['value']     ?? array();
		$message = $value['messages'][0] ?? array();
		$meta    = $value['metadata']    ?? array();

		$msg_type = sanitize_key( $message['type'] ?? 'unknown' );

		// Extract message text based on type.
		$text = '';
		if ( 'text' === $msg_type ) {
			$text = sanitize_text_field( $message['text']['body'] ?? '' );
		} elseif ( isset( $message[ $msg_type ]['caption'] ) ) {
			$text = sanitize_text_field( $message[ $msg_type ]['caption'] );
		}

		return array(
			'provider'   => 'whatsapp',
			'from'       => sanitize_text_field( $message['from']               ?? '' ),
			'to'         => sanitize_text_field( $meta['display_phone_number']  ?? '' ),
			'message_id' => sanitize_text_field( $message['id']                 ?? '' ),
			'type'       => $msg_type,
			'text'       => $text,
			'timestamp'  => sanitize_text_field( $message['timestamp']          ?? '' ),
			'phone_id'   => sanitize_text_field( $meta['phone_number_id']       ?? '' ),
			'raw'        => $data,
		);
	}

	/**
	 * Generic normaliser — merges query params, body params, and JSON body.
	 *
	 * @param WP_REST_Request $req Incoming request.
	 * @return array Merged parameter array.
	 */
	private static function normalize_generic( WP_REST_Request $req ): array {
		$query = $req->get_query_params() ?? array();
		$body  = $req->get_body_params()  ?? array();
		$json  = $req->get_json_params()  ?? array();

		// JSON body takes precedence; body params fall back; query params last.
		$merged = array_merge( $query, $body, is_array( $json ) ? $json : array() );

		return array_merge( $merged, array( 'provider' => 'generic' ) );
	}

	// -------------------------------------------------------------------------
	// Signature verifiers (private)
	// -------------------------------------------------------------------------

	/**
	 * Verify a Twilio request signature (HMAC-SHA1).
	 *
	 * Twilio signs: fully-qualified URL + alphabetically sorted POST params
	 * concatenated as key=value pairs (no separator), then base64 of HMAC-SHA1.
	 *
	 * @param WP_REST_Request $req        Incoming request.
	 * @param string          $auth_token Twilio auth token.
	 * @return bool
	 */
	private static function verify_twilio( WP_REST_Request $req, string $auth_token ): bool {
		$signature = $req->get_header( 'x-twilio-signature' );
		if ( ! $signature ) {
			return false;
		}

		// Build the full URL as Twilio signed it, using the actual incoming request
		// URL rather than home_url() to avoid mismatches behind proxies/CDNs.
		$scheme = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ? 'https' : 'http';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		$path   = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? $req->get_route() ) );
		$url    = $scheme . '://' . $host . $path;

		// Form params alphabetically sorted, values appended directly.
		$params = array();
		wp_parse_str( $req->get_body(), $params );
		ksort( $params );

		$data = $url;
		foreach ( $params as $key => $val ) {
			$data .= $key . $val;
		}

		$expected = base64_encode( hash_hmac( 'sha1', $data, $auth_token, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return hash_equals( $expected, $signature );
	}

	/**
	 * Verify a HubSpot v1 signature (SHA-256 of secret + raw body).
	 *
	 * @param WP_REST_Request $req    Incoming request.
	 * @param string          $secret HubSpot client secret.
	 * @return bool
	 */
	private static function verify_hubspot( WP_REST_Request $req, string $secret ): bool {
		$signature = $req->get_header( 'x-hubspot-signature' );
		if ( ! $signature ) {
			return false;
		}

		$expected = hash( 'sha256', $secret . $req->get_body() );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Verify a Meta/WhatsApp Cloud API signature (HMAC-SHA256).
	 *
	 * Header format: sha256=<hex_digest>
	 *
	 * @param WP_REST_Request $req    Incoming request.
	 * @param string          $secret App secret.
	 * @return bool
	 */
	private static function verify_whatsapp( WP_REST_Request $req, string $secret ): bool {
		$header = $req->get_header( 'x-hub-signature-256' );
		if ( ! $header ) {
			return false;
		}

		// Strip the 'sha256=' prefix if present.
		$received = str_starts_with( $header, 'sha256=' ) ? substr( $header, 7 ) : $header;

		$expected = hash_hmac( 'sha256', $req->get_body(), $secret );

		return hash_equals( $expected, $received );
	}
}
