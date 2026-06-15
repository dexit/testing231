<?php
/**
 * WRM Messaging — multi-provider SMS / WhatsApp / message dispatch.
 *
 * Supported providers: twilio, whatsapp, sinch, messagemedia, webhook.
 *
 * Usage:
 *   $result = WRM_Messaging::send( 'twilio', $config, '+15550001234', 'Hello!' );
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Messaging {

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	const string CONTEXT = 'messaging';

	const string STATUS_SENT    = 'sent';
	const string STATUS_FAILED  = 'failed';
	const string STATUS_SKIPPED = 'skipped';

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns the list of supported provider keys for the UI / dispatch.
	 *
	 * @return string[]
	 */
	public static function providers(): array {
		return array( 'twilio', 'whatsapp', 'sinch', 'messagemedia', 'webhook' );
	}

	/**
	 * Send a message via $provider.
	 *
	 * $to and $body are already merge-tag-resolved strings.
	 *
	 * Returns a normalised result array:
	 *   [
	 *     'provider'   => string,
	 *     'status'     => 'sent' | 'failed' | 'skipped',
	 *     'message_id' => string,
	 *     'code'       => int,
	 *     'error'      => string,   // present only on failure / skip
	 *   ]
	 *
	 * @param string  $provider Provider key.
	 * @param array   $config   Provider credentials / settings.
	 * @param string  $to       Destination address (phone number, etc.).
	 * @param string  $body     Message body.
	 * @param array   $context  Optional extra context passed through to the webhook provider.
	 * @return array
	 */
	public static function send(
		string $provider,
		array $config,
		string $to,
		string $body,
		array $context = array()
	): array {
		$defaults = array(
			'provider'   => $provider,
			'status'     => self::STATUS_FAILED,
			'message_id' => '',
			'code'       => 0,
		);

		// Allow external code to short-circuit the send entirely.
		$override = apply_filters( 'wrm_send_message', null, $provider, $config, $to, $body, $context );
		if ( is_array( $override ) ) {
			$merged = array_merge( $defaults, $override );
			if ( empty( $merged['provider'] ) ) {
				$merged['provider'] = $provider;
			}
			if ( empty( $merged['status'] ) ) {
				$merged['status'] = self::STATUS_FAILED;
			}
			return $merged;
		}

		// Validate required fields.
		if ( '' === $to || '' === $body ) {
			return self::skipped( $provider, 'missing_to_or_body' );
		}

		// Dispatch to the appropriate private method.
		$key = sanitize_key( $provider );

		return match ( $key ) {
			'twilio'       => self::twilio( $config, $to, $body ),
			'whatsapp'     => self::whatsapp( $config, $to, $body ),
			'sinch'        => self::sinch( $config, $to, $body ),
			'messagemedia' => self::messagemedia( $config, $to, $body ),
			'webhook'      => self::webhook( $config, $to, $body, $context ),
			default        => self::skipped( $provider, 'unsupported_provider' ),
		};
	}

	// -------------------------------------------------------------------------
	// Per-provider private methods
	// -------------------------------------------------------------------------

	/**
	 * Send via Twilio SMS REST API.
	 */
	private static function twilio( array $config, string $to, string $body ): array {
		$sid   = (string) ( $config['account_sid'] ?? '' );
		$token = (string) ( $config['auth_token']  ?? '' );
		$from  = (string) ( $config['from']         ?? '' );

		if ( '' === $sid || '' === $token || '' === $from ) {
			return self::skipped( 'twilio', 'missing_credentials' );
		}

		$url = sprintf(
			'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
			rawurlencode( $sid )
		);

		$args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'To'   => $to,
				'From' => $from,
				'Body' => $body,
			),
			'timeout' => 15,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			WRM_Logger::error(
				self::CONTEXT,
				'twilio: wp_remote_post error',
				array( 'error' => $response->get_error_message() )
			);
			return self::failed( 'twilio', 0, $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = self::parse_json_body( wp_remote_retrieve_body( $response ) );
		$ok      = $code >= 200 && $code < 300;
		$msg_id  = (string) ( $decoded['sid'] ?? '' );

		WRM_Logger::info(
			self::CONTEXT,
			'twilio: message ' . ( $ok ? 'sent' : 'failed' ),
			array( 'code' => $code, 'message_id' => $msg_id, 'to' => $to )
		);

		if ( ! $ok ) {
			$error = (string) ( $decoded['message'] ?? $decoded['detail'] ?? 'http_error' );
			return self::failed( 'twilio', $code, $error );
		}

		return array(
			'provider'   => 'twilio',
			'status'     => self::STATUS_SENT,
			'message_id' => $msg_id,
			'code'       => $code,
		);
	}

	/**
	 * Send via Meta WhatsApp Cloud API.
	 */
	private static function whatsapp( array $config, string $to, string $body ): array {
		$phone_id    = (string) ( $config['phone_number_id'] ?? '' );
		$token       = (string) ( $config['access_token']    ?? '' );
		$api_version = (string) ( $config['api_version']     ?? 'v19.0' );

		if ( '' === $phone_id || '' === $token ) {
			return self::skipped( 'whatsapp', 'missing_credentials' );
		}

		$url = sprintf(
			'https://graph.facebook.com/%s/%s/messages',
			rawurlencode( $api_version ),
			rawurlencode( $phone_id )
		);

		$payload = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'text',
			'text'              => array(
				'preview_url' => false,
				'body'        => $body,
			),
		);

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			WRM_Logger::error(
				self::CONTEXT,
				'whatsapp: wp_remote_post error',
				array( 'error' => $response->get_error_message() )
			);
			return self::failed( 'whatsapp', 0, $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = self::parse_json_body( wp_remote_retrieve_body( $response ) );
		$ok      = $code >= 200 && $code < 300;
		$msg_id  = (string) ( $decoded['messages'][0]['id'] ?? '' );

		WRM_Logger::info(
			self::CONTEXT,
			'whatsapp: message ' . ( $ok ? 'sent' : 'failed' ),
			array( 'code' => $code, 'message_id' => $msg_id, 'to' => $to )
		);

		if ( ! $ok ) {
			$error = (string) ( $decoded['error']['message'] ?? 'http_error' );
			return self::failed( 'whatsapp', $code, $error );
		}

		return array(
			'provider'   => 'whatsapp',
			'status'     => self::STATUS_SENT,
			'message_id' => $msg_id,
			'code'       => $code,
		);
	}

	/**
	 * Send via Sinch SMS XMS API.
	 */
	private static function sinch( array $config, string $to, string $body ): array {
		$service_plan_id = (string) ( $config['service_plan_id'] ?? '' );
		$api_token       = (string) ( $config['api_token']       ?? '' );
		$from            = (string) ( $config['from']             ?? '' );

		if ( '' === $service_plan_id || '' === $api_token || '' === $from ) {
			return self::skipped( 'sinch', 'missing_credentials' );
		}

		$url = sprintf(
			'https://sms.api.sinch.com/xms/v1/%s/batches',
			rawurlencode( $service_plan_id )
		);

		$payload = array(
			'from' => $from,
			'to'   => array( $to ),
			'body' => $body,
		);

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			WRM_Logger::error(
				self::CONTEXT,
				'sinch: wp_remote_post error',
				array( 'error' => $response->get_error_message() )
			);
			return self::failed( 'sinch', 0, $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = self::parse_json_body( wp_remote_retrieve_body( $response ) );
		$ok      = $code >= 200 && $code < 300;
		$msg_id  = (string) ( $decoded['id'] ?? '' );

		WRM_Logger::info(
			self::CONTEXT,
			'sinch: message ' . ( $ok ? 'sent' : 'failed' ),
			array( 'code' => $code, 'message_id' => $msg_id, 'to' => $to )
		);

		if ( ! $ok ) {
			$error = (string) ( $decoded['text'] ?? $decoded['title'] ?? 'http_error' );
			return self::failed( 'sinch', $code, $error );
		}

		return array(
			'provider'   => 'sinch',
			'status'     => self::STATUS_SENT,
			'message_id' => $msg_id,
			'code'       => $code,
		);
	}

	/**
	 * Send via MessageMedia SMS API.
	 */
	private static function messagemedia( array $config, string $to, string $body ): array {
		$api_key    = (string) ( $config['api_key']    ?? '' );
		$api_secret = (string) ( $config['api_secret'] ?? '' );

		if ( '' === $api_key || '' === $api_secret ) {
			return self::skipped( 'messagemedia', 'missing_credentials' );
		}

		$url = 'https://api.messagemedia.com/v1/messages';

		$payload = array(
			'messages' => array(
				array(
					'content'            => $body,
					'destination_number' => $to,
					'format'             => 'SMS',
				),
			),
		);

		$args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			WRM_Logger::error(
				self::CONTEXT,
				'messagemedia: wp_remote_post error',
				array( 'error' => $response->get_error_message() )
			);
			return self::failed( 'messagemedia', 0, $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = self::parse_json_body( wp_remote_retrieve_body( $response ) );
		$ok      = $code >= 200 && $code < 300;
		$msg_id  = (string) ( $decoded['messages'][0]['message_id'] ?? '' );

		WRM_Logger::info(
			self::CONTEXT,
			'messagemedia: message ' . ( $ok ? 'sent' : 'failed' ),
			array( 'code' => $code, 'message_id' => $msg_id, 'to' => $to )
		);

		if ( ! $ok ) {
			$error = (string) ( $decoded['message'] ?? $decoded['detail'] ?? 'http_error' );
			return self::failed( 'messagemedia', $code, $error );
		}

		return array(
			'provider'   => 'messagemedia',
			'status'     => self::STATUS_SENT,
			'message_id' => $msg_id,
			'code'       => $code,
		);
	}

	/**
	 * Send via a generic webhook endpoint.
	 */
	private static function webhook( array $config, string $to, string $body, array $context ): array {
		$url            = (string) ( $config['url']            ?? '' );
		$signing_secret = (string) ( $config['signing_secret'] ?? '' );

		if ( '' === $url ) {
			return self::skipped( 'webhook', 'missing_url' );
		}

		$payload  = array(
			'to'      => $to,
			'body'    => $body,
			'context' => $context,
		);
		$body_json = wp_json_encode( $payload );

		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( '' !== $signing_secret ) {
			$headers['X-WRM-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body_json, $signing_secret );
		}

		$args = array(
			'headers' => $headers,
			'body'    => $body_json,
			'timeout' => 15,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			WRM_Logger::error(
				self::CONTEXT,
				'webhook: wp_remote_post error',
				array( 'error' => $response->get_error_message(), 'url' => $url )
			);
			return self::failed( 'webhook', 0, $response->get_error_message() );
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$raw_body     = wp_remote_retrieve_body( $response );
		$decoded      = self::parse_json_body( $raw_body );
		$ok           = $code >= 200 && $code < 300;
		$msg_id       = is_array( $decoded ) ? (string) ( $decoded['message_id'] ?? '' ) : '';

		WRM_Logger::info(
			self::CONTEXT,
			'webhook: request ' . ( $ok ? 'sent' : 'failed' ),
			array( 'code' => $code, 'message_id' => $msg_id, 'url' => $url )
		);

		if ( ! $ok ) {
			$error = is_array( $decoded )
				? (string) ( $decoded['error'] ?? $decoded['message'] ?? 'http_error' )
				: 'http_error';
			return self::failed( 'webhook', $code, $error );
		}

		return array(
			'provider'   => 'webhook',
			'status'     => self::STATUS_SENT,
			'message_id' => $msg_id,
			'code'       => $code,
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Safely parse a JSON string into an associative array.
	 *
	 * Returns an empty array when the body is not valid JSON.
	 *
	 * @param string $raw Raw HTTP response body.
	 * @return array
	 */
	private static function parse_json_body( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}
		if ( function_exists( 'json_validate' ) && ! json_validate( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Build a normalised "skipped" result.
	 *
	 * @param string $provider Provider key.
	 * @param string $reason   Machine-readable reason code.
	 * @return array
	 */
	private static function skipped( string $provider, string $reason ): array {
		WRM_Logger::warning(
			self::CONTEXT,
			sprintf( '%s: skipped — %s', $provider, $reason ),
			array( 'provider' => $provider, 'reason' => $reason )
		);

		return array(
			'provider'   => $provider,
			'status'     => self::STATUS_SKIPPED,
			'message_id' => '',
			'code'       => 0,
			'error'      => $reason,
		);
	}

	/**
	 * Build a normalised "failed" result.
	 *
	 * @param string $provider Provider key.
	 * @param int    $code     HTTP status code (0 if not applicable).
	 * @param string $error    Human-readable error description.
	 * @return array
	 */
	private static function failed( string $provider, int $code, string $error ): array {
		return array(
			'provider'   => $provider,
			'status'     => self::STATUS_FAILED,
			'message_id' => '',
			'code'       => $code,
			'error'      => $error,
		);
	}
}
