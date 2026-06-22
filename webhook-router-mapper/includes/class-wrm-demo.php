<?php
/**
 * Demo data seeder — installs sample routes, mappings, captures, jobs,
 * schedules, and messages to showcase all plugin features.
 *
 * Called via POST /wrm/v1/demo/seed (manage_options only).
 * Idempotent: checks for existing demo data by slug/title before inserting.
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Demo {

	const string DEMO_FLAG = 'wrm_demo_seeded';

	public static function seed(): array {
		$results = array();

		$results['routes']    = self::seed_routes();
		$results['mappings']  = self::seed_mappings();
		$results['captures']  = self::seed_captures();
		$results['jobs']      = self::seed_jobs();
		$results['schedules'] = self::seed_schedules();
		$results['messages']  = self::seed_messages();
		$results['metrics']   = self::seed_metrics();
		$results['logs']      = self::seed_logs();

		update_option( self::DEMO_FLAG, current_time( 'mysql' ) );

		return $results;
	}

	// -------------------------------------------------------------------------
	// Routes
	// -------------------------------------------------------------------------

	private static function seed_routes(): array {
		$routes = array(
			array(
				'slug'         => 'demo-stripe-webhook',
				'label'        => 'Demo: Stripe Payments',
				'methods'      => 'POST',
				'provider'     => 'stripe',
				'auth_token'   => 'whsec_demo_stripe_signing_secret_123',
				'rate_limit'   => 100,
				'rate_window'  => 60,
				'run_mode'     => 'async',
				'ip_allowlist' => '3.18.12.63,3.130.192.231,13.235.14.237,54.187.174.169',
				'ip_blocklist' => '',
			),
			array(
				'slug'         => 'demo-hubspot-contact',
				'label'        => 'Demo: HubSpot Contact Sync',
				'methods'      => 'POST',
				'provider'     => 'hubspot',
				'auth_token'   => 'hubspot_demo_token_abc456',
				'rate_limit'   => 200,
				'rate_window'  => 60,
				'run_mode'     => 'async',
				'ip_allowlist' => '',
				'ip_blocklist' => '1.2.3.4',
			),
			array(
				'slug'         => 'demo-order-notify',
				'label'        => 'Demo: Order → Email + SMS',
				'methods'      => 'POST',
				'provider'     => 'custom',
				'auth_token'   => 'demo_order_token_xyz789',
				'rate_limit'   => 0,
				'rate_window'  => 60,
				'run_mode'     => 'async',
				'ip_allowlist' => '',
				'ip_blocklist' => '',
			),
			array(
				'slug'         => 'demo-dead-letter-queue',
				'label'        => 'Demo: Dead Letter Queue',
				'methods'      => 'POST',
				'provider'     => 'custom',
				'auth_token'   => '',
				'rate_limit'   => 0,
				'rate_window'  => 60,
				'run_mode'     => 'async',
				'ip_allowlist' => '',
				'ip_blocklist' => '',
			),
			array(
				'slug'         => 'demo-conditional-chains',
				'label'        => 'Demo: Conditional Chain Routing',
				'methods'      => 'POST,PUT',
				'provider'     => 'custom',
				'auth_token'   => 'cond_demo_token_111',
				'rate_limit'   => 50,
				'rate_window'  => 60,
				'run_mode'     => 'sync',
				'ip_allowlist' => '',
				'ip_blocklist' => '',
			),
		);

		$inserted = 0;
		foreach ( $routes as $route ) {
			global $wpdb;
			$table = $wpdb->prefix . WRM_Installer::ROUTES_TABLE;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $route['slug'] ) );
			if ( ! $exists ) {
				$route['status']     = 'active';
				$route['created_at'] = current_time( 'mysql' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert( $table, $route );
				++$inserted;
			}
		}

		return array(
			'inserted' => $inserted,
			'total'    => count( $routes ),
		);
	}

	// -------------------------------------------------------------------------
	// Mappings (wrm_mapping CPT + wrm_config post meta)
	// -------------------------------------------------------------------------

	private static function seed_mappings(): array {
		$mappings = array(
			array(
				'title'  => 'Demo: Stripe Payment → Order Post',
				'config' => array(
					'cpt'               => 'post',
					'post_status'       => 'publish',
					'update_existing'   => true,
					'match_source'      => 'metadata.order_id',
					'match_field'       => 'meta:_stripe_order_id',
					'fields'            => array(
						array(
							'source' => 'metadata.customer_name',
							'target' => 'post_title',
						),
						array(
							'source' => 'metadata.order_id',
							'target' => 'meta:_stripe_order_id',
						),
						array(
							'source' => 'amount',
							'target' => 'meta:_order_amount',
						),
						array(
							'source' => 'currency',
							'target' => 'meta:_order_currency',
						),
						array(
							'source' => 'metadata.plan',
							'target' => 'taxonomy:category',
						),
					),
					'chains'            => array(
						array(
							'type'          => 'email',
							'to'            => '{{payload.metadata.customer_email}}',
							'subject'       => 'Payment Confirmed — Order #{{payload.metadata.order_id}}',
							'format'        => 'html',
							'body_template' => '<h2>Thank you, {{payload.metadata.customer_name}}!</h2><p>Your payment of {{payload.amount}} {{payload.currency}} has been received.</p>',
							'track'         => true,
							'conditions'    => array(
								array(
									'field' => 'type',
									'op'    => 'eq',
									'value' => 'payment_intent.succeeded',
								),
							),
						),
						array(
							'type'          => 'webhook',
							'url'           => 'https://httpbin.org/post',
							'method'        => 'POST',
							'body_template' => '{"order_id":"{{payload.metadata.order_id}}","status":"paid","amount":{{payload.amount}}}',
							'conditions'    => array(
								array(
									'field' => 'amount',
									'op'    => 'gt',
									'value' => '1000',
								),
							),
						),
					),
					'dead_letter_route' => 'demo-dead-letter-queue',
				),
			),
			array(
				'title'  => 'Demo: HubSpot Contact → WP User Meta',
				'config' => array(
					'cpt'               => 'post',
					'post_status'       => 'draft',
					'update_existing'   => false,
					'fields'            => array(
						array(
							'source' => 'properties.firstname',
							'target' => 'post_title',
						),
						array(
							'source' => 'properties.email',
							'target' => 'meta:contact_email',
						),
						array(
							'source' => 'properties.company',
							'target' => 'meta:contact_company',
						),
						array(
							'source' => 'properties.lifecyclestage',
							'target' => 'taxonomy:post_tag',
						),
					),
					'chains'            => array(
						array(
							'type'        => 'sms',
							'provider'    => 'twilio',
							'to'          => '{{payload.properties.phone}}',
							'from'        => '+15551234567',
							'body'        => 'Hi {{payload.properties.firstname}}, thanks for signing up! Reply STOP to opt out.',
							'account_sid' => 'ACdemo_account_sid',
							'auth_token'  => 'demo_auth_token',
							'track'       => true,
							'conditions'  => array(
								array(
									'field' => 'properties.phone',
									'op'    => 'not_empty',
									'value' => '',
								),
								array(
									'field' => 'properties.hs_marketable_status',
									'op'    => 'eq',
									'value' => 'true',
								),
							),
						),
					),
					'dead_letter_route' => 'demo-dead-letter-queue',
				),
			),
			array(
				'title'  => 'Demo: Order Notification (Email + SMS)',
				'config' => array(
					'cpt'         => 'post',
					'post_status' => 'publish',
					'fields'      => array(
						array(
							'source' => 'order.id',
							'target' => 'post_title',
						),
						array(
							'source' => 'order.total',
							'target' => 'meta:order_total',
						),
						array(
							'source' => 'order.status',
							'target' => 'meta:order_status',
						),
						array(
							'source' => 'customer.email',
							'target' => 'meta:customer_email',
						),
					),
					'chains'      => array(
						array(
							'type'          => 'email',
							'to'            => '{{payload.customer.email}}',
							'subject'       => 'Order #{{payload.order.id}} — {{payload.order.status}}',
							'format'        => 'html',
							'body_template' => '<h2>Order Update</h2><p>Order #{{payload.order.id}}<br>Total: ${{payload.order.total}}<br>Status: {{payload.order.status}}</p>',
							'track'         => true,
						),
						array(
							'type'        => 'sms',
							'provider'    => 'twilio',
							'to'          => '{{payload.customer.phone}}',
							'from'        => '+15559876543',
							'body'        => 'Your order #{{payload.order.id}} is {{payload.order.status}}. Total: ${{payload.order.total}}',
							'account_sid' => 'ACdemo_account_sid',
							'auth_token'  => 'demo_auth_token',
							'track'       => true,
							'conditions'  => array(
								array(
									'field' => 'customer.phone',
									'op'    => 'not_empty',
									'value' => '',
								),
							),
						),
						array(
							'type'         => 'dispatcher',
							'url'          => 'https://httpbin.org/post',
							'method'       => 'POST',
							'body_builder' => array(
								'event'     => 'order.updated',
								'order_id'  => '{{payload.order.id}}',
								'customer'  => '{{payload.customer.email}}',
								'total'     => '{{payload.order.total}}',
								'timestamp' => '{{payload.timestamp}}',
							),
							'conditions'   => array(
								array(
									'field' => 'order.total',
									'op'    => 'gt',
									'value' => '50',
								),
							),
						),
					),
				),
			),
			array(
				'title'  => 'Demo: Conditional Chain Router',
				'config' => array(
					'cpt'               => 'post',
					'post_status'       => 'publish',
					'fields'            => array(
						array(
							'source' => 'event',
							'target' => 'post_title',
						),
						array(
							'source' => 'payload',
							'target' => 'meta:event_payload',
						),
					),
					'chains'            => array(
						array(
							'type'          => 'email',
							'to'            => 'admin@example.com',
							'subject'       => '[ALERT] High-value event: {{payload.event}}',
							'format'        => 'text',
							'body_template' => 'High value event detected:\nEvent: {{payload.event}}\nAmount: {{payload.amount}}\nUser: {{payload.user_id}}',
							'conditions'    => array(
								array(
									'field' => 'amount',
									'op'    => 'gte',
									'value' => '500',
								),
								array(
									'field' => 'event',
									'op'    => 'contains',
									'value' => 'purchase',
								),
							),
						),
						array(
							'type'       => 'function',
							'function'   => 'wrm_demo_log_event',
							'conditions' => array(
								array(
									'field' => 'amount',
									'op'    => 'lt',
									'value' => '500',
								),
							),
						),
						array(
							'type'       => 'webhook',
							'url'        => 'https://httpbin.org/post',
							'method'     => 'POST',
							'conditions' => array(
								array(
									'field' => 'event',
									'op'    => 'eq',
									'value' => 'refund',
								),
							),
						),
					),
					'dead_letter_route' => 'demo-dead-letter-queue',
				),
			),
			array(
				'title'  => 'Demo: Dead Letter Queue Handler',
				'config' => array(
					'cpt'         => 'post',
					'post_status' => 'draft',
					'fields'      => array(
						array(
							'source' => '_job_id',
							'target' => 'post_title',
						),
						array(
							'source' => '_route',
							'target' => 'meta:dlq_source_route',
						),
						array(
							'source' => '_error',
							'target' => 'meta:dlq_error',
						),
						array(
							'source' => '_attempt',
							'target' => 'meta:dlq_attempts',
						),
					),
					'chains'      => array(
						array(
							'type'          => 'email',
							'to'            => 'admin@example.com',
							'subject'       => '[DLQ] Failed job #{{payload._job_id}} from {{payload._route}}',
							'format'        => 'html',
							'body_template' => '<h3>Dead Letter Queue Alert</h3>'
								. '<p><strong>Job:</strong> #{{payload._job_id}}<br>'
								. '<strong>Route:</strong> {{payload._route}}<br>'
								. '<strong>Error:</strong> {{payload._error}}<br>'
								. '<strong>Attempts:</strong> {{payload._attempt}}</p>',
						),
					),
				),
			),
		);

		$inserted = 0;
		foreach ( $mappings as $m ) {
			$q        = new WP_Query(
				array(
					'post_type'      => 'wrm_mapping',
					'title'          => $m['title'],
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$existing = $q->have_posts() ? get_post( $q->posts[0] ) : null;
			if ( ! $existing ) {
				$post_id = wp_insert_post(
					array(
						'post_type'   => 'wrm_mapping',
						'post_status' => 'publish',
						'post_title'  => $m['title'],
					)
				);
				if ( $post_id > 0 ) {
					update_post_meta( $post_id, 'wrm_config', wp_json_encode( $m['config'] ) );

					// Wire the mapping_id to its corresponding route.
					$route_slug = self::get_route_slug_for_mapping( $m['title'] );
					if ( $route_slug ) {
						WRM_Router::update_route( $route_slug, array( 'mapping_id' => $post_id ) );
					}

					++$inserted;
				}
			}
		}

		return array(
			'inserted' => $inserted,
			'total'    => count( $mappings ),
		);
	}

	private static function get_route_slug_for_mapping( string $title ): string {
		$map = array(
			'Demo: Stripe Payment → Order Post'      => 'demo-stripe-webhook',
			'Demo: HubSpot Contact → WP User Meta'   => 'demo-hubspot-contact',
			'Demo: Order Notification (Email + SMS)' => 'demo-order-notify',
			'Demo: Conditional Chain Router'         => 'demo-conditional-chains',
			'Demo: Dead Letter Queue Handler'        => 'demo-dead-letter-queue',
		);
		return $map[ $title ] ?? '';
	}

	// -------------------------------------------------------------------------
	// Captures
	// -------------------------------------------------------------------------

	private static function seed_captures(): array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::CAPTURES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE route_slug LIKE 'demo-%'" );
		if ( $existing >= 10 ) {
			return array(
				'inserted' => 0,
				'skipped'  => true,
			);
		}

		$now      = time();
		$captures = array(
			array(
				'route_slug' => 'demo-stripe-webhook',
				'method'     => 'POST',
				'provider'   => 'stripe',
				'payload'    => wp_json_encode(
					array(
						'type'     => 'payment_intent.succeeded',
						'amount'   => 2999,
						'currency' => 'usd',
						'metadata' => array(
							'order_id'       => 'ord_demo_001',
							'customer_name'  => 'Alice Johnson',
							'customer_email' => 'alice@example.com',
							'plan'           => 'Pro',
						),
					)
				),
				'headers'    => wp_json_encode(
					array(
						'content-type'     => 'application/json',
						'stripe-signature' => 'v1=demo_sig_1',
					)
				),
				'source_ip'  => '54.187.174.169',
				'sig_status' => 'verified',
				'mapped'     => 1,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 3600 ),
			),
			array(
				'route_slug' => 'demo-stripe-webhook',
				'method'     => 'POST',
				'provider'   => 'stripe',
				'payload'    => wp_json_encode(
					array(
						'type'               => 'payment_intent.payment_failed',
						'amount'             => 1500,
						'currency'           => 'usd',
						'last_payment_error' => array(
							'code'    => 'card_declined',
							'message' => 'Your card was declined.',
						),
						'metadata'           => array(
							'order_id'       => 'ord_demo_002',
							'customer_name'  => 'Bob Smith',
							'customer_email' => 'bob@example.com',
						),
					)
				),
				'headers'    => wp_json_encode(
					array(
						'content-type'     => 'application/json',
						'stripe-signature' => 'v1=demo_sig_2',
					)
				),
				'source_ip'  => '3.18.12.63',
				'sig_status' => 'verified',
				'mapped'     => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 7200 ),
			),
			array(
				'route_slug' => 'demo-hubspot-contact',
				'method'     => 'POST',
				'provider'   => 'hubspot',
				'payload'    => wp_json_encode(
					array(
						'objectId'   => 12345,
						'objectType' => 'CONTACT',
						'properties' => array(
							'firstname'            => 'Carol',
							'lastname'             => 'Williams',
							'email'                => 'carol@acme.com',
							'company'              => 'Acme Corp',
							'phone'                => '+14155551234',
							'lifecyclestage'       => 'lead',
							'hs_marketable_status' => 'true',
						),
					)
				),
				'headers'    => wp_json_encode(
					array(
						'content-type'        => 'application/json',
						'x-hubspot-signature' => 'demo_hs_sig',
					)
				),
				'source_ip'  => '185.234.219.167',
				'sig_status' => 'failed',
				'mapped'     => 1,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 1800 ),
			),
			array(
				'route_slug' => 'demo-order-notify',
				'method'     => 'POST',
				'provider'   => 'custom',
				'payload'    => wp_json_encode(
					array(
						'order'     => array(
							'id'     => 'WC-10042',
							'total'  => '149.99',
							'status' => 'completed',
						),
						'customer'  => array(
							'email' => 'dave@example.com',
							'phone' => '+447700900123',
							'name'  => 'Dave Evans',
						),
						'timestamp' => gmdate( 'c', $now - 900 ),
					)
				),
				'headers'    => wp_json_encode(
					array(
						'content-type' => 'application/json',
						'x-wrm-token'  => 'demo_order_token_xyz789',
					)
				),
				'source_ip'  => '10.0.0.5',
				'sig_status' => 'skipped',
				'mapped'     => 1,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 900 ),
			),
			array(
				'route_slug' => 'demo-conditional-chains',
				'method'     => 'POST',
				'provider'   => 'custom',
				'payload'    => wp_json_encode(
					array(
						'event'   => 'purchase',
						'user_id' => 'usr_789',
						'amount'  => 750,
						'product' => 'Enterprise Plan',
						'region'  => 'EMEA',
					)
				),
				'headers'    => wp_json_encode( array( 'content-type' => 'application/json' ) ),
				'source_ip'  => '203.0.113.42',
				'sig_status' => 'skipped',
				'mapped'     => 1,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 300 ),
			),
			array(
				'route_slug' => 'demo-conditional-chains',
				'method'     => 'POST',
				'provider'   => 'custom',
				'payload'    => wp_json_encode(
					array(
						'event'   => 'refund',
						'user_id' => 'usr_456',
						'amount'  => 99,
						'reason'  => 'customer_request',
					)
				),
				'headers'    => wp_json_encode( array( 'content-type' => 'application/json' ) ),
				'source_ip'  => '198.51.100.22',
				'sig_status' => 'skipped',
				'mapped'     => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 60 ),
			),
		);

		$inserted = 0;
		foreach ( $captures as $cap ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $cap );
			++$inserted;
		}

		return array( 'inserted' => $inserted );
	}

	// -------------------------------------------------------------------------
	// Jobs
	// -------------------------------------------------------------------------

	private static function seed_jobs(): array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::JOBS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE route_slug LIKE 'demo-%'" );
		if ( $existing >= 5 ) {
			return array(
				'inserted' => 0,
				'skipped'  => true,
			);
		}

		$jobs = array(
			array(
				'job_key'       => md5( 'demo-job-1' ),
				'route_slug'    => 'demo-stripe-webhook',
				'capture_id'    => 0,
				'mapping_id'    => 0,
				'status'        => 'done',
				'attempt'       => 1,
				'max_attempts'  => 3,
				'result'        => wp_json_encode(
					array(
						'success' => true,
						'post_id' => 42,
						'action'  => 'created',
						'chains'  => array(
							array(
								'type'   => 'email',
								'status' => 'sent',
								'to'     => 'alice@example.com',
							),
						),
					)
				),
				'duration_ms'   => 324,
				'queued_at'     => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
				'started_at'    => gmdate( 'Y-m-d H:i:s', time() - 3599 ),
				'finished_at'   => gmdate( 'Y-m-d H:i:s', time() - 3598 ),
				'next_retry_at' => null,
			),
			array(
				'job_key'       => md5( 'demo-job-2' ),
				'route_slug'    => 'demo-hubspot-contact',
				'capture_id'    => 0,
				'mapping_id'    => 0,
				'status'        => 'done',
				'attempt'       => 1,
				'max_attempts'  => 3,
				'result'        => wp_json_encode(
					array(
						'success' => true,
						'post_id' => 43,
						'action'  => 'created',
						'chains'  => array(
							array(
								'type'   => 'sms',
								'status' => 'sent',
								'to'     => '+14155551234',
							),
						),
					)
				),
				'duration_ms'   => 891,
				'queued_at'     => gmdate( 'Y-m-d H:i:s', time() - 1800 ),
				'started_at'    => gmdate( 'Y-m-d H:i:s', time() - 1799 ),
				'finished_at'   => gmdate( 'Y-m-d H:i:s', time() - 1798 ),
				'next_retry_at' => null,
			),
			array(
				'job_key'       => md5( 'demo-job-3' ),
				'route_slug'    => 'demo-order-notify',
				'capture_id'    => 0,
				'mapping_id'    => 0,
				'status'        => 'failed',
				'attempt'       => 2,
				'max_attempts'  => 3,
				'error_message' => 'cURL error 6: Could not resolve host: httpbin.org (Demo simulated error — external webhook unreachable)',
				'duration_ms'   => 15023,
				'queued_at'     => gmdate( 'Y-m-d H:i:s', time() - 900 ),
				'started_at'    => gmdate( 'Y-m-d H:i:s', time() - 895 ),
				'finished_at'   => gmdate( 'Y-m-d H:i:s', time() - 880 ),
				'next_retry_at' => gmdate( 'Y-m-d H:i:s', time() + 1200 ),
			),
			array(
				'job_key'       => md5( 'demo-job-4' ),
				'route_slug'    => 'demo-conditional-chains',
				'capture_id'    => 0,
				'mapping_id'    => 0,
				'status'        => 'dead',
				'attempt'       => 3,
				'max_attempts'  => 3,
				'error_message' => 'Function wrm_demo_log_event is not registered in the allowlist. Add WRM_Mapper::register_callback("wrm_demo_log_event") to plugins_loaded.',
				'duration_ms'   => 12,
				'queued_at'     => gmdate( 'Y-m-d H:i:s', time() - 600 ),
				'started_at'    => gmdate( 'Y-m-d H:i:s', time() - 598 ),
				'finished_at'   => gmdate( 'Y-m-d H:i:s', time() - 597 ),
				'next_retry_at' => null,
			),
			array(
				'job_key'       => md5( 'demo-job-5' ),
				'route_slug'    => 'demo-stripe-webhook',
				'capture_id'    => 0,
				'mapping_id'    => 0,
				'status'        => 'queued',
				'attempt'       => 0,
				'max_attempts'  => 3,
				'queued_at'     => gmdate( 'Y-m-d H:i:s', time() - 30 ),
				'next_retry_at' => gmdate( 'Y-m-d H:i:s', time() - 25 ),
			),
		);

		$inserted = 0;
		foreach ( $jobs as $job ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $job );
			++$inserted;
		}

		return array( 'inserted' => $inserted );
	}

	// -------------------------------------------------------------------------
	// Schedules
	// -------------------------------------------------------------------------

	private static function seed_schedules(): array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::SCHEDULES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE label LIKE 'Demo:%'" );
		if ( $existing >= 2 ) {
			return array(
				'inserted' => 0,
				'skipped'  => true,
			);
		}

		$mapping_id = self::get_demo_mapping_id( 'Demo: Order Notification (Email + SMS)' );

		$schedules = array(
			array(
				'label'         => 'Demo: Daily Sales Report',
				'mapping_id'    => $mapping_id,
				'interval_key'  => 'daily',
				'seed_payload'  => wp_json_encode(
					array(
						'order'     => array(
							'id'     => 'SCHEDULED-001',
							'total'  => '0.00',
							'status' => 'report',
						),
						'customer'  => array(
							'email' => 'admin@example.com',
							'phone' => '',
							'name'  => 'Admin',
						),
						'timestamp' => '{{now}}',
					)
				),
				'source_url'    => '',
				'source_method' => 'GET',
				'trigger_token' => wp_generate_password( 32, false ),
				'run_mode'      => 'async',
				'status'        => 'active',
				'last_run'      => gmdate( 'Y-m-d H:i:s', time() - 86400 ),
				'next_run'      => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				'last_result'   => wp_json_encode(
					array(
						'success' => true,
						'post_id' => 10,
						'action'  => 'created',
					)
				),
				'created_at'    => current_time( 'mysql' ),
			),
			array(
				'label'         => 'Demo: External API Sync (Hourly)',
				'mapping_id'    => $mapping_id,
				'interval_key'  => 'hourly',
				'seed_payload'  => null,
				'source_url'    => 'https://jsonplaceholder.typicode.com/todos/1',
				'source_method' => 'GET',
				'trigger_token' => wp_generate_password( 32, false ),
				'run_mode'      => 'async',
				'status'        => 'paused',
				'last_run'      => null,
				'next_run'      => null,
				'last_result'   => null,
				'created_at'    => current_time( 'mysql' ),
			),
		);

		$inserted = 0;
		foreach ( $schedules as $sched ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $sched );
			++$inserted;
		}

		return array( 'inserted' => $inserted );
	}

	// -------------------------------------------------------------------------
	// Messages
	// -------------------------------------------------------------------------

	private static function seed_messages(): array {
		global $wpdb;
		$msgs_table   = $wpdb->prefix . WRM_Installer::MESSAGES_TABLE;
		$events_table = $wpdb->prefix . WRM_Installer::MESSAGE_EVENTS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$msgs_table} WHERE recipient LIKE '%@example.com'" );
		if ( $existing >= 5 ) {
			return array(
				'inserted' => 0,
				'skipped'  => true,
			);
		}

		$now      = time();
		$messages = array(
			array(
				'token'               => wp_generate_password( 32, false ),
				'channel'             => 'email',
				'provider'            => 'wp_mail',
				'recipient'           => 'alice@example.com',
				'subject'             => 'Payment Confirmed — Order #ord_demo_001',
				'mapping_id'          => 0,
				'capture_id'          => 0,
				'provider_message_id' => '',
				'status'              => 'opened',
				'open_count'          => 3,
				'click_count'         => 1,
				'sent_at'             => gmdate( 'Y-m-d H:i:s', $now - 3500 ),
				'first_open_at'       => gmdate( 'Y-m-d H:i:s', $now - 3200 ),
				'created_at'          => gmdate( 'Y-m-d H:i:s', $now - 3600 ),
			),
			array(
				'token'               => wp_generate_password( 32, false ),
				'channel'             => 'sms',
				'provider'            => 'twilio',
				'recipient'           => '+14155551234',
				'subject'             => 'Hi Carol, thanks for signing up! Reply STOP to opt out.',
				'mapping_id'          => 0,
				'capture_id'          => 0,
				'provider_message_id' => 'SM_demo_twilio_001',
				'status'              => 'delivered',
				'open_count'          => 0,
				'click_count'         => 0,
				'sent_at'             => gmdate( 'Y-m-d H:i:s', $now - 1750 ),
				'first_open_at'       => null,
				'created_at'          => gmdate( 'Y-m-d H:i:s', $now - 1800 ),
			),
			array(
				'token'               => wp_generate_password( 32, false ),
				'channel'             => 'email',
				'provider'            => 'wp_mail',
				'recipient'           => 'dave@example.com',
				'subject'             => 'Order #WC-10042 — completed',
				'mapping_id'          => 0,
				'capture_id'          => 0,
				'provider_message_id' => '',
				'status'              => 'clicked',
				'open_count'          => 1,
				'click_count'         => 2,
				'sent_at'             => gmdate( 'Y-m-d H:i:s', $now - 850 ),
				'first_open_at'       => gmdate( 'Y-m-d H:i:s', $now - 800 ),
				'created_at'          => gmdate( 'Y-m-d H:i:s', $now - 900 ),
			),
			array(
				'token'               => wp_generate_password( 32, false ),
				'channel'             => 'sms',
				'provider'            => 'twilio',
				'recipient'           => '+447700900123',
				'subject'             => 'Your order #WC-10042 is completed. Total: $149.99',
				'mapping_id'          => 0,
				'capture_id'          => 0,
				'provider_message_id' => 'SM_demo_twilio_002',
				'status'              => 'sent',
				'open_count'          => 0,
				'click_count'         => 0,
				'sent_at'             => gmdate( 'Y-m-d H:i:s', $now - 840 ),
				'first_open_at'       => null,
				'created_at'          => gmdate( 'Y-m-d H:i:s', $now - 900 ),
			),
			array(
				'token'               => wp_generate_password( 32, false ),
				'channel'             => 'email',
				'provider'            => 'wp_mail',
				'recipient'           => 'admin@example.com',
				'subject'             => '[DLQ] Failed job #4 from demo-conditional-chains',
				'mapping_id'          => 0,
				'capture_id'          => 0,
				'provider_message_id' => '',
				'status'              => 'bounced',
				'open_count'          => 0,
				'click_count'         => 0,
				'sent_at'             => gmdate( 'Y-m-d H:i:s', $now - 590 ),
				'first_open_at'       => null,
				'created_at'          => gmdate( 'Y-m-d H:i:s', $now - 600 ),
			),
		);

		$inserted = 0;
		foreach ( $messages as $msg ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $msgs_table, $msg );
			$msg_id = (int) $wpdb->insert_id;

			// Insert events for messages with trackable statuses.
			if ( $msg_id && in_array( $msg['status'], array( 'opened', 'clicked', 'delivered' ), true ) ) {
				$event_rows = array();
				if ( 'opened' === $msg['status'] || 'clicked' === $msg['status'] ) {
					$event_rows[] = array(
						'message_id' => $msg_id,
						'event'      => 'sent',
						'ip'         => '',
						'url'        => '',
						'user_agent' => '',
						'data'       => null,
						'created_at' => gmdate( 'Y-m-d H:i:s', $now - 3500 ),
					);
					$event_rows[] = array(
						'message_id' => $msg_id,
						'event'      => 'opened',
						'ip'         => '203.0.113.1',
						'url'        => '',
						'user_agent' => 'Mozilla/5.0 (Macintosh)',
						'data'       => null,
						'created_at' => $msg['first_open_at'] ?? current_time( 'mysql' ), // @phpstan-ignore-line
					);
				}
				if ( 'clicked' === $msg['status'] ) {
					$event_rows[] = array(
						'message_id' => $msg_id,
						'event'      => 'clicked',
						'ip'         => '203.0.113.1',
						'url'        => 'https://example.com/order/WC-10042',
						'user_agent' => 'Mozilla/5.0',
						'data'       => null,
						'created_at' => gmdate( 'Y-m-d H:i:s', $now - 750 ),
					);
					$event_rows[] = array(
						'message_id' => $msg_id,
						'event'      => 'clicked',
						'ip'         => '203.0.113.1',
						'url'        => 'https://example.com/track',
						'user_agent' => 'Mozilla/5.0',
						'data'       => null,
						'created_at' => gmdate( 'Y-m-d H:i:s', $now - 600 ),
					);
				}
				if ( 'delivered' === $msg['status'] ) {
					$event_rows[] = array(
						'message_id' => $msg_id,
						'event'      => 'sent',
						'ip'         => '',
						'url'        => '',
						'user_agent' => '',
						'data'       => null,
						'created_at' => $msg['sent_at'],
					);
					$event_rows[] = array(
						'message_id' => $msg_id,
						'event'      => 'delivered',
						'ip'         => '',
						'url'        => '',
						'user_agent' => 'Twilio/Webhook',
						'data'       => null,
						'created_at' => gmdate( 'Y-m-d H:i:s', $now - 1700 ),
					);
				}
				foreach ( $event_rows as $ev ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->insert( $events_table, $ev );
				}
			}

			++$inserted;
		}

		return array( 'inserted' => $inserted );
	}

	// -------------------------------------------------------------------------
	// Metrics
	// -------------------------------------------------------------------------

	private static function seed_metrics(): array {
		$routes   = array( 'demo-stripe-webhook', 'demo-hubspot-contact', 'demo-order-notify', 'demo-conditional-chains' );
		$inserted = 0;

		for ( $h = 23; $h >= 0; $h-- ) {
			$bucket = gmdate( 'Y-m-d H:00:00', time() - ( $h * 3600 ) );
			foreach ( $routes as $slug ) {
				$captures  = max( 0, (int) ( ( sin( ( 23 - $h ) / 4 ) + 1 ) * 5 ) + wp_rand( 0, 3 ) );
				$jobs_ok   = max( 0, $captures - wp_rand( 0, 2 ) );
				$jobs_fail = max( 0, $captures - $jobs_ok - wp_rand( 0, 1 ) );

				if ( $captures > 0 ) {
					global $wpdb;
					$table = $wpdb->prefix . WRM_Installer::METRICS_TABLE;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query(
						$wpdb->prepare(
							"INSERT IGNORE INTO {$table} (route_slug, bucket, captures, jobs_ok, jobs_failed)
							 VALUES (%s, %s, %d, %d, %d)",
							$slug,
							$bucket,
							$captures,
							$jobs_ok,
							$jobs_fail
						)
					);
					++$inserted;
				}
			}
		}

		return array( 'inserted' => $inserted );
	}

	// -------------------------------------------------------------------------
	// Logs
	// -------------------------------------------------------------------------

	private static function seed_logs(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wrm_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE context IN ('demo','queue','mapper','router')" );
		if ( $existing >= 20 ) {
			return array(
				'inserted' => 0,
				'skipped'  => true,
			);
		}

		$now  = time();
		$logs = array(
			array(
				'level'      => 'info',
				'context'    => 'router',
				'ref_id'     => 1,
				'message'    => '[demo-stripe-webhook] Request received — sig verified, enqueued job #1',
				'data'       => null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 3601 ),
			),
			array(
				'level'      => 'info',
				'context'    => 'queue',
				'ref_id'     => 1,
				'message'    => 'Job #1 started (attempt 1/3).',
				'data'       => null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 3599 ),
			),
			array(
				'level'      => 'info',
				'context'    => 'mapper',
				'ref_id'     => 1,
				'message'    => 'Mapping applied — post #42 created, email sent to alice@example.com',
				'data'       => null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 3598 ),
			),
			array(
				'level'      => 'info',
				'context'    => 'queue',
				'ref_id'     => 1,
				'message'    => 'Job #1 completed successfully.',
				'data'       => null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 3597 ),
			),
			array(
				'level'      => 'info',
				'context'    => 'router',
				'ref_id'     => 2,
				'message'    => '[demo-hubspot-contact] Request received — sig verification failed (skipping), enqueued job #2',
				'data'       => null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 1801 ),
			),
			array(
				'level'      => 'warning',
				'context'    => 'mapper',
				'ref_id'     => 2,
				'message'    => 'Chain skipped — conditions not met (phone not_empty check failed)',
				'data'       => wp_json_encode(
					array(
						'type'      => 'sms',
						'condition' => array(
							'field' => 'properties.phone',
							'op'    => 'not_empty',
						),
					)
				),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 1799 ),
			),
			array(
				'level'      => 'info',
				'context'    => 'queue',
				'ref_id'     => 3,
				'message'    => 'Job #3 failed on attempt 2; retry in 10 min.',
				'data'       => null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 880 ),
			),
			array(
				'level'      => 'error',
				'context'    => 'queue',
				'ref_id'     => 3,
				'message'    => 'cURL error 6: Could not resolve host: httpbin.org (Demo simulated error)',
				'data'       => wp_json_encode(
					array(
						'attempt'      => 2,
						'max_attempts' => 3,
					)
				),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 879 ),
			),
			array(
				'level'      => 'error',
				'context'    => 'queue',
				'ref_id'     => 4,
				'message'    => 'Job #4 permanently failed after 3 attempt(s).',
				'data'       => wp_json_encode( array( 'error' => 'Function wrm_demo_log_event not in allowlist' ) ),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 597 ),
			),
			array(
				'level'      => 'warning',
				'context'    => 'mapper',
				'ref_id'     => 4,
				'message'    => 'Blocked unregistered callback — wrm_demo_log_event. Add WRM_Mapper::register_callback() to plugins_loaded.',
				'data'       => null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 598 ),
			),
			array(
				'level'      => 'info',
				'context'    => 'queue',
				'ref_id'     => 4,
				'message'    => 'Job #4 dispatched to dead-letter route "demo-dead-letter-queue".',
				'data'       => null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 596 ),
			),
			array(
				'level'      => 'debug',
				'context'    => 'router',
				'ref_id'     => 5,
				'message'    => '[demo-conditional-chains] Chain skipped — conditions not met (amount gte 500 check passed, event contains purchase check passed)',
				'data'       => null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 299 ),
			),
			array(
				'level'      => 'info',
				'context'    => 'router',
				'ref_id'     => 6,
				'message'    => '[demo-conditional-chains] Received refund event — webhook chain dispatched to httpbin.org',
				'data'       => null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 59 ),
			),
			array(
				'level'      => 'info',
				'context'    => 'queue',
				'ref_id'     => 5,
				'message'    => 'Job #5 queued — waiting for next cron sweep.',
				'data'       => null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 29 ),
			),
		);

		$inserted = 0;
		foreach ( $logs as $log ) {
			$row = array(
				'level'      => $log['level'],
				'context'    => $log['context'],
				'ref_id'     => $log['ref_id'],
				'message'    => $log['message'],
				'data'       => $log['data'],
				'created_at' => $log['created_at'],
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $row );
			++$inserted;
		}

		return array( 'inserted' => $inserted );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private static function get_demo_mapping_id( string $title ): int {
		$q = new WP_Query(
			array(
				'post_type'      => 'wrm_mapping',
				'title'          => $title,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return $q->have_posts() ? (int) $q->posts[0] : 0;
	}
}
