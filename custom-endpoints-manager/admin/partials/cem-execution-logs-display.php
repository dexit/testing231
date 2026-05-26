<?php
/**
 * Execution Logs admin view.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/admin/partials
 */

if ( ! current_user_can( 'manage_options' ) ) {
    return;
}

// Handle retry / requeue action
if (
    isset( $_GET['cem_action'], $_GET['job_id'], $_GET['_wpnonce'] ) &&
    wp_verify_nonce( sanitize_text_field( $_GET['_wpnonce'] ), 'cem_job_action' )
) {
    $job_id = (int) $_GET['job_id'];
    $action = sanitize_key( $_GET['cem_action'] );

    if ( 'requeue' === $action ) {
        CEM_Execution_Logger::requeue( $job_id );
    }

    wp_redirect( admin_url( 'options-general.php?page=custom-endpoints-manager&tab=logs&message=job_updated' ) );
    exit;
}

// Pagination + filter
$status_filter = isset( $_GET['log_status'] ) ? sanitize_key( $_GET['log_status'] ) : '';
$slug_filter   = isset( $_GET['log_slug'] )   ? sanitize_text_field( $_GET['log_slug'] ) : '';
$per_page      = 30;
$current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$offset        = ( $current_page - 1 ) * $per_page;

$logs  = CEM_Execution_Logger::get_logs( array(
    'per_page'      => $per_page,
    'offset'        => $offset,
    'status'        => $status_filter,
    'endpoint_slug' => $slug_filter,
) );
$total = CEM_Execution_Logger::count_logs( $status_filter );
$pages = max( 1, (int) ceil( $total / $per_page ) );

// Status counts for filter bar
$status_counts = array();
foreach ( array( '', 'queued', 'running', 'done', 'failed', 'dead' ) as $s ) {
    $status_counts[ $s ] = CEM_Execution_Logger::count_logs( $s );
}

$status_labels = array(
    ''        => __( 'All',      'custom-endpoints-manager' ),
    'queued'  => __( 'Queued',   'custom-endpoints-manager' ),
    'running' => __( 'Running',  'custom-endpoints-manager' ),
    'done'    => __( 'Done',     'custom-endpoints-manager' ),
    'failed'  => __( 'Failed',   'custom-endpoints-manager' ),
    'dead'    => __( 'Dead',     'custom-endpoints-manager' ),
);

$badge_colors = array(
    'queued'  => '#646970',
    'running' => '#0073aa',
    'done'    => '#00a32a',
    'failed'  => '#d63638',
    'dead'    => '#8c1515',
);

if ( isset( $_GET['message'] ) && $_GET['message'] === 'job_updated' ) : ?>
    <div class="notice notice-success is-dismissible">
        <p><?php esc_html_e( 'Job requeued successfully.', 'custom-endpoints-manager' ); ?></p>
    </div>
<?php endif; ?>

<h2><?php esc_html_e( 'Execution Logs', 'custom-endpoints-manager' ); ?></h2>
<p><?php esc_html_e( 'Every sync and async endpoint execution is logged here. Use Requeue to retry failed or dead async jobs.', 'custom-endpoints-manager' ); ?></p>

<ul class="subsubsub" style="margin-bottom:10px">
    <?php foreach ( $status_labels as $s => $label ) :
        $url     = add_query_arg( array( 'page' => 'custom-endpoints-manager', 'tab' => 'logs', 'log_status' => $s ), admin_url( 'options-general.php' ) );
        $current = ( $status_filter === $s ) ? ' class="current"' : '';
        $count   = $status_counts[ $s ];
    ?>
        <li><a href="<?php echo esc_url( $url ); ?>"<?php echo $current; ?>><?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( $count ); ?>)</span></a> |</li>
    <?php endforeach; ?>
</ul>

