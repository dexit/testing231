<?php
/**
 * Mapping engine — applies a wrm_mapping post config to a captured payload.
 *
 * Mapping config (stored as JSON in post meta 'wrm_config'):
 * {
 *   "cpt": "post",
 *   "update_existing": true,
 *   "match_source": "email",         // dot-path in payload to match on
 *   "match_field": "meta:user_email",// post field or meta:key to match against
 *   "fields": [
 *     { "source": "name",            // dot-path or {{merge.tag}}
 *       "target": "post_title" },    // post field | meta:key | taxonomy:tax
 *     { "source": "tags",
 *       "target": "taxonomy:post_tag" }
 *   ],
 *   "chains": [
 *     { "type": "webhook",   "url": "https://...", "method": "POST", "body_template": "{...}" },
 *     { "type": "action",    "hook": "my_custom_action" },
 *     { "type": "function",  "function": "my_callback_fn" },
 *     { "type": "mapping",   "mapping_id": 42 }
 *   ]
 * }
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security note for integrators:
 *
 * Before using chain type "function", call:
 *   WRM_Mapper::register_callback( 'my_fn' );
 * on the `plugins_loaded` hook to opt the function into the allowlist.
 *
 * Before using chain type "action", call:
 *   WRM_Mapper::register_hook( 'my_hook' );
 * on the `plugins_loaded` hook to opt the hook into the allowlist.
 *
 * Unregistered callbacks and hooks will be blocked and a warning logged.
 */
class WRM_Mapper {

	/** Option keys for the admin-managed (UI) allowlists. */
	const string OPT_CALLBACKS = 'wrm_allowed_callbacks';
	const string OPT_HOOKS     = 'wrm_allowed_hooks';

	/** @var array<string,true> Allowlist of callable function names permitted in "function" chains. */
	private static array $allowed_callbacks = [];

	/** @var array<string,true> Allowlist of action hook names permitted in "action" chains. */
	private static array $allowed_hooks = [];

	/** @var array<string,true> Names registered in code (read-only in the UI). */
	private static array $code_callbacks = [];
	private static array $code_hooks     = [];

	/** @var bool Whether persisted allowlists have been merged in. */
	private static bool $loaded = false;

	public static function register_callback( string $fn ): void {
		self::$allowed_callbacks[ $fn ] = true;
		self::$code_callbacks[ $fn ]    = true;
	}

	public static function register_hook( string $hook ): void {
		self::$allowed_hooks[ $hook ] = true;
		self::$code_hooks[ $hook ]    = true;
	}

	/**
	 * Merge the admin-managed (option-stored) allowlists into the in-memory
	 * lists. Runs once, lazily, so it works regardless of hook ordering with
	 * integrators that call register_callback() / register_hook().
	 */
	private static function ensure_loaded(): void {
		if ( self::$loaded ) {
			return;
		}
		self::$loaded = true;

		// Rebuild the effective allowlist from code-registered names + persisted
		// options. Rebuilding (rather than appending) means a UI removal takes
		// effect in the same request once flush_allowlist_cache() is called.
		self::$allowed_callbacks = self::$code_callbacks;
		self::$allowed_hooks     = self::$code_hooks;

		foreach ( (array) get_option( self::OPT_CALLBACKS, array() ) as $fn ) {
			$fn = sanitize_text_field( (string) $fn );
			if ( '' !== $fn ) {
				self::$allowed_callbacks[ $fn ] = true;
			}
		}
		foreach ( (array) get_option( self::OPT_HOOKS, array() ) as $hook ) {
			$hook = sanitize_text_field( (string) $hook );
			if ( '' !== $hook ) {
				self::$allowed_hooks[ $hook ] = true;
			}
		}
	}

	/**
	 * Force ensure_loaded() to re-merge persisted allowlists on next use.
	 * Called by the admin API after add/remove so changes apply immediately.
	 */
	public static function flush_allowlist_cache(): void {
		self::$loaded = false;
	}

	/**
	 * Public SSRF guard so other components (e.g. the scheduler's source_url
	 * fetch) can reuse the same private/reserved-IP rejection logic.
	 */
	public static function is_url_safe( string $url ): bool {
		return self::is_safe_url( $url );
	}

