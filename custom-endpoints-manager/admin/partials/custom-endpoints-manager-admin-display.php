<?php
/**
 * Admin area view for the plugin.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/admin/partials
 */

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$custom_endpoints = get_option( 'cem_custom_endpoints', array() );
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- sanitized immediately below; read-only tab selector.
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'endpoints';

$microplugins_posts = get_posts(
	array(
		'post_type'      => Microplugins::POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	)
);
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<nav class="nav-tab-wrapper">
		<a href="?page=custom-endpoints-manager&tab=endpoints"
			class="nav-tab <?php echo 'endpoints' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Custom Endpoints', 'custom-endpoints-manager' ); ?>
		</a>
		<a href="edit.php?post_type=<?php echo esc_attr( Microplugins::POST_TYPE ); ?>" class="nav-tab">
			<?php esc_html_e( 'Microplugins', 'custom-endpoints-manager' ); ?>
		</a>
		<a href="?page=custom-endpoints-manager&tab=logs"
			class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php
			$total_logs = CEM_Execution_Logger::count_logs();
			esc_html_e( 'Execution Logs', 'custom-endpoints-manager' );
			if ( $total_logs ) {
				echo ' <span class="update-plugins count-' . esc_attr( $total_logs ) . '"><span class="plugin-count">' . esc_html( $total_logs ) . '</span></span>';
			}
			?>
		</a>
	</nav>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
	if ( isset( $_GET['message'] ) && 'endpoints' === $active_tab ) :
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$display_message = sanitize_key( wp_unslash( $_GET['message'] ) );
		?>
		<div id="message" class="updated notice is-dismissible">
			<p>
				<?php
				if ( 'saved' === $display_message ) {
					esc_html_e( 'Settings saved.', 'custom-endpoints-manager' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( 'endpoints' === $active_tab ) : ?>
		<h2><?php esc_html_e( 'Manage Custom REST API Endpoints', 'custom-endpoints-manager' ); ?></h2>
		<p>
			<?php esc_html_e( 'Define your custom REST API endpoints here. Select a published Microplugin as the callback. The Microplugin must contain a function named cem_microplugin_callback_{ID} where {ID} is the Microplugin Post ID.', 'custom-endpoints-manager' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=custom-endpoints-manager' ) ); ?>">
			<?php wp_nonce_field( 'cem_nonce', 'cem_nonce' ); ?>

			<table class="widefat fixed striped" id="cem-endpoints-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Route Slug', 'custom-endpoints-manager' ); ?></th>
						<th><?php esc_html_e( 'Methods', 'custom-endpoints-manager' ); ?></th>
						<th><?php esc_html_e( 'Capability', 'custom-endpoints-manager' ); ?></th>
						<th><?php esc_html_e( 'Microplugin (Callback)', 'custom-endpoints-manager' ); ?></th>
						<th><?php esc_html_e( 'Arguments (name:type,...)', 'custom-endpoints-manager' ); ?></th>
						<th><?php esc_html_e( 'Async', 'custom-endpoints-manager' ); ?></th>
						<th><?php esc_html_e( 'Action', 'custom-endpoints-manager' ); ?></th>
					</tr>
				</thead>
				<tbody id="custom-endpoints-list">
					<?php
					$rows = ! empty( $custom_endpoints ) ? $custom_endpoints : array(
						array(
							'slug'           => '',
							'methods'        => '',
							'capability'     => '',
							'microplugin_id' => 0,
							'args'           => '',
						),
					);
					foreach ( $rows as $row_index => $endpoint ) :
						$selected_mp_id = isset( $endpoint['microplugin_id'] ) ? absint( $endpoint['microplugin_id'] ) : 0;
						$row_i          = absint( $row_index );
						?>
					<tr>
						<td>
							<input type="text" name="cem_endpoints[<?php echo esc_attr( $row_i ); ?>][slug]"
								value="<?php echo esc_attr( $endpoint['slug'] ); ?>" class="regular-text" />
						</td>
						<td>
							<input type="text" name="cem_endpoints[<?php echo esc_attr( $row_i ); ?>][methods]"
								value="<?php echo esc_attr( $endpoint['methods'] ); ?>" class="small-text"
								placeholder="GET" />
						</td>
						<td>
							<input type="text" name="cem_endpoints[<?php echo esc_attr( $row_i ); ?>][capability]"
								value="<?php echo esc_attr( $endpoint['capability'] ); ?>" class="regular-text"
								placeholder="read" />
						</td>
						<td>
							<select name="cem_endpoints[<?php echo esc_attr( $row_i ); ?>][microplugin_id]">
								<option value=""><?php esc_html_e( '— Select Microplugin —', 'custom-endpoints-manager' ); ?></option>
								<?php foreach ( $microplugins_posts as $mp_post ) : ?>
									<option value="<?php echo esc_attr( $mp_post->ID ); ?>"
										<?php selected( $selected_mp_id, $mp_post->ID ); ?>>
										<?php echo esc_html( $mp_post->post_title . ' (ID: ' . $mp_post->ID . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php if ( $selected_mp_id ) : ?>
								<?php
								$mp_obj     = get_post( $selected_mp_id );
								$cache_file = MICROPLUGINS_CACHE_DIR . '/' . absint( $selected_mp_id ) . '.php';
								$cached     = file_exists( $cache_file );
								$mp_status  = $mp_obj instanceof WP_Post ? $mp_obj->post_status : '';
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
								$status_cfg = isset( $status_map[ $mp_status ] ) ? $status_map[ $mp_status ] : array(
									'label' => ucfirst( $mp_status ),
									'class' => 'cem-status-draft',
								);
								?>
								<p class="description">
									<?php
									/* translators: %s: PHP callback function name */
									printf( esc_html__( 'Callback: %s', 'custom-endpoints-manager' ), '<code>cem_microplugin_callback_' . absint( $selected_mp_id ) . '</code>' );
									?>
								</p>
								<p class="cem-mp-meta">
									<span class="cem-mp-status <?php echo esc_attr( $status_cfg['class'] ); ?>"><?php echo esc_html( $status_cfg['label'] ); ?></span>
									<?php if ( $cached ) : ?>
										<span class="cem-mp-cache cem-mp-cache-ok" title="<?php esc_attr_e( 'Cache file exists', 'custom-endpoints-manager' ); ?>">&#10003; cached</span>
									<?php else : ?>
										<span class="cem-mp-cache cem-mp-cache-miss" title="<?php esc_attr_e( 'No cache file — publish the microplugin', 'custom-endpoints-manager' ); ?>">&#9888; no cache</span>
									<?php endif; ?>
									<?php if ( $mp_obj instanceof WP_Post ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $selected_mp_id ) ); ?>" target="_blank" class="cem-mp-edit-link"><?php esc_html_e( 'Edit &#8599;', 'custom-endpoints-manager' ); ?></a>
									<?php endif; ?>
								</p>
							<?php endif; ?>
						</td>
						<td>
							<input type="text" name="cem_endpoints[<?php echo esc_attr( $row_i ); ?>][args]"
								value="<?php echo esc_attr( isset( $endpoint['args'] ) ? $endpoint['args'] : '' ); ?>"
								class="regular-text" placeholder="id:integer,s:string" />
						</td>
						<td style="white-space:nowrap">
							<label>
								<input type="checkbox"
									name="cem_endpoints[<?php echo esc_attr( $row_i ); ?>][async]"
									value="1"
									<?php checked( ! empty( $endpoint['async'] ) ); ?> />
								<?php esc_html_e( 'Async', 'custom-endpoints-manager' ); ?>
							</label><br>
							<label style="font-size:11px">
								<?php esc_html_e( 'Attempts:', 'custom-endpoints-manager' ); ?>
								<input type="number" name="cem_endpoints[<?php echo esc_attr( $row_i ); ?>][max_attempts]"
									value="<?php echo esc_attr( $endpoint['max_attempts'] ?? 3 ); ?>"
									min="1" max="10" style="width:45px" />
							</label>
						</td>
						<td>
							<button type="button" class="button button-secondary remove-endpoint">
								<?php esc_html_e( 'Remove', 'custom-endpoints-manager' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<button type="button" class="button button-secondary" id="add-endpoint">
					<?php esc_html_e( 'Add New Endpoint', 'custom-endpoints-manager' ); ?>
				</button>
			</p>

			<?php submit_button( __( 'Save Endpoints', 'custom-endpoints-manager' ) ); ?>
		</form>

		<hr />
		<h2><?php esc_html_e( 'Registered CEM Routes', 'custom-endpoints-manager' ); ?></h2>
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

	<?php endif; ?>
</div>
