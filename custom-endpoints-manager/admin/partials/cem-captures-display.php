<?php
/**
 * Captures & Mapping tab display.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/admin/partials
 */

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

// Determine active endpoint from query string.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display.
$active_slug = isset( $_GET['cap_slug'] ) ? sanitize_title( wp_unslash( $_GET['cap_slug'] ) ) : '';

// Load endpoints that have the capture flag enabled.
$all_endpoints   = get_option( 'cem_custom_endpoints', array() );
$capture_enabled = array();
if ( is_array( $all_endpoints ) ) {
	foreach ( $all_endpoints as $ep ) {
		if ( ! empty( $ep['capture'] ) ) {
			$capture_enabled[] = sanitize_title( $ep['slug'] );
		}
	}
}

// Load capture counts.
$counts_by_slug = CEM_Data_Capture::count_by_endpoint();
$sidebar_slugs  = array_unique( array_merge( $capture_enabled, array_keys( $counts_by_slug ) ) );
sort( $sidebar_slugs );

if ( ! $active_slug && ! empty( $sidebar_slugs ) ) {
	$active_slug = $sidebar_slugs[0];
}

// Load captures for active endpoint.
$captures = $active_slug
	? CEM_Data_Capture::get_captures( array( 'endpoint_slug' => $active_slug, 'per_page' => 30 ) )
	: array();

// Load mapping config for active endpoint.
$all_mappings    = get_option( 'cem_endpoint_mappings', array() );
$mapping         = is_array( $all_mappings ) && $active_slug && isset( $all_mappings[ $active_slug ] )
	? $all_mappings[ $active_slug ]
	: array();

$map_cpt          = sanitize_key( $mapping['cpt'] ?? 'post' );
$map_trigger      = sanitize_text_field( $mapping['trigger'] ?? 'manual' );
$map_update       = ! empty( $mapping['update_existing'] );
$map_match_source = sanitize_text_field( $mapping['match_source'] ?? '' );
$map_match_field  = sanitize_text_field( $mapping['match_field'] ?? '' );
$map_fields       = is_array( $mapping['fields'] ?? null ) ? $mapping['fields'] : array();
$map_chains       = is_array( $mapping['chains'] ?? null ) ? $mapping['chains'] : array();

// Detected paths from the latest capture.
$detected_paths = $active_slug ? CEM_Data_Capture::get_detected_paths( $active_slug ) : array();

// Public CPTs for the target selector.
$public_cpts = get_post_types( array( 'public' => true ), 'objects' );

// Nonce for saving mapping.
$mapping_nonce = wp_create_nonce( 'cem_save_mapping' );

