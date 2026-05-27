<?php
/**
 * Singleton that contains the Microplugins functionality.
 *
 * Adapted from the original Microplugins plugin by Andy D. Navarro Taño.
 *
 * @package Custom_Endpoints_Manager
 */

/**
 * Manages the Microplugins custom post type, code editor, and cache.
 *
 * @since 1.0.0
 */
class Microplugins {

	const VERSION   = '1.1.0';
	const POST_TYPE = 'microplugin';

	/**
	 * Singleton instance.
	 *
	 * @var Microplugins|null
	 */
	protected static $instance;

	/**
	 * Tracks rendered admin notice keys to prevent duplicates.
	 *
	 * @var array
	 */
	protected static $admin_messages;

	/**
	 * Recursion guard for save_post_action.
	 *
	 * @var bool
	 */
	private static $save_in_progress = false;

	/**
	 * Constructor — registers hooks after verifying cache directory permissions.
	 *
	 * @since 1.0.0
	 */
	protected function __construct() {
		if ( ! file_exists( MICROPLUGINS_CACHE_DIR ) ) {
			wp_mkdir_p( MICROPLUGINS_CACHE_DIR );
		}

		if ( true === self::check_file_permissions() ) {
			register_shutdown_function( array( __CLASS__, 'shutdown_function' ) );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- required to catch fatal PHP errors in microplugin cache files.
			set_error_handler( array( __CLASS__, 'error_handler' ) );

			add_action( 'init', array( __CLASS__, 'run_microplugins' ) );
			add_action( 'init', array( __CLASS__, 'register_post_type' ) );
			add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );

			if ( true === is_admin() ) {
				add_action( 'add_meta_boxes', array( __CLASS__, 'add_editor_metabox' ) );
				add_action( 'admin_menu', array( __CLASS__, 'add_recompile_all_submenu' ) );
				add_action( 'save_post', array( __CLASS__, 'save_post_action' ) );
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
			}

			self::$admin_messages = array();
		} else {
			$message = sprintf(
				/* translators: 1: path to the cache directory, 2: octal permission bits */
				__( 'Microplugins needs read and write permissions on the "%1$s" directory. Current permissions: %2$s.', 'custom-endpoints-manager' ),
				MICROPLUGINS_CACHE_DIR,
				substr( sprintf( '%o', fileperms( MICROPLUGINS_CACHE_DIR ) ), -4 )
			);
			self::add_admin_notice( $message, 'warning' );
		}
	}

	/**
	 * Return (or create) the singleton instance.
	 *
	 * @since 1.0.0
	 * @return Microplugins
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Include all cached microplugin PHP files on the init hook.
	 *
	 * @since 1.0.0
	 */
	public static function run_microplugins() {
		$error_filename = MICROPLUGINS_CACHE_DIR . '/error';

		if ( file_exists( $error_filename ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local error file.
			$error = json_decode( file_get_contents( $error_filename ), true );
			if ( true === self::is_microplugin_error( $error ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing own error file.
				unlink( $error_filename );
				if ( isset( $error['file'] ) && file_exists( $error['file'] ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing own cache file after fatal.
					unlink( $error['file'] );
				}
				$func = function () use ( $error ) {
					Microplugins::process_mircoplugin_error( $error );
				};
				add_action( 'init', $func );
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- suppressing errors during require of cached files; restored immediately after.
		$last_error_reporting = error_reporting( 0 );

		$files = glob( MICROPLUGINS_CACHE_DIR . '/*.php' );
		if ( $files ) {
			foreach ( $files as $filename ) {
				include_once $filename;
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- restoring original error_reporting level.
		error_reporting( $last_error_reporting );
	}

	/**
	 * Write a published microplugin's content to its cache file.
	 *
	 * @since 1.0.0
	 * @param int $post_id Microplugin post ID.
	 * @return int|bool Bytes written, or false on failure.
	 */
	public static function dump( $post_id ) {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing to plugin-owned cache directory.
			return file_put_contents( MICROPLUGINS_CACHE_DIR . "/{$post->ID}.php", $post->post_content );
		}
		return false;
	}

	/**
	 * Delete a microplugin's cache file.
	 *
	 * @since 1.0.0
	 * @param int $post_id Microplugin post ID.
	 * @return bool True if deleted, false if not found.
	 */
	public static function clear( $post_id ) {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && self::POST_TYPE === $post->post_type ) {
			$filename = MICROPLUGINS_CACHE_DIR . "/{$post_id}.php";
			if ( file_exists( $filename ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing plugin-owned cache file.
				return unlink( $filename );
			}
		}
		return false;
	}

	/**
	 * Return the absolute path to a microplugin cache file, or false if not found.
	 *
	 * @since 1.0.0
	 * @param int $post_id Microplugin post ID.
	 * @return string|false
	 */
	public static function get_microplugin_cache_file( $post_id ) {
		$filename = MICROPLUGINS_CACHE_DIR . "/{$post_id}.php";
		return file_exists( $filename ) ? $filename : false;
	}

	/**
	 * Register the microplugin custom post type.
	 *
	 * @since 1.0.0
	 */
	public static function register_post_type() {
		$labels = array(
			'name'               => __( 'Microplugins', 'custom-endpoints-manager' ),
			'singular_name'      => __( 'Microplugin', 'custom-endpoints-manager' ),
			'add_new'            => __( 'Add New', 'custom-endpoints-manager' ),
			'add_new_item'       => __( 'Add New Microplugin', 'custom-endpoints-manager' ),
			'edit_item'          => __( 'Edit Microplugin', 'custom-endpoints-manager' ),
			'new_item'           => __( 'New Microplugin', 'custom-endpoints-manager' ),
			'all_items'          => __( 'All Microplugins', 'custom-endpoints-manager' ),
			'view_item'          => __( 'View Microplugin', 'custom-endpoints-manager' ),
			'search_items'       => __( 'Search Microplugins', 'custom-endpoints-manager' ),
			'not_found'          => __( 'No microplugins found', 'custom-endpoints-manager' ),
			'not_found_in_trash' => __( 'No microplugins found in Trash', 'custom-endpoints-manager' ),
			'parent_item_colon'  => '',
			'menu_name'          => __( 'Microplugins', 'custom-endpoints-manager' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'query_var'       => true,
			'rewrite'         => array( 'slug' => self::POST_TYPE ),
			'capability_type' => array( 'microplugin', 'microplugins' ),
			'map_meta_cap'    => true,
			'has_archive'     => false,
			'hierarchical'    => false,
			'menu_position'   => null,
			'supports'        => array( 'title', 'revisions' ),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register the "Recompile All" admin submenu page.
	 *
	 * @since 1.0.0
	 */
	public static function add_recompile_all_submenu() {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__( 'Recompile All Microplugins', 'custom-endpoints-manager' ),
			__( 'Recompile All', 'custom-endpoints-manager' ),
			'manage_options',
			'recompile-all',
			array( __CLASS__, 'recompile_all_page' )
		);
	}

	/**
	 * Render the "Recompile All" admin page.
	 *
	 * @since 1.0.0
	 */
	public static function recompile_all_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized user' );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Recompile All Microplugins', 'custom-endpoints-manager' ); ?></h1>
			<p><?php esc_html_e( 'Deletes all cached microplugin files and regenerates them from published microplugin posts.', 'custom-endpoints-manager' ); ?></p>

			<h2><?php esc_html_e( 'Deleting old files', 'custom-endpoints-manager' ); ?></h2>
			<?php
			$files = glob( MICROPLUGINS_CACHE_DIR . '/*' );
			if ( empty( $files ) ) {
				echo '<p>' . esc_html__( 'No files to delete.', 'custom-endpoints-manager' ) . '</p>';
			} else {
				foreach ( $files as $filename ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing plugin-owned cache file.
					$result = unlink( $filename )
						? esc_html__( 'OK', 'custom-endpoints-manager' )
						: esc_html__( 'ERROR', 'custom-endpoints-manager' );
					printf(
						'<p>%s %s</p>',
						esc_html(
							sprintf(
								/* translators: %s: basename of the file being deleted */
								__( 'Deleting %s...', 'custom-endpoints-manager' ),
								basename( $filename )
							)
						),
						esc_html( $result )
					);
				}
			}
			?>

			<h2><?php esc_html_e( 'Generating new microplugins', 'custom-endpoints-manager' ); ?></h2>
			<?php
			$posts = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'posts_per_page' => -1,
				)
			);
			if ( empty( $posts ) ) {
				echo '<p>' . esc_html__( 'No microplugins to generate.', 'custom-endpoints-manager' ) . '</p>';
			} else {
				foreach ( $posts as $post ) {
					$result = self::dump( $post->ID )
						? esc_html__( 'OK', 'custom-endpoints-manager' )
						: esc_html__( 'ERROR', 'custom-endpoints-manager' );
					printf(
						'<p>%s %s</p>',
						esc_html(
							sprintf(
								/* translators: %d: microplugin post ID */
								__( 'Generating microplugin %d...', 'custom-endpoints-manager' ),
								intval( $post->ID )
							)
						),
						esc_html( $result )
					);
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * Register the source-code editor meta box on the microplugin edit screen.
	 *
	 * @since 1.0.0
	 */
	public static function add_editor_metabox() {
		add_meta_box(
			'microplugin-editor',
			__( 'Source Code Editor', 'custom-endpoints-manager' ),
			array( __CLASS__, 'render_editor_metabox' ),
			self::POST_TYPE,
			'normal',
			'high',
			array()
		);

		add_meta_box(
			'microplugin-output-vars',
			__( 'Output Variables', 'custom-endpoints-manager' ),
			array( __CLASS__, 'render_output_vars_metabox' ),
			self::POST_TYPE,
			'side',
			'default',
			array()
		);

		add_meta_box(
			'microplugin-used-by',
			__( 'Used By Endpoints', 'custom-endpoints-manager' ),
			array( __CLASS__, 'render_used_by_metabox' ),
			self::POST_TYPE,
			'side',
			'default',
			array()
		);

		add_meta_box(
			'microplugin-cem-ref',
			__( 'CEM Reference', 'custom-endpoints-manager' ),
			array( __CLASS__, 'render_cem_reference_metabox' ),
			self::POST_TYPE,
			'side',
			'low',
			array()
		);
	}

	/**
	 * Render the Output Variables meta box.
	 *
	 * @since 1.0.0
	 */
	public static function render_output_vars_metabox() {
		global $post;
		wp_nonce_field( 'cem_output_vars_nonce', 'cem_output_vars_nonce' );

		$output_vars = get_post_meta( $post->ID, 'microplugin_output_vars', true );
		if ( ! is_array( $output_vars ) ) {
			$output_vars = array();
		}
		?>
		<p style="color:#646970;font-size:11px;margin-top:0">
			<?php esc_html_e( 'Document the keys your callback returns. Helps other developers and the Response Schema mapper.', 'custom-endpoints-manager' ); ?>
		</p>
		<table id="cem-output-vars-table" class="widefat fixed" style="font-size:12px">
			<thead>
				<tr>
					<th style="width:40%"><?php esc_html_e( 'Key', 'custom-endpoints-manager' ); ?></th>
					<th style="width:30%"><?php esc_html_e( 'Type', 'custom-endpoints-manager' ); ?></th>
					<th style="width:30%"><?php esc_html_e( 'Notes', 'custom-endpoints-manager' ); ?></th>
				</tr>
			</thead>
			<tbody id="cem-ov-tbody">
				<?php if ( ! empty( $output_vars ) ) : ?>
					<?php foreach ( $output_vars as $ov ) : ?>
						<tr class="cem-ov-row">
							<td>
								<input type="text" name="cem_output_vars[][key]"
									value="<?php echo esc_attr( $ov['key'] ); ?>"
									placeholder="key_name"
									style="width:100%;box-sizing:border-box" />
							</td>
							<td>
								<select name="cem_output_vars[][type]" style="width:100%;box-sizing:border-box">
									<?php foreach ( array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ) as $ov_type ) : ?>
										<option value="<?php echo esc_attr( $ov_type ); ?>"
											<?php selected( $ov['type'], $ov_type ); ?>>
											<?php echo esc_html( $ov_type ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<input type="text" name="cem_output_vars[][notes]"
									value="<?php echo esc_attr( isset( $ov['notes'] ) ? $ov['notes'] : '' ); ?>"
									placeholder="<?php esc_attr_e( 'optional description', 'custom-endpoints-manager' ); ?>"
									style="width:100%;box-sizing:border-box" />
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<p style="margin-top:8px">
			<button type="button" id="cem-ov-add-row" class="button button-small">
				<?php esc_html_e( '+ Add Variable', 'custom-endpoints-manager' ); ?>
			</button>
		</p>
		<script>
		( function () {
			var types = [ 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ];
			var tbody = document.getElementById( 'cem-ov-tbody' );
			var addBtn = document.getElementById( 'cem-ov-add-row' );
			if ( ! addBtn || ! tbody ) { return; }
			addBtn.addEventListener( 'click', function () {
				var tr = document.createElement( 'tr' );
				tr.className = 'cem-ov-row';
				var typeOpts = '';
				var tIdx;
				for ( tIdx = 0; tIdx < types.length; tIdx++ ) {
					typeOpts += '<option value="' + types[ tIdx ] + '">' + types[ tIdx ] + '</option>';
				}
				tr.innerHTML = '<td><input type="text" name="cem_output_vars[][key]" placeholder="key_name" style="width:100%;box-sizing:border-box" /></td>'
					+ '<td><select name="cem_output_vars[][type]" style="width:100%;box-sizing:border-box">' + typeOpts + '</select></td>'
					+ '<td><input type="text" name="cem_output_vars[][notes]" placeholder="optional description" style="width:100%;box-sizing:border-box" /></td>';
				tbody.appendChild( tr );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Render the Used By Endpoints meta box.
	 *
	 * @since 1.0.0
	 */
	public static function render_used_by_metabox() {
		global $post;
		$post_id          = $post->ID;
		$custom_endpoints = get_option( 'cem_custom_endpoints', array() );
		$used_by          = array();

		foreach ( $custom_endpoints as $ep ) {
			if ( isset( $ep['microplugin_id'] ) && absint( $ep['microplugin_id'] ) === $post_id ) {
				$used_by[] = $ep;
			}
		}

		if ( empty( $used_by ) ) {
			echo '<p style="color:#646970;font-size:12px">' . esc_html__( 'Not assigned to any endpoint yet.', 'custom-endpoints-manager' ) . '</p>';
			echo '<p><a href="' . esc_url( admin_url( 'options-general.php?page=custom-endpoints-manager' ) ) . '" class="button button-small">' . esc_html__( 'Assign to an Endpoint →', 'custom-endpoints-manager' ) . '</a></p>';
			return;
		}

		$method_colors = array(
			'GET'    => '#2271b1',
			'POST'   => '#00a32a',
			'PUT'    => '#b26200',
			'PATCH'  => '#6d3fc0',
			'DELETE' => '#d63638',
		);

		echo '<ul style="margin:0;padding:0;list-style:none">';
		foreach ( $used_by as $ep ) {
			$slug       = isset( $ep['slug'] ) ? $ep['slug'] : '';
			$ep_methods = isset( $ep['methods'] ) ? explode( ',', $ep['methods'] ) : array();
			echo '<li style="padding:4px 0;border-bottom:1px solid #f0f0f1;font-size:12px">';
			foreach ( $ep_methods as $ep_method ) {
				$ep_method = strtoupper( trim( $ep_method ) );
				$bg_color  = isset( $method_colors[ $ep_method ] ) ? $method_colors[ $ep_method ] : '#646970';
				$badge_css = 'display:inline-block;padding:1px 5px;border-radius:3px;background:' . $bg_color . ';color:#fff;font-size:10px;font-weight:700;margin-right:3px';
				echo '<span style="' . esc_attr( $badge_css ) . '">' . esc_html( $ep_method ) . '</span>';
			}
			echo ' <code>/cem/v1/' . esc_html( $slug ) . '</code>';
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Render the CEM Reference meta box.
	 *
	 * @since 1.0.0
	 */
	public static function render_cem_reference_metabox() {
		$cem_methods = array(
			array(
				'sig'  => "CEM::param( 'key', \$default )",
				'desc' => 'URL / query-string parameter.',
			),
			array(
				'sig'  => 'CEM::params()',
				'desc' => 'All query parameters as array.',
			),
			array(
				'sig'  => 'CEM::body()',
				'desc' => 'Full POST body as array.',
			),
			array(
				'sig'  => "CEM::header( 'Header-Name' )",
				'desc' => 'Raw request header value.',
			),
			array(
				'sig'  => 'CEM::method()',
				'desc' => 'HTTP method (GET, POST, …).',
			),
			array(
				'sig'  => 'CEM::route()',
				'desc' => 'Full REST route string.',
			),
			array(
				'sig'  => 'CEM::user()',
				'desc' => 'Current WP_User object.',
			),
			array(
				'sig'  => 'CEM::user_id()',
				'desc' => 'Current user ID (0 = anonymous).',
			),
			array(
				'sig'  => "CEM::option( 'key', \$default )",
				'desc' => 'get_option() shorthand.',
			),
			array(
				'sig'  => "CEM::now( 'mysql' )",
				'desc' => 'Current timestamp (mysql/U/c).',
			),
			array(
				'sig'  => 'CEM::site_url( $path )',
				'desc' => 'Site URL with optional path.',
			),
			array(
				'sig'  => 'CEM::response( $data, 200 )',
				'desc' => 'Return a WP_REST_Response.',
			),
			array(
				'sig'  => "CEM::error( 'code', 'msg', 400 )",
				'desc' => 'Return a WP_Error response.',
			),
			array(
				'sig'  => 'CEM::http_get( $url, $args )',
				'desc' => 'wp_remote_get() shorthand.',
			),
			array(
				'sig'  => 'CEM::http_post( $url, $body )',
				'desc' => 'wp_remote_post() shorthand.',
			),
			array(
				'sig'  => "CEM::store( 'key', \$val, 3600 )",
				'desc' => 'set_transient() shorthand.',
			),
			array(
				'sig'  => "CEM::retrieve( 'key' )",
				'desc' => 'get_transient() shorthand.',
			),
			array(
				'sig'  => "CEM::queue( 'slug', \$payload )",
				'desc' => 'Queue an async endpoint call.',
			),
		);
		?>
		<style>
			#microplugin-cem-ref .cem-ref-list { margin:0;padding:0;list-style:none }
			#microplugin-cem-ref .cem-ref-list li { padding:4px 0;border-bottom:1px solid #f0f0f1;font-size:11px }
			#microplugin-cem-ref .cem-ref-sig { font-family:Consolas,'Courier New',monospace;color:#2271b1;font-size:11px;display:block;margin-bottom:1px }
			#microplugin-cem-ref .cem-ref-desc { color:#646970;display:block }
		</style>
		<ul class="cem-ref-list">
			<?php foreach ( $cem_methods as $ref ) : ?>
				<li>
					<code class="cem-ref-sig"><?php echo esc_html( $ref['sig'] ); ?></code>
					<span class="cem-ref-desc"><?php echo esc_html( $ref['desc'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Enqueue Ace editor assets on the microplugin edit screen.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook ) {
		global $post;
		if ( ( 'post.php' !== $hook && 'post-new.php' !== $hook ) || ! isset( $post->post_type ) || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		wp_enqueue_style( 'microplugin-editor', MICROPLUGINS_URI . 'assets/editor.css', array(), self::VERSION );
		wp_enqueue_script( 'ace', MICROPLUGINS_URI . 'assets/vendor/ace/ace.js', array(), self::VERSION, true );
		wp_enqueue_script( 'ace-mode-php', MICROPLUGINS_URI . 'assets/vendor/ace/mode-php.js', array( 'ace' ), self::VERSION, true );

		foreach ( self::get_ace_themes() as $style_themes ) {
			foreach ( $style_themes as $theme_def ) {
				wp_enqueue_script(
					'ace-theme-' . $theme_def['id'],
					MICROPLUGINS_URI . 'assets/vendor/ace/theme-' . $theme_def['id'] . '.js',
					array( 'ace' ),
					self::VERSION,
					true
				);
			}
		}

		wp_enqueue_script( 'microplugin-editor', MICROPLUGINS_URI . 'assets/microplugin-editor.js', array( 'ace' ), self::VERSION, true );
	}

	/**
	 * Render the Ace editor meta box HTML.
	 *
	 * @since 1.0.0
	 */
	public static function render_editor_metabox() {
		global $post;

		$comment      = __( 'Write here the microplugin description.', 'custom-endpoints-manager' );
		$post_content = "<?php\n/**\n * {$comment}\n */\n";

		if ( $post instanceof WP_Post && ! empty( $post->post_content ) ) {
			$post_content = $post->post_content;
		}

		$microplugin_php_errors = get_post_meta( $post->ID, 'microplugin_php_errors', true );
		if ( ! is_array( $microplugin_php_errors ) ) {
			$microplugin_php_errors = array();
		}

		$themes = self::get_ace_themes();
		?>
		<div class="editor-toolbar" style="padding:5px 10px;box-sizing:border-box">
			<?php esc_html_e( 'Theme', 'custom-endpoints-manager' ); ?>:
			<select id="micropluginEditorThemeSelect">
				<optgroup label="Bright">
					<?php foreach ( $themes['bright'] as $value ) : ?>
						<option value="ace/theme/<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['name'] ); ?></option>
					<?php endforeach; ?>
				</optgroup>
				<optgroup label="Dark">
					<?php foreach ( $themes['dark'] as $value ) : ?>
						<option value="ace/theme/<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['name'] ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			</select>
			<?php esc_html_e( 'Font Size', 'custom-endpoints-manager' ); ?>:
			<select id="micropluginEditorFontSize" size="1">
				<option value="10">10px</option>
				<option value="12">12px</option>
				<option value="14">14px</option>
				<option value="16">16px</option>
				<option value="18" selected="selected">18px</option>
				<option value="20">20px</option>
				<option value="22">22px</option>
				<option value="24">24px</option>
				<option value="26">26px</option>
				<option value="28">28px</option>
				<option value="30">30px</option>
			</select>
		</div>
		<div class="editing-area">
			<div id="micropluginEditor"></div>
			<textarea name="content" id="postContent" cols="30" rows="10" style="display:none"><?php echo esc_textarea( $post_content ); ?></textarea>
			<?php foreach ( $microplugin_php_errors as $php_error ) : ?>
				<div class="php-error"
					data-type="<?php echo esc_attr( $php_error['type'] ); ?>"
					data-message="<?php echo esc_attr( $php_error['message'] ); ?>"
					data-file="<?php echo esc_attr( $php_error['file'] ); ?>"
					data-line="<?php echo esc_attr( $php_error['line'] ); ?>">
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Handle post save — validate code and write/clear cache file.
	 *
	 * @since 1.0.0
	 * @param int $post_id Saved post ID.
	 */
	public static function save_post_action( $post_id ) {
		if ( self::$save_in_progress ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by WP core before save_post fires.
		if ( ! isset( $_POST['post_type'] ) || self::POST_TYPE !== $_POST['post_type'] ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		// Save output vars meta (before code validation, so it persists even on failure).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WP core before save_post fires.
		if ( isset( $_POST['cem_output_vars_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cem_output_vars_nonce'] ) ), 'cem_output_vars_nonce' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_vars   = isset( $_POST['cem_output_vars'] ) && is_array( $_POST['cem_output_vars'] ) ? wp_unslash( $_POST['cem_output_vars'] ) : array();
			$clean_vars = array();
			foreach ( $raw_vars as $ov ) {
				$ov_key = isset( $ov['key'] ) ? sanitize_key( $ov['key'] ) : '';
				if ( ! empty( $ov_key ) ) {
					$clean_vars[] = array(
						'key'   => $ov_key,
						'type'  => isset( $ov['type'] ) ? sanitize_key( $ov['type'] ) : 'string',
						'notes' => isset( $ov['notes'] ) ? sanitize_text_field( $ov['notes'] ) : '',
					);
				}
			}
			update_post_meta( $post_id, 'microplugin_output_vars', $clean_vars );
		}

		if ( 'publish' === $post->post_status ) {
			$function_name = 'cem_microplugin_callback_' . $post_id;
			$validator     = new CEM_Code_Validator();
			$validation    = $validator->validate( $post->post_content, $function_name );

			if ( ! $validation['valid'] ) {
				self::$save_in_progress = true;
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'pending',
					)
				);
				self::$save_in_progress = false;

				$error = array(
					'type'    => E_USER_ERROR,
					'message' => $validation['error'],
					'file'    => MICROPLUGINS_CACHE_DIR . "/{$post_id}.php",
					'line'    => 0,
				);
				update_post_meta( $post_id, 'microplugin_php_errors', array( $error ) );
				self::add_admin_notice(
					sprintf(
						/* translators: 1: microplugin post ID, 2: validation error message */
						__( 'Microplugin #%1$d validation failed and was not published: %2$s', 'custom-endpoints-manager' ),
						$post_id,
						esc_html( $validation['error'] )
					),
					'error'
				);
				return;
			}

			self::dump( $post_id );
			delete_post_meta( $post_id, 'microplugin_php_errors' );
		} else {
			self::clear( $post_id );
		}
	}

	/**
	 * Shutdown handler — persist any fatal error from a microplugin cache file.
	 *
	 * @since 1.0.0
	 */
	public static function shutdown_function() {
		$last_error = error_get_last();
		if ( true === self::is_microplugin_error( $last_error ) ) {
			$error_filename = MICROPLUGINS_CACHE_DIR . '/error';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing to plugin-owned directory.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_json_encode -- writing raw data to file; wp_json_encode is not available before WP fully loads.
			file_put_contents( $error_filename, wp_json_encode( $last_error ) );

			if ( ! headers_sent() ) {
				$referer = wp_get_referer();
				wp_safe_redirect( $referer ? add_query_arg( 'cem_error', 'microplugin_fatal', $referer ) : admin_url() );
				exit;
			}
		}
	}

	/**
	 * Check whether an error array refers to a file in the microplugin cache.
	 *
	 * @since 1.0.0
	 * @param mixed $error Error array from error_get_last() or similar.
	 * @return bool
	 */
	protected static function is_microplugin_error( $error ) {
		if ( ! is_array( $error ) ) {
			return false;
		}
		if ( ! isset( $error['type'], $error['message'], $error['file'], $error['line'] ) ) {
			return false;
		}
		return false !== strpos( $error['file'], basename( MICROPLUGINS_CACHE_DIR ) );
	}

	/**
	 * Queue an admin notice, de-duplicating by message+type key.
	 *
	 * @since 1.0.0
	 * @param string $message       Notice HTML/text.
	 * @param string $type          Notice type (error|warning|success|info).
	 * @param bool   $is_dismissible Whether to add the is-dismissible class.
	 */
	public static function add_admin_notice( $message, $type, $is_dismissible = true ) {
		if ( ! is_array( self::$admin_messages ) ) {
			self::$admin_messages = array();
		}

		$key = $message . $type;
		if ( in_array( $key, self::$admin_messages, true ) ) {
			return;
		}

		$class = "notice notice-{$type}" . ( $is_dismissible ? ' is-dismissible' : '' );
		$func  = function () use ( $message, $class ) {
			?>
			<div class="<?php echo esc_attr( $class ); ?>">
				<p><?php echo wp_kses_post( $message ); ?></p>
			</div>
			<?php
		};
		add_action( 'admin_notices', $func );
		self::$admin_messages[] = $key;
	}

	/**
	 * Process a microplugin error — disable the post if fatal, store meta, show notice.
	 *
	 * @since 1.0.0
	 * @param array $error Error array with type/message/file/line keys.
	 */
	public static function process_mircoplugin_error( $error ) {
		if ( ! self::is_microplugin_error( $error ) ) {
			return;
		}

		$fatal_error_types = array( 1, 4, 16, 32, 64, 128 );
		$is_fatal          = in_array( $error['type'], $fatal_error_types, true );
		$post_id           = intval( basename( $error['file'], '.php' ) );
		$post              = get_post( $post_id );

		if ( $is_fatal ) {
			self::clear( $post_id );
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'pending',
				)
			);
		}

		$post_errors = get_post_meta( $post_id, 'microplugin_php_errors', true );
		if ( ! is_array( $post_errors ) ) {
			$post_errors = array();
		}
		$post_errors[] = $error;
		update_post_meta( $post_id, 'microplugin_php_errors', $post_errors );

		$edit_link = admin_url( "post.php?post={$post_id}&action=edit" );

		if ( $is_fatal ) {
			$message = sprintf(
				/* translators: 1: microplugin post ID, 2: microplugin title, 3: URL to edit the post */
				__( 'The microplugin #%1$d "%2$s" has been disabled due to fatal errors. <a href="%3$s">Edit microplugin</a>', 'custom-endpoints-manager' ),
				$post->ID,
				$post->post_title,
				$edit_link
			);
			self::add_admin_notice( $message, 'error' );
		} else {
			$message = sprintf(
				/* translators: 1: microplugin post ID, 2: microplugin title, 3: URL to edit the post */
				__( 'The microplugin #%1$d "%2$s" has warnings. <a href="%3$s">Edit microplugin</a>', 'custom-endpoints-manager' ),
				$post->ID,
				$post->post_title,
				$edit_link
			);
			self::add_admin_notice( $message, 'warning' );
		}
	}

	/**
	 * Custom error handler — routes microplugin errors to process_mircoplugin_error().
	 *
	 * @since 1.0.0
	 * @param int    $type    Error level constant.
	 * @param string $message Error message.
	 * @param string $file    File where the error occurred.
	 * @param int    $line    Line number.
	 * @return bool False to propagate to the default handler.
	 */
	public static function error_handler( $type, $message, $file, $line ) {
		$error = array(
			'type'    => $type,
			'message' => $message,
			'file'    => $file,
			'line'    => $line,
		);

		if ( true === self::is_microplugin_error( $error ) ) {
			$func = function () use ( $error ) {
				Microplugins::process_mircoplugin_error( $error );
			};
			add_action( 'init', $func );
		} else {
			return false;
		}
	}

	/**
	 * Verify the cache directory is readable and writable.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function check_file_permissions() {
		if ( ! file_exists( MICROPLUGINS_CACHE_DIR ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- checking local plugin-owned directory.
		return is_writable( MICROPLUGINS_CACHE_DIR ) && is_readable( MICROPLUGINS_CACHE_DIR );
	}

	/**
	 * Plugin activation — install DB table and register microplugin capabilities.
	 *
	 * @since 1.0.0
	 */
	public static function activation_hook() {
		CEM_Execution_Logger::install();

		global $wp_roles;

		$capabilities = array(
			'delete_microplugins',
			'delete_others_microplugins',
			'delete_private_microplugins',
			'delete_published_microplugins',
			'edit_microplugins',
			'edit_others_microplugins',
			'edit_private_microplugins',
			'edit_published_microplugins',
			'publish_microplugins',
			'read_private_microplugins',
		);

		foreach ( $wp_roles->roles as $role_id => $role_args ) {
			if ( ! empty( $role_args['capabilities']['manage_options'] ) ) {
				$role = get_role( $role_id );
				foreach ( $capabilities as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}

		if ( ! file_exists( MICROPLUGINS_CACHE_DIR ) ) {
			wp_mkdir_p( MICROPLUGINS_CACHE_DIR );
		}
	}

	/**
	 * Return the Ace editor theme definitions grouped by bright/dark.
	 *
	 * @since 1.0.0
	 * @return array{bright: array, dark: array}
	 */
	protected static function get_ace_themes() {
		$bright = array(
			'chrome'          => array(
				'id'   => 'chrome',
				'name' => 'Chrome',
			),
			'clouds'          => array(
				'id'   => 'clouds',
				'name' => 'Clouds',
			),
			'crimson_editor'  => array(
				'id'   => 'crimson_editor',
				'name' => 'Crimson Editor',
			),
			'dawn'            => array(
				'id'   => 'dawn',
				'name' => 'Dawn',
			),
			'dreamweaver'     => array(
				'id'   => 'dreamweaver',
				'name' => 'Dreamweaver',
			),
			'eclipse'         => array(
				'id'   => 'eclipse',
				'name' => 'Eclipse',
			),
			'github'          => array(
				'id'   => 'github',
				'name' => 'GitHub',
			),
			'iplastic'        => array(
				'id'   => 'iplastic',
				'name' => 'IPlastic',
			),
			'solarized_light' => array(
				'id'   => 'solarized_light',
				'name' => 'Solarized Light',
			),
			'textmate'        => array(
				'id'   => 'textmate',
				'name' => 'TextMate',
			),
			'tomorrow'        => array(
				'id'   => 'tomorrow',
				'name' => 'Tomorrow',
			),
			'xcode'           => array(
				'id'   => 'xcode',
				'name' => 'XCode',
			),
			'kuroir'          => array(
				'id'   => 'kuroir',
				'name' => 'Kuroir',
			),
			'katzenmilch'     => array(
				'id'   => 'katzenmilch',
				'name' => 'KatzenMilch',
			),
			'sqlserver'       => array(
				'id'   => 'sqlserver',
				'name' => 'SQL Server',
			),
		);

		$dark = array(
			'ambiance'                => array(
				'id'   => 'ambiance',
				'name' => 'Ambiance',
			),
			'chaos'                   => array(
				'id'   => 'chaos',
				'name' => 'Chaos',
			),
			'clouds_midnight'         => array(
				'id'   => 'clouds_midnight',
				'name' => 'Clouds Midnight',
			),
			'cobalt'                  => array(
				'id'   => 'cobalt',
				'name' => 'Cobalt',
			),
			'idle_fingers'            => array(
				'id'   => 'idle_fingers',
				'name' => 'idle Fingers',
			),
			'kr_theme'                => array(
				'id'   => 'kr_theme',
				'name' => 'krTheme',
			),
			'merbivore'               => array(
				'id'   => 'merbivore',
				'name' => 'Merbivore',
			),
			'merbivore_soft'          => array(
				'id'   => 'merbivore_soft',
				'name' => 'Merbivore Soft',
			),
			'mono_industrial'         => array(
				'id'   => 'mono_industrial',
				'name' => 'Mono Industrial',
			),
			'monokai'                 => array(
				'id'   => 'monokai',
				'name' => 'Monokai',
			),
			'pastel_on_dark'          => array(
				'id'   => 'pastel_on_dark',
				'name' => 'Pastel on dark',
			),
			'solarized_dark'          => array(
				'id'   => 'solarized_dark',
				'name' => 'Solarized Dark',
			),
			'terminal'                => array(
				'id'   => 'terminal',
				'name' => 'Terminal',
			),
			'tomorrow_night'          => array(
				'id'   => 'tomorrow_night',
				'name' => 'Tomorrow Night',
			),
			'tomorrow_night_blue'     => array(
				'id'   => 'tomorrow_night_blue',
				'name' => 'Tomorrow Night Blue',
			),
			'tomorrow_night_bright'   => array(
				'id'   => 'tomorrow_night_bright',
				'name' => 'Tomorrow Night Bright',
			),
			'tomorrow_night_eighties' => array(
				'id'   => 'tomorrow_night_eighties',
				'name' => 'Tomorrow Night 80s',
			),
			'twilight'                => array(
				'id'   => 'twilight',
				'name' => 'Twilight',
			),
			'vibrant_ink'             => array(
				'id'   => 'vibrant_ink',
				'name' => 'Vibrant Ink',
			),
		);

		return array(
			'bright' => $bright,
			'dark'   => $dark,
		);
	}

	/**
	 * Register microplugin category and tag taxonomies.
	 *
	 * @since 1.0.0
	 */
	public static function register_taxonomies() {
		register_taxonomy(
			'microplugin-category',
			array( self::POST_TYPE ),
			array(
				'hierarchical'      => true,
				'labels'            => array(
					'name'          => _x( 'Microplugin Categories', 'Categories', 'custom-endpoints-manager' ),
					'singular_name' => _x( 'Microplugin Category', 'Category', 'custom-endpoints-manager' ),
					'menu_name'     => __( 'Categories', 'custom-endpoints-manager' ),
				),
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => array( 'slug' => 'microplugin-category' ),
			)
		);

		register_taxonomy(
			'microplugin-tag',
			self::POST_TYPE,
			array(
				'hierarchical'          => false,
				'labels'                => array(
					'name'          => _x( 'Microplugin Tags', 'Tags', 'custom-endpoints-manager' ),
					'singular_name' => _x( 'Microplugin Tag', 'Tag', 'custom-endpoints-manager' ),
					'menu_name'     => __( 'Tags', 'custom-endpoints-manager' ),
				),
				'show_ui'               => true,
				'show_admin_column'     => true,
				'update_count_callback' => '_update_post_term_count',
				'query_var'             => true,
				'rewrite'               => array( 'slug' => 'microplugin-tag' ),
			)
		);
	}
}
