<?php
/**
 * Mapping Editor meta box — renders the visual mapping builder on wrm_mapping CPT.
 *
 * Outputs a hidden textarea (#wrm_config_json) as the canonical store.
 * The JS builder reads and writes this field; PHP only reads it on save.
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Mapping_Editor {

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_wrm_mapping', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'redirect_post_location', array( __CLASS__, 'maybe_add_error_notice' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'show_notices' ) );
	}

	public static function add_meta_boxes(): void {
		add_meta_box(
			'wrm_mapping_builder',
			__( 'Mapping Builder', 'wrm' ),
			array( __CLASS__, 'render' ),
			'wrm_mapping',
			'normal',
			'high'
		);
		add_meta_box(
			'wrm_mapping_route',
			__( 'Route Association', 'wrm' ),
			array( __CLASS__, 'render_route_box' ),
			'wrm_mapping',
			'side',
			'default'
		);
	}

	public static function enqueue( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || 'wrm_mapping' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script( 'wp-api-fetch' );
		wp_enqueue_script(
			'wrm-mapping-builder',
			WRM_PLUGIN_URL . 'assets/js/wrm-mapping-builder.js',
			array( 'jquery', 'wp-api-fetch' ),
			WRM_VERSION,
			true
		);
		wp_enqueue_style(
			'wrm-admin',
			WRM_PLUGIN_URL . 'assets/css/wrm-admin.css',
			array(),
			WRM_VERSION
		);

		// Pass data to JS
		global $post;
		$route_slug = '';
		if ( $post ) {
			$route_slug = get_post_meta( $post->ID, 'wrm_route_slug', true ) ?: '';
		}

		// Get all registered public post types for CPT selector
		$post_types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
			$post_types[] = array(
				'slug'  => $pt->name,
				'label' => $pt->labels->singular_name ?: $pt->name,
			);
		}

		wp_localize_script(
			'wrm-mapping-builder',
			'wrmData',
			array(
				'apiRoot'   => esc_url_raw( rest_url() ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'postTypes' => $post_types,
				'routeSlug' => $route_slug,
				'postId'    => $post ? (int) $post->ID : 0,
				'i18n'      => array(
					'addField'      => __( '+ Add Field', 'wrm' ),
					'addChain'      => __( '+ Add Chain', 'wrm' ),
					'removeField'   => __( 'Remove', 'wrm' ),
					'insertTag'     => __( 'Insert Tag', 'wrm' ),
					'noCaptures'    => __( 'No captures yet — send a test request to this endpoint to load available tags.', 'wrm' ),
					'invalidJson'   => __( 'Invalid JSON. Please fix before switching to visual mode.', 'wrm' ),
					'saved'         => __( 'Config saved.', 'wrm' ),
					'confirmDelete' => __( 'Remove this item?', 'wrm' ),
				),
			)
		);
	}

	public static function render( WP_Post $post ): void {
		wp_nonce_field( 'wrm_save_mapping_' . $post->ID, 'wrm_mapping_nonce' );

		$raw = get_post_meta( $post->ID, 'wrm_config', true ) ?: '{}';

		// Validate stored JSON
		$decoded = json_decode( $raw );
		if ( null === $decoded ) {
			$raw = '{}';
		}
		?>
		<div id="wrm-mapping-editor-wrap" class="wrm-editor-wrap">

			<?php /* Hidden canonical config store — JS reads+writes this */ ?>
			<textarea id="wrm_config_json" name="wrm_config_json" class="wrm-hidden-config"
				aria-hidden="true"><?php echo esc_textarea( $raw ); ?></textarea>

			<?php /* Visual builder mounts here */ ?>
			<div id="wrm-mapping-builder-container" class="wrm-builder-root" data-config="<?php echo esc_attr( $raw ); ?>">
				<p class="wrm-loading"><?php esc_html_e( 'Loading builder…', 'wrm' ); ?></p>
			</div>

		</div>
		<?php
	}

	public static function render_route_box( WP_Post $post ): void {
		wp_nonce_field( 'wrm_save_route_slug_' . $post->ID, 'wrm_route_slug_nonce' );

		$route_slug = get_post_meta( $post->ID, 'wrm_route_slug', true ) ?: '';

		global $wpdb;
		$table  = $wpdb->prefix . WRM_Installer::ROUTES_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$routes = $wpdb->get_results( "SELECT slug, label FROM {$table} ORDER BY slug ASC", ARRAY_A ) ?? array();
		?>
		<p class="description">
			<?php esc_html_e( 'Associate this mapping with a route to load merge tags from its captures.', 'wrm' ); ?>
		</p>
		<select id="wrm_route_slug" name="wrm_route_slug" class="widefat">
			<option value=""><?php esc_html_e( '— None —', 'wrm' ); ?></option>
			<?php foreach ( $routes as $r ) : ?>
				<option value="<?php echo esc_attr( $r['slug'] ); ?>" <?php selected( $route_slug, $r['slug'] ); ?>>
					<?php echo esc_html( $r['label'] ?: $r['slug'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description" style="margin-top:8px">
			<?php esc_html_e( 'Webhook URL:', 'wrm' ); ?>
			<?php if ( $route_slug ) : ?>
				<code><?php echo esc_html( rest_url( 'wrm/v1/' . $route_slug ) ); ?></code>
			<?php else : ?>
				<em><?php esc_html_e( 'Select a route above', 'wrm' ); ?></em>
			<?php endif; ?>
		</p>
		<?php
	}

	public static function save( int $post_id, WP_Post $post ): void {
		// Security checks
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save mapping config
		if (
			isset( $_POST['wrm_mapping_nonce'] ) &&
			wp_verify_nonce( sanitize_key( $_POST['wrm_mapping_nonce'] ), 'wrm_save_mapping_' . $post_id )
		) {
			$raw_config = isset( $_POST['wrm_config_json'] ) ? wp_unslash( $_POST['wrm_config_json'] ) : '{}'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			$decoded = json_decode( $raw_config, true );
			if ( null === $decoded ) {
				// Invalid JSON — store error flag
				set_transient( 'wrm_json_error_' . $post_id, 1, 30 );
				WRM_Logger::error( 'admin', 'Invalid JSON config on save', array( 'post_id' => $post_id ) );
				return;
			}

			update_post_meta( $post_id, 'wrm_config', $raw_config );
			WRM_Logger::info( 'admin', 'Mapping config saved', array( 'post_id' => $post_id, 'ref_id' => $post_id ) );
		}

		// Save route association
		if (
			isset( $_POST['wrm_route_slug_nonce'] ) &&
			wp_verify_nonce( sanitize_key( $_POST['wrm_route_slug_nonce'] ), 'wrm_save_route_slug_' . $post_id )
		) {
			$route_slug = isset( $_POST['wrm_route_slug'] ) ? sanitize_title( wp_unslash( $_POST['wrm_route_slug'] ) ) : '';
			update_post_meta( $post_id, 'wrm_route_slug', $route_slug );
		}
	}

	public static function maybe_add_error_notice( string $location, int $post_id ): string {
		if ( get_transient( 'wrm_json_error_' . $post_id ) ) {
			$location = add_query_arg( 'wrm_json_error', '1', $location );
		}
		return $location;
	}

	public static function show_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'wrm_mapping' !== $screen->post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['wrm_json_error'] ) ) {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Mapping not saved: the JSON config was invalid. Please check the Config Preview panel.', 'wrm' );
			echo '</p></div>';
		}
	}
}