<table class="widefat fixed striped">
    <thead>
        <tr>
            <th style="width:50px"><?php esc_html_e( 'ID', 'custom-endpoints-manager' ); ?></th>
            <th style="width:80px"><?php esc_html_e( 'Mode', 'custom-endpoints-manager' ); ?></th>
            <th style="width:90px"><?php esc_html_e( 'Status', 'custom-endpoints-manager' ); ?></th>
            <th><?php esc_html_e( 'Endpoint', 'custom-endpoints-manager' ); ?></th>
            <th style="width:60px"><?php esc_html_e( 'Method', 'custom-endpoints-manager' ); ?></th>
            <th style="width:80px"><?php esc_html_e( 'Attempts', 'custom-endpoints-manager' ); ?></th>
            <th style="width:90px"><?php esc_html_e( 'Duration', 'custom-endpoints-manager' ); ?></th>
            <th style="width:140px"><?php esc_html_e( 'Finished', 'custom-endpoints-manager' ); ?></th>
            <th style="width:120px"><?php esc_html_e( 'Actions', 'custom-endpoints-manager' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if ( empty( $logs ) ) : ?>
            <tr>
                <td colspan="9">
                    <?php esc_html_e( 'No execution logs found.', 'custom-endpoints-manager' ); ?>
                </td>
            </tr>
        <?php else : ?>
            <?php foreach ( $logs as $log ) :
                $color         = $badge_colors[ $log->status ] ?? '#646970';
                $badge_style   = "display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;background:{$color};font-size:11px;font-weight:600";
                $duration_disp = $log->duration_ms ? number_format( $log->duration_ms ) . ' ms' : '—';
                $finished_disp = $log->finished_at ? $log->finished_at : '—';
                $can_requeue   = in_array( $log->status, array( 'failed', 'dead' ), true ) && $log->run_mode === 'async';
                $retry_url     = wp_nonce_url(
                    add_query_arg(
                        array( 'page' => 'custom-endpoints-manager', 'tab' => 'logs', 'cem_action' => 'requeue', 'job_id' => $log->id ),
                        admin_url( 'options-general.php' )
                    ),
                    'cem_job_action'
                );

                $payload_json = $log->payload ? json_decode( $log->payload, true ) : array();
                $result_json  = $log->result  ? json_decode( $log->result, true )  : null;
                $row_id       = 'cem-log-row-' . $log->id;
            ?>
            <tr>
                <td><?php echo esc_html( $log->id ); ?></td>
                <td><?php echo esc_html( strtoupper( $log->run_mode ) ); ?></td>
                <td><span style="<?php echo esc_attr( $badge_style ); ?>"><?php echo esc_html( strtoupper( $log->status ) ); ?></span></td>
                <td>
                    <code>/cem/v1/<?php echo esc_html( $log->endpoint_slug ); ?></code>
                    <?php if ( $log->error_message ) : ?>
                        <br><small style="color:#d63638"><?php echo esc_html( wp_trim_words( $log->error_message, 10 ) ); ?></small>
                    <?php endif; ?>
                    <a href="#<?php echo esc_attr( $row_id ); ?>" onclick="document.getElementById('<?php echo esc_attr( $row_id ); ?>').style.display=document.getElementById('<?php echo esc_attr( $row_id ); ?>').style.display==='none'?'table-row':'none';return false;" style="font-size:11px;margin-left:6px">
                        <?php esc_html_e( '[details]', 'custom-endpoints-manager' ); ?>
                    </a>
                </td>
                <td><?php echo esc_html( $log->http_method ); ?></td>
                <td><?php echo esc_html( $log->attempt . '/' . $log->max_attempts ); ?></td>
                <td><?php echo esc_html( $duration_disp ); ?></td>
                <td><small><?php echo esc_html( $finished_disp ); ?></small></td>
                <td>
                    <?php if ( $can_requeue ) : ?>
                        <a href="<?php echo esc_url( $retry_url ); ?>" class="button button-small">
                            <?php esc_html_e( 'Requeue', 'custom-endpoints-manager' ); ?>
                        </a>
                    <?php elseif ( $log->run_mode === 'async' && $log->status === 'queued' ) : ?>
                        <span style="color:#646970;font-size:12px"><?php esc_html_e( 'Pending…', 'custom-endpoints-manager' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr id="<?php echo esc_attr( $row_id ); ?>" style="display:none;background:#f9f9f9">
                <td colspan="9" style="padding:12px 20px">
                    <strong><?php esc_html_e( 'Queued:', 'custom-endpoints-manager' ); ?></strong>
                    <?php echo esc_html( $log->queued_at ); ?>
                    <?php if ( $log->next_retry_at ) : ?>
                        &nbsp;|&nbsp;<strong><?php esc_html_e( 'Next retry:', 'custom-endpoints-manager' ); ?></strong>
                        <?php echo esc_html( $log->next_retry_at ); ?>
                    <?php endif; ?>
                    <br><strong><?php esc_html_e( 'Payload:', 'custom-endpoints-manager' ); ?></strong>
                    <pre style="max-height:120px;overflow:auto;background:#fff;padding:8px;margin:4px 0;font-size:11px"><?php echo esc_html( wp_json_encode( $payload_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
                    <?php if ( $result_json !== null ) : ?>
                        <strong><?php esc_html_e( 'Result:', 'custom-endpoints-manager' ); ?></strong>
                        <pre style="max-height:120px;overflow:auto;background:#fff;padding:8px;margin:4px 0;font-size:11px"><?php echo esc_html( wp_json_encode( $result_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
                    <?php endif; ?>
                    <?php if ( $log->error_message ) : ?>
                        <strong style="color:#d63638"><?php esc_html_e( 'Error:', 'custom-endpoints-manager' ); ?></strong>
                        <pre style="background:#fff3f3;padding:8px;margin:4px 0;font-size:11px;color:#d63638"><?php echo esc_html( $log->error_message ); ?></pre>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php if ( $pages > 1 ) : ?>
    <div class="tablenav bottom" style="margin-top:10px">
        <div class="tablenav-pages">
            <?php
            $pagination_args = array(
                'base'    => add_query_arg( 'paged', '%#%' ),
                'format'  => '',
                'current' => $current_page,
                'total'   => $pages,
            );
            echo paginate_links( $pagination_args );
            ?>
        </div>
    </div>
<?php endif; ?>
