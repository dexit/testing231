/**
 * Admin JS for Custom Endpoints Manager.
 *
 * @package Custom_Endpoints_Manager
 */

( function () {
	'use strict';

	var stack        = document.getElementById( 'cem-endpoints-stack' );
	var addBtn       = document.getElementById( 'cem-add-endpoint' );
	var methods      = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ];
	var argTypes     = [ 'string', 'integer', 'number', 'boolean' ];
	var methodColors = {
		GET:    'cem-hdr-method--get',
		POST:   'cem-hdr-method--post',
		PUT:    'cem-hdr-method--put',
		PATCH:  'cem-hdr-method--patch',
		DELETE: 'cem-hdr-method--delete'
	};

	if ( ! stack ) {
		return;
	}

	// ── Init all existing cards ──────────────────────────────────────
	var existingCards    = stack.querySelectorAll( '.cem-endpoint-card' );
	var cardCount        = existingCards.length;
	var existingCardsLen = cardCount;
	var i;
	for ( i = 0; i < existingCardsLen; i++ ) {
		initCard( existingCards[ i ] );
	}

	// ── Add endpoint button ──────────────────────────────────────────
	if ( addBtn ) {
		addBtn.addEventListener(
			'click',
			function () {
				var card = buildNewCard( cardCount );
				stack.appendChild( card );
				initCard( card );
				cardCount++;
			}
		);
	}

	// ── Build a new blank card ───────────────────────────────────────
	function buildNewCard( idx ) {
		var div       = document.createElement( 'div' );
		div.className = 'cem-endpoint-card is-empty';
		div.id        = 'cem-card-' + idx;

		// Build microplugin options.
		var mpOpts = '<option value="">' + escHtml( cemI18n.selectMicroplugin || '— Select Microplugin —' ) + '</option>';
		if ( typeof cemMicropluginOptions !== 'undefined' ) {
			var mpIdx;
			var mpOptsLen = cemMicropluginOptions.length;
			for ( mpIdx = 0; mpIdx < mpOptsLen; mpIdx++ ) {
				var mp  = cemMicropluginOptions[ mpIdx ];
				mpOpts += '<option value="' + escHtml( String( mp.id ) ) + '">' + escHtml( mp.title ) + '</option>';
			}
		}

		// Build method pills.
		var pillsHtml  = '';
		var methodsLen = methods.length;
		var mIdx;
		for ( mIdx = 0; mIdx < methodsLen; mIdx++ ) {
			var m      = methods[ mIdx ];
			var active = ( 'GET' === m ) ? ' is-active' : '';
			pillsHtml += '<label class="cem-method-pill' + active + '" data-method="' + m + '">'
				+ '<input type="checkbox" class="cem-method-cb" value="' + m + '"' + ( active ? ' checked' : '' ) + ' />'
				+ escHtml( m ) + '</label>';
		}

		// Build capability datalist.
		var capPresets   = [
			{ val: 'read',           lbl: 'read — logged-in user' },
			{ val: 'edit_posts',     lbl: 'edit_posts — editor+' },
			{ val: 'publish_posts',  lbl: 'publish_posts — author+' },
			{ val: 'manage_options', lbl: 'manage_options — admin only' }
		];
		var datalistOpts  = '';
		var capPresetsLen = capPresets.length;
		var cIdx;
		for ( cIdx = 0; cIdx < capPresetsLen; cIdx++ ) {
			datalistOpts += '<option value="' + escHtml( capPresets[ cIdx ].val ) + '">' + escHtml( capPresets[ cIdx ].lbl ) + '</option>';
		}

		div.innerHTML = [
			'<div class="cem-card-header">',
			'  <span class="cem-card-methods"><span class="cem-hdr-method cem-hdr-method--get">GET</span></span>',
			'  <span class="cem-card-route"><span class="cem-route-base">/wp-json/cem/v1/</span><strong class="cem-route-slug"></strong></span>',
			'  <span class="cem-card-header-actions">',
			'    <button type="button" class="cem-card-toggle" aria-label="Toggle">&#9660;</button>',
			'    <button type="button" class="cem-card-remove" title="Remove endpoint">&#x2715;</button>',
			'  </span>',
			'</div>',
			'<div class="cem-card-body">',

			// Primary config grid.
			'<div class="cem-primary-fields">',
			'  <div class="cem-fields-grid">',

			'    <div class="cem-field-group">',
			'      <label for="cem-slug-' + idx + '">Route Slug</label>',
			'      <div class="cem-slug-row">',
			'        <span class="cem-slug-prefix">/wp-json/cem/v1/</span>',
			'        <input type="text" id="cem-slug-' + idx + '" name="cem_endpoints[' + idx + '][slug]" class="cem-slug-input" placeholder="my-endpoint" />',
			'      </div>',
			'    </div>',

			'    <div class="cem-field-group">',
			'      <div class="cem-callback-label-row">',
			'        <label>Microplugin (Callback)</label>',
			'        <span class="cem-callback-tabs">',
			'          <button type="button" class="cem-cb-tab is-active" data-mode="microplugin">Microplugin</button>',
			'          <button type="button" class="cem-cb-tab" data-mode="function">Raw Function</button>',
			'        </span>',
			'      </div>',
			'      <input type="hidden" name="cem_endpoints[' + idx + '][callback_mode]" class="cem-callback-mode-val" value="microplugin" />',
			'      <div class="cem-cb-panel cem-cb-panel--mp">',
			'        <div class="cem-mp-select-row">',
			'          <select id="cem-mp-' + idx + '" name="cem_endpoints[' + idx + '][microplugin_id]" class="cem-mp-select">' + mpOpts + '</select>',
			'          <a href="post-new.php?post_type=microplugin" target="_blank" class="button button-small">+ New</a>',
			'        </div>',
			'        <p class="description">No callback assigned — endpoint returns 403 until a microplugin is selected.</p>',
			'      </div>',
			'      <div class="cem-cb-panel cem-cb-panel--fn" style="display:none">',
			'        <input type="text" name="cem_endpoints[' + idx + '][callback_fn]" class="cem-callback-fn-input" placeholder="my_plugin_callback_function" />',
			'        <p class="description">A globally defined PHP function to use as the callback. Must accept <code>WP_REST_Request $request</code>.</p>',
			'      </div>',
			'    </div>',

			'    <div class="cem-field-group">',
			'      <span class="cem-field-label">HTTP Methods</span>',
			'      <div class="cem-method-pills">' + pillsHtml + '</div>',
			'      <input type="hidden" name="cem_endpoints[' + idx + '][methods]" class="cem-methods-val" value="GET" />',
			'    </div>',

			'    <div class="cem-field-group">',
			'      <label for="cem-cap-' + idx + '">Capability</label>',
			'      <input type="text" id="cem-cap-' + idx + '" name="cem_endpoints[' + idx + '][capability]" value="read" list="cem-cap-list-' + idx + '" placeholder="read" />',
			'      <datalist id="cem-cap-list-' + idx + '">' + datalistOpts + '</datalist>',
			'      <p class="description">WP capability required to call this endpoint.</p>',
			'    </div>',

			'  </div>',
			'</div>',

			// Arguments section.
			'<div class="cem-card-section cem-section-collapsed">',
			'  <button type="button" class="cem-section-head">',
			'    <span class="cem-section-icon">&#9654;</span>',
			'    <strong class="cem-section-title">Arguments</strong>',
			'    <span class="cem-section-hint">Define typed parameters this endpoint accepts</span>',
			'  </button>',
			'  <div class="cem-section-body">',
			'    <table class="cem-arg-table widefat fixed striped">',
			'      <thead><tr>',
			'        <th style="width:38%">Name</th>',
			'        <th style="width:28%">Type</th>',
			'        <th style="width:18%;text-align:center">Required</th>',
			'        <th style="width:16%"></th>',
			'      </tr></thead>',
			'      <tbody class="cem-arg-tbody"></tbody>',
			'    </table>',
			'    <div class="cem-arg-actions">',
			'      <button type="button" class="button cem-add-arg">+ Add Argument</button>',
			'    </div>',
			'    <input type="hidden" name="cem_endpoints[' + idx + '][args]" class="cem-args-val" value="" />',
			'  </div>',
			'</div>',

			// Response Schema section.
			'<div class="cem-card-section cem-section-collapsed">',
			'  <button type="button" class="cem-section-head">',
			'    <span class="cem-section-icon">&#9654;</span>',
			'    <strong class="cem-section-title">Response Schema</strong>',
			'    <span class="cem-section-hint">Map JSON keys for pagination — used by client SDKs</span>',
			'  </button>',
			'  <div class="cem-section-body">',
			'    <div class="cem-schema-paste-row">',
			'      <label class="cem-schema-paste-label">Paste a sample JSON response to auto-detect field paths:</label>',
			'      <textarea class="cem-schema-json" rows="4" placeholder=\'{ "data": { "items": [...], "total": 100, "page": 1, "pages": 5 } }\'></textarea>',
			'      <button type="button" class="button cem-schema-extract">&#128269; Extract Keys</button>',
			'    </div>',
			'    <datalist id="cem-schema-paths-' + idx + '"></datalist>',
			'    <div class="cem-schema-fields">',
			'      <div class="cem-schema-row"><label>Root key</label><input type="text" name="cem_endpoints[' + idx + '][response_map][root]" list="cem-schema-paths-' + idx + '" placeholder="data (leave blank = top-level)" /></div>',
			'      <div class="cem-schema-row"><label>Items array</label><input type="text" name="cem_endpoints[' + idx + '][response_map][items]" list="cem-schema-paths-' + idx + '" placeholder="items" /></div>',
			'      <div class="cem-schema-row"><label>Total count</label><input type="text" name="cem_endpoints[' + idx + '][response_map][total_count]" list="cem-schema-paths-' + idx + '" placeholder="total" /></div>',
			'      <div class="cem-schema-row"><label>Current page</label><input type="text" name="cem_endpoints[' + idx + '][response_map][page]" list="cem-schema-paths-' + idx + '" placeholder="page" /></div>',
			'      <div class="cem-schema-row"><label>Total pages</label><input type="text" name="cem_endpoints[' + idx + '][response_map][total_pages]" list="cem-schema-paths-' + idx + '" placeholder="pages" /></div>',
			'    </div>',
			'  </div>',
			'</div>',

			// Async section.
			'<div class="cem-card-section cem-section-collapsed">',
			'  <button type="button" class="cem-section-head">',
			'    <span class="cem-section-icon">&#9654;</span>',
			'    <strong class="cem-section-title">Async Execution</strong>',
			'    <span class="cem-section-hint">Return job_id immediately, callback runs via WP Cron</span>',
			'  </button>',
			'  <div class="cem-section-body">',
			'    <div class="cem-async-row">',
			'      <label>',
			'        <input type="checkbox" name="cem_endpoints[' + idx + '][async]" value="1" class="cem-async-toggle" />',
			'        Run asynchronously &mdash; returns <code>job_id</code> immediately',
			'      </label>',
			'      <span class="cem-async-attempts">',
			'        <label for="cem-att-' + idx + '">Max attempts:</label>',
			'        <input type="number" id="cem-att-' + idx + '" name="cem_endpoints[' + idx + '][max_attempts]" value="3" min="1" max="10" />',
			'      </span>',
			'    </div>',
			'  </div>',
			'</div>',

			'</div>' // .cem-card-body
		].join( '\n' );

		return div;
	}

	// ── Wire up a card's interactive behaviour ───────────────────────
	function initCard( card ) {
		var header      = card.querySelector( '.cem-card-header' );
		var toggleBtn   = card.querySelector( '.cem-card-toggle' );
		var removeBtn   = card.querySelector( '.cem-card-remove' );
		var slugInput   = card.querySelector( '.cem-slug-input' );
		var methodsHid  = card.querySelector( '.cem-methods-val' );
		var pills       = card.querySelectorAll( '.cem-method-pill' );
		var asyncToggle = card.querySelector( '.cem-async-toggle' );
		var asyncOpts   = card.querySelector( '.cem-async-attempts' );

		if ( header ) {
			header.addEventListener(
				'click',
				function ( e ) {
					if ( e.target.tagName === 'BUTTON' || e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'A' ) {
						return;
					}
					card.classList.toggle( 'is-collapsed' );
				}
			);
		}

		if ( toggleBtn ) {
			toggleBtn.addEventListener(
				'click',
				function ( e ) {
					e.stopPropagation();
					card.classList.toggle( 'is-collapsed' );
				}
			);
		}

		if ( removeBtn ) {
			removeBtn.addEventListener(
				'click',
				function () {
					card.parentNode.removeChild( card );
					reindex();
				}
			);
		}

		if ( slugInput ) {
			slugInput.addEventListener(
				'input',
				function () {
					updateHeaderRoute( card, slugInput.value );
				}
			);
		}

		var pillIdx;
		var pillsLen = pills.length;
		for ( pillIdx = 0; pillIdx < pillsLen; pillIdx++ ) {
			( function ( pill ) {
				var cb = pill.querySelector( 'input[type="checkbox"]' );
				if ( cb ) {
					cb.addEventListener(
						'change',
						function () {
							if ( cb.checked ) {
								pill.classList.add( 'is-active' );
							} else {
								pill.classList.remove( 'is-active' );
							}
							syncMethods( card, methodsHid, pills );
							updateHeaderMethods( card );
						}
					);
				}
			} )( pills[ pillIdx ] );
		}

		if ( asyncToggle && asyncOpts ) {
			asyncToggle.addEventListener(
				'change',
				function () {
					if ( asyncToggle.checked ) {
						asyncOpts.classList.add( 'is-visible' );
					} else {
						asyncOpts.classList.remove( 'is-visible' );
					}
				}
			);
		}

		initSectionToggles( card );
		initArgBuilder( card );
		initSchemaMapper( card );
		initCallbackTabs( card );
		initCardAI( card );
	}

	// ── Section collapse / expand ────────────────────────────────────
	function initSectionToggles( card ) {
		var sections    = card.querySelectorAll( '.cem-card-section' );
		var sectionsLen = sections.length;
		var sIdx;
		for ( sIdx = 0; sIdx < sectionsLen; sIdx++ ) {
			( function ( section ) {
				var head = section.querySelector( '.cem-section-head' );
				if ( ! head ) {
					return;
				}
				head.addEventListener(
					'click',
					function () {
						section.classList.toggle( 'cem-section-collapsed' );
					}
				);
			} )( sections[ sIdx ] );
		}
	}

	// ── Callback mode tabs ───────────────────────────────────────────
	function initCallbackTabs( card ) {
		var tabs    = card.querySelectorAll( '.cem-cb-tab' );
		var modeHid = card.querySelector( '.cem-callback-mode-val' );
		var mpPanel = card.querySelector( '.cem-cb-panel--mp' );
		var fnPanel = card.querySelector( '.cem-cb-panel--fn' );

		var tabsLen = tabs.length;
		if ( ! tabsLen || ! modeHid ) {
			return;
		}

		// Sync initial state.
		var curMode = modeHid.value || 'microplugin';
		if ( mpPanel ) {
			mpPanel.style.display = ( 'microplugin' === curMode ) ? '' : 'none';
		}
		if ( fnPanel ) {
			fnPanel.style.display = ( 'function' === curMode ) ? '' : 'none';
		}
		var initIdx;
		for ( initIdx = 0; initIdx < tabsLen; initIdx++ ) {
			if ( tabs[ initIdx ].getAttribute( 'data-mode' ) === curMode ) {
				tabs[ initIdx ].classList.add( 'is-active' );
			} else {
				tabs[ initIdx ].classList.remove( 'is-active' );
			}
		}

		var tIdx;
		for ( tIdx = 0; tIdx < tabsLen; tIdx++ ) {
			( function ( tab ) {
				tab.addEventListener(
					'click',
					function () {
						var mode = tab.getAttribute( 'data-mode' );
						var otherIdx;
						for ( otherIdx = 0; otherIdx < tabsLen; otherIdx++ ) {
							tabs[ otherIdx ].classList.remove( 'is-active' );
						}
						tab.classList.add( 'is-active' );
						modeHid.value = mode;
						if ( mpPanel ) {
							mpPanel.style.display = ( 'microplugin' === mode ) ? '' : 'none';
						}
						if ( fnPanel ) {
							fnPanel.style.display = ( 'function' === mode ) ? '' : 'none';
						}
					}
				);
			} )( tabs[ tIdx ] );
		}
	}

	// ── Arg builder ──────────────────────────────────────────────────
	function initArgBuilder( card ) {
		var tbody    = card.querySelector( '.cem-arg-tbody' );
		var addArgB  = card.querySelector( '.cem-add-arg' );
		var argsHid  = card.querySelector( '.cem-args-val' );
		var detected = card.querySelector( '.cem-use-detected' );

		if ( ! tbody || ! argsHid ) {
			return;
		}

		// Parse existing args text into rows.
		if ( argsHid.value ) {
			var pairs    = argsHid.value.split( ',' );
			var pairsLen = pairs.length;
			var pIdx;
			for ( pIdx = 0; pIdx < pairsLen; pIdx++ ) {
				var pair  = pairs[ pIdx ].trim();
				var colon = pair.indexOf( ':' );
				if ( colon > 0 ) {
					var argName = pair.substring( 0, colon ).trim();
					var argType = pair.substring( colon + 1 ).trim();
					if ( argName ) {
						addArgRow( tbody, argName, argType, false, argsHid );
					}
				}
			}
		}

		if ( addArgB ) {
			addArgB.addEventListener(
				'click',
				function () {
					addArgRow( tbody, '', 'string', false, argsHid );
				}
			);
		}

		// "Use detected params" button (rendered by PHP when MP is selected).
		if ( detected ) {
			detected.addEventListener(
				'click',
				function () {
					var params = detected.getAttribute( 'data-params' );
					if ( ! params ) {
						return;
					}
					var names    = params.split( ',' );
					var namesLen = names.length;
					var nIdx;
					for ( nIdx = 0; nIdx < namesLen; nIdx++ ) {
						var n = names[ nIdx ].trim();
						if ( n ) {
							addArgRow( tbody, n, 'string', false, argsHid );
						}
					}
					syncArgsText( tbody, argsHid );
				}
			);
		}
	}

	function addArgRow( tbody, argName, argType, required, argsHid ) {
		var tr       = document.createElement( 'tr' );
		tr.className = 'cem-arg-row';

		var typeOptHtml = '';
		var argTypeLen  = argTypes.length;
		var aIdx;
		for ( aIdx = 0; aIdx < argTypeLen; aIdx++ ) {
			var isSel    = ( argTypes[ aIdx ] === argType ) ? ' selected' : '';
			typeOptHtml += '<option value="' + argTypes[ aIdx ] + '"' + isSel + '>' + argTypes[ aIdx ] + '</option>';
		}

		tr.innerHTML = [
			'<td><input type="text" class="cem-arg-name" value="' + escHtml( argName ) + '" placeholder="param_name" style="width:100%;box-sizing:border-box" /></td>',
			'<td><select class="cem-arg-type" style="width:100%;box-sizing:border-box">' + typeOptHtml + '</select></td>',
			'<td style="text-align:center;vertical-align:middle"><input type="checkbox" class="cem-arg-required"' + ( required ? ' checked' : '' ) + ' /></td>',
			'<td style="text-align:right;vertical-align:middle"><button type="button" class="cem-arg-remove button-link" title="Remove argument">&#x2715;</button></td>'
		].join( '' );

		tbody.appendChild( tr );

		var nameEl   = tr.querySelector( '.cem-arg-name' );
		var typeEl   = tr.querySelector( '.cem-arg-type' );
		var removeEl = tr.querySelector( '.cem-arg-remove' );

		if ( nameEl ) {
			nameEl.addEventListener(
				'input',
				function () {
					syncArgsText( tbody, argsHid );
				}
			);
		}
		if ( typeEl ) {
			typeEl.addEventListener(
				'change',
				function () {
					syncArgsText( tbody, argsHid );
				}
			);
		}
		if ( removeEl ) {
			removeEl.addEventListener(
				'click',
				function () {
					tbody.removeChild( tr );
					syncArgsText( tbody, argsHid );
				}
			);
		}

		syncArgsText( tbody, argsHid );
	}

	function syncArgsText( tbody, argsHid ) {
		if ( ! argsHid ) {
			return;
		}
		var rows    = tbody.querySelectorAll( '.cem-arg-row' );
		var rowsLen = rows.length;
		var parts   = [];
		var rIdx;
		for ( rIdx = 0; rIdx < rowsLen; rIdx++ ) {
			var nameEl = rows[ rIdx ].querySelector( '.cem-arg-name' );
			var typeEl = rows[ rIdx ].querySelector( '.cem-arg-type' );
			if ( nameEl && nameEl.value.trim() ) {
				parts.push( nameEl.value.trim() + ':' + ( typeEl ? typeEl.value : 'string' ) );
			}
		}
		argsHid.value = parts.join( ', ' );
	}

	// ── Response schema mapper ───────────────────────────────────────
	function initSchemaMapper( card ) {
		var jsonArea   = card.querySelector( '.cem-schema-json' );
		var extractBtn = card.querySelector( '.cem-schema-extract' );
		var datalist   = card.querySelector( '[id^="cem-schema-paths-"]' );

		if ( ! jsonArea || ! extractBtn ) {
			return;
		}

		extractBtn.addEventListener(
			'click',
			function () {
				var text = jsonArea.value.trim();
				if ( ! text ) {
					return;
				}
				var parsed;
				try {
					parsed = JSON.parse( text );
				} catch ( e ) {
					jsonArea.style.outline = '2px solid #d63638';
					return;
				}
				jsonArea.style.outline = '';

				var paths    = extractJsonPaths( parsed, '' );
				var pathsLen = paths.length;

				if ( datalist ) {
					var optHtml = '';
					var pIdx;
					for ( pIdx = 0; pIdx < pathsLen; pIdx++ ) {
						optHtml += '<option value="' + escHtml( paths[ pIdx ] ) + '">';
					}
					datalist.innerHTML = optHtml;
				}

				autoDetectSchema( card, parsed, paths );
			}
		);
	}

	function extractJsonPaths( obj, prefix ) {
		var paths = [];
		if ( null === obj || 'object' !== typeof obj ) {
			return paths;
		}
		var keys    = Object.keys( obj );
		var keysLen = keys.length;
		var kIdx;
		for ( kIdx = 0; kIdx < keysLen; kIdx++ ) {
			var key  = keys[ kIdx ];
			var path = prefix ? prefix + '.' + key : key;
			paths.push( path );
			if ( obj[ key ] && 'object' === typeof obj[ key ] && ! Array.isArray( obj[ key ] ) ) {
				var nested    = extractJsonPaths( obj[ key ], path );
				var nestedLen = nested.length;
				var nIdx;
				for ( nIdx = 0; nIdx < nestedLen; nIdx++ ) {
					paths.push( nested[ nIdx ] );
				}
			}
		}
		return paths;
	}

	function autoDetectSchema( card, parsed, paths ) {
		var rootInput       = card.querySelector( '[name$="[response_map][root]"]' );
		var itemsInput      = card.querySelector( '[name$="[response_map][items]"]' );
		var totalCountInput = card.querySelector( '[name$="[response_map][total_count]"]' );
		var pageInput       = card.querySelector( '[name$="[response_map][page]"]' );
		var totalPagesInput = card.querySelector( '[name$="[response_map][total_pages]"]' );
		var pathsLen        = paths.length;
		var pIdx;

		for ( pIdx = 0; pIdx < pathsLen; pIdx++ ) {
			var path  = paths[ pIdx ];
			var parts = path.split( '.' );
			var key   = parts[ parts.length - 1 ].toLowerCase();
			var value = getByPath( parsed, path );

			if ( Array.isArray( value ) && itemsInput && ! itemsInput.value ) {
				itemsInput.value = path;
			}
			if ( ( 'total' === key || 'count' === key || 'total_count' === key ) && totalCountInput && ! totalCountInput.value ) {
				totalCountInput.value = path;
			}
			if ( ( 'page' === key || 'current_page' === key ) && pageInput && ! pageInput.value ) {
				pageInput.value = path;
			}
			if ( ( 'pages' === key || 'total_pages' === key || 'page_count' === key || 'last_page' === key ) && totalPagesInput && ! totalPagesInput.value ) {
				totalPagesInput.value = path;
			}
		}
	}

	function getByPath( obj, path ) {
		var parts    = path.split( '.' );
		var cur      = obj;
		var partsLen = parts.length;
		var pIdx;
		for ( pIdx = 0; pIdx < partsLen; pIdx++ ) {
			if ( null === cur || 'object' !== typeof cur ) {
				return undefined;
			}
			cur = cur[ parts[ pIdx ] ];
		}
		return cur;
	}

	// ── Sync method checkboxes → hidden input ────────────────────────
	function syncMethods( card, hiddenInput, pills ) {
		if ( ! hiddenInput ) {
			return;
		}
		var checked  = [];
		var pillsLen = pills.length;
		var pIdx;
		for ( pIdx = 0; pIdx < pillsLen; pIdx++ ) {
			var cb = pills[ pIdx ].querySelector( 'input[type="checkbox"]' );
			if ( cb && cb.checked ) {
				checked.push( cb.value );
			}
		}
		hiddenInput.value = checked.join( ',' );
	}

	// ── Update card header method badges ─────────────────────────────
	function updateHeaderMethods( card ) {
		var methodsHid = card.querySelector( '.cem-methods-val' );
		var container  = card.querySelector( '.cem-card-methods' );
		if ( ! methodsHid || ! container ) {
			return;
		}
		var active    = methodsHid.value ? methodsHid.value.split( ',' ) : [];
		var activeLen = active.length;
		var html      = '';
		if ( 0 === activeLen ) {
			html = '<span class="cem-hdr-method cem-hdr-method--other">—</span>';
		} else {
			var mIdx;
			for ( mIdx = 0; mIdx < activeLen; mIdx++ ) {
				var meth = active[ mIdx ].trim().toUpperCase();
				var cls  = methodColors[ meth ] ? methodColors[ meth ] : 'cem-hdr-method--other';
				html    += '<span class="cem-hdr-method ' + cls + '">' + escHtml( meth ) + '</span>';
			}
		}
		container.innerHTML = html;
	}

	// ── Update card header route slug ────────────────────────────────
	function updateHeaderRoute( card, slug ) {
		var el = card.querySelector( '.cem-route-slug' );
		if ( el ) {
			el.textContent = slug;
		}
	}

	// ── Reindex all cards after a remove ─────────────────────────────
	function reindex() {
		var cards    = stack.querySelectorAll( '.cem-endpoint-card' );
		var cardsLen = cards.length;
		var cIdx;
		for ( cIdx = 0; cIdx < cardsLen; cIdx++ ) {
			var c      = cards[ cIdx ];
			var inputs = c.querySelectorAll( '[name^="cem_endpoints["]' );
			var inLen  = inputs.length;
			var iIdx;
			for ( iIdx = 0; iIdx < inLen; iIdx++ ) {
				inputs[ iIdx ].name = inputs[ iIdx ].name.replace(
					/cem_endpoints\[\d+\]/,
					'cem_endpoints[' + cIdx + ']'
				);
			}
			c.id = 'cem-card-' + cIdx;
		}
		cardCount = cardsLen;
	}

	// ── Minimal HTML escaping ─────────────────────────────────────────
	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	// ── AI integration ────────────────────────────────────────────────
	var cemAIAvailable = false;

	function initAI() {
		if ( 'undefined' === typeof cemAI || ! cemAI.statusUrl ) {
			return;
		}
		fetch( cemAI.statusUrl, { headers: { 'X-WP-Nonce': cemAI.nonce } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data.available ) {
					cemAIAvailable = true;
					showAIButtons();
				}
			} )
			.catch( function () {} );
	}

	function showAIButtons() {
		var cards    = stack ? stack.querySelectorAll( '.cem-endpoint-card' ) : [];
		var cardsLen = cards.length;
		var cIdx;
		for ( cIdx = 0; cIdx < cardsLen; cIdx++ ) {
			initCardAI( cards[ cIdx ] );
		}
	}

	function initCardAI( card ) {
		if ( ! cemAIAvailable ) {
			return;
		}
		var argsBtn   = card.querySelector( '.cem-ai-suggest-args' );
		var schemaBtn = card.querySelector( '.cem-ai-suggest-schema' );

		if ( argsBtn ) {
			argsBtn.style.display = '';
			argsBtn.addEventListener( 'click', function () {
				handleAISuggestArgs( card, argsBtn );
			} );
		}
		if ( schemaBtn ) {
			schemaBtn.style.display = '';
			schemaBtn.addEventListener( 'click', function () {
				handleAISuggestSchema( card, schemaBtn );
			} );
		}
	}

	function getCardDescription( card ) {
		var slugEl = card.querySelector( '.cem-slug-input' );
		return slugEl ? slugEl.value.trim().replace( /-/g, ' ' ) : '';
	}

	function getCardMicropluginId( card ) {
		var mpSel = card.querySelector( '.cem-microplugin-select' );
		return mpSel ? parseInt( mpSel.value, 10 ) || 0 : 0;
	}

	function handleAISuggestArgs( card, btn ) {
		var slug  = card.querySelector( '.cem-slug-input' ) ? card.querySelector( '.cem-slug-input' ).value.trim() : '';
		var mpId  = getCardMicropluginId( card );
		var tbody = card.querySelector( '.cem-arg-tbody' );
		var argsHid = card.querySelector( '.cem-args-val' );

		if ( ! slug ) {
			// eslint-disable-next-line no-alert
			alert( 'Please enter an endpoint slug first so AI has context.' );
			return;
		}

		var origText    = btn.textContent;
		btn.disabled    = true;
		btn.textContent = 'Thinking…';

		var payload = { description: slug.replace( /-/g, ' ' ), endpoint_slug: slug };
		if ( mpId > 0 ) {
			payload.microplugin_id = mpId;
		}

		fetch( cemAI.suggestArgs, {
			method:  'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cemAI.nonce },
			body:    JSON.stringify( payload )
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				btn.disabled    = false;
				btn.textContent = origText;
				if ( ! data.args || ! Array.isArray( data.args ) ) {
					return;
				}
				var aiArgs    = data.args;
				var aiArgsLen = aiArgs.length;
				var aIdx;
				for ( aIdx = 0; aIdx < aiArgsLen; aIdx++ ) {
					var arg = aiArgs[ aIdx ];
					if ( arg.name ) {
						addArgRow( tbody, arg.name, arg.type || 'string', false, argsHid );
					}
				}
				syncArgsText( tbody, argsHid );
			} )
			.catch( function () {
				btn.disabled    = false;
				btn.textContent = origText;
			} );
	}

	function handleAISuggestSchema( card, btn ) {
		var slug = card.querySelector( '.cem-slug-input' ) ? card.querySelector( '.cem-slug-input' ).value.trim() : '';
		if ( ! slug ) {
			// eslint-disable-next-line no-alert
			alert( 'Please enter an endpoint slug first so AI has context.' );
			return;
		}

		var origText    = btn.textContent;
		btn.disabled    = true;
		btn.textContent = 'Thinking…';

		var payload = { description: slug.replace( /-/g, ' ' ) };

		fetch( cemAI.suggestSchema, {
			method:  'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cemAI.nonce },
			body:    JSON.stringify( payload )
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				btn.disabled    = false;
				btn.textContent = origText;
				if ( ! data.schema ) {
					return;
				}
				var s = data.schema;
				var rootInput       = card.querySelector( '[name$="[response_map][root]"]' );
				var itemsInput      = card.querySelector( '[name$="[response_map][items]"]' );
				var totalCountInput = card.querySelector( '[name$="[response_map][total_count]"]' );
				var pageInput       = card.querySelector( '[name$="[response_map][page]"]' );
				var totalPagesInput = card.querySelector( '[name$="[response_map][total_pages]"]' );
				if ( rootInput && s.root !== undefined )        { rootInput.value       = s.root; }
				if ( itemsInput && s.items !== undefined )       { itemsInput.value      = s.items; }
				if ( totalCountInput && s.total_count !== undefined ) { totalCountInput.value = s.total_count; }
				if ( pageInput && s.page !== undefined )         { pageInput.value       = s.page; }
				if ( totalPagesInput && s.total_pages !== undefined ) { totalPagesInput.value = s.total_pages; }

				// Expand the schema section if it was collapsed.
				var schemaSection = btn.closest( '.cem-card-section' );
				if ( schemaSection ) {
					schemaSection.classList.remove( 'cem-section-collapsed' );
				}
			} )
			.catch( function () {
				btn.disabled    = false;
				btn.textContent = origText;
			} );
	}

	// Kick off AI status check after DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAI );
	} else {
		initAI();
	}

} )();
