<?php
/**
 * Admin pages: Routes, Captures, Jobs, Logs.
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_pages' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_shared' ) );
		WRM_Mapping_Editor::init();
	}

	public static function enqueue_shared( string $hook ): void {
		$wrm_pages = array( 'toplevel_page_wrm-routes', 'webhook-router_page_wrm-captures', 'webhook-router_page_wrm-jobs', 'webhook-router_page_wrm-logs' );
		if ( ! in_array( $hook, $wrm_pages, true ) ) {
			return;
		}
		wp_enqueue_style( 'wrm-admin', WRM_PLUGIN_URL . 'assets/css/wrm-admin.css', array(), WRM_VERSION );
	}

	public static function register_pages(): void {
		add_menu_page(
			__( 'Webhook Router', 'wrm' ),
			__( 'Webhook Router', 'wrm' ),
			'manage_options',
			'wrm-routes',
			array( __CLASS__, 'page_routes' ),
			'dashicons-rest-api',
			56
		);
		add_submenu_page( 'wrm-routes', __( 'Routes', 'wrm' ),   __( 'Routes', 'wrm' ),   'manage_options', 'wrm-routes',   array( __CLASS__, 'page_routes' ) );
		add_submenu_page( 'wrm-routes', __( 'Mappings', 'wrm' ), __( 'Mappings', 'wrm' ), 'manage_options', 'edit.php?post_type=wrm_mapping' );
		add_submenu_page( 'wrm-routes', __( 'Captures', 'wrm' ), __( 'Captures', 'wrm' ), 'manage_options', 'wrm-captures', array( __CLASS__, 'page_captures' ) );
		add_submenu_page( 'wrm-routes', __( 'Jobs', 'wrm' ),     __( 'Jobs', 'wrm' ),     'manage_options', 'wrm-jobs',     array( __CLASS__, 'page_jobs' ) );
		add_submenu_page( 'wrm-routes', __( 'Logs', 'wrm' ),     __( 'Logs', 'wrm' ),     'manage_options', 'wrm-logs',     array( __CLASS__, 'page_logs' ) );
	}

	// -------------------------------------------------------------------------
	// ROUTES PAGE
	// -------------------------------------------------------------------------

	public static function page_routes(): void {
		// Handle form actions
		if ( isset( $_POST['wrm_routes_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wrm_routes_nonce'] ), 'wrm_routes_action' ) ) {
			self::handle_route_action();
		}

		$routes = WRM_Router::get_routes( 200, 0 );
		?>
		<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Webhook Routes', 'wrm' ); ?></h1>
		<a href="#wrm-add-route" class="page-title-action"><?php esc_html_e( '+ New Route', 'wrm' ); ?></a>
		<hr class="wp-header-end">

		<?php if ( ! empty( $_GET['wrm_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Route saved.', 'wrm' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['wrm_deleted'] ) ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Route deleted.', 'wrm' ); ?></p></div>
		<?php endif; ?>

		<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th><?php esc_html_e( 'Slug', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Label', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Methods', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Provider', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Mode', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Rate Limit', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Mapping', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Status', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Webhook URL', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'wrm' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $routes ) ) : ?>
			<tr><td colspan="10"><em><?php esc_html_e( 'No routes yet. Add one below.', 'wrm' ); ?></em></td></tr>
		<?php else : ?>
			<?php foreach ( $routes as $r ) : ?>
			<tr>
				<td><strong><?php echo esc_html( $r['slug'] ); ?></strong></td>
				<td><?php echo esc_html( $r['label'] ); ?></td>
				<td><code><?php echo esc_html( $r['methods'] ); ?></code></td>
				<td><?php echo esc_html( $r['provider'] ); ?></td>
				<td><?php echo esc_html( $r['run_mode'] ); ?></td>
				<td><?php echo $r['rate_limit'] ? esc_html( $r['rate_limit'] . ' / ' . $r['rate_window'] . 's' ) : '—'; ?></td>
				<td><?php echo $r['mapping_id'] ? esc_html( get_the_title( (int) $r['mapping_id'] ) ?: '#' . $r['mapping_id'] ) : '—'; ?></td>
				<td><span class="wrm-badge wrm-badge-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $r['status'] ); ?></span></td>
				<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis"><code style="font-size:10px"><?php echo esc_html( rest_url( 'wrm/v1/' . $r['slug'] ) ); ?></code></td>
				<td>
					<?php $nonce = wp_create_nonce( 'wrm_routes_action' ); ?>
					<?php if ( 'active' === $r['status'] ) : ?>
					<form method="post" style="display:inline"><input type="hidden" name="wrm_routes_nonce" value="<?php echo esc_attr( $nonce ); ?>"><input type="hidden" name="wrm_action" value="pause"><input type="hidden" name="wrm_slug" value="<?php echo esc_attr( $r['slug'] ); ?>"><button class="button button-small"><?php esc_html_e( 'Pause', 'wrm' ); ?></button></form>
					<?php else : ?>
					<form method="post" style="display:inline"><input type="hidden" name="wrm_routes_nonce" value="<?php echo esc_attr( $nonce ); ?>"><input type="hidden" name="wrm_action" value="resume"><input type="hidden" name="wrm_slug" value="<?php echo esc_attr( $r['slug'] ); ?>"><button class="button button-small"><?php esc_html_e( 'Resume', 'wrm' ); ?></button></form>
					<?php endif; ?>
					<form method="post" style="display:inline" onsubmit="return confirm('Delete route <?php echo esc_js( $r['slug'] ); ?>?')"><input type="hidden" name="wrm_routes_nonce" value="<?php echo esc_attr( $nonce ); ?>"><input type="hidden" name="wrm_action" value="delete"><input type="hidden" name="wrm_slug" value="<?php echo esc_attr( $r['slug'] ); ?>"><button class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'wrm' ); ?></button></form>
				</td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
		</table>

		<?php // Add new route form ?>
		<div id="wrm-add-route" style="margin-top:32px">
		<h2><?php esc_html_e( 'Add New Route', 'wrm' ); ?></h2>
		<form method="post" class="wrm-add-form">
		<?php wp_nonce_field( 'wrm_routes_action', 'wrm_routes_nonce' ); ?>
		<input type="hidden" name="wrm_action" value="create">
		<table class="form-table">
			<tr><th><?php esc_html_e( 'Slug', 'wrm' ); ?> *</th>
				<td><input type="text" name="new_slug" class="regular-text" required placeholder="my-webhook"> <p class="description"><?php printf( esc_html__( 'Endpoint URL: %s', 'wrm' ), '<code>' . esc_html( rest_url( 'wrm/v1/{slug}' ) ) . '</code>' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Label', 'wrm' ); ?></th>
				<td><input type="text" name="new_label" class="regular-text" placeholder="Human readable name"></td></tr>
			<tr><th><?php esc_html_e( 'Methods', 'wrm' ); ?></th>
				<td><select name="new_methods">
					<?php foreach ( array( 'POST', 'GET', 'POST,PUT', 'POST,GET', 'GET,POST,PUT,DELETE' ) as $m ) : ?>
						<option value="<?php echo esc_attr( $m ); ?>"><?php echo esc_html( $m ); ?></option>
					<?php endforeach; ?>
				</select></td></tr>
			<tr><th><?php esc_html_e( 'Provider', 'wrm' ); ?></th>
				<td><select name="new_provider">
					<?php foreach ( array( 'custom', 'twilio', 'hubspot', 'whatsapp' ) as $p ) : ?>
						<option value="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( ucfirst( $p ) ); ?></option>
					<?php endforeach; ?>
				</select></td></tr>
			<tr><th><?php esc_html_e( 'Auth Token / Secret', 'wrm' ); ?></th>
				<td><input type="text" name="new_auth_token" class="regular-text" placeholder="Leave blank for open access"></td></tr>
			<tr><th><?php esc_html_e( 'Rate Limit', 'wrm' ); ?></th>
				<td><input type="number" name="new_rate_limit" min="0" value="0" class="small-text"> <?php esc_html_e( 'requests per', 'wrm' ); ?> <input type="number" name="new_rate_window" min="1" value="60" class="small-text"> <?php esc_html_e( 'seconds (0 = unlimited)', 'wrm' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Run Mode', 'wrm' ); ?></th>
				<td><select name="new_run_mode"><option value="async">Async (queued)</option><option value="sync">Sync (inline)</option></select></td></tr>
			<tr><th><?php esc_html_e( 'Mapping', 'wrm' ); ?></th>
				<td>
					<select name="new_mapping_id">
						<option value="0"><?php esc_html_e( '— None (capture only) —', 'wrm' ); ?></option>
						<?php
						$mappings = get_posts( array( 'post_type' => 'wrm_mapping', 'posts_per_page' => 200, 'post_status' => 'publish' ) );
						foreach ( $mappings as $mp ) :
							?>
							<option value="<?php echo (int) $mp->ID; ?>"><?php echo esc_html( $mp->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
		</table>
		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Create Route', 'wrm' ); ?></button></p>
		</form>
		</div>
		</div>
		<?php
	}

	private static function handle_route_action(): void {
		$action = sanitize_key( $_POST['wrm_action'] ?? '' );
		$slug   = sanitize_title( $_POST['wrm_slug'] ?? '' );

		switch ( $action ) {
			case 'create':
				WRM_Router::insert_route( array(
					'slug'        => sanitize_title( $_POST['new_slug'] ?? '' ),
					'label'       => sanitize_text_field( $_POST['new_label'] ?? '' ),
					'methods'     => sanitize_text_field( $_POST['new_methods'] ?? 'POST' ),
					'provider'    => sanitize_key( $_POST['new_provider'] ?? 'custom' ),
					'auth_token'  => sanitize_text_field( $_POST['new_auth_token'] ?? '' ),
					'rate_limit'  => (int) ( $_POST['new_rate_limit'] ?? 0 ),
					'rate_window' => (int) ( $_POST['new_rate_window'] ?? 60 ),
					'run_mode'    => sanitize_key( $_POST['new_run_mode'] ?? 'async' ),
					'mapping_id'  => (int) ( $_POST['new_mapping_id'] ?? 0 ),
				) );
				wp_safe_redirect( add_query_arg( 'wrm_saved', '1', menu_page_url( 'wrm-routes', false ) ) );
				exit;

			case 'pause':
				WRM_Router::update_route( $slug, array( 'status' => 'paused' ) );
				WRM_Job_Queue::pause_route( $slug );
				wp_safe_redirect( menu_page_url( 'wrm-routes', false ) );
				exit;

			case 'resume':
				WRM_Router::update_route( $slug, array( 'status' => 'active' ) );
				WRM_Job_Queue::resume_route( $slug );
				wp_safe_redirect( menu_page_url( 'wrm-routes', false ) );
				exit;

			case 'delete':
				WRM_Router::delete_route( $slug );
				wp_safe_redirect( add_query_arg( 'wrm_deleted', '1', menu_page_url( 'wrm-routes', false ) ) );
				exit;
		}
	}

	// -------------------------------------------------------------------------
	// CAPTURES PAGE
	// -------------------------------------------------------------------------

	public static function page_captures(): void {
		$slug     = sanitize_title( $_GET['route_slug'] ?? '' );
		$per_page = 30;
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;
		$captures = WRM_Capture::list( array( 'route_slug' => $slug, 'per_page' => $per_page, 'offset' => $offset ) );
		?>
		<div class="wrap">
		<h1><?php esc_html_e( 'Captured Payloads', 'wrm' ); ?></h1>

		<form method="get" style="margin-bottom:12px">
			<input type="hidden" name="page" value="wrm-captures">
			<label><?php esc_html_e( 'Filter by route:', 'wrm' ); ?>
			<select name="route_slug" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( '— All —', 'wrm' ); ?></option>
				<?php foreach ( WRM_Router::get_routes( 200, 0 ) as $r ) : ?>
					<option value="<?php echo esc_attr( $r['slug'] ); ?>" <?php selected( $slug, $r['slug'] ); ?>><?php echo esc_html( $r['label'] ?: $r['slug'] ); ?></option>
				<?php endforeach; ?>
			</select></label>
		</form>

		<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th>ID</th>
			<th><?php esc_html_e( 'Route', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Provider', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Method', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Mapped', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'IP', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Created', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Payload', 'wrm' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $captures ) ) : ?>
			<tr><td colspan="8"><em><?php esc_html_e( 'No captures yet.', 'wrm' ); ?></em></td></tr>
		<?php else : ?>
			<?php foreach ( $captures as $c ) : ?>
			<tr>
				<td><?php echo (int) $c['id']; ?></td>
				<td><code><?php echo esc_html( $c['route_slug'] ); ?></code></td>
				<td><?php echo esc_html( $c['provider'] ); ?></td>
				<td><?php echo esc_html( $c['method'] ); ?></td>
				<td><?php echo $c['mapped'] ? '<span class="wrm-badge wrm-badge-done">✓</span>' : '—'; ?></td>
				<td><?php echo esc_html( $c['source_ip'] ?? '' ); ?></td>
				<td><?php echo esc_html( $c['created_at'] ); ?></td>
				<td><details><summary><?php esc_html_e( 'View payload', 'wrm' ); ?></summary>
					<pre class="wrm-log-data"><?php echo esc_html( $c['payload'] ); ?></pre>
					<?php if ( $c['mapping_log'] ) : ?>
						<pre class="wrm-log-data"><?php esc_html_e( 'Mapping result:', 'wrm' ); ?><?php echo "\n" . esc_html( $c['mapping_log'] ); ?></pre>
					<?php endif; ?>
				</details></td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
		</table>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// JOBS PAGE
	// -------------------------------------------------------------------------

	public static function page_jobs(): void {
		// Handle retry action
		if ( isset( $_POST['wrm_jobs_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wrm_jobs_nonce'] ), 'wrm_jobs_action' ) ) {
			$action = sanitize_key( $_POST['wrm_action'] ?? '' );
			$job_id = (int) ( $_POST['wrm_job_id'] ?? 0 );
			if ( 'retry' === $action && $job_id ) {
				WRM_Job_Queue::requeue( $job_id );
				wp_safe_redirect( menu_page_url( 'wrm-jobs', false ) );
				exit;
			}
		}

		$status   = sanitize_key( $_GET['status'] ?? '' );
		$per_page = 50;
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$jobs     = WRM_Job_Queue::get_jobs( array( 'status' => $status, 'per_page' => $per_page, 'offset' => ( $paged - 1 ) * $per_page ) );
		?>
		<div class="wrap">
		<h1><?php esc_html_e( 'Job Queue', 'wrm' ); ?></h1>

		<ul class="subsubsub">
			<?php foreach ( array( '' => 'All', 'queued' => 'Queued', 'running' => 'Running', 'done' => 'Done', 'failed' => 'Failed', 'dead' => 'Dead', 'paused' => 'Paused' ) as $s => $label ) : ?>
				<li><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wrm-jobs', 'status' => $s ), admin_url( 'admin.php' ) ) ); ?>" <?php echo $status === $s ? 'class="current"' : ''; ?>><?php echo esc_html( $label ); ?></a> |</li>
			<?php endforeach; ?>
		</ul>

		<table class="wp-list-table widefat fixed striped" style="margin-top:8px">
		<thead><tr>
			<th>ID</th>
			<th><?php esc_html_e( 'Route', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Status', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Attempt', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Duration', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Queued', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Finished', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Error', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'wrm' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $jobs ) ) : ?>
			<tr><td colspan="9"><em><?php esc_html_e( 'No jobs.', 'wrm' ); ?></em></td></tr>
		<?php else : ?>
			<?php foreach ( $jobs as $j ) : ?>
			<tr>
				<td><?php echo (int) $j['id']; ?></td>
				<td><code><?php echo esc_html( $j['route_slug'] ); ?></code></td>
				<td><span class="wrm-badge wrm-badge-<?php echo esc_attr( $j['status'] ); ?>"><?php echo esc_html( $j['status'] ); ?></span></td>
				<td><?php echo (int) $j['attempt'] . '/' . (int) $j['max_attempts']; ?></td>
				<td><?php echo $j['duration_ms'] ? esc_html( $j['duration_ms'] . ' ms' ) : '—'; ?></td>
				<td><?php echo esc_html( $j['queued_at'] ); ?></td>
				<td><?php echo $j['finished_at'] ? esc_html( $j['finished_at'] ) : '—'; ?></td>
				<td><?php echo $j['error_message'] ? '<code style="color:#d63638">' . esc_html( $j['error_message'] ) . '</code>' : '—'; ?></td>
				<td>
					<?php if ( in_array( $j['status'], array( 'failed', 'dead' ), true ) ) : ?>
						<form method="post" style="display:inline">
							<?php wp_nonce_field( 'wrm_jobs_action', 'wrm_jobs_nonce' ); ?>
							<input type="hidden" name="wrm_action" value="retry">
							<input type="hidden" name="wrm_job_id" value="<?php echo (int) $j['id']; ?>">
							<button class="button button-small"><?php esc_html_e( 'Retry', 'wrm' ); ?></button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
		</table>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// LOGS PAGE
	// -------------------------------------------------------------------------

	public static function page_logs(): void {
		$level   = sanitize_key( $_GET['level'] ?? '' );
		$context = sanitize_key( $_GET['context'] ?? '' );
		$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$logs    = WRM_Logger::get_logs( array( 'level' => $level, 'context' => $context, 'per_page' => 100, 'offset' => ( $paged - 1 ) * 100 ) );

		// Purge action
		if ( isset( $_POST['wrm_logs_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wrm_logs_nonce'] ), 'wrm_purge_logs' ) ) {
			WRM_Logger::purge( 0 );
			wp_safe_redirect( menu_page_url( 'wrm-logs', false ) );
			exit;
		}
		?>
		<div class="wrap">
		<h1><?php esc_html_e( 'Logs', 'wrm' ); ?></h1>

		<form method="post" style="float:right">
			<?php wp_nonce_field( 'wrm_purge_logs', 'wrm_logs_nonce' ); ?>
			<button type="submit" class="button" onclick="return confirm('Clear all logs?')"><?php esc_html_e( 'Purge all logs', 'wrm' ); ?></button>
		</form>

		<form method="get" style="margin-bottom:12px">
			<input type="hidden" name="page" value="wrm-logs">
			<select name="level" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( '— All levels —', 'wrm' ); ?></option>
				<?php foreach ( array( 'debug', 'info', 'warning', 'error', 'exception' ) as $l ) : ?>
					<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $level, $l ); ?>><?php echo esc_html( ucfirst( $l ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="context" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( '— All contexts —', 'wrm' ); ?></option>
				<?php foreach ( array( 'mapper', 'router', 'queue', 'provider', 'admin' ) as $c ) : ?>
					<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $context, $c ); ?>><?php echo esc_html( $c ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>

		<table class="wp-list-table widefat fixed striped wrm-logs-table">
		<thead><tr>
			<th>ID</th>
			<th><?php esc_html_e( 'Level', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Context', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Ref', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Message', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Data', 'wrm' ); ?></th>
			<th><?php esc_html_e( 'Time', 'wrm' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $logs ) ) : ?>
			<tr><td colspan="7"><em><?php esc_html_e( 'No logs.', 'wrm' ); ?></em></td></tr>
		<?php else : ?>
			<?php foreach ( $logs as $log ) : ?>
			<tr class="wrm-log-<?php echo esc_attr( $log['level'] ); ?>">
				<td><?php echo (int) $log['id']; ?></td>
				<td><span class="wrm-badge wrm-log-<?php echo esc_attr( $log['level'] ); ?>"><?php echo esc_html( strtoupper( $log['level'] ) ); ?></span></td>
				<td><?php echo esc_html( $log['context'] ); ?></td>
				<td><?php echo $log['ref_id'] ? (int) $log['ref_id'] : '—'; ?></td>
				<td><?php echo esc_html( $log['message'] ); ?></td>
				<td><?php echo $log['data'] ? '<details><summary>…</summary><pre class="wrm-log-data">' . esc_html( $log['data'] ) . '</pre></details>' : '—'; ?></td>
				<td style="white-space:nowrap"><?php echo esc_html( $log['created_at'] ); ?></td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
		</table>
		</div>
		<?php
	}
}
