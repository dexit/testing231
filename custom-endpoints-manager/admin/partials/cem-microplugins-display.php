<?php
/**
 * Microplugins tab content for the settings page.
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

$all_microplugins = get_posts(
	array(
		'post_type'      => Microplugins::POST_TYPE,
		'post_status'    => array( 'publish', 'draft', 'pending' ),
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	)
);

// Build endpoint-usage map: microplugin_id => count.
$endpoint_usage  = array();
$saved_endpoints = get_option( 'cem_custom_endpoints', array() );
foreach ( $saved_endpoints as $ep ) {
	$mp_id = isset( $ep['microplugin_id'] ) ? absint( $ep['microplugin_id'] ) : 0;
	if ( $mp_id ) {
		$endpoint_usage[ $mp_id ] = isset( $endpoint_usage[ $mp_id ] ) ? $endpoint_usage[ $mp_id ] + 1 : 1;
	}
}

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
?>

<div class="cem-mp-list-header">
	<h2><?php esc_html_e( 'Manage Microplugins', 'custom-endpoints-manager' ); ?></h2>
	<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Microplugins::POST_TYPE ) ); ?>"
		class="button button-primary">
		<?php esc_html_e( '+ Add New Microplugin', 'custom-endpoints-manager' ); ?>
	</a>
</div>
<p>
	<?php esc_html_e( 'Microplugins are PHP snippets stored as posts. Publish one to generate a cache file, then assign it to an endpoint on the Custom Endpoints tab.', 'custom-endpoints-manager' ); ?>
</p>

<?php if ( empty( $all_microplugins ) ) : ?>
	<div class="cem-empty-state">
		<p><?php esc_html_e( 'No microplugins found.', 'custom-endpoints-manager' ); ?></p>
		<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Microplugins::POST_TYPE ) ); ?>"
			class="button button-primary">
			<?php esc_html_e( 'Create Your First Microplugin', 'custom-endpoints-manager' ); ?>
		</a>
		<p style="margin-top:12px">
			<?php esc_html_e( 'Or use the "Install Demo Data" button on the Custom Endpoints tab to seed 8 example microplugins.', 'custom-endpoints-manager' ); ?>
		</p>
	</div>
<?php else : ?>
	<table class="widefat fixed striped cem-mp-list-table">
		<thead>
			<tr>
				<th style="width:50px"><?php esc_html_e( 'ID', 'custom-endpoints-manager' ); ?></th>
				<th><?php esc_html_e( 'Title', 'custom-endpoints-manager' ); ?></th>
				<th style="width:100px"><?php esc_html_e( 'Status', 'custom-endpoints-manager' ); ?></th>
				<th style="width:90px"><?php esc_html_e( 'Cache', 'custom-endpoints-manager' ); ?></th>
				<th style="width:90px"><?php esc_html_e( 'Endpoints', 'custom-endpoints-manager' ); ?></th>
				<th style="width:160px"><?php esc_html_e( 'Actions', 'custom-endpoints-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $all_microplugins as $mp ) : ?>
				<?php
				$mp_status  = $mp->post_status;
				$status_cfg = isset( $status_map[ $mp_status ] )
					? $status_map[ $mp_status ]
					: array(
						'label' => ucfirst( $mp_status ),
						'class' => 'cem-status-draft',
					);
				$cache_file = MICROPLUGINS_CACHE_DIR . '/' . absint( $mp->ID ) . '.php';
				$cached     = file_exists( $cache_file );
				$ep_count   = isset( $endpoint_usage[ $mp->ID ] ) ? $endpoint_usage[ $mp->ID ] : 0;
				$edit_link  = get_edit_post_link( $mp->ID );
				?>
				<tr>
					<td><?php echo esc_html( $mp->ID ); ?></td>
					<td>
						<strong>
							<a href="<?php echo esc_url( $edit_link ); ?>">
								<?php echo esc_html( $mp->post_title ); ?>
							</a>
						</strong>
						<br>
						<code style="font-size:11px">cem_microplugin_callback_<?php echo esc_html( $mp->ID ); ?></code>
					</td>
					<td>
						<span class="cem-mp-status <?php echo esc_attr( $status_cfg['class'] ); ?>">
							<?php echo esc_html( $status_cfg['label'] ); ?>
						</span>
					</td>
					<td>
						<?php if ( $cached ) : ?>
							<span class="cem-mp-cache cem-mp-cache-ok" title="<?php esc_attr_e( 'Cache file exists', 'custom-endpoints-manager' ); ?>">
								&#10003; cached
							</span>
						<?php else : ?>
							<span class="cem-mp-cache cem-mp-cache-miss" title="<?php esc_attr_e( 'No cache file — publish the microplugin', 'custom-endpoints-manager' ); ?>">
								&#9888; no cache
							</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $ep_count > 0 ) : ?>
							<a href="<?php echo esc_url( admin_url( 'options-general.php?page=custom-endpoints-manager&tab=endpoints' ) ); ?>">
								<?php
								/* translators: %d: number of endpoints using this microplugin */
								echo esc_html( sprintf( __( '%d endpoint(s)', 'custom-endpoints-manager' ), $ep_count ) );
								?>
							</a>
						<?php else : ?>
							<span style="color:#646970"><?php esc_html_e( 'None', 'custom-endpoints-manager' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<a href="<?php echo esc_url( $edit_link ); ?>" class="button button-small">
							<?php esc_html_e( 'Edit', 'custom-endpoints-manager' ); ?>
						</a>
						<?php if ( 'publish' === $mp_status ) : ?>
							<a href="<?php echo esc_url( get_permalink( $mp->ID ) ); ?>" class="button button-small" target="_blank">
								<?php esc_html_e( 'View', 'custom-endpoints-manager' ); ?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
