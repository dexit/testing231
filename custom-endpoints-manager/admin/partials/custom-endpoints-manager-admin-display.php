<?php
/**
 * Admin area view for the plugin — Endpoints tab content only.
 *
 * The wrapping <div class="wrap">, <h1>, and nav tabs are rendered by
 * Custom_Endpoints_Manager_Admin::display_options_page().
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/admin/partials
 */

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$custom_endpoints = get_option( 'cem_custom_endpoints', array() );

$microplugins_posts = get_posts(
	array(
		'post_type'      => Microplugins::POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	)
);

// Build an index of published microplugins for quick lookup.
$mp_index = array();
foreach ( $microplugins_posts as $mp_post ) {
	$mp_index[ $mp_post->ID ] = $mp_post;
}

$all_methods = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );

$capability_presets = array(
	'read'           => 'read — logged-in user',
	'edit_posts'     => 'edit_posts — editor+',
	'publish_posts'  => 'publish_posts — author+',
	'manage_options' => 'manage_options — admin only',
);
?>

<?php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
if ( isset( $_GET['message'] ) ) :
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$display_message = sanitize_key( wp_unslash( $_GET['message'] ) );
	?>
	<div id="message" class="updated notice is-dismissible">
		<p>
			<?php
			if ( 'saved' === $display_message ) {
				esc_html_e( 'Settings saved.', 'custom-endpoints-manager' );
			}
			if ( 'demos_installed' === $display_message ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$msg_count = isset( $_GET['count'] ) ? absint( wp_unslash( $_GET['count'] ) ) : 0;
				/* translators: %d: number of demos installed */
				echo esc_html( sprintf( __( '%d demo microplugins installed successfully.', 'custom-endpoints-manager' ), $msg_count ) );
			}
			?>
		</p>
	</div>
<?php endif; ?>

<h2><?php esc_html_e( 'Custom REST API Endpoints', 'custom-endpoints-manager' ); ?></h2>
<p>
	<?php esc_html_e( 'Each card is a live REST route. Pick a published Microplugin as the callback, set your HTTP method(s) and capability, then save.', 'custom-endpoints-manager' ); ?>
</p>