	/**
	 * Return the merged allowlist state for the admin UI.
	 *
	 * @return array{callbacks:array<int,array{name:string,source:string,exists:bool}>,hooks:array<int,array{name:string,source:string}>}
	 */
	public static function get_allowlists(): array {
		self::ensure_loaded();

		$callbacks = array();
		foreach ( array_keys( self::$allowed_callbacks ) as $fn ) {
			$callbacks[] = array(
				'name'   => $fn,
				'source' => isset( self::$code_callbacks[ $fn ] ) ? 'code' : 'ui',
				'exists' => function_exists( $fn ),
			);
		}

		$hooks = array();
		foreach ( array_keys( self::$allowed_hooks ) as $hook ) {
			$hooks[] = array(
				'name'   => $hook,
				'source' => isset( self::$code_hooks[ $hook ] ) ? 'code' : 'ui',
			);
		}

		return array( 'callbacks' => $callbacks, 'hooks' => $hooks );
	}

	public static function apply( int $capture_id, int $mapping_id ): array {
		WRM_Logger::info( 'mapper', 'Applying mapping', array( 'capture_id' => $capture_id, 'mapping_id' => $mapping_id, 'ref_id' => $capture_id ) );

		try {
			return self::_apply_inner( $capture_id, $mapping_id );
		} catch ( \Throwable $e ) {
			WRM_Logger::exception( 'mapper', $e, array( 'capture_id' => $capture_id, 'mapping_id' => $mapping_id, 'ref_id' => $capture_id ) );
			return array( 'success' => false, 'error' => $e->getMessage(), 'exception' => get_class( $e ) );
		}
	}

