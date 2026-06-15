<?php
/**
 * WRM MJML — compiles MJML markup to responsive HTML.
 *
 * Resolution order:
 *   1. `wrm_render_mjml` filter (allows custom compiler integration).
 *   2. MJML REST API (api.mjml.io/v1/render) with HTTP Basic auth.
 *   3. Fallback: strips MJML tags and wraps content in a minimal
 *      responsive HTML document safe for email clients.
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_MJML {

	const string API_ENDPOINT  = 'https://api.mjml.io/v1/render';
	const string OPT_APP_ID    = 'wrm_mjml_app_id';
	const string OPT_APP_SECRET = 'wrm_mjml_app_secret';

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Compile MJML to HTML. Never throws; returns best-effort HTML.
	 *
	 * Resolution order:
	 *   1. `wrm_render_mjml` filter — if a hooked callback returns a non-empty string, use it.
	 *   2. MJML REST API (api.mjml.io/v1/render) using app id/secret (HTTP Basic auth).
	 *   3. Fallback: self::fallback_wrap( $mjml ).
	 *
	 * @param string $mjml  Raw MJML markup.
	 * @param array  $opts  Optional overrides: 'app_id', 'app_secret'.
	 * @return string Compiled HTML (never empty — fallback always produces output).
	 */
	public static function render( string $mjml, array $opts = array() ): string {
		// 1. Allow external compiler via filter.
		$filtered = apply_filters( 'wrm_render_mjml', null, $mjml, $opts );
		if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
			WRM_Logger::info( 'mjml', 'MJML rendered via wrm_render_mjml filter.' );
			return $filtered;
		}

		// 2. MJML REST API.
		[ $app_id, $app_secret ] = self::resolve_credentials( $opts );
		if ( '' !== $app_id && '' !== $app_secret ) {
			$result = self::call_api( $mjml, $app_id, $app_secret );
			if ( null !== $result ) {
				return $result;
			}
			// API failed — fall through to fallback.
		}

		// 3. Fallback.
		WRM_Logger::info( 'mjml', 'Using fallback MJML renderer (no compiler available).' );
		return self::fallback_wrap( $mjml );
	}

	/**
	 * True if a real compiler is available (filter hooked OR API credentials present).
	 *
	 * @param array $opts Optional overrides: 'app_id', 'app_secret'.
	 * @return bool
	 */
	public static function is_available( array $opts = array() ): bool {
		if ( has_filter( 'wrm_render_mjml' ) ) {
			return true;
		}

		[ $app_id, $app_secret ] = self::resolve_credentials( $opts );
		return '' !== $app_id && '' !== $app_secret;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve app_id and app_secret from $opts then WordPress options.
	 *
	 * @param array $opts Caller-supplied overrides.
	 * @return array{0: string, 1: string} [ $app_id, $app_secret ]
	 */
	private static function resolve_credentials( array $opts ): array {
		$app_id = isset( $opts['app_id'] ) ? (string) $opts['app_id'] : '';
		if ( '' === $app_id ) {
			$app_id = (string) get_option( self::OPT_APP_ID, '' );
		}

		$app_secret = isset( $opts['app_secret'] ) ? (string) $opts['app_secret'] : '';
		if ( '' === $app_secret ) {
			$app_secret = (string) get_option( self::OPT_APP_SECRET, '' );
		}

		return [ $app_id, $app_secret ];
	}

	/**
	 * POST to the MJML API and return the compiled HTML, or null on any failure.
	 *
	 * @param string $mjml       Raw MJML markup.
	 * @param string $app_id     MJML API application ID.
	 * @param string $app_secret MJML API application secret.
	 * @return string|null Compiled HTML on success, null on failure.
	 */
	private static function call_api( string $mjml, string $app_id, string $app_secret ): ?string {
		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $app_id . ':' . $app_secret ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'mjml' => $mjml ) ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			WRM_Logger::error(
				'mjml',
				'MJML API request failed: ' . $response->get_error_message(),
				array( 'code' => $response->get_error_code() )
			);
			return null;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			WRM_Logger::error(
				'mjml',
				'MJML API returned non-2xx status.',
				array( 'status_code' => $status_code, 'body' => $body )
			);
			return null;
		}

		if ( ! json_validate( $body ) ) {
			WRM_Logger::error(
				'mjml',
				'MJML API response is not valid JSON.',
				array( 'status_code' => $status_code )
			);
			return null;
		}

		$decoded = json_decode( $body, true );

		if ( ! empty( $decoded['errors'] ) ) {
			WRM_Logger::warning(
				'mjml',
				'MJML API returned compilation errors.',
				array( 'errors' => $decoded['errors'] )
			);
		}

		if ( ! empty( $decoded['html'] ) ) {
			WRM_Logger::info( 'mjml', 'MJML compiled successfully via API.', array( 'status_code' => $status_code ) );
			return (string) $decoded['html'];
		}

		WRM_Logger::error(
			'mjml',
			'MJML API response missing html field.',
			array( 'status_code' => $status_code, 'decoded' => $decoded )
		);
		return null;
	}

	/**
	 * Strip MJML-specific tags (keeping their inner content) and wrap in a
	 * minimal, email-client-safe responsive HTML document.
	 *
	 * @param string $mjml Raw MJML markup.
	 * @return string Fallback HTML document.
	 */
	private static function fallback_wrap( string $mjml ): string {
		// Remove opening MJML tags (e.g. <mj-section>, <mj-text attr="…">).
		$content = preg_replace( '/<mj-[a-z0-9-]+(?:\s[^>]*)?\s*>/i', '', $mjml ) ?? $mjml;
		// Remove closing MJML tags (e.g. </mj-section>).
		$content = preg_replace( '#</mj-[a-z0-9-]+>#i', '', $content ) ?? $content;
		// Collapse excessive whitespace runs to keep output readable.
		$content = preg_replace( '/(\s*\n\s*){3,}/', "\n\n", trim( $content ) ) ?? trim( $content );

		return '<!DOCTYPE html>' . "\n"
			. '<html lang="en">' . "\n"
			. '<head>' . "\n"
			. '<meta charset="UTF-8">' . "\n"
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n"
			. '<meta http-equiv="X-UA-Compatible" content="IE=edge">' . "\n"
			. '<title></title>' . "\n"
			. '<style>' . "\n"
			. 'body{margin:0;padding:0;background-color:#ffffff;font-family:Arial,sans-serif;font-size:14px;color:#333333;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}' . "\n"
			. 'table{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;}' . "\n"
			. 'img{border:0;height:auto;line-height:100%;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;}' . "\n"
			. 'a{color:#0077cc;}' . "\n"
			. '.container{max-width:600px;margin:0 auto;padding:0 16px;}' . "\n"
			. '</style>' . "\n"
			. '</head>' . "\n"
			. '<body>' . "\n"
			. '<div class="container">' . "\n"
			. $content . "\n"
			. '</div>' . "\n"
			. '</body>' . "\n"
			. '</html>';
	}
}