<form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=custom-endpoints-manager' ) ); ?>">
	<?php wp_nonce_field( 'cem_nonce', 'cem_nonce' ); ?>

	<div class="cem-endpoints-stack" id="cem-endpoints-stack">
		<?php
		$rows = ! empty( $custom_endpoints ) ? $custom_endpoints : array(
			array(
				'slug'           => '',
				'methods'        => 'GET',
				'capability'     => 'read',
				'microplugin_id' => 0,
				'args'           => '',
				'async'          => false,
				'max_attempts'   => 3,
			),
		);

		foreach ( $rows as $row_index => $endpoint ) :
			$i              = absint( $row_index );
			$sel_mp_id      = isset( $endpoint['microplugin_id'] ) ? absint( $endpoint['microplugin_id'] ) : 0;
			$methods_val    = isset( $endpoint['methods'] ) ? strtoupper( trim( $endpoint['methods'] ) ) : 'GET';
			$active_methods = array_filter( array_map( 'trim', explode( ',', $methods_val ) ) );
			$slug_val       = isset( $endpoint['slug'] ) ? $endpoint['slug'] : '';
			$cap_val        = isset( $endpoint['capability'] ) ? $endpoint['capability'] : 'read';
			$args_val       = isset( $endpoint['args'] ) ? $endpoint['args'] : '';
			$is_async       = ! empty( $endpoint['async'] );
			$max_attempts   = isset( $endpoint['max_attempts'] ) ? absint( $endpoint['max_attempts'] ) : 3;

			// Determine card readiness state.
			$card_state = 'is-empty';
			if ( $sel_mp_id ) {
				$mp_obj     = get_post( $sel_mp_id );
				$cache_file = MICROPLUGINS_CACHE_DIR . '/' . $sel_mp_id . '.php';
				$mp_cached  = file_exists( $cache_file );
				$mp_status  = $mp_obj instanceof WP_Post ? $mp_obj->post_status : '';
				if ( 'publish' === $mp_status && $mp_cached ) {
					$card_state = 'is-ready';
				} else {
					$card_state = 'is-warning';
				}
			}
			?>
		<div class="cem-endpoint-card <?php echo esc_attr( $card_state ); ?>" id="cem-card-<?php echo esc_attr( $i ); ?>">

			<!-- Card header (click to expand/collapse) -->
			<div class="cem-card-header">
				<span class="cem-card-methods">
					<?php foreach ( $active_methods as $method_name ) : ?>
						<span class="cem-hdr-method cem-hdr-method--<?php echo esc_attr( strtolower( $method_name ) ); ?>">
							<?php echo esc_html( $method_name ); ?>
						</span>
					<?php endforeach; ?>
					<?php if ( empty( $active_methods ) ) : ?>
						<span class="cem-hdr-method cem-hdr-method--other">—</span>
					<?php endif; ?>
				</span>
				<span class="cem-card-route">
					<span class="cem-route-base">/wp-json/cem/v1/</span><strong class="cem-route-slug"><?php echo esc_html( $slug_val ); ?></strong>
				</span>
				<?php if ( $sel_mp_id && isset( $mp_status ) ) : ?>
					<?php
					$status_map_card = array(
						'publish' => array(
							'label' => 'Published',
							'class' => 'cem-status-publish',
						),
						'pending' => array(
							'label' => 'Pending',
							'class' => 'cem-status-pending',
						),
						'draft'   => array(
							'label' => 'Draft',
							'class' => 'cem-status-draft',
						),
					);
					$s_cfg           = isset( $status_map_card[ $mp_status ] ) ? $status_map_card[ $mp_status ] : array(
						'label' => ucfirst( $mp_status ),
						'class' => 'cem-status-draft',
					);
					?>
					<span class="cem-card-mp-status">
						<span class="cem-mp-status <?php echo esc_attr( $s_cfg['class'] ); ?>"><?php echo esc_html( $s_cfg['label'] ); ?></span>
					</span>
				<?php endif; ?>
				<span class="cem-card-header-actions">
					<button type="button" class="cem-card-toggle" aria-label="<?php esc_attr_e( 'Toggle', 'custom-endpoints-manager' ); ?>">&#9660;</button>
					<button type="button" class="cem-card-remove" title="<?php esc_attr_e( 'Remove endpoint', 'custom-endpoints-manager' ); ?>">&#x2715;</button>
				</span>
			</div><!-- .cem-card-header -->

			<!-- Card body -->
			<div class="cem-card-body">
				<div class="cem-fields-grid">

					<!-- Slug -->
					<div class="cem-field-group">
						<label for="cem-slug-<?php echo esc_attr( $i ); ?>"><?php esc_html_e( 'Route Slug', 'custom-endpoints-manager' ); ?></label>
						<div class="cem-slug-row">
							<span class="cem-slug-prefix">/wp-json/cem/v1/</span>
							<input type="text" id="cem-slug-<?php echo esc_attr( $i ); ?>"
								name="cem_endpoints[<?php echo esc_attr( $i ); ?>][slug]"
								value="<?php echo esc_attr( $slug_val ); ?>"
								class="cem-slug-input"
								placeholder="my-endpoint" />
						</div>
					</div>

					<!-- Microplugin picker -->
					<div class="cem-field-group">
						<label for="cem-mp-<?php echo esc_attr( $i ); ?>"><?php esc_html_e( 'Microplugin (Callback)', 'custom-endpoints-manager' ); ?></label>
						<select id="cem-mp-<?php echo esc_attr( $i ); ?>"
							name="cem_endpoints[<?php echo esc_attr( $i ); ?>][microplugin_id]"
							class="cem-mp-select">
							<option value=""><?php esc_html_e( '— Select Microplugin —', 'custom-endpoints-manager' ); ?></option>
							<?php foreach ( $microplugins_posts as $mp_post ) : ?>
								<option value="<?php echo esc_attr( $mp_post->ID ); ?>"
									<?php selected( $sel_mp_id, $mp_post->ID ); ?>>
									<?php echo esc_html( $mp_post->post_title . ' (ID: ' . $mp_post->ID . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<?php if ( $sel_mp_id && isset( $mp_obj ) && $mp_obj instanceof WP_Post ) : ?>
							<div class="cem-mp-context">
								<span class="cem-mp-status <?php echo esc_attr( $s_cfg['class'] ); ?>"><?php echo esc_html( $s_cfg['label'] ); ?></span>
								<?php if ( $mp_cached ) : ?>
									<span class="cem-mp-cache cem-mp-cache-ok">&#10003; cached</span>
								<?php else : ?>
									<span class="cem-mp-cache cem-mp-cache-miss">&#9888; no cache</span>
								<?php endif; ?>
								<code>cem_microplugin_callback_<?php echo esc_html( $sel_mp_id ); ?></code>
								<a href="<?php echo esc_url( get_edit_post_link( $sel_mp_id ) ); ?>" target="_blank" class="cem-mp-edit-link"><?php esc_html_e( 'Edit &#8599;', 'custom-endpoints-manager' ); ?></a>
							</div>
							<?php if ( 'is-warning' === $card_state ) : ?>
								<p class="cem-mp-context-warn">
									&#9888;
									<?php
									if ( 'publish' !== $mp_status ) {
										esc_html_e( 'Microplugin is not published — endpoint will not work.', 'custom-endpoints-manager' );
									} else {
										esc_html_e( 'No cache file — publish the microplugin to generate it.', 'custom-endpoints-manager' );
									}
									?>
								</p>
							<?php endif; ?>
						<?php elseif ( ! $sel_mp_id ) : ?>
							<p class="description"><?php esc_html_e( 'No callback assigned — endpoint returns 403 until a microplugin is selected.', 'custom-endpoints-manager' ); ?></p>
						<?php endif; ?>
					</div>

					<!-- HTTP Methods -->
					<div class="cem-field-group">
						<span class="cem-field-label"><?php esc_html_e( 'HTTP Methods', 'custom-endpoints-manager' ); ?></span>
						<div class="cem-method-pills">
							<?php foreach ( $all_methods as $method ) : ?>
								<label class="cem-method-pill <?php echo in_array( $method, $active_methods, true ) ? 'is-active' : ''; ?>"
									data-method="<?php echo esc_attr( $method ); ?>">
									<input type="checkbox" class="cem-method-cb"
										value="<?php echo esc_attr( $method ); ?>"
										<?php checked( in_array( $method, $active_methods, true ) ); ?> />
									<?php echo esc_html( $method ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<input type="hidden"
							name="cem_endpoints[<?php echo esc_attr( $i ); ?>][methods]"
							class="cem-methods-val"
							value="<?php echo esc_attr( $methods_val ); ?>" />
					</div>

					<!-- Capability -->
					<div class="cem-field-group">
						<label for="cem-cap-<?php echo esc_attr( $i ); ?>"><?php esc_html_e( 'Capability', 'custom-endpoints-manager' ); ?></label>
						<input type="text" id="cem-cap-<?php echo esc_attr( $i ); ?>"
							name="cem_endpoints[<?php echo esc_attr( $i ); ?>][capability]"
							value="<?php echo esc_attr( $cap_val ); ?>"
							list="cem-cap-list-<?php echo esc_attr( $i ); ?>"
							placeholder="read" />
						<datalist id="cem-cap-list-<?php echo esc_attr( $i ); ?>">
							<?php foreach ( $capability_presets as $cap_key => $cap_label ) : ?>
								<option value="<?php echo esc_attr( $cap_key ); ?>"><?php echo esc_html( $cap_label ); ?></option>
							<?php endforeach; ?>
						</datalist>
						<p class="description"><?php esc_html_e( 'WP capability required to call this endpoint.', 'custom-endpoints-manager' ); ?></p>
					</div>

					<!-- Arguments (full width) -->
					<div class="cem-field-group cem-field-full">
						<label for="cem-args-<?php echo esc_attr( $i ); ?>"><?php esc_html_e( 'Arguments', 'custom-endpoints-manager' ); ?></label>
						<input type="text" id="cem-args-<?php echo esc_attr( $i ); ?>"
							name="cem_endpoints[<?php echo esc_attr( $i ); ?>][args]"
							value="<?php echo esc_attr( $args_val ); ?>"
							placeholder="id:integer, name:string, active:boolean" />
						<p class="description"><?php esc_html_e( 'Comma-separated name:type pairs. Types: string, integer, number, boolean. Leave blank for no typed args.', 'custom-endpoints-manager' ); ?></p>
					</div>

					<!-- Async (full width) -->
					<div class="cem-field-group cem-field-full">
						<span class="cem-field-label"><?php esc_html_e( 'Async Execution', 'custom-endpoints-manager' ); ?></span>
						<div class="cem-async-row">
							<label>
								<input type="checkbox"
									name="cem_endpoints[<?php echo esc_attr( $i ); ?>][async]"
									value="1"
									class="cem-async-toggle"
									<?php checked( $is_async ); ?> />
								<?php esc_html_e( 'Run asynchronously — returns job_id immediately, executes via WP Cron', 'custom-endpoints-manager' ); ?>
							</label>
							<span class="cem-async-attempts <?php echo $is_async ? 'is-visible' : ''; ?>">
								<label for="cem-att-<?php echo esc_attr( $i ); ?>"><?php esc_html_e( 'Max attempts:', 'custom-endpoints-manager' ); ?></label>
								<input type="number" id="cem-att-<?php echo esc_attr( $i ); ?>"
									name="cem_endpoints[<?php echo esc_attr( $i ); ?>][max_attempts]"
									value="<?php echo esc_attr( $max_attempts ); ?>"
									min="1" max="10" />
							</span>
						</div>
					</div>

				</div><!-- .cem-fields-grid -->
			</div><!-- .cem-card-body -->

		</div><!-- .cem-endpoint-card -->
		<?php endforeach; ?>
	</div><!-- .cem-endpoints-stack -->

	<div class="cem-add-row">
		<button type="button" class="button button-secondary" id="cem-add-endpoint">
			<?php esc_html_e( '+ Add Endpoint', 'custom-endpoints-manager' ); ?>
		</button>
	</div>

	<?php submit_button( __( 'Save Endpoints', 'custom-endpoints-manager' ) ); ?>
</form>

<hr />
<h3><?php esc_html_e( 'Registered CEM Routes', 'custom-endpoints-manager' ); ?></h3>
<ul>
	<?php
	$rest_server = rest_get_server();
	$all_routes  = $rest_server->get_routes();
	$cem_routes  = array_filter(
		$all_routes,
		function ( $route ) {
			return false !== strpos( $route, '/cem/v1/' );
		},
		ARRAY_FILTER_USE_KEY
	);

	if ( ! empty( $cem_routes ) ) :
		foreach ( $cem_routes as $route => $handlers ) :
			?>
		<li><code><?php echo esc_html( $route ); ?></code></li>
			<?php
		endforeach;
	else :
		?>
		<li><?php esc_html_e( 'No custom routes registered yet.', 'custom-endpoints-manager' ); ?></li>
	<?php endif; ?>
</ul>

<?php
// Pass microplugin list to JS for the "Add Endpoint" button.
$mp_options = array();
foreach ( $microplugins_posts as $mp_post ) {
	$mp_options[] = array(
		'id'    => $mp_post->ID,
		'title' => $mp_post->post_title . ' (ID: ' . $mp_post->ID . ')',
	);
}
wp_localize_script( 'custom-endpoints-manager', 'cemMicropluginOptions', $mp_options );
?>

<hr />
<?php
// Check if demos already installed.
$demo_posts      = get_posts(
	array(
		'post_type'      => Microplugins::POST_TYPE,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		's'              => '[CEM Demo]',
	)
);
$demos_installed = ! empty( $demo_posts );
?>
<div class="cem-demo-card <?php echo $demos_installed ? 'cem-demo-card--done' : ''; ?>">
	<h3>
		<?php if ( $demos_installed ) : ?>
			<?php esc_html_e( '✓ Demo Data Installed', 'custom-endpoints-manager' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Quick Start: Install Demo Data', 'custom-endpoints-manager' ); ?>
		<?php endif; ?>
	</h3>
	<?php if ( $demos_installed ) : ?>
		<p><?php esc_html_e( 'Demo microplugins and endpoints are already installed. Visit the Microplugins tab to explore them.', 'custom-endpoints-manager' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Install 8 example microplugins (Hello World, HubSpot webhooks, Twilio SMS, form submit) to explore all plugin features — one click.', 'custom-endpoints-manager' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cem_install_demos" />
			<?php wp_nonce_field( 'cem_install_demos' ); ?>
			<?php submit_button( __( 'Install Demo Data', 'custom-endpoints-manager' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>
</div>