	private static function _apply_inner( int $capture_id, int $mapping_id ): array {
		$capture = WRM_Capture::get( $capture_id );
		if ( ! $capture ) {
			WRM_Logger::warning( 'mapper', 'Capture not found', array( 'capture_id' => $capture_id ) );
			return array( 'success' => false, 'error' => 'capture_not_found' );
		}

		$mapping_post = get_post( $mapping_id );
		if ( ! $mapping_post || 'wrm_mapping' !== $mapping_post->post_type ) {
			WRM_Logger::warning( 'mapper', 'Mapping not found', array( 'mapping_id' => $mapping_id ) );
			return array( 'success' => false, 'error' => 'mapping_not_found' );
		}

		$raw_config = get_post_meta( $mapping_id, 'wrm_config', true );
		if ( $raw_config && ! json_validate( $raw_config ) ) {
			WRM_Logger::error( 'mapper', 'Mapping config invalid JSON', array( 'mapping_id' => $mapping_id ) );
			return array( 'success' => false, 'error' => 'mapping_config_invalid' );
		}
		$config = json_decode( $raw_config ?: '{}', true ) ?? array();

		$payload = json_validate( $capture['payload'] )
			? ( json_decode( $capture['payload'], true ) ?? array() )
			: array();
		$payload = apply_filters( 'wrm_normalize_payload', $payload, $capture_id, $mapping_id );

		$context = array(
			'payload'    => $payload,
			'post'       => null,
			'meta'       => array(),
			'now'        => time(),
			'capture_id' => $capture_id,
			'mapping_id' => $mapping_id,
		);

		$cpt             = sanitize_key( $config['cpt'] ?? 'post' );
		$fields          = (array) ( $config['fields'] ?? array() );
		$chains          = (array) ( $config['chains'] ?? array() );
		$update_existing = ! empty( $config['update_existing'] );
		$match_source    = sanitize_text_field( $config['match_source'] ?? '' );
		$match_field     = sanitize_text_field( $config['match_field'] ?? '' );

		// Find existing post to update
		$existing_id = 0;
		if ( $update_existing && $match_source && $match_field ) {
			$match_val = WRM_Merge_Tags::get_by_path( $payload, $match_source );
			if ( null !== $match_val ) {
				$existing_id = self::find_post( $cpt, $match_field, $match_val );
			}
		}

		// Build post data from field mapping rules
		$post_data = array(
			'post_type'   => $cpt,
			'post_status' => sanitize_key( $config['post_status'] ?? 'publish' ),
		);
		$meta_data      = array();
		$meta_json_data = array(); // stored JSON-encoded — decodable by chained mappings
		$tax_data       = array();

		foreach ( $fields as $rule ) {
			$source = sanitize_text_field( $rule['source'] ?? '' );
			$target = sanitize_text_field( $rule['target'] ?? '' );
			if ( ! $source || ! $target ) {
				continue;
			}

			$value = str_contains( $source, '{{' )
				? WRM_Merge_Tags::resolve( $source, $context )
				: WRM_Merge_Tags::get_by_path( $payload, $source );

			if ( null === $value || '' === $value ) {
				continue;
			}

			if ( str_starts_with( $target, 'meta_json:' ) ) {
				$meta_json_data[ sanitize_key( substr( $target, 10 ) ) ] = $value;
			} elseif ( str_starts_with( $target, 'meta:' ) ) {
				$meta_data[ sanitize_key( substr( $target, 5 ) ) ] = $value;
			} elseif ( str_starts_with( $target, 'taxonomy:' ) ) {
				$tax_data[ sanitize_key( substr( $target, 9 ) ) ] = $value;
			} else {
				$post_data[ $target ] = is_array( $value )
					? implode( ', ', array_map( 'strval', $value ) )
					: (string) $value;
			}
		}

		// Create or update the post
		if ( $existing_id > 0 ) {
			$post_data['ID'] = $existing_id;
			$result_id       = wp_update_post( wp_slash( $post_data ), true );
			$action          = 'updated';
		} else {
			$result_id = wp_insert_post( wp_slash( $post_data ), true );
			$action    = 'created';
		}

		if ( is_wp_error( $result_id ) ) {
			WRM_Logger::wp_error( 'mapper', $result_id, array( 'capture_id' => $capture_id, 'mapping_id' => $mapping_id ) );
			return array( 'success' => false, 'error' => $result_id->get_error_message() );
		}

		foreach ( $meta_data as $key => $val ) {
			update_post_meta( $result_id, $key, $val );
		}
		foreach ( $meta_json_data as $key => $val ) {
			update_post_meta( $result_id, $key, wp_json_encode( $val ) );
		}
		foreach ( $tax_data as $tax => $terms ) {
			wp_set_object_terms( $result_id, is_array( $terms ) ? $terms : array( (string) $terms ), $tax );
		}

		// Update context with created post for chain merge tags.
		// meta_json values are exposed in decoded (pre-encode) form so merge tags
		// like {{meta.key}} resolve to the structured value, not the JSON string.
		$context['post'] = get_post( $result_id );
		$context['meta'] = array_merge( $meta_json_data, $meta_data );

		// Execute action chains
		$chain_log = array();
		foreach ( $chains as $chain ) {
			$chain_log[] = self::execute_chain( $chain, $result_id, $payload, $context );
		}

		WRM_Logger::info( 'mapper', 'Mapping applied', array( 'post_id' => $result_id, 'action' => $action, 'capture_id' => $capture_id, 'ref_id' => $capture_id ) );

		return array(
			'success'   => true,
			'post_id'   => $result_id,
			'action'    => $action,
			'meta_keys' => array_keys( $meta_data ),
			'tax_keys'  => array_keys( $tax_data ),
			'chains'    => $chain_log,
		);
	}

