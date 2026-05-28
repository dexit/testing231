<?php
/**
 * Admin area view for the plugin — Endpoints tab content.
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

$mp_index = array();
foreach ( $microplugins_posts as $mp_post ) {
	$mp_index[ $mp_post->ID ] = $mp_post;
}

$all_methods = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );

$capability_presets = array(
	'public'         => 'public — no authentication required',
	'read'           => 'read — logged-in user',
	'edit_posts'     => 'edit_posts — editor+',
	'publish_posts'  => 'publish_posts — author+',
	'manage_options' => 'manage_options — admin only',
);

$status_map = array(
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

$test_nonce = wp_create_nonce( 'wp_rest' );
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

<!-- ─── Live Registered Routes ───────────────────────────────────────── -->
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

$ep_by_slug = array();
foreach ( $custom_endpoints as $ep ) {
	if ( ! empty( $ep['slug'] ) ) {
		$ep_by_slug[ sanitize_title( $ep['slug'] ) ] = $ep;
	}
}
?>
<div class="cem-routes-panel">
	<h3 class="cem-routes-panel-title">
		<?php esc_html_e( 'Live Registered Routes', 'custom-endpoints-manager' ); ?>
		<span class="cem-routes-count"><?php echo esc_html( count( $cem_routes ) ); ?></span>
	</h3>

	<?php if ( ! empty( $cem_routes ) ) : ?>
	<table class="widefat fixed striped cem-routes-table">
		<thead>
			<tr>
				<th style="width:110px"><?php esc_html_e( 'Methods', 'custom-endpoints-manager' ); ?></th>
				<th><?php esc_html_e( 'Route', 'custom-endpoints-manager' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Capability', 'custom-endpoints-manager' ); ?></th>
				<th style="width:160px"><?php esc_html_e( 'Callback', 'custom-endpoints-manager' ); ?></th>
				<th style="width:60px;text-align:center"><?php esc_html_e( 'Async', 'custom-endpoints-manager' ); ?></th>
				<th style="width:80px"><?php esc_html_e( 'Test', 'custom-endpoints-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $cem_routes as $route => $handlers ) :
				$route_methods = array();
				foreach ( $handlers as $handler ) {
					if ( ! empty( $handler['methods'] ) && is_array( $handler['methods'] ) ) {
						$route_methods = array_merge( $route_methods, array_keys( $handler['methods'] ) );
					}
				}
				$route_methods = array_unique( $route_methods );

				$route_slug = ltrim( str_replace( '/cem/v1', '', $route ), '/' );
				$ep_data    = isset( $ep_by_slug[ $route_slug ] ) ? $ep_by_slug[ $route_slug ] : null;
				$is_system  = null === $ep_data;
				$ep_cap     = $ep_data ? ( ! empty( $ep_data['capability'] ) ? $ep_data['capability'] : 'read' ) : '—';
				$ep_async   = $ep_data && ! empty( $ep_data['async'] );
				$ep_mp_id   = $ep_data ? absint( $ep_data['microplugin_id'] ) : 0;
				$ep_mp_post = $ep_mp_id ? get_post( $ep_mp_id ) : null;
				$ep_cb_mode = $ep_data ? ( isset( $ep_data['callback_mode'] ) ? $ep_data['callback_mode'] : 'microplugin' ) : '';
				$ep_cb_fn   = $ep_data ? ( isset( $ep_data['callback_fn'] ) ? $ep_data['callback_fn'] : '' ) : '';

				$has_get  = in_array( 'GET', $route_methods, true );
				$test_url = $has_get ? get_rest_url( null, 'cem/v1/' . $route_slug ) . '?_wpnonce=' . $test_nonce : '';
			?>
			<tr>
				<td>
					<?php foreach ( $route_methods as $rm ) : ?>
						<span class="cem-hdr-method cem-hdr-method--<?php echo esc_attr( strtolower( $rm ) ); ?>"><?php echo esc_html( $rm ); ?></span>
					<?php endforeach; ?>
				</td>
				<td>
					<code>/cem/v1/<?php echo esc_html( $route_slug ); ?></code>
					<?php if ( $is_system ) : ?>
						<span class="cem-route-system-badge"><?php esc_html_e( 'system', 'custom-endpoints-manager' ); ?></span>
					<?php endif; ?>
				</td>
				<td><code><?php echo esc_html( $ep_cap ); ?></code></td>
				<td>
					<?php if ( $ep_mp_post instanceof WP_Post ) : ?>
						<a href="<?php echo esc_url( get_edit_post_link( $ep_mp_id ) ); ?>" target="_blank"><?php echo esc_html( $ep_mp_post->post_title ); ?></a>
					<?php elseif ( 'function' === $ep_cb_mode && ! empty( $ep_cb_fn ) ) : ?>
						<code><?php echo esc_html( $ep_cb_fn ); ?></code>
					<?php else : ?>
						<span style="color:#c3c4c7">—</span>
					<?php endif; ?>
				</td>
				<td style="text-align:center">
					<?php if ( $ep_async ) : ?>
						<span class="cem-async-badge">ASYNC</span>
					<?php else : ?>
						<span style="color:#c3c4c7">sync</span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $test_url ) : ?>
						<a href="<?php echo esc_url( $test_url ); ?>" target="_blank" class="button button-small"><?php esc_html_e( 'Test', 'custom-endpoints-manager' ); ?></a>
					<?php else : ?>
						<span style="color:#c3c4c7">—</span>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No CEM routes registered yet. Add and save an endpoint below to register it.', 'custom-endpoints-manager' ); ?></p>
	<?php endif; ?>
</div><!-- .cem-routes-panel -->

<h2><?php esc_html_e( 'Custom REST API Endpoints', 'custom-endpoints-manager' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'Each card is a REST route. Configure the callback, methods, and capability, then save to register it.', 'custom-endpoints-manager' ); ?>
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
				'capture'        => false,
				'callback_mode'  => 'microplugin',
				'callback_fn'    => '',
				'outgoing'       => array(),
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
			$is_capture     = ! empty( $endpoint['capture'] );
			$max_attempts   = isset( $endpoint['max_attempts'] ) ? absint( $endpoint['max_attempts'] ) : 3;
			$callback_mode  = isset( $endpoint['callback_mode'] ) && 'function' === $endpoint['callback_mode'] ? 'function' : 'microplugin';
			$callback_fn    = isset( $endpoint['callback_fn'] ) ? $endpoint['callback_fn'] : '';
			$outgoing       = isset( $endpoint['outgoing'] ) && is_array( $endpoint['outgoing'] ) ? $endpoint['outgoing'] : array();

			// Determine card readiness state.
			$card_state = 'is-empty';
			$mp_obj     = null;
			$mp_cached  = false;
			$mp_status  = '';
			$s_cfg      = array(
				'label' => '',
				'class' => 'cem-status-draft',
			);

			if ( $sel_mp_id ) {
				$mp_obj     = get_post( $sel_mp_id );
				$cache_file = MICROPLUGINS_CACHE_DIR . '/' . $sel_mp_id . '.php';
				$mp_cached  = file_exists( $cache_file );
				$mp_status  = $mp_obj instanceof WP_Post ? $mp_obj->post_status : '';
				$s_cfg      = isset( $status_map[ $mp_status ] ) ? $status_map[ $mp_status ] : array(
					'label' => ucfirst( $mp_status ),
					'class' => 'cem-status-draft',
				);
				if ( 'publish' === $mp_status && $mp_cached ) {
					$card_state = 'is-ready';
				} else {
					$card_state = 'is-warning';
				}
			} elseif ( 'function' === $callback_mode && ! empty( $callback_fn ) ) {
				$card_state = 'is-warning';
			}

			// Scan microplugin code for CEM::param() calls to suggest args.
			$detected_params = array();
			if ( $mp_obj instanceof WP_Post && ! empty( $mp_obj->post_content ) ) {
				preg_match_all(
					'/CEM::(?:param)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
					$mp_obj->post_content,
					$param_matches
				);
				$detected_params = array_unique( $param_matches[1] );
			}
			?>
		<div class="cem-endpoint-card <?php echo esc_attr( $card_state ); ?>" id="cem-card-<?php echo esc_attr( $i ); ?>">

			<!-- Card header -->
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
				<?php if ( $sel_mp_id && '' !== $mp_status ) : ?>
					<span class="cem-card-mp-status">
						<span class="cem-mp-status <?php echo esc_attr( $s_cfg['class'] ); ?>"><?php echo esc_html( $s_cfg['label'] ); ?></span>
					</span>
				<?php elseif ( 'function' === $callback_mode && ! empty( $callback_fn ) ) : ?>
					<span class="cem-card-mp-status">
						<span class="cem-mp-status cem-status-draft">fn</span>
					</span>
				<?php endif; ?>
				<span class="cem-card-header-actions">
					<button type="button" class="cem-card-toggle" aria-label="<?php esc_attr_e( 'Toggle', 'custom-endpoints-manager' ); ?>">&#9660;</button>
					<button type="button" class="cem-card-remove" title="<?php esc_attr_e( 'Remove endpoint', 'custom-endpoints-manager' ); ?>">&#x2715;</button>
				</span>
			</div><!-- .cem-card-header -->

			<!-- Card body -->
			<div class="cem-card-body">

				<!-- Core config grid -->
				<div class="cem-fields-grid">

					<!-- Route Slug (full width) -->
					<div class="cem-field-group cem-field-full">
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

					<!-- HTTP Methods -->
					<div class="cem-field-group">
						<span class="cem-field-label"><?php esc_html_e( 'HTTP Methods', 'custom-endpoints-manager' ); ?></span>
						<div class="cem-method-pills">
							<?php foreach ( $all_methods as $method_name ) : ?>
								<label class="cem-method-pill <?php echo in_array( $method_name, $active_methods, true ) ? 'is-active' : ''; ?>"
									data-method="<?php echo esc_attr( $method_name ); ?>">
									<input type="checkbox" class="cem-method-cb"
										value="<?php echo esc_attr( $method_name ); ?>"
										<?php checked( in_array( $method_name, $active_methods, true ) ); ?> />
									<?php echo esc_html( $method_name ); ?>
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
						<p class="description"><?php esc_html_e( 'Use "public" for unauthenticated access.', 'custom-endpoints-manager' ); ?></p>
					</div>

					<!-- Callback (full width) -->
					<div class="cem-field-group cem-field-full">
						<div class="cem-callback-label-row">
							<label><?php esc_html_e( 'Callback', 'custom-endpoints-manager' ); ?></label>
							<span class="cem-callback-tabs">
								<button type="button" class="cem-cb-tab <?php echo 'microplugin' === $callback_mode ? 'is-active' : ''; ?>" data-mode="microplugin"><?php esc_html_e( 'Microplugin', 'custom-endpoints-manager' ); ?></button>
								<button type="button" class="cem-cb-tab <?php echo 'function' === $callback_mode ? 'is-active' : ''; ?>" data-mode="function"><?php esc_html_e( 'Raw Function', 'custom-endpoints-manager' ); ?></button>
							</span>
						</div>
						<input type="hidden"
							name="cem_endpoints[<?php echo esc_attr( $i ); ?>][callback_mode]"
							class="cem-callback-mode-val"
							value="<?php echo esc_attr( $callback_mode ); ?>" />

						<!-- Microplugin panel -->
						<div class="cem-cb-panel cem-cb-panel--mp" <?php echo 'function' === $callback_mode ? 'style="display:none"' : ''; ?>>
							<div class="cem-mp-select-row">
								<select id="cem-mp-<?php echo esc_attr( $i ); ?>"
									name="cem_endpoints[<?php echo esc_attr( $i ); ?>][microplugin_id]"
									class="cem-mp-select">
									<option value=""><?php esc_html_e( '— Select Microplugin —', 'custom-endpoints-manager' ); ?></option>
									<?php foreach ( $microplugins_posts as $mp_post ) : ?>
										<option value="<?php echo esc_attr( $mp_post->ID ); ?>"
											<?php selected( $sel_mp_id, $mp_post->ID ); ?>>
											<?php echo esc_html( $mp_post->post_title ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Microplugins::POST_TYPE ) ); ?>"
									target="_blank"
									class="button button-small">
									<?php esc_html_e( '+ New', 'custom-endpoints-manager' ); ?>
								</a>
							</div>

							<?php if ( $sel_mp_id && $mp_obj instanceof WP_Post ) : ?>
								<div class="cem-mp-context">
									<span class="cem-mp-status <?php echo esc_attr( $s_cfg['class'] ); ?>"><?php echo esc_html( $s_cfg['label'] ); ?></span>
									<?php if ( $mp_cached ) : ?>
										<span class="cem-mp-cache cem-mp-cache-ok">&#10003; cached</span>
									<?php else : ?>
										<span class="cem-mp-cache cem-mp-cache-miss">&#9888; no cache</span>
									<?php endif; ?>
									<code>cem_microplugin_callback_<?php echo esc_html( $sel_mp_id ); ?></code>
									<a href="<?php echo esc_url( get_edit_post_link( $sel_mp_id ) ); ?>"
										target="_blank"
										class="cem-mp-edit-link">
										<?php esc_html_e( 'Edit &#8599;', 'custom-endpoints-manager' ); ?>
									</a>
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
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Select a microplugin to use as the callback.', 'custom-endpoints-manager' ); ?></p>
							<?php endif; ?>
						</div><!-- .cem-cb-panel--mp -->

						<!-- Raw function panel -->
						<div class="cem-cb-panel cem-cb-panel--fn" <?php echo 'microplugin' === $callback_mode ? 'style="display:none"' : ''; ?>>
							<input type="text"
								name="cem_endpoints[<?php echo esc_attr( $i ); ?>][callback_fn]"
								class="cem-callback-fn-input"
								value="<?php echo esc_attr( $callback_fn ); ?>"
								placeholder="my_plugin_callback_function" />
							<p class="description"><?php esc_html_e( 'A globally defined PHP function. Must accept WP_REST_Request $request and return WP_REST_Response.', 'custom-endpoints-manager' ); ?></p>
						</div><!-- .cem-cb-panel--fn -->
					</div><!-- callback -->

				</div><!-- .cem-fields-grid -->

				<!-- Input Arguments -->
				<div class="cem-card-subsection">
					<div class="cem-subsection-head">
						<strong><?php esc_html_e( 'Input Arguments', 'custom-endpoints-manager' ); ?></strong>
						<span class="cem-section-hint"><?php esc_html_e( 'Typed parameters this endpoint accepts', 'custom-endpoints-manager' ); ?></span>
					</div>

					<?php if ( ! empty( $detected_params ) ) : ?>
						<div class="cem-detected-hint">
							<button type="button"
								class="button button-small cem-use-detected"
								data-params="<?php echo esc_attr( implode( ',', $detected_params ) ); ?>">
								<?php
								/* translators: %s: comma-separated list of detected parameter names */
								echo esc_html( sprintf( __( '&#10024; Use detected: %s', 'custom-endpoints-manager' ), implode( ', ', $detected_params ) ) );
								?>
							</button>
						</div>
					<?php endif; ?>

					<table class="cem-arg-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width:38%"><?php esc_html_e( 'Name', 'custom-endpoints-manager' ); ?></th>
								<th style="width:28%"><?php esc_html_e( 'Type', 'custom-endpoints-manager' ); ?></th>
								<th style="width:18%;text-align:center"><?php esc_html_e( 'Required', 'custom-endpoints-manager' ); ?></th>
								<th style="width:16%"></th>
							</tr>
						</thead>
						<tbody class="cem-arg-tbody"></tbody>
					</table>
					<div class="cem-arg-actions">
						<button type="button" class="button cem-add-arg"><?php esc_html_e( '+ Add Argument', 'custom-endpoints-manager' ); ?></button>
						<button type="button" class="button cem-ai-suggest-args" style="display:none" data-index="<?php echo esc_attr( $i ); ?>">
							&#10024; <?php esc_html_e( 'AI Suggest', 'custom-endpoints-manager' ); ?>
						</button>
					</div>
					<input type="hidden"
						name="cem_endpoints[<?php echo esc_attr( $i ); ?>][args]"
						class="cem-args-val"
						value="<?php echo esc_attr( $args_val ); ?>" />
				</div><!-- Input Arguments -->

				<!-- Outgoing Actions -->
				<div class="cem-card-subsection">
					<div class="cem-subsection-head">
						<strong><?php esc_html_e( 'Outgoing Actions', 'custom-endpoints-manager' ); ?></strong>
						<span class="cem-section-hint"><?php esc_html_e( 'Fire webhooks and control execution after this endpoint runs', 'custom-endpoints-manager' ); ?></span>
					</div>

					<div class="cem-outgoing-flags">
						<label class="cem-flag-label">
							<input type="checkbox"
								name="cem_endpoints[<?php echo esc_attr( $i ); ?>][async]"
								value="1"
								class="cem-async-toggle"
								<?php checked( $is_async ); ?> />
							<?php esc_html_e( 'Run async', 'custom-endpoints-manager' ); ?>
						</label>
						<span class="cem-async-attempts <?php echo $is_async ? 'is-visible' : ''; ?>">
							<label for="cem-att-<?php echo esc_attr( $i ); ?>"><?php esc_html_e( 'max attempts:', 'custom-endpoints-manager' ); ?></label>
							<input type="number" id="cem-att-<?php echo esc_attr( $i ); ?>"
								name="cem_endpoints[<?php echo esc_attr( $i ); ?>][max_attempts]"
								value="<?php echo esc_attr( $max_attempts ); ?>"
								min="1" max="10" style="width:55px" />
						</span>
						<span class="cem-flag-sep"></span>
						<label class="cem-flag-label">
							<input type="checkbox"
								name="cem_endpoints[<?php echo esc_attr( $i ); ?>][capture]"
								value="1"
								<?php checked( $is_capture ); ?> />
							<?php esc_html_e( 'Capture payloads', 'custom-endpoints-manager' ); ?>
						</label>
						<?php if ( $is_capture ) : ?>
							<a href="<?php echo esc_url( admin_url( 'options-general.php?page=custom-endpoints-manager&tab=captures&cap_slug=' . rawurlencode( sanitize_title( $slug_val ) ) ) ); ?>"
								class="cem-captures-link">
								<?php esc_html_e( '&#8594; View captures &amp; mapping', 'custom-endpoints-manager' ); ?>
							</a>
						<?php endif; ?>
					</div><!-- .cem-outgoing-flags -->

					<!-- Outgoing Webhooks -->
					<div class="cem-webhooks-wrap">
						<div class="cem-webhook-rows">
							<?php foreach ( $outgoing as $wh_idx => $wh ) :
								$wh_method   = isset( $wh['method'] ) ? strtoupper( $wh['method'] ) : 'POST';
								$wh_url      = isset( $wh['url'] ) ? $wh['url'] : '';
								$wh_template = isset( $wh['body_template'] ) ? $wh['body_template'] : '';
							?>
							<div class="cem-webhook-row">
								<select name="cem_endpoints[<?php echo esc_attr( $i ); ?>][outgoing][<?php echo esc_attr( $wh_idx ); ?>][method]" class="cem-wh-method">
									<?php foreach ( array( 'POST', 'GET', 'PUT', 'PATCH', 'DELETE' ) as $wm ) : ?>
										<option value="<?php echo esc_attr( $wm ); ?>" <?php selected( $wh_method, $wm ); ?>><?php echo esc_html( $wm ); ?></option>
									<?php endforeach; ?>
								</select>
								<input type="url"
									name="cem_endpoints[<?php echo esc_attr( $i ); ?>][outgoing][<?php echo esc_attr( $wh_idx ); ?>][url]"
									value="<?php echo esc_attr( $wh_url ); ?>"
									class="cem-wh-url"
									placeholder="https://example.com/webhook" />
								<button type="button" class="cem-wh-toggle-body button button-small">
									<?php esc_html_e( 'Body', 'custom-endpoints-manager' ); ?> &#9660;
								</button>
								<button type="button" class="cem-wh-remove button-link" title="<?php esc_attr_e( 'Remove webhook', 'custom-endpoints-manager' ); ?>">&#x2715;</button>
								<textarea
									name="cem_endpoints[<?php echo esc_attr( $i ); ?>][outgoing][<?php echo esc_attr( $wh_idx ); ?>][body_template]"
									class="cem-wh-body"
									rows="3"
									placeholder='{"key": "{{body.field}}", "id": "{{query.id}}"}'
									style="display:none"><?php echo esc_textarea( $wh_template ); ?></textarea>
								<p class="cem-wh-body-hint description" style="display:none">
									<?php esc_html_e( 'Use {{body.field}}, {{query.param}}, {{json.key}} placeholders. Leave blank to forward the full payload.', 'custom-endpoints-manager' ); ?>
								</p>
							</div>
							<?php endforeach; ?>
						</div><!-- .cem-webhook-rows -->
						<div class="cem-webhook-add-row">
							<button type="button" class="button cem-add-webhook">
								<?php esc_html_e( '+ Add Outgoing Webhook', 'custom-endpoints-manager' ); ?>
							</button>
						</div>
					</div><!-- .cem-webhooks-wrap -->

				</div><!-- Outgoing Actions -->

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

<?php
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

<?php
// Pass microplugin list to JS for the "Add Endpoint" button.
$mp_options = array();
foreach ( $microplugins_posts as $mp_post ) {
	$mp_options[] = array(
		'id'    => $mp_post->ID,
		'title' => $mp_post->post_title . ' (#' . $mp_post->ID . ')',
	);
}
wp_localize_script( 'custom-endpoints-manager', 'cemMicropluginOptions', $mp_options );
?>
