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
		wp_localize_script(
			$this->plugin_name,
			'cemAI',
			array(
				'statusUrl'     => rest_url( 'cem/v1/ai/status' ),
				'suggestArgs'   => rest_url( 'cem/v1/ai/suggest-args' ),
				'suggestSchema' => rest_url( 'cem/v1/ai/suggest-schema' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
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
	 * Render the nav tabs for the settings page.
	 *
	 * @since 1.0.0
	 * @param string $active_tab The currently active tab key.
	 */
	private function render_nav_tabs( string $active_tab ) {
		$total_logs     = CEM_Execution_Logger::count_logs();
		$total_captures = CEM_Data_Capture::count_captures();
		?>
		<nav class="nav-tab-wrapper">
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=custom-endpoints-manager&tab=endpoints' ) ); ?>"
				class="nav-tab <?php echo 'endpoints' === $active_tab ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Custom Endpoints', 'custom-endpoints-manager' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=custom-endpoints-manager&tab=microplugins' ) ); ?>"
				class="nav-tab <?php echo 'microplugins' === $active_tab ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Microplugins', 'custom-endpoints-manager' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=custom-endpoints-manager&tab=captures' ) ); ?>"
				class="nav-tab <?php echo 'captures' === $active_tab ? 'nav-tab-active' : ''; ?>">
				<?php
				esc_html_e( 'Captures & Mapping', 'custom-endpoints-manager' );
				if ( $total_captures ) {
					echo ' <span class="update-plugins count-' . esc_attr( $total_captures ) . '"><span class="plugin-count">' . esc_html( $total_captures ) . '</span></span>';
				}
				?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=custom-endpoints-manager&tab=logs' ) ); ?>"
				class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">
				<?php
				esc_html_e( 'Execution Logs', 'custom-endpoints-manager' );
				if ( $total_logs ) {
					echo ' <span class="update-plugins count-' . esc_attr( $total_logs ) . '"><span class="plugin-count">' . esc_html( $total_logs ) . '</span></span>';
				}
				?>
			</a>
		</nav>
		<?php
	}

	/**
	 * Render the options page.
	 *
	 * @since 1.0.0
	 */
	public function display_options_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing flag.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'endpoints';

		// Normalise: any unrecognised tab falls back to endpoints.
		if ( ! in_array( $tab, array( 'endpoints', 'microplugins', 'captures', 'logs' ), true ) ) {
			$tab = 'endpoints';
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php $this->render_nav_tabs( $tab ); ?>
			<?php
			if ( 'logs' === $tab ) {
				include CEM_PLUGIN_DIR . 'admin/partials/cem-execution-logs-display.php';
			} elseif ( 'microplugins' === $tab ) {
				include CEM_PLUGIN_DIR . 'admin/partials/cem-microplugins-display.php';
			} elseif ( 'captures' === $tab ) {
				include CEM_PLUGIN_DIR . 'admin/partials/cem-captures-display.php';
			} else {
				include CEM_PLUGIN_DIR . 'admin/partials/custom-endpoints-manager-admin-display.php';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Handle the job requeue action via admin_init (before any HTML output).
	 *
	 * @since 1.0.0
	 */
	public function handle_job_action() {
		if (
			! isset( $_GET['page'], $_GET['cem_action'], $_GET['job_id'], $_GET['_wpnonce'] ) ||
			'custom-endpoints-manager' !== sanitize_key( wp_unslash( $_GET['page'] ) )
		) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cem_job_action' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$job_id     = absint( $_GET['job_id'] );
		$cem_action = sanitize_key( wp_unslash( $_GET['cem_action'] ) );

		if ( 'requeue' === $cem_action ) {
			CEM_Execution_Logger::requeue( $job_id );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=custom-endpoints-manager&tab=logs&message=job_updated' ) );
		exit;
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
				$raw_map = isset( $endpoint['response_map'] ) && is_array( $endpoint['response_map'] ) ? $endpoint['response_map'] : array();

				// Sanitize outgoing webhooks.
				$outgoing_raw = isset( $endpoint['outgoing'] ) && is_array( $endpoint['outgoing'] ) ? $endpoint['outgoing'] : array();
				$outgoing     = array();
				foreach ( $outgoing_raw as $wh ) {
					$wh_url = esc_url_raw( $wh['url'] ?? '' );
					if ( $wh_url ) {
						$outgoing[] = array(
							'method'        => sanitize_text_field( $wh['method'] ?? 'POST' ),
							'url'           => $wh_url,
							'body_template' => sanitize_textarea_field( $wh['body_template'] ?? '' ),
						);
					}
				}

				$sanitized = array(
					'slug'           => sanitize_title( $endpoint['slug'] ),
					'methods'        => sanitize_text_field( $endpoint['methods'] ),
					'capability'     => sanitize_text_field( $endpoint['capability'] ),
					'microplugin_id' => isset( $endpoint['microplugin_id'] ) ? intval( $endpoint['microplugin_id'] ) : 0,
					'args'           => isset( $endpoint['args'] ) ? sanitize_text_field( $endpoint['args'] ) : '',
					'async'          => ! empty( $endpoint['async'] ),
					'capture'        => ! empty( $endpoint['capture'] ),
					'max_attempts'   => isset( $endpoint['max_attempts'] ) ? max( 1, min( 10, intval( $endpoint['max_attempts'] ) ) ) : 3,
					'callback_mode'  => isset( $endpoint['callback_mode'] ) && 'function' === $endpoint['callback_mode'] ? 'function' : 'microplugin',
					'callback_fn'    => isset( $endpoint['callback_fn'] ) ? sanitize_text_field( $endpoint['callback_fn'] ) : '',
					'outgoing'       => $outgoing,
					'response_map'   => array(
						'root'        => sanitize_text_field( $raw_map['root'] ?? '' ),
						'items'       => sanitize_text_field( $raw_map['items'] ?? '' ),
						'total_count' => sanitize_text_field( $raw_map['total_count'] ?? '' ),
						'page'        => sanitize_text_field( $raw_map['page'] ?? '' ),
						'total_pages' => sanitize_text_field( $raw_map['total_pages'] ?? '' ),
					),
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

	/**
	 * Save the capture-to-CPT mapping configuration for an endpoint.
	 *
	 * Triggered by the admin-post action cem_save_mapping.
	 *
	 * @since 1.2.0
	 */
	public function save_capture_mapping() {
		if ( ! isset( $_POST['cem_mapping_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cem_mapping_nonce'] ) ), 'cem_save_mapping' ) ) {
			wp_die( 'Nonce verification failed' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized user' );
		}

		$slug = isset( $_POST['cem_mapping_slug'] ) ? sanitize_title( wp_unslash( $_POST['cem_mapping_slug'] ) ) : '';
		if ( ! $slug ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=custom-endpoints-manager&tab=captures' ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- individual fields sanitized below.
		$raw = isset( $_POST['cem_mapping'] ) && is_array( $_POST['cem_mapping'] ) ? wp_unslash( $_POST['cem_mapping'] ) : array();

		// Sanitize field rules.
		$fields     = array();
		$raw_fields = is_array( $raw['fields'] ?? null ) ? $raw['fields'] : array();
		foreach ( $raw_fields as $rule ) {
			$source = sanitize_text_field( $rule['source'] ?? '' );
			$target = sanitize_text_field( $rule['target'] ?? '' );
			if ( $source && $target ) {
				$fields[] = array(
					'source' => $source,
					'target' => $target,
				);
			}
		}

		// Sanitize chains.
		$chains     = array();
		$raw_chains = is_array( $raw['chains'] ?? null ) ? $raw['chains'] : array();
		foreach ( $raw_chains as $ch ) {
			$url = esc_url_raw( $ch['url'] ?? '' );
			if ( $url ) {
				$chains[] = array(
					'type'   => sanitize_key( $ch['type'] ?? 'webhook' ),
					'method' => sanitize_text_field( $ch['method'] ?? 'POST' ),
					'url'    => $url,
				);
			}
		}

		$mapping = array(
			'cpt'             => sanitize_key( $raw['cpt'] ?? 'post' ),
			'trigger'         => in_array( $raw['trigger'] ?? '', array( 'auto', 'manual' ), true ) ? $raw['trigger'] : 'manual',
			'update_existing' => ! empty( $raw['update_existing'] ),
			'match_source'    => sanitize_text_field( $raw['match_source'] ?? '' ),
			'match_field'     => sanitize_text_field( $raw['match_field'] ?? '' ),
			'fields'          => $fields,
			'chains'          => $chains,
		);

		$all_mappings          = get_option( 'cem_endpoint_mappings', array() );
		$all_mappings[ $slug ] = $mapping;
		update_option( 'cem_endpoint_mappings', $all_mappings );

		wp_safe_redirect(
			admin_url( 'options-general.php?page=custom-endpoints-manager&tab=captures&cap_slug=' . rawurlencode( $slug ) . '&mapping_saved=1' )
		);
		exit;
	}

	/**
	 * Handle the demo-installer form submission.
	 *
	 * Creates 8 example microplugins (Hello World, HubSpot webhooks, Twilio SMS,
	 * form submit) and registers their corresponding endpoints.
	 *
	 * @since 1.0.0
	 */
	public function install_demos() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'cem_install_demos' );

		$examples_dir = CEM_PLUGIN_DIR . 'microplugins/examples/';
		$sep          = "\n";

		$demos = array(
			array(
				'title'        => '[CEM Demo] Hello World',
				'file'         => '',
				'placeholder'  => '',
				'slug'         => 'hello',
				'methods'      => 'GET',
				'capability'   => 'read',
				'async'        => false,
				'max_attempts' => 3,
			),
			array(
				'title'        => '[CEM Demo] HubSpot Deal Webhook',
				'file'         => 'example-hubspot-deal-webhook.php.example',
				'placeholder'  => 'cem_microplugin_callback_101',
				'slug'         => 'webhook/hubspot/deal',
				'methods'      => 'POST',
				'capability'   => 'read',
				'async'        => false,
				'max_attempts' => 3,
			),
			array(
				'title'        => '[CEM Demo] HubSpot Contact Webhook',
				'file'         => 'example-hubspot-contact-webhook.php.example',
				'placeholder'  => 'cem_microplugin_callback_102',
				'slug'         => 'webhook/hubspot/contact',
				'methods'      => 'POST',
				'capability'   => 'read',
				'async'        => false,
				'max_attempts' => 3,
			),
			array(
				'title'        => '[CEM Demo] HubSpot Company Webhook',
				'file'         => 'example-hubspot-company-webhook.php.example',
				'placeholder'  => 'cem_microplugin_callback_103',
				'slug'         => 'webhook/hubspot/company',
				'methods'      => 'POST',
				'capability'   => 'read',
				'async'        => false,
				'max_attempts' => 3,
			),
			array(
				'title'        => '[CEM Demo] Twilio Inbound SMS',
				'file'         => 'example-twilio-receive-sms.php.example',
				'placeholder'  => 'cem_microplugin_callback_104',
				'slug'         => 'webhook/twilio',
				'methods'      => 'POST',
				'capability'   => 'read',
				'async'        => false,
				'max_attempts' => 3,
			),
			array(
				'title'        => '[CEM Demo] Twilio Dispatch SMS (async)',
				'file'         => 'example-twilio-dispatch-sms.php.example',
				'placeholder'  => 'cem_microplugin_callback_105',
				'slug'         => 'dispatch/sms',
				'methods'      => 'POST',
				'capability'   => 'manage_options',
				'async'        => true,
				'max_attempts' => 3,
			),
			array(
				'title'        => '[CEM Demo] Form to HubSpot Submit',
				'file'         => 'example-form-to-hubspot-submit.php.example',
				'placeholder'  => 'cem_microplugin_callback_106',
				'slug'         => 'forms/submit',
				'methods'      => 'POST',
				'capability'   => 'read',
				'async'        => false,
				'max_attempts' => 3,
			),
			array(
				'title'        => '[CEM Demo] HubSpot Contact Enrich & Dispatch',
				'file'         => 'example-hubspot-contact-enrich-and-dispatch.php.example',
				'placeholder'  => 'cem_microplugin_callback_107',
				'slug'         => 'webhook/hubspot/contact-enrich',
				'methods'      => 'POST',
				'capability'   => 'read',
				'async'        => false,
				'max_attempts' => 3,
			),
		);

		$endpoints = get_option( 'cem_custom_endpoints', array() );
		$installed = 0;

		foreach ( $demos as $demo ) {
			// Skip if a post with this title already exists.
			$existing = get_posts(
				array(
					'post_type'      => Microplugins::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'title'          => $demo['title'],
				)
			);
			if ( ! empty( $existing ) ) {
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_title'   => $demo['title'],
					'post_type'    => Microplugins::POST_TYPE,
					'post_status'  => 'draft',
					'post_content' => '',
				)
			);

			if ( ! $post_id || is_wp_error( $post_id ) ) {
				continue;
			}

			$fn = 'cem_microplugin_callback_' . $post_id;

			if ( '' === $demo['file'] ) {
				$code  = '<?php' . $sep;
				$code .= 'function ' . $fn . '( WP_REST_Request $request ): WP_REST_Response {' . $sep;
				$code .= '    return CEM::response( array(' . $sep;
				$code .= "        'message' => 'Hello from WordPress!'," . $sep;
				$code .= "        'method'  => CEM::method()," . $sep;
				$code .= "        'time'    => CEM::now()," . $sep;
				$code .= "        'user_id' => CEM::user_id()" . $sep;
				$code .= '    ) );' . $sep;
				$code .= '}';
			} else {
				$file_path = $examples_dir . $demo['file'];
				if ( ! file_exists( $file_path ) ) {
					wp_delete_post( $post_id, true );
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local plugin example file.
				$code = file_get_contents( $file_path );
				$code = str_replace( $demo['placeholder'], $fn, $code );
			}

			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $code,
					'post_status'  => 'publish',
				)
			);

			Microplugins::dump( $post_id );

			$endpoints[] = array(
				'slug'           => $demo['slug'],
				'methods'        => $demo['methods'],
				'capability'     => $demo['capability'],
				'microplugin_id' => $post_id,
				'args'           => '',
				'async'          => $demo['async'],
				'max_attempts'   => $demo['max_attempts'],
			);
			++$installed;
		}

		update_option( 'cem_custom_endpoints', $endpoints );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'custom-endpoints-manager',
					'message' => 'demos_installed',
					'count'   => $installed,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