	private static function find_post( string $cpt, string $field, $value ): int {
		if ( str_starts_with( $field, 'meta:' ) ) {
			$posts = get_posts(
				array(
					'post_type'      => $cpt,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => sanitize_key( substr( $field, 5 ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			return $posts ? (int) $posts[0] : 0;
		}
		if ( 'post_title' === $field ) {
			$posts = get_posts( array(
				'post_type'      => $cpt,
				'title'          => sanitize_text_field( (string) $value ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post_status'    => 'any',
				'no_found_rows'  => true,
			) );
			return $posts ? (int) $posts[0] : 0;
		}
		return 0;
	}

	private static function execute_chain( array $chain, int $post_id, array $payload, array $context ): array {
		$type = sanitize_key( $chain['type'] ?? '' );
		self::ensure_loaded();

		switch ( $type ) {
			case 'webhook':
				return self::chain_webhook( $chain, $post_id, $payload, $context );

			case 'email':
				return self::chain_email( $chain, $post_id, $payload, $context );

			case 'sms':
				return self::chain_sms( $chain, $post_id, $payload, $context );

			case 'action':
				$hook = sanitize_text_field( $chain['hook'] ?? '' );
				if ( ! $hook || ! isset( self::$allowed_hooks[ $hook ] ) ) {
					WRM_Logger::warning( 'mapper', 'Blocked unregistered hook', array( 'hook' => $hook ) );
					return array( 'type' => 'action', 'status' => 'skipped', 'reason' => 'not_registered', 'hook' => $hook );
				}
				do_action( $hook, $post_id, $payload, $context );
				return array( 'type' => 'action', 'hook' => $hook, 'status' => 'fired' );

			case 'function':
				$fn = sanitize_text_field( $chain['function'] ?? '' );
				if ( ! $fn || ! function_exists( $fn ) || ! isset( self::$allowed_callbacks[ $fn ] ) ) {
					WRM_Logger::warning( 'mapper', 'Blocked unregistered callback', array( 'function' => $fn ) );
					return array( 'type' => 'function', 'status' => 'skipped', 'reason' => 'not_registered', 'function' => $fn );
				}
				try {
					$fn_result = call_user_func( $fn, $post_id, $payload, $context );
					return array( 'type' => 'function', 'function' => $fn, 'result' => $fn_result );
				} catch ( \Throwable $e ) {
					return array( 'type' => 'function', 'function' => $fn, 'error' => $e->getMessage() );
				}

			case 'dispatcher':
				// Semantic alias of webhook; relabel the log entry so it reflects config.
				$dispatch_result         = self::chain_webhook( $chain, $post_id, $payload, $context );
				$dispatch_result['type'] = 'dispatcher';
				return $dispatch_result;

			case 'mapping':
				$next_id = (int) ( $chain['mapping_id'] ?? 0 );
				if ( ! $next_id ) {
					return array( 'type' => 'mapping', 'status' => 'skipped', 'reason' => 'no_mapping_id' );
				}
				$chain_src = sanitize_key( $chain['chain_source'] ?? 'payload' );
				$chain_key = sanitize_key( $chain['chain_source_key'] ?? '' );
				if ( 'post_meta_json' === $chain_src && $chain_key ) {
					$meta_raw = get_post_meta( $post_id, $chain_key, true );
					$decoded  = ( is_string( $meta_raw ) && json_validate( $meta_raw ) )
						? json_decode( $meta_raw, true )
						: null;
					if ( is_array( $decoded ) ) {
						$chain_payload = $decoded;
					} else {
						WRM_Logger::warning(
							'mapper',
							'chain_source post_meta_json did not decode to an array; falling back to original payload',
							array( 'meta_key' => $chain_key, 'post_id' => $post_id )
						);
						$chain_payload = $payload;
					}
				} else {
					$chain_payload = $payload;
				}
				$syn_capture_id = WRM_Capture::store_internal( '_chain', 'custom', $chain_payload );
				if ( ! $syn_capture_id ) {
					WRM_Logger::error( 'mapper', 'Failed to store synthetic chain capture', array( 'mapping_id' => $next_id ) );
					return array( 'type' => 'mapping', 'mapping_id' => $next_id, 'status' => 'skipped', 'reason' => 'capture_store_failed' );
				}
				$chain_result = self::apply( $syn_capture_id, $next_id );
				return array( 'type' => 'mapping', 'mapping_id' => $next_id, 'result' => $chain_result );

			default:
				return array( 'type' => $type, 'status' => 'unknown_type' );
		}
	}

	/**
	 * Validate a webhook URL against SSRF risks.
	 *
	 * Rejects URLs that do not pass wp_http_validate_url, have no resolvable host,
	 * or resolve to a private/reserved IP range.
	 * Operators may override via the `wrm_webhook_url_allowed` filter.
	 *
	 * @param string $url URL to validate.
	 * @return bool True if the URL is safe to request.
	 */
	private static function is_safe_url( string $url ): bool {
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}
		$host = (string) parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		// Block if host resolves to private/reserved IP
		$ip = gethostbyname( $host );
		if ( $ip === $host && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false; // DNS lookup failed
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			if (
				filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false
			) {
				return false; // Private/reserved range
			}
		}
		return true;
	}

	private static function chain_webhook( array $chain, int $post_id, array $payload, array $context ): array {
		$url    = WRM_Merge_Tags::resolve( esc_url_raw( $chain['url'] ?? '' ), $context );
		$method = strtoupper( sanitize_text_field( $chain['method'] ?? 'POST' ) );

		if ( ! $url ) {
			return array( 'type' => 'webhook', 'status' => 'skipped', 'reason' => 'no_url' );
		}

		if ( ! apply_filters( 'wrm_webhook_url_allowed', self::is_safe_url( $url ), $url, $chain ) ) {
			WRM_Logger::warning( 'mapper', 'Blocked unsafe webhook URL', array( 'url' => $url ) );
			return array( 'type' => 'webhook', 'status' => 'skipped', 'reason' => 'unsafe_url', 'url' => $url );
		}

		// Body resolution priority: body_builder > body_template > raw payload
		if ( ! empty( $chain['body_builder'] ) && is_array( $chain['body_builder'] ) ) {
			$body = self::render_body_builder( $chain['body_builder'], $payload, $context );
		} elseif ( ! empty( $chain['body_template'] ) ) {
			$rendered = WRM_Merge_Tags::resolve( (string) $chain['body_template'], $context );
			$body     = ( json_validate( $rendered ) && is_array( $decoded = json_decode( $rendered, true ) ) )
				? $decoded
				: array_merge( $payload, array( '_wrm_post_id' => $post_id ) );
		} else {
			$body = array_merge( $payload, array( '_wrm_post_id' => $post_id ) );
		}

		$extra_headers = is_array( $chain['headers'] ?? null ) ? $chain['headers'] : array();

		$body          = apply_filters( 'wrm_webhook_chain_body', $body, $chain, $post_id, $payload );
		$extra_headers = apply_filters( 'wrm_webhook_chain_headers', $extra_headers, $chain, $post_id );

		$body_json = wp_json_encode( $body );

		// Outbound HMAC signing — adds X-WRM-Signature: sha256=<hex> when signing_secret configured.
		if ( ! empty( $chain['signing_secret'] ) ) {
			$extra_headers['X-WRM-Signature'] = 'sha256=' . hash_hmac( 'sha256', (string) $body_json, $chain['signing_secret'] );
		}

		WRM_Logger::debug( 'mapper', 'Firing webhook chain', array( 'url' => $url, 'method' => $method ) );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'body'    => $body_json,
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $extra_headers ),
				'timeout' => (int) ( $chain['timeout'] ?? 15 ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WRM_Logger::error( 'mapper', 'Webhook chain error: ' . $response->get_error_message(), array( 'url' => $url ) );
			return array( 'type' => 'webhook', 'url' => $url, 'error' => $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		WRM_Logger::debug( 'mapper', 'Webhook chain response', array( 'url' => $url, 'status_code' => $status_code ) );

		return array(
			'type'        => 'webhook',
			'url'         => $url,
			'status_code' => $status_code,
		);
	}

	/**
	 * Send an email via wp_mail(). All fields support merge tags.
	 *
	 * Config: {
	 *   to, subject, cc, bcc,
	 *   format: 'text' | 'html' | 'mjml',   // default 'text'
	 *   body_template,                       // text/html source
	 *   mjml_source,                         // MJML source (format=mjml)
	 *   track (bool)                         // inject open pixel + click tracking
	 * }
	 */
	private static function chain_email( array $chain, int $post_id, array $payload, array $context ): array {
		$to      = WRM_Merge_Tags::resolve( (string) ( $chain['to'] ?? '' ), $context );
		$subject = WRM_Merge_Tags::resolve( (string) ( $chain['subject'] ?? '' ), $context );
		$format  = sanitize_key( $chain['format'] ?? ( ! empty( $chain['html'] ) ? 'html' : 'text' ) );

		if ( ! $to || ! is_email( $to ) ) {
			WRM_Logger::warning( 'mapper', 'Email chain skipped — invalid recipient', array( 'to' => $to ) );
			return array( 'type' => 'email', 'status' => 'skipped', 'reason' => 'invalid_recipient', 'to' => $to );
		}

		// Resolve the body per format. MJML is compiled to responsive HTML.
		if ( 'mjml' === $format ) {
			$mjml = WRM_Merge_Tags::resolve( (string) ( $chain['mjml_source'] ?? '' ), $context );
			$body = WRM_MJML::render( $mjml, $chain['mjml'] ?? array() );
			$html = true;
		} else {
			$body = WRM_Merge_Tags::resolve( (string) ( $chain['body_template'] ?? '' ), $context );
			$html = ( 'html' === $format );
		}

		// Tracking: register the message first so we can instrument the HTML.
		$tracking = ! empty( $chain['track'] );
		$token    = '';
		$msg_id   = 0;
		if ( $tracking ) {
			$created = WRM_Tracking::create_message(
				array(
					'channel'    => 'email',
					'provider'   => 'wp_mail',
					'recipient'  => $to,
					'subject'    => $subject,
					'mapping_id' => (int) ( $context['mapping_id'] ?? 0 ),
					'capture_id' => (int) ( $context['capture_id'] ?? 0 ),
					'status'     => 'queued',
				)
			);
			$msg_id = $created['id'];
			$token  = $created['token'];
			if ( $html && $token ) {
				$body = WRM_Tracking::instrument_html( $body, $token );
			}
		}

		$headers = array();
		if ( $html ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}
		if ( ! empty( $chain['cc'] ) ) {
			$cc = WRM_Merge_Tags::resolve( (string) $chain['cc'], $context );
			if ( is_email( $cc ) ) {
				$headers[] = 'Cc: ' . $cc;
			}
		}
		if ( ! empty( $chain['bcc'] ) ) {
			$bcc = WRM_Merge_Tags::resolve( (string) $chain['bcc'], $context );
			if ( is_email( $bcc ) ) {
				$headers[] = 'Bcc: ' . $bcc;
			}
		}

		$headers = apply_filters( 'wrm_email_chain_headers', $headers, $chain, $post_id, $payload );
		$sent    = wp_mail( $to, $subject, $body, $headers );

		if ( $msg_id ) {
			WRM_Tracking::update_message( $msg_id, array( 'status' => $sent ? 'sent' : 'failed', 'sent_at' => current_time( 'mysql', true ) ) );
			WRM_Tracking::record_event( $msg_id, $sent ? 'sent' : 'failed' );
		}

		if ( ! $sent ) {
			WRM_Logger::error( 'mapper', 'Email chain failed to send', array( 'to' => $to, 'subject' => $subject ) );
			return array( 'type' => 'email', 'status' => 'failed', 'to' => $to, 'message_id' => $msg_id );
		}

		WRM_Logger::info( 'mapper', 'Email chain sent', array( 'to' => $to, 'subject' => $subject, 'format' => $format, 'tracked' => $tracking, 'ref_id' => $post_id ) );
		return array( 'type' => 'email', 'status' => 'sent', 'to' => $to, 'format' => $format, 'tracked' => $tracking, 'message_id' => $msg_id );
	}

	/**
	 * Send an SMS / WhatsApp / chat message through a pluggable provider.
	 * Supported providers: twilio, whatsapp, sinch, messagemedia, webhook.
	 * Any gateway can be intercepted via the `wrm_send_message` filter.
	 *
	 * Config: { provider, to, from, body, track, <provider-specific creds...> }
	 */
	private static function chain_sms( array $chain, int $post_id, array $payload, array $context ): array {
		$provider = sanitize_key( $chain['provider'] ?? 'twilio' );
		$to       = WRM_Merge_Tags::resolve( (string) ( $chain['to'] ?? '' ), $context );
		$from     = WRM_Merge_Tags::resolve( (string) ( $chain['from'] ?? '' ), $context );
		$text     = WRM_Merge_Tags::resolve( (string) ( $chain['body'] ?? '' ), $context );

		if ( ! $to || ! $text ) {
			return array( 'type' => 'sms', 'status' => 'skipped', 'reason' => 'missing_to_or_body' );
		}

		// Provider config = the chain itself plus the resolved `from`.
		$config         = $chain;
		$config['from'] = $from;

		$result = WRM_Messaging::send( $provider, $config, $to, $text, $context );

		// Optional deliverability tracking — store the message so a provider
		// status webhook can later flip it to delivered/bounced/failed.
		if ( ! empty( $chain['track'] ) ) {
			$created = WRM_Tracking::create_message(
				array(
					'channel'             => 'whatsapp' === $provider ? 'whatsapp' : 'sms',
					'provider'            => $provider,
					'recipient'           => $to,
					'subject'             => mb_substr( $text, 0, 120 ),
					'mapping_id'          => (int) ( $context['mapping_id'] ?? 0 ),
					'capture_id'          => (int) ( $context['capture_id'] ?? 0 ),
					'provider_message_id' => (string) ( $result['message_id'] ?? '' ),
					'status'              => ( $result['status'] ?? '' ) === 'sent' ? 'sent' : 'failed',
				)
			);
			WRM_Tracking::record_event( $created['id'], ( $result['status'] ?? '' ) === 'sent' ? 'sent' : 'failed' );
			$result['message_id_local'] = $created['id'];
		}

		WRM_Logger::info( 'mapper', 'SMS chain dispatched', array( 'provider' => $provider, 'to' => $to, 'status' => $result['status'] ?? '', 'ref_id' => $post_id ) );

		return array_merge( array( 'type' => 'sms' ), $result );
	}

	/**
	 * Recursively render a body_builder node tree, resolving merge tags and loops.
	 *
	 * Nodes with __wrm_loop__ marker are expanded by iterating the source array
	 * from the payload and rendering the template for each item.
	 *
	 * @param mixed $node    Body builder node (array|string|int|bool|null).
	 * @param array $payload Normalized payload.
	 * @param array $context Merge tag context.
	 * @return mixed Rendered value.
	 */
	private static function render_body_builder( $node, array $payload, array $context ) {
		if ( ! is_array( $node ) ) {
			// Scalar — resolve merge tags in strings
			if ( is_string( $node ) && str_contains( $node, '{{' ) ) {
				return WRM_Merge_Tags::resolve( $node, $context );
			}
			return $node;
		}

		// Loop marker
		if ( ! empty( $node['__wrm_loop__'] ) ) {
			$source   = sanitize_text_field( $node['source'] ?? '' );
			$template = (string) ( $node['template'] ?? '' );
			$join     = (string) ( $node['join'] ?? '' );
			$items    = WRM_Merge_Tags::get_by_path( $payload, $source );

			if ( ! is_array( $items ) ) {
				return $join && strtoupper( $join ) !== 'ARRAY' ? '' : array();
			}

			$rendered = array();
			foreach ( $items as $item ) {
				$item_context          = $context;
				$item_context['item']  = is_array( $item ) ? $item : array( '__value__' => $item );
				// Support {{item.field}} by injecting item into payload context
				$item_context['payload']['item'] = $item_context['item'];
				$rendered[] = WRM_Merge_Tags::resolve( $template, $item_context );
			}

			if ( strtoupper( $join ) === 'ARRAY' || '' === $join ) {
				return $rendered;
			}
			return implode( $join, $rendered );
		}

		// Plain array → recurse each element
		if ( array_is_list( $node ) ) {
			return array_map( fn( $v ) => self::render_body_builder( $v, $payload, $context ), $node );
		}

		// Object → recurse each value
		$out = array();
		foreach ( $node as $key => $val ) {
			$resolved_key    = WRM_Merge_Tags::resolve( $key, $context );
			$out[ $resolved_key ] = self::render_body_builder( $val, $payload, $context );
		}
		return $out;
	}
}
