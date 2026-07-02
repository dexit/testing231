<?php
/**
 * Elementor 4.1+ integration.
 *
 * Routes Elementor Pro Form submissions through WRM's capture → job pipeline
 * so any form can be treated as a webhook source without a real HTTP request.
 *
 * Setup:
 *  1. In any Elementor Pro Form widget, add a "Webhook" action (built-in) OR
 *     simply rely on this integration — it fires on every form submission that
 *     belongs to a route slug matching "elementor_<form-name-slug>".
 *  2. Optionally call WRM_Elementor::map_form( 'Contact Form', 'my-route-slug' )
 *     during `plugins_loaded` to map a specific form to a custom route slug.
 *
 * Compatibility: Elementor 4.1+ / Elementor Pro 3.7+ / WP 6.9+ / PHP 8.3+
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Elementor {

	/** @var array<string,string> form-name → route-slug overrides set by integrators. */
	private static array $form_map = array();

	public static function init(): void {
		// Elementor Pro 3.7+ fires this after form validation passes.
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'handle_form' ), 10, 2 );

		// Prevent Elementor editor scripts from being enqueued on WRM admin pages
		// (avoids JS conflicts with the jQuery mapping builder).
		add_action( 'elementor/editor/after_enqueue_scripts', array( __CLASS__, 'dequeue_on_elementor_editor' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'dequeue_on_wrm_pages' ), 99 );

		// Block Elementor from injecting its meta into wrm_mapping posts.
		add_filter( 'elementor/document/urls/edit', array( __CLASS__, 'block_elementor_edit_url' ), 10, 2 );
	}

	/**
	 * Register a form-name → route-slug mapping so a specific Elementor form
	 * routes to a specific WRM route rather than the auto-derived slug.
	 *
	 * @param string $form_name  Exact form name as set in Elementor widget settings.
	 * @param string $route_slug WRM route slug to use for captures from this form.
	 */
	public static function map_form( string $form_name, string $route_slug ): void {
		self::$form_map[ $form_name ] = sanitize_title( $route_slug );
	}

	/**
	 * Handle an Elementor Pro form submission.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record      Submitted form data.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Elementor AJAX handler.
	 */
	public static function handle_form( object $record, object $ajax_handler ): void {
		$form_name  = (string) $record->get_form_settings( 'form_name' );
		$route_slug = self::$form_map[ $form_name ]
			?? ( 'elementor_' . sanitize_title( $form_name ?: 'form' ) );

		// Flatten Elementor field array: [ id => value, label => value ]
		$raw_fields = (array) $record->get( 'fields' );
		$payload    = array(
			'_form_name'    => $form_name,
			'_form_id'      => $record->get_form_settings( 'id' ) ?? '',
			'_submitted_on' => gmdate( 'Y-m-d H:i:s' ),
			'fields'        => array(),
		);

		foreach ( $raw_fields as $field_id => $field ) {
			$field_id                       = sanitize_key( (string) $field_id );
			$value                          = $field['value'] ?? '';
			$payload['fields'][ $field_id ] = $value;
			// Also expose by label for convenience: e.g. payload.email
			$label             = sanitize_key( (string) ( $field['label'] ?? $field_id ) );
			$payload[ $label ] = $value;
		}

		// Normalize via the WRM provider layer so custom normalizers can hook in.
		$payload = (array) apply_filters( 'wrm_normalize_elementor_payload', $payload, $record );

		$capture_id = WRM_Capture::store_internal( $route_slug, 'elementor', $payload );

		if ( ! $capture_id ) {
			WRM_Logger::error(
				'elementor',
				'Failed to store capture for Elementor form.',
				array(
					'form_name'  => $form_name,
					'route_slug' => $route_slug,
				)
			);
			return;
		}

		// Look up the route to find the assigned mapping_id and run_mode.
		$route = self::get_route( $route_slug );

		if ( ! $route ) {
			// No route configured for this form — capture is stored, discoverable,
			// but nothing is dispatched. Admin can replay it later.
			WRM_Logger::info(
				'elementor',
				"Elementor form '{$form_name}' stored as capture #{$capture_id} (no route '{$route_slug}' configured).",
				array( 'ref_id' => $capture_id )
			);
			return;
		}

		if ( 'paused' === ( $route['status'] ?? 'active' ) ) {
			WRM_Logger::info( 'elementor', "Route '{$route_slug}' is paused — capture #{$capture_id} stored only.", array( 'ref_id' => $capture_id ) );
			return;
		}

		$mapping_id = (int) ( $route['mapping_id'] ?? 0 );
		if ( ! $mapping_id ) {
			WRM_Logger::info( 'elementor', "Route '{$route_slug}' has no mapping_id — capture #{$capture_id} stored only.", array( 'ref_id' => $capture_id ) );
			return;
		}

		$run_mode = sanitize_key( $route['run_mode'] ?? 'async' );

		if ( 'sync' === $run_mode ) {
			$result = WRM_Mapper::apply( $capture_id, $mapping_id );
			if ( ! empty( $result['success'] ) ) {
				WRM_Capture::mark_mapped( $capture_id, wp_json_encode( $result ) );
			}
			WRM_Logger::info( 'elementor', "Elementor form '{$form_name}' mapped synchronously (capture #{$capture_id}).", array( 'ref_id' => $capture_id ) );
		} else {
			$job_id = WRM_Job_Queue::enqueue( $route_slug, $capture_id, $mapping_id );
			WRM_Logger::info(
				'elementor',
				"Elementor form '{$form_name}' enqueued as job #{$job_id} (capture #{$capture_id}).",
				array( 'ref_id' => $capture_id )
			);
		}
	}

	/**
	 * Prevent WRM mapping builder scripts from loading in the Elementor editor.
	 */
	public static function dequeue_on_elementor_editor(): void {
		wp_dequeue_script( 'wrm-mapping-builder' );
		wp_dequeue_style( 'wrm-mapping-builder' );
	}

	/**
	 * Prevent Elementor editor assets from loading on WRM admin pages.
	 */
	public static function dequeue_on_wrm_pages( string $hook ): void {
		$wrm_hooks = array(
			'toplevel_page_wrm-routes',
			'webhook-router_page_wrm-captures',
			'webhook-router_page_wrm-jobs',
			'webhook-router_page_wrm-logs',
		);
		if ( ! in_array( $hook, $wrm_hooks, true ) ) {
			return;
		}
		wp_dequeue_script( 'elementor-editor' );
		wp_dequeue_style( 'elementor-editor' );
		wp_dequeue_script( 'elementor-common' );
	}

	/**
	 * Prevent Elementor from offering to edit wrm_mapping posts in its editor.
	 *
	 * @param string                            $url      Edit URL.
	 * @param \Elementor\Core\Documents_Manager $document Document.
	 * @return string
	 */
	public static function block_elementor_edit_url( string $url, object $document ): string {
		if ( 'wrm_mapping' === get_post_type( $document->get_main_id() ) ) {
			return '';
		}
		return $url;
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	private static function get_route( string $slug ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . WRM_Installer::ROUTES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug ), ARRAY_A ) ?? null;
	}
}