// REST base URL and WP REST nonce (for JS).
$rest_base  = esc_url( rest_url( 'cem/v1/' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );
?>

<style>
/* ── Captures tab layout ───────────────────────────────────────────────── */
.cem-cap-wrap { display:flex;gap:20px;margin-top:16px;align-items:flex-start }
.cem-cap-sidebar { flex:0 0 200px;min-width:160px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden }
.cem-cap-sidebar h3 { margin:0;padding:10px 12px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#646970;background:#f6f7f7;border-bottom:1px solid #c3c4c7 }
.cem-ep-list { margin:0;padding:4px 0;list-style:none }
.cem-ep-item { display:flex;align-items:center;justify-content:space-between;padding:7px 12px;font-size:13px;cursor:pointer;color:#1d2327;transition:background .12s }
.cem-ep-item:hover { background:#f0f6fc }
.cem-ep-item.is-active { background:#e8f0fe;font-weight:600;color:#1a6eb5 }
.cem-ep-item.is-empty { color:#646970;cursor:default }
.cem-ep-count { display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:18px;padding:0 5px;font-size:10px;font-weight:700;background:#646970;color:#fff;border-radius:9px }
.cem-ep-item.is-active .cem-ep-count { background:#1a6eb5 }
.cem-ep-capture-on { display:inline-block;width:6px;height:6px;border-radius:50%;background:#00a32a;margin-left:5px;flex-shrink:0;title:"Capture enabled" }

/* ── Main area ─────────────────────────────────────────────────────────── */
.cem-cap-main { flex:1;min-width:0 }
.cem-empty-state { text-align:center;padding:48px 24px;background:#fff;border:1px dashed #c3c4c7;border-radius:4px;color:#646970 }
.cem-empty-state .dashicons { font-size:48px;height:48px;width:48px;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;color:#c3c4c7 }
.cem-empty-state h3 { margin-top:0;color:#1d2327 }

/* ── Sub-tabs ──────────────────────────────────────────────────────────── */
.cem-sub-tabs { display:flex;gap:0;border-bottom:1px solid #c3c4c7;margin-bottom:0 }
.cem-sub-tab { border:1px solid transparent;border-bottom:none;padding:8px 16px;font-size:13px;background:none;cursor:pointer;color:#646970;margin-bottom:-1px;border-radius:3px 3px 0 0;transition:background .12s }
.cem-sub-tab:hover { background:#f6f7f7;color:#1d2327 }
.cem-sub-tab.is-active { background:#fff;color:#1d2327;border-color:#c3c4c7;font-weight:600 }
.cem-subtab-panel { background:#fff;border:1px solid #c3c4c7;border-top:none;border-radius:0 4px 4px 4px }

/* ── Capture log ───────────────────────────────────────────────────────── */
.cem-cap-toolbar { display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #f0f0f1 }
.cem-cap-toolbar h3 { margin:0;font-size:13px }
.cem-cap-table th, .cem-cap-table td { padding:8px 10px;vertical-align:top }
.cem-cap-table th { font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#646970;background:#f6f7f7 }
.cem-mapped-yes { display:inline-block;padding:1px 7px;background:#d1e7dd;color:#0a3622;border-radius:3px;font-size:10px;font-weight:700 }
.cem-mapped-no  { display:inline-block;padding:1px 7px;background:#f8d7da;color:#58151c;border-radius:3px;font-size:10px;font-weight:700 }
.cem-expand-btn, .cem-apply-btn, .cem-del-btn { font-size:11px;height:22px;line-height:20px;padding:0 7px }
.cem-payload-row td { background:#1d2025;border-top:2px solid #2271b1 }
.cem-payload-json { color:#abb2bf;font-size:11px;margin:0;padding:10px;overflow:auto;max-height:280px;white-space:pre;word-break:break-word;background:none }
.cem-json-key { color:#e06c75 }
.cem-json-string { color:#98c379 }
.cem-json-number { color:#d19a66 }
.cem-json-bool { color:#c678dd }
.cem-json-null { color:#56b6c2 }

/* ── Field mapper ──────────────────────────────────────────────────────── */
.cem-mapping-form-inner { padding:20px }
.cem-mapping-section { margin-bottom:24px }
.cem-mapping-section h4 { margin:0 0 10px;font-size:13px;font-weight:600;padding-bottom:6px;border-bottom:1px solid #f0f0f1 }
.cem-mapping-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:16px }
.cem-mapping-grid label { font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#1d2327 }
.cem-mapping-grid input, .cem-mapping-grid select { width:100%;box-sizing:border-box;font-size:12px }

/* Visual mapper table */
.cem-mapper-table { width:100%;border-collapse:collapse;font-size:12px }
.cem-mapper-table th { text-align:left;padding:6px 8px;background:#f6f7f7;border:1px solid #c3c4c7;font-weight:600;color:#646970;font-size:11px;text-transform:uppercase }
.cem-mapper-table td { padding:5px 8px;border:1px solid #e0e0e0;vertical-align:middle }
.cem-mapper-table td:nth-child(2) { text-align:center;color:#2271b1;font-size:16px;width:28px;padding:4px }
.cem-source-input, .cem-target-input { width:100%;box-sizing:border-box;font-size:12px }
.cem-mapper-add-row { margin-top:8px }
.cem-detect-paths-btn { margin-left:6px;font-size:11px }

/* Chain builder */
.cem-chain-list { display:flex;flex-direction:column;gap:8px;margin-bottom:8px }
.cem-chain-item { display:flex;gap:8px;align-items:center;background:#f6f7f7;padding:8px 10px;border-radius:3px;border:1px solid #e0e0e0 }
.cem-chain-item select { font-size:12px;flex-shrink:0;width:100px }
.cem-chain-item input { font-size:12px;flex:1;min-width:0 }
.cem-chain-item .cem-chain-method { width:70px;flex-shrink:0 }
.cem-chain-remove { flex-shrink:0;color:#d63638;cursor:pointer;background:none;border:none;padding:2px 6px;font-size:16px;line-height:1 }

/* Status bar */
.cem-save-row { display:flex;align-items:center;gap:12px;padding-top:16px;border-top:1px solid #f0f0f1 }
#cem-mapping-status { font-size:12px;color:#00a32a;display:none }
</style>

<div class="cem-cap-wrap">

	<!-- ── Sidebar ──────────────────────────────────────────────────── -->
	<div class="cem-cap-sidebar">
		<h3><?php esc_html_e( 'Endpoints', 'custom-endpoints-manager' ); ?></h3>
		<ul class="cem-ep-list">
			<?php if ( empty( $sidebar_slugs ) ) : ?>
				<li class="cem-ep-item cem-ep-empty" style="color:#646970;font-size:12px;padding:12px">
					<?php esc_html_e( 'Enable "Capture" on an endpoint to see data here.', 'custom-endpoints-manager' ); ?>
				</li>
			<?php else : ?>
				<?php foreach ( $sidebar_slugs as $slug ) : ?>
					<?php
					$count    = $counts_by_slug[ $slug ] ?? 0;
					$is_cap   = in_array( $slug, $capture_enabled, true );
					$is_act   = $slug === $active_slug;
					$tab_url  = admin_url( 'options-general.php?page=custom-endpoints-manager&tab=captures&cap_slug=' . rawurlencode( $slug ) );
					?>
					<li class="cem-ep-item<?php echo $is_act ? ' is-active' : ''; ?>"
						data-slug="<?php echo esc_attr( $slug ); ?>"
						onclick="location.href='<?php echo esc_url( $tab_url ); ?>'">
						<span>
							<?php echo esc_html( $slug ); ?>
							<?php if ( $is_cap ) : ?>
								<span class="cem-ep-capture-on" title="<?php esc_attr_e( 'Capture enabled', 'custom-endpoints-manager' ); ?>"></span>
							<?php endif; ?>
						</span>
						<?php if ( $count > 0 ) : ?>
							<span class="cem-ep-count"><?php echo esc_html( $count ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			<?php endif; ?>
		</ul>
	</div><!-- .cem-cap-sidebar -->

	<!-- ── Main content ─────────────────────────────────────────────── -->
	<div class="cem-cap-main">
		<?php if ( ! $active_slug ) : ?>
			<div class="cem-empty-state">
				<span class="dashicons dashicons-database-import"></span>
				<h3><?php esc_html_e( 'No endpoint selected', 'custom-endpoints-manager' ); ?></h3>
				<p><?php esc_html_e( 'Enable "Capture Incoming Data" on an endpoint card, then send requests to it. Captured payloads will appear here for visual mapping to CPT fields.', 'custom-endpoints-manager' ); ?></p>
			</div>
		<?php else : ?>

			<!-- Sub-tabs -->
			<nav class="cem-sub-tabs">
				<button type="button" class="cem-sub-tab is-active" data-panel="log">
					<?php esc_html_e( 'Capture Log', 'custom-endpoints-manager' ); ?>
					<?php $cnt = CEM_Data_Capture::count_captures( $active_slug ); ?>
					<?php if ( $cnt > 0 ) : ?>
						<span style="margin-left:5px;background:#1a6eb5;color:#fff;border-radius:8px;padding:0 6px;font-size:10px;font-weight:700"><?php echo esc_html( $cnt ); ?></span>
					<?php endif; ?>
				</button>
				<button type="button" class="cem-sub-tab" data-panel="map">
					<?php esc_html_e( 'Field Mapping', 'custom-endpoints-manager' ); ?>
					<?php if ( ! empty( $mapping ) ) : ?>
						<span style="margin-left:5px;color:#00a32a;font-size:11px">&#10003;</span>
					<?php endif; ?>
				</button>
			</nav>

			<!-- ── Capture Log panel ──────────────────────────────── -->
			<div class="cem-subtab-panel" data-panel="log">
				<div class="cem-cap-toolbar">
					<h3>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: endpoint slug */
								__( 'Captures for: %s', 'custom-endpoints-manager' ),
								$active_slug
							)
						);
						?>
					</h3>
					<div style="display:flex;gap:6px;align-items:center">
						<button type="button" id="cem-apply-all-btn" class="button"
							data-slug="<?php echo esc_attr( $active_slug ); ?>"
							<?php echo empty( $mapping ) ? 'disabled title="Configure a mapping first"' : ''; ?>>
							<?php esc_html_e( '&#9654; Apply Mapping to All', 'custom-endpoints-manager' ); ?>
						</button>
						<button type="button" id="cem-clear-all-btn" class="button button-link-delete"
							data-slug="<?php echo esc_attr( $active_slug ); ?>">
							<?php esc_html_e( 'Clear All', 'custom-endpoints-manager' ); ?>
						</button>
					</div>
				</div>

				<?php if ( empty( $captures ) ) : ?>
					<div class="cem-empty-state" style="border:none;border-top:1px dashed #c3c4c7;border-radius:0">
						<span class="dashicons dashicons-database"></span>
						<h3><?php esc_html_e( 'No captures yet', 'custom-endpoints-manager' ); ?></h3>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: endpoint route */
									__( 'Send a request to /wp-json/cem/v1/%s and the payload will appear here.', 'custom-endpoints-manager' ),
									$active_slug
								)
							);
							?>
						</p>
					</div>
				<?php else : ?>
					<table class="widefat fixed cem-cap-table">
						<thead>
							<tr>
								<th style="width:50px">#</th>
								<th style="width:130px"><?php esc_html_e( 'Time (UTC)', 'custom-endpoints-manager' ); ?></th>
								<th style="width:55px"><?php esc_html_e( 'Method', 'custom-endpoints-manager' ); ?></th>
								<th style="width:110px"><?php esc_html_e( 'IP', 'custom-endpoints-manager' ); ?></th>
								<th style="width:70px"><?php esc_html_e( 'Status', 'custom-endpoints-manager' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'custom-endpoints-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="cem-capture-tbody">
							<?php foreach ( $captures as $cap ) : ?>
								<?php
								$cid     = (int) $cap['id'];
								$is_map  = ! empty( $cap['mapped'] );
								$payload = json_decode( $cap['payload'], true ) ?: array();
								?>
								<tr class="cem-cap-row" id="cem-cap-<?php echo esc_attr( $cid ); ?>">
									<td style="color:#646970;font-size:11px"><?php echo esc_html( $cid ); ?></td>
									<td style="font-size:11px;font-family:monospace"><?php echo esc_html( $cap['created_at'] ); ?></td>
									<td>
										<span class="cem-hdr-method cem-hdr-method--<?php echo esc_attr( strtolower( $cap['method'] ) ); ?>" style="font-size:10px">
											<?php echo esc_html( $cap['method'] ); ?>
										</span>
									</td>
									<td style="font-size:11px;color:#646970"><?php echo esc_html( $cap['source_ip'] ?? '' ); ?></td>
									<td>
										<?php if ( $is_map ) : ?>
											<span class="cem-mapped-yes"><?php esc_html_e( 'Mapped', 'custom-endpoints-manager' ); ?></span>
										<?php else : ?>
											<span class="cem-mapped-no"><?php esc_html_e( 'New', 'custom-endpoints-manager' ); ?></span>
										<?php endif; ?>
									</td>
									<td style="white-space:nowrap">
										<button type="button"
											class="button cem-expand-btn"
											data-cap-id="<?php echo esc_attr( $cid ); ?>"
											aria-expanded="false">
											<?php esc_html_e( 'View', 'custom-endpoints-manager' ); ?>
										</button>
										<?php if ( ! empty( $mapping ) ) : ?>
											<button type="button"
												class="button cem-apply-btn"
												data-cap-id="<?php echo esc_attr( $cid ); ?>"
												style="margin-left:4px">
												<?php esc_html_e( 'Apply', 'custom-endpoints-manager' ); ?>
											</button>
										<?php endif; ?>
										<button type="button"
											class="button-link cem-del-btn"
											data-cap-id="<?php echo esc_attr( $cid ); ?>"
											style="color:#d63638;margin-left:6px"
											title="<?php esc_attr_e( 'Delete capture', 'custom-endpoints-manager' ); ?>">
											&#x2715;
										</button>
									</td>
								</tr>
								<tr class="cem-payload-row" id="cem-payload-<?php echo esc_attr( $cid ); ?>" style="display:none">
									<td colspan="6">
										<div style="display:flex;gap:16px;padding:6px 0">
											<!-- JSON tree viewer -->
											<div style="flex:1;min-width:0">
												<div style="padding:6px 10px;background:#2c313a;font-size:10px;color:#abb2bf;font-family:monospace;border-radius:3px 3px 0 0">payload.json</div>
												<pre class="cem-payload-json" id="cem-json-<?php echo esc_attr( $cid ); ?>"><?php echo esc_html( wp_json_encode( $payload, JSON_PRETTY_PRINT ) ); ?></pre>
											</div>
											<!-- Mapping log (if mapped) -->
											<?php if ( $is_map && ! empty( $cap['mapping_log'] ) ) : ?>
												<?php $mlog = json_decode( $cap['mapping_log'], true ) ?: array(); ?>
												<div style="flex:0 0 200px;background:#f6f7f7;border-radius:3px;padding:10px;font-size:11px">
													<strong style="display:block;margin-bottom:6px"><?php esc_html_e( 'Mapping result', 'custom-endpoints-manager' ); ?></strong>
													<?php if ( isset( $mlog['post_id'] ) ) : ?>
														<div>
															<?php
															echo esc_html(
																sprintf(
																	/* translators: 1: action (created/updated), 2: post ID */
																	__( '%1$s post #%2$s', 'custom-endpoints-manager' ),
																	ucfirst( $mlog['action'] ?? 'created' ),
																	$mlog['post_id']
																)
															);
															?>
															<a href="<?php echo esc_url( get_edit_post_link( (int) $mlog['post_id'] ) ); ?>" target="_blank" style="margin-left:4px">&#x270F;</a>
														</div>
													<?php endif; ?>
												</div>
											<?php endif; ?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div><!-- capture log panel -->

			<!-- ── Field Mapping panel ───────────────────────────── -->
			<div class="cem-subtab-panel" data-panel="map" style="display:none">
				<form id="cem-mapping-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cem_save_mapping" />
					<input type="hidden" name="cem_mapping_nonce" value="<?php echo esc_attr( $mapping_nonce ); ?>" />
					<input type="hidden" name="cem_mapping_slug" value="<?php echo esc_attr( $active_slug ); ?>" />

					<div class="cem-mapping-form-inner">

						<!-- Target CPT + trigger -->
						<div class="cem-mapping-section">
							<h4><?php esc_html_e( 'Mapping Target', 'custom-endpoints-manager' ); ?></h4>
							<div class="cem-mapping-grid">
								<div>
									<label for="cem-map-cpt"><?php esc_html_e( 'Target CPT', 'custom-endpoints-manager' ); ?></label>
									<select id="cem-map-cpt" name="cem_mapping[cpt]">
										<?php foreach ( $public_cpts as $pt ) : ?>
											<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $map_cpt, $pt->name ); ?>>
												<?php echo esc_html( $pt->label ); ?> (<?php echo esc_html( $pt->name ); ?>)
											</option>
										<?php endforeach; ?>
									</select>
								</div>
								<div>
									<label for="cem-map-trigger"><?php esc_html_e( 'Trigger', 'custom-endpoints-manager' ); ?></label>
									<select id="cem-map-trigger" name="cem_mapping[trigger]">
										<option value="manual" <?php selected( $map_trigger, 'manual' ); ?>><?php esc_html_e( 'Manual (click Apply)', 'custom-endpoints-manager' ); ?></option>
										<option value="auto" <?php selected( $map_trigger, 'auto' ); ?>><?php esc_html_e( 'Auto (on capture)', 'custom-endpoints-manager' ); ?></option>
									</select>
								</div>
								<div>
									<label style="display:flex;align-items:center;gap:6px;margin-top:20px">
										<input type="checkbox" name="cem_mapping[update_existing]" value="1" <?php checked( $map_update ); ?> id="cem-map-update" />
										<?php esc_html_e( 'Update existing post', 'custom-endpoints-manager' ); ?>
									</label>
								</div>
							</div>
							<!-- Match field (visible when update_existing is on) -->
							<div id="cem-match-row" style="<?php echo $map_update ? '' : 'display:none'; ?>display:flex;gap:12px;background:#fff3cd;border-left:3px solid #ffc107;padding:10px 12px;border-radius:2px;font-size:12px">
								<div style="flex:1">
									<label style="font-weight:600;display:block;margin-bottom:3px"><?php esc_html_e( 'Source path to match', 'custom-endpoints-manager' ); ?></label>
									<input type="text"
										name="cem_mapping[match_source]"
										id="cem-match-source"
										value="<?php echo esc_attr( $map_match_source ); ?>"
										list="cem-paths-datalist"
										placeholder="body.id"
										style="width:100%;box-sizing:border-box;font-size:12px" />
								</div>
								<div style="flex:1">
									<label style="font-weight:600;display:block;margin-bottom:3px"><?php esc_html_e( 'Match against CPT field', 'custom-endpoints-manager' ); ?></label>
									<input type="text"
										name="cem_mapping[match_field]"
										id="cem-match-field"
										value="<?php echo esc_attr( $map_match_field ); ?>"
										placeholder="meta:external_id"
										style="width:100%;box-sizing:border-box;font-size:12px" />
								</div>
							</div>
						</div><!-- .cem-mapping-section (target) -->

						<!-- Visual field mapper -->
						<div class="cem-mapping-section">
							<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
								<h4 style="margin:0"><?php esc_html_e( 'Field Mapping', 'custom-endpoints-manager' ); ?></h4>
								<button type="button" id="cem-detect-paths" class="button button-small cem-detect-paths-btn"
									data-slug="<?php echo esc_attr( $active_slug ); ?>"
									data-rest="<?php echo esc_attr( rest_url( 'cem/v1/captures/paths' ) ); ?>"
									data-nonce="<?php echo esc_attr( $rest_nonce ); ?>">
									&#128270; <?php esc_html_e( 'Detect Fields', 'custom-endpoints-manager' ); ?>
								</button>
							</div>

							<!-- Datalist populated by detected paths -->
							<datalist id="cem-paths-datalist">
								<?php foreach ( $detected_paths as $path ) : ?>
									<option value="<?php echo esc_attr( $path ); ?>">
								<?php endforeach; ?>
							</datalist>
							<datalist id="cem-targets-datalist">
								<option value="post_title">
								<option value="post_content">
								<option value="post_excerpt">
								<option value="post_status">
								<option value="post_name">
								<option value="post_date">
								<option value="post_author">
								<option value="meta:">
								<option value="taxonomy:category">
								<option value="taxonomy:post_tag">
							</datalist>

							<table class="cem-mapper-table" id="cem-mapper-table">
								<thead>
									<tr>
										<th style="width:42%"><?php esc_html_e( 'Source (payload path)', 'custom-endpoints-manager' ); ?></th>
										<th style="width:28px">&#8594;</th>
										<th style="width:42%"><?php esc_html_e( 'Target (CPT field)', 'custom-endpoints-manager' ); ?></th>
										<th style="width:32px"></th>
									</tr>
								</thead>
								<tbody id="cem-mapper-tbody">
									<?php if ( empty( $map_fields ) ) : ?>
										<?php
										// Render at least one blank row.
										$map_fields = array( array( 'source' => '', 'target' => '' ) );
										?>
									<?php endif; ?>
									<?php foreach ( $map_fields as $ridx => $rule ) : ?>
										<tr class="cem-mapper-row">
											<td>
												<input type="text"
													class="cem-source-input"
													name="cem_mapping[fields][<?php echo esc_attr( $ridx ); ?>][source]"
													value="<?php echo esc_attr( $rule['source'] ?? '' ); ?>"
													list="cem-paths-datalist"
													placeholder="<?php esc_attr_e( 'body.field_name', 'custom-endpoints-manager' ); ?>" />
											</td>
											<td>&#8594;</td>
											<td>
												<input type="text"
													class="cem-target-input"
													name="cem_mapping[fields][<?php echo esc_attr( $ridx ); ?>][target]"
													value="<?php echo esc_attr( $rule['target'] ?? '' ); ?>"
													list="cem-targets-datalist"
													placeholder="<?php esc_attr_e( 'post_title or meta:key', 'custom-endpoints-manager' ); ?>" />
											</td>
											<td>
												<button type="button" class="cem-mapper-remove button-link" style="color:#d63638;font-size:16px" title="<?php esc_attr_e( 'Remove row', 'custom-endpoints-manager' ); ?>">&#x2715;</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<div class="cem-mapper-add-row">
								<button type="button" id="cem-add-mapping-row" class="button button-small">
									<?php esc_html_e( '+ Add Field', 'custom-endpoints-manager' ); ?>
								</button>
							</div>
						</div><!-- .cem-mapping-section (fields) -->

						<!-- Action chains -->
						<div class="cem-mapping-section">
							<h4><?php esc_html_e( 'Action Chains', 'custom-endpoints-manager' ); ?></h4>
							<p style="font-size:12px;color:#646970;margin-top:0"><?php esc_html_e( 'After a post is created/updated, run these additional actions in sequence.', 'custom-endpoints-manager' ); ?></p>
							<div class="cem-chain-list" id="cem-chain-list">
								<?php foreach ( $map_chains as $cidx => $ch ) : ?>
									<div class="cem-chain-item" data-idx="<?php echo esc_attr( $cidx ); ?>">
										<select name="cem_mapping[chains][<?php echo esc_attr( $cidx ); ?>][type]" class="cem-chain-type-sel">
											<option value="webhook" <?php selected( $ch['type'] ?? '', 'webhook' ); ?>><?php esc_html_e( 'Webhook', 'custom-endpoints-manager' ); ?></option>
										</select>
										<select name="cem_mapping[chains][<?php echo esc_attr( $cidx ); ?>][method]" class="cem-chain-method">
											<option value="POST" <?php selected( $ch['method'] ?? 'POST', 'POST' ); ?>>POST</option>
											<option value="GET"  <?php selected( $ch['method'] ?? 'POST', 'GET' ); ?>>GET</option>
											<option value="PUT"  <?php selected( $ch['method'] ?? 'POST', 'PUT' ); ?>>PUT</option>
										</select>
										<input type="url"
											name="cem_mapping[chains][<?php echo esc_attr( $cidx ); ?>][url]"
											value="<?php echo esc_attr( $ch['url'] ?? '' ); ?>"
											placeholder="https://your-webhook-url.com/hook" />
										<button type="button" class="cem-chain-remove" title="<?php esc_attr_e( 'Remove', 'custom-endpoints-manager' ); ?>">&#x2715;</button>
									</div>
								<?php endforeach; ?>
							</div>
							<button type="button" id="cem-add-chain" class="button button-small">
								<?php esc_html_e( '+ Add Webhook', 'custom-endpoints-manager' ); ?>
							</button>
						</div><!-- .cem-mapping-section (chains) -->

						<!-- Save button -->
						<div class="cem-save-row">
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Save Mapping', 'custom-endpoints-manager' ); ?>
							</button>
							<span id="cem-mapping-status"></span>
							<?php if ( ! empty( $mapping ) ) : ?>
								<span style="font-size:12px;color:#646970">
									<?php esc_html_e( 'Mapping saved', 'custom-endpoints-manager' ); ?> &#10003;
								</span>
							<?php endif; ?>
						</div>
					</div><!-- .cem-mapping-form-inner -->
				</form>
			</div><!-- field mapping panel -->

		<?php endif; ?>
	</div><!-- .cem-cap-main -->
</div><!-- .cem-cap-wrap -->

<script>
( function () {
	'use strict';

	var REST_NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;
	var REST_CAP   = <?php echo wp_json_encode( rest_url( 'cem/v1/captures' ) ); ?>;

	// ── Sub-tab switching ───────────────────────────────────────────
	var subTabs  = document.querySelectorAll( '.cem-sub-tab' );
	var subPanels = document.querySelectorAll( '.cem-subtab-panel' );

	var stLen = subTabs.length;
	for ( var si = 0; si < stLen; si++ ) {
		subTabs[ si ].addEventListener( 'click', ( function ( tab ) {
			return function () {
				var panelName = tab.getAttribute( 'data-panel' );
				var stLen2 = subTabs.length;
				var spLen  = subPanels.length;
				for ( var j = 0; j < stLen2; j++ ) {
					subTabs[ j ].classList.toggle( 'is-active', subTabs[ j ] === tab );
				}
				for ( var k = 0; k < spLen; k++ ) {
					subPanels[ k ].style.display = ( subPanels[ k ].getAttribute( 'data-panel' ) === panelName ) ? '' : 'none';
				}
			};
		} )( subTabs[ si ] ) );
	}

	// ── Payload expand / collapse ───────────────────────────────────
	var expandBtns    = document.querySelectorAll( '.cem-expand-btn' );
	var expandBtnsLen = expandBtns.length;
	for ( var ei = 0; ei < expandBtnsLen; ei++ ) {
		expandBtns[ ei ].addEventListener( 'click', ( function ( btn ) {
			return function () {
				var cid     = btn.getAttribute( 'data-cap-id' );
				var payRow  = document.getElementById( 'cem-payload-' + cid );
				if ( ! payRow ) return;
				var isOpen  = payRow.style.display !== 'none';
				payRow.style.display  = isOpen ? 'none' : '';
				btn.setAttribute( 'aria-expanded', isOpen ? 'false' : 'true' );
				btn.textContent = isOpen ? '<?php echo esc_js( __( 'View', 'custom-endpoints-manager' ) ); ?>' : '<?php echo esc_js( __( 'Close', 'custom-endpoints-manager' ) ); ?>';
			};
		} )( expandBtns[ ei ] ) );
	}

	// ── Delete single capture ───────────────────────────────────────
	var delBtns    = document.querySelectorAll( '.cem-del-btn' );
	var delBtnsLen = delBtns.length;
	for ( var di = 0; di < delBtnsLen; di++ ) {
		delBtns[ di ].addEventListener( 'click', ( function ( btn ) {
			return function () {
				var cid = btn.getAttribute( 'data-cap-id' );
				if ( ! confirm( '<?php echo esc_js( __( 'Delete this capture?', 'custom-endpoints-manager' ) ); ?>' ) ) return;
				fetch( REST_CAP + '/' + cid, {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': REST_NONCE }
				} ).then( function ( r ) { return r.json(); } ).then( function ( data ) {
					if ( data.deleted ) {
						var row = document.getElementById( 'cem-cap-' + cid );
						var payRow = document.getElementById( 'cem-payload-' + cid );
						if ( row ) row.parentNode.removeChild( row );
						if ( payRow ) payRow.parentNode.removeChild( payRow );
					}
				} );
			};
		} )( delBtns[ di ] ) );
	}

	// ── Apply mapping to single capture ────────────────────────────
	var applyBtns    = document.querySelectorAll( '.cem-apply-btn' );
	var applyBtnsLen = applyBtns.length;
	for ( var ai = 0; ai < applyBtnsLen; ai++ ) {
		applyBtns[ ai ].addEventListener( 'click', ( function ( btn ) {
			return function () {
				var cid = btn.getAttribute( 'data-cap-id' );
				btn.disabled    = true;
				btn.textContent = '<?php echo esc_js( __( 'Applying…', 'custom-endpoints-manager' ) ); ?>';
				fetch( REST_CAP + '/' + cid + '/apply', {
					method:  'POST',
					headers: { 'X-WP-Nonce': REST_NONCE }
				} ).then( function ( r ) { return r.json(); } ).then( function ( data ) {
					btn.disabled    = false;
					btn.textContent = '<?php echo esc_js( __( 'Apply', 'custom-endpoints-manager' ) ); ?>';
					if ( data.success || data.post_id ) {
						// Update mapped badge.
						var row = document.getElementById( 'cem-cap-' + cid );
						if ( row ) {
							var badge = row.querySelector( '.cem-mapped-no' );
							if ( badge ) {
								badge.className   = 'cem-mapped-yes';
								badge.textContent = '<?php echo esc_js( __( 'Mapped', 'custom-endpoints-manager' ) ); ?>';
							}
						}
					} else {
						// eslint-disable-next-line no-alert
						alert( data.message || '<?php echo esc_js( __( 'Mapping failed.', 'custom-endpoints-manager' ) ); ?>' );
					}
				} ).catch( function () {
					btn.disabled    = false;
					btn.textContent = '<?php echo esc_js( __( 'Apply', 'custom-endpoints-manager' ) ); ?>';
				} );
			};
		} )( applyBtns[ ai ] ) );
	}

	// ── Apply mapping to all unmapped captures ──────────────────────
	var applyAllBtn = document.getElementById( 'cem-apply-all-btn' );
	if ( applyAllBtn ) {
		applyAllBtn.addEventListener( 'click', function () {
			var unmapped = document.querySelectorAll( '.cem-apply-btn' );
			var umLen    = unmapped.length;
			for ( var ui = 0; ui < umLen; ui++ ) {
				unmapped[ ui ].click();
			}
		} );
	}

	// ── Clear all captures ──────────────────────────────────────────
	var clearAllBtn = document.getElementById( 'cem-clear-all-btn' );
	if ( clearAllBtn ) {
		clearAllBtn.addEventListener( 'click', function () {
			var slug = clearAllBtn.getAttribute( 'data-slug' );
			if ( ! confirm( '<?php echo esc_js( __( 'Delete ALL captures for this endpoint? This cannot be undone.', 'custom-endpoints-manager' ) ); ?>' ) ) return;
			fetch( REST_CAP + '?endpoint_slug=' + encodeURIComponent( slug ), {
				method:  'DELETE',
				headers: { 'X-WP-Nonce': REST_NONCE }
			} ).then( function () { location.reload(); } );
		} );
	}

	// ── Detect payload paths ────────────────────────────────────────
	var detectBtn = document.getElementById( 'cem-detect-paths' );
	if ( detectBtn ) {
		detectBtn.addEventListener( 'click', function () {
			var slug     = detectBtn.getAttribute( 'data-slug' );
			var restUrl  = detectBtn.getAttribute( 'data-rest' );
			var nonce    = detectBtn.getAttribute( 'data-nonce' );
			var origTxt  = detectBtn.textContent;
			detectBtn.disabled    = true;
			detectBtn.textContent = '<?php echo esc_js( __( 'Detecting…', 'custom-endpoints-manager' ) ); ?>';

			fetch( restUrl + '?endpoint_slug=' + encodeURIComponent( slug ), {
				headers: { 'X-WP-Nonce': nonce }
			} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				detectBtn.disabled    = false;
				detectBtn.textContent = origTxt;
				if ( ! data.paths || ! data.paths.length ) return;
				var dl    = document.getElementById( 'cem-paths-datalist' );
				if ( ! dl ) return;
				var html  = '';
				var paths    = data.paths;
				var pathsLen = paths.length;
				for ( var pi = 0; pi < pathsLen; pi++ ) {
					html += '<option value="' + escHtml( paths[ pi ] ) + '">';
				}
				dl.innerHTML = html;
				// Auto-fill empty source inputs.
				var empties    = document.querySelectorAll( '.cem-source-input' );
				var emptiesLen = empties.length;
				for ( var ei2 = 0; ei2 < emptiesLen && ei2 < pathsLen; ei2++ ) {
					if ( ! empties[ ei2 ].value ) {
						empties[ ei2 ].value = paths[ ei2 ];
					}
				}
			} )
			.catch( function () {
				detectBtn.disabled    = false;
				detectBtn.textContent = origTxt;
			} );
		} );
	}

	// ── Mapper: add row ─────────────────────────────────────────────
	var addRowBtn = document.getElementById( 'cem-add-mapping-row' );
	if ( addRowBtn ) {
		addRowBtn.addEventListener( 'click', function () {
			addMapperRow();
		} );
	}

	function addMapperRow( source, target ) {
		var tbody = document.getElementById( 'cem-mapper-tbody' );
		if ( ! tbody ) return;
		var idx = tbody.querySelectorAll( '.cem-mapper-row' ).length;
		var tr  = document.createElement( 'tr' );
		tr.className = 'cem-mapper-row';
		tr.innerHTML = [
			'<td><input type="text" class="cem-source-input" name="cem_mapping[fields][' + idx + '][source]" value="' + escHtml( source || '' ) + '" list="cem-paths-datalist" placeholder="body.field_name" /></td>',
			'<td>&#8594;</td>',
			'<td><input type="text" class="cem-target-input" name="cem_mapping[fields][' + idx + '][target]" value="' + escHtml( target || '' ) + '" list="cem-targets-datalist" placeholder="post_title or meta:key" /></td>',
			'<td><button type="button" class="cem-mapper-remove button-link" style="color:#d63638;font-size:16px" title="Remove">&#x2715;</button></td>'
		].join( '' );
		tbody.appendChild( tr );
		wireRemoveRow( tr );
	}

	// Wire existing remove buttons.
	var existingRemove    = document.querySelectorAll( '.cem-mapper-remove' );
	var existingRemoveLen = existingRemove.length;
	for ( var ri = 0; ri < existingRemoveLen; ri++ ) {
		wireRemoveRow( existingRemove[ ri ].closest( 'tr' ) );
	}

	function wireRemoveRow( tr ) {
		var btn = tr ? tr.querySelector( '.cem-mapper-remove' ) : null;
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				tr.parentNode.removeChild( tr );
				reindexMapperRows();
			} );
		}
	}

	function reindexMapperRows() {
		var rows    = document.querySelectorAll( '.cem-mapper-row' );
		var rowsLen = rows.length;
		for ( var ri2 = 0; ri2 < rowsLen; ri2++ ) {
			var src = rows[ ri2 ].querySelector( '.cem-source-input' );
			var tgt = rows[ ri2 ].querySelector( '.cem-target-input' );
			if ( src ) { src.name = 'cem_mapping[fields][' + ri2 + '][source]'; }
			if ( tgt ) { tgt.name = 'cem_mapping[fields][' + ri2 + '][target]'; }
		}
	}

	// ── Chain builder: add webhook ──────────────────────────────────
	var addChainBtn = document.getElementById( 'cem-add-chain' );
	if ( addChainBtn ) {
		addChainBtn.addEventListener( 'click', function () {
			addChainRow();
		} );
	}

	function addChainRow( method, url ) {
		var list = document.getElementById( 'cem-chain-list' );
		if ( ! list ) return;
		var idx  = list.querySelectorAll( '.cem-chain-item' ).length;
		var div  = document.createElement( 'div' );
		div.className = 'cem-chain-item';
		div.innerHTML = [
			'<select name="cem_mapping[chains][' + idx + '][type]" class="cem-chain-type-sel"><option value="webhook">Webhook</option></select>',
			'<select name="cem_mapping[chains][' + idx + '][method]" class="cem-chain-method">',
			'<option value="POST"' + ( ( method || 'POST' ) === 'POST' ? ' selected' : '' ) + '>POST</option>',
			'<option value="GET"'  + ( method === 'GET'  ? ' selected' : '' ) + '>GET</option>',
			'<option value="PUT"'  + ( method === 'PUT'  ? ' selected' : '' ) + '>PUT</option>',
			'</select>',
			'<input type="url" name="cem_mapping[chains][' + idx + '][url]" value="' + escHtml( url || '' ) + '" placeholder="https://your-webhook-url.com/hook" />',
			'<button type="button" class="cem-chain-remove" title="Remove">&#x2715;</button>'
		].join( '' );
		list.appendChild( div );
		wireChainRemove( div );
		reindexChains();
	}

	var existingChains    = document.querySelectorAll( '.cem-chain-item' );
	var existingChainsLen = existingChains.length;
	for ( var chi = 0; chi < existingChainsLen; chi++ ) {
		wireChainRemove( existingChains[ chi ] );
	}

	function wireChainRemove( div ) {
		var btn = div ? div.querySelector( '.cem-chain-remove' ) : null;
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				div.parentNode.removeChild( div );
				reindexChains();
			} );
		}
	}

	function reindexChains() {
		var items    = document.querySelectorAll( '.cem-chain-item' );
		var itemsLen = items.length;
		for ( var ci2 = 0; ci2 < itemsLen; ci2++ ) {
			var inputs    = items[ ci2 ].querySelectorAll( 'input, select' );
			var inputsLen = inputs.length;
			for ( var ii = 0; ii < inputsLen; ii++ ) {
				if ( inputs[ ii ].name ) {
					inputs[ ii ].name = inputs[ ii ].name.replace( /\[\d+\]/, '[' + ci2 + ']' );
				}
			}
		}
	}

	// ── Update existing toggle ──────────────────────────────────────
	var updateChk  = document.getElementById( 'cem-map-update' );
	var matchRow   = document.getElementById( 'cem-match-row' );
	if ( updateChk && matchRow ) {
		updateChk.addEventListener( 'change', function () {
			matchRow.style.display = updateChk.checked ? '' : 'none';
		} );
	}

	// ── Minimal HTML escape ─────────────────────────────────────────
	function escHtml( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}
} () );
</script>
