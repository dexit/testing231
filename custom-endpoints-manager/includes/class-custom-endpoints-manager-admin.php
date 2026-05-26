<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes
 */

/**
 * Admin-area plugin functionality.
 *
 * @since 1.0.0
 */
class Custom_Endpoints_Manager_Admin {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $plugin_name Plugin slug.
	 * @param string $version     Plugin version.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Check whether the current admin screen is the plugin settings page.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private function is_plugin_page(): bool {
		$screen = get_current_screen();
		return $screen && 'settings_page_custom-endpoints-manager' === $screen->id;
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_styles() {
		if ( ! $this->is_plugin_page() ) {
			return;
		}
		wp_enqueue_style(
			$this->plugin_name,
			CEM_PLUGIN_URL . 'admin/css/custom-endpoints-manager-admin.css',
			array(),
			$this->version,
			'all'
		);
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		if ( ! $this->is_plugin_page() ) {
			return;
		}
		wp_enqueue_script(
			$this->plugin_name,
			CEM_PLUGIN_URL . 'admin/js/custom-endpoints-manager-admin.js',
			array( 'jquery' ),
			$this->version,
			true
		);
		wp_localize_script(
			$this->plugin_name,
			'cem_ajax_object',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'cem_nonce' ),
			)
		);
		wp_localize_script(
			$this->plugin_name,
			'cemI18n',
			array(
				'selectMicroplugin' => __( '— Select Microplugin —', 'custom-endpoints-manager' ),
				'remove'            => __( 'Remove', 'custom-endpoints-manager' ),
			)
		);
	}

	/**
	 * Register the options page.
	 *
	 * @since 1.0.0
	 */
	public function add_options_page() {
		add_options_page(
			__( 'Custom Endpoints Manager', 'custom-endpoints-manager' ),
			__( 'Custom Endpoints', 'custom-endpoints-manager' ),
			'manage_options',
			'custom-endpoints-manager',
			array( $this, 'display_options_page' )
		);
	}

	/**
	 * Render the options page.
	 *
	 * @since 1.0.0
	 */
	public function display_options_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing flag.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'endpoints';
		if ( 'logs' === $tab ) {
			include_once CEM_PLUGIN_DIR . 'admin/partials/cem-execution-logs-display.php';
		} else {
			include_once CEM_PLUGIN_DIR . 'admin/partials/custom-endpoints-manager-admin-display.php';
		}
	}

	/**
	 * Handle the save-endpoints form submission.
	 *
	 * @since 1.0.0
	 */
	public function save_custom_endpoints() {
		if ( ! isset( $_POST['cem_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cem_nonce'] ) ), 'cem_nonce' ) ) {
			wp_die( 'Nonce verification failed' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized user' );
		}

		$endpoints = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized individually below.
		if ( isset( $_POST['cem_endpoints'] ) && is_array( $_POST['cem_endpoints'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( wp_unslash( $_POST['cem_endpoints'] ) as $endpoint ) {
				$sanitized = array(
					'slug'           => sanitize_title( $endpoint['slug'] ),
					'methods'        => sanitize_text_field( $endpoint['methods'] ),
					'capability'     => sanitize_text_field( $endpoint['capability'] ),
					'microplugin_id' => isset( $endpoint['microplugin_id'] ) ? intval( $endpoint['microplugin_id'] ) : 0,
					'args'           => isset( $endpoint['args'] ) ? sanitize_text_field( $endpoint['args'] ) : '',
					'async'          => ! empty( $endpoint['async'] ),
					'max_attempts'   => isset( $endpoint['max_attempts'] ) ? max( 1, min( 10, intval( $endpoint['max_attempts'] ) ) ) : 3,
				);
				if ( ! empty( $sanitized['slug'] ) ) {
					$endpoints[] = $sanitized;
				}
			}
		}

		update_option( 'cem_custom_endpoints', $endpoints );

		wp_safe_redirect( admin_url( 'options-general.php?page=custom-endpoints-manager&message=saved' ) );
		exit;
	}
}
