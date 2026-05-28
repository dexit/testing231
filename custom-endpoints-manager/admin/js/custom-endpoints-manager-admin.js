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
	var existingCardsLen = existingCards.length;
	var cardCount        = existingCardsLen;
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
		var mpOpts = '<option value="">' + escHtml( ( 'undefined' !== typeof cemI18n && cemI18n.selectMicroplugin ) ? cemI18n.selectMicroplugin : '— Select Microplugin —' ) + '</option>';
		if ( 'undefined' !== typeof cemMicropluginOptions ) {
			var mpOptsLen = cemMicropluginOptions.length;
			var mpIdx;
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
		var capPresets = [
			{ val: 'public',         lbl: 'public — no authentication required' },
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

			'<div class="cem-fields-grid">',

			'  <div class="cem-field-group cem-field-full">',
			'    <label for="cem-slug-' + idx + '">Route Slug</label>',
			'    <div class="cem-slug-row">',
			'      <span class="cem-slug-prefix">/wp-json/cem/v1/</span>',
			'      <input type="text" id="cem-slug-' + idx + '" name="cem_endpoints[' + idx + '][slug]" class="cem-slug-input" placeholder="my-endpoint" />',
			'    </div>',
			'  </div>',

			'  <div class="cem-field-group">',
			'    <span class="cem-field-label">HTTP Methods</span>',
			'    <div class="cem-method-pills">' + pillsHtml + '</div>',
			'    <input type="hidden" name="cem_endpoints[' + idx + '][methods]" class="cem-methods-val" value="GET" />',
			'  </div>',

			'  <div class="cem-field-group">',
			'    <label for="cem-cap-' + idx + '">Capability</label>',
			'    <input type="text" id="cem-cap-' + idx + '" name="cem_endpoints[' + idx + '][capability]" value="read" list="cem-cap-list-' + idx + '" placeholder="read" />',
			'    <datalist id="cem-cap-list-' + idx + '">' + datalistOpts + '</datalist>',
			'    <p class="description">Use &ldquo;public&rdquo; for unauthenticated access.</p>',
			'  </div>',

			'  <div class="cem-field-group cem-field-full">',
			'    <div class="cem-callback-label-row">',
			'      <label>Callback</label>',
			'      <span class="cem-callback-tabs">',
			'        <button type="button" class="cem-cb-tab is-active" data-mode="microplugin">Microplugin</button>',
			'        <button type="button" class="cem-cb-tab" data-mode="function">Raw Function</button>',
			'      </span>',
			'    </div>',
			'    <input type="hidden" name="cem_endpoints[' + idx + '][callback_mode]" class="cem-callback-mode-val" value="microplugin" />',
			'    <div class="cem-cb-panel cem-cb-panel--mp">',
			'      <div class="cem-mp-select-row">',
			'        <select id="cem-mp-' + idx + '" name="cem_endpoints[' + idx + '][microplugin_id]" class="cem-mp-select">' + mpOpts + '</select>',
			'        <a href="post-new.php?post_type=microplugin" target="_blank" class="button button-small">+ New</a>',
			'      </div>',
			'      <p class="description">Select a microplugin to use as the callback.</p>',
			'    </div>',
			'    <div class="cem-cb-panel cem-cb-panel--fn" style="display:none">',
			'      <input type="text" name="cem_endpoints[' + idx + '][callback_fn]" class="cem-callback-fn-input" placeholder="my_plugin_callback_function" />',
			'      <p class="description">A globally defined PHP function accepting WP_REST_Request and returning WP_REST_Response.</p>',
			'    </div>',
			'  </div>',

			'</div>',

			// Input Arguments subsection.
			'<div class="cem-card-subsection">',
			'  <div class="cem-subsection-head">',
			'    <strong>Input Arguments</strong>',
			'    <span class="cem-section-hint">Typed parameters this endpoint accepts</span>',
			'  </div>',
			'  <table class="cem-arg-table widefat fixed striped">',
			'    <thead><tr>',
			'      <th style="width:38%">Name</th>',
			'      <th style="width:28%">Type</th>',
			'      <th style="width:18%;text-align:center">Required</th>',
			'      <th style="width:16%"></th>',
			'    </tr></thead>',
			'    <tbody class="cem-arg-tbody"></tbody>',
			'  </table>',
			'  <div class="cem-arg-actions">',
			'    <button type="button" class="button cem-add-arg">+ Add Argument</button>',
			'  </div>',
			'  <input type="hidden" name="cem_endpoints[' + idx + '][args]" class="cem-args-val" value="" />',
			'</div>',

			// Outgoing Actions subsection.
			'<div class="cem-card-subsection">',
			'  <div class="cem-subsection-head">',
			'    <strong>Outgoing Actions</strong>',
			'    <span class="cem-section-hint">Fire webhooks and control execution after this endpoint runs</span>',
			'  </div>',
			'  <div class="cem-outgoing-flags">',
			'    <label class="cem-flag-label">',
			'      <input type="checkbox" name="cem_endpoints[' + idx + '][async]" value="1" class="cem-async-toggle" />',
			'      Run async',
			'    </label>',
			'    <span class="cem-async-attempts">',
			'      <label for="cem-att-' + idx + '">max attempts:</label>',
			'      <input type="number" id="cem-att-' + idx + '" name="cem_endpoints[' + idx + '][max_attempts]" value="3" min="1" max="10" style="width:55px" />',
			'    </span>',
			'    <span class="cem-flag-sep"></span>',
			'    <label class="cem-flag-label">',
			'      <input type="checkbox" name="cem_endpoints[' + idx + '][capture]" value="1" />',
			'      Capture payloads',
			'    </label>',
			'  </div>',
			'  <div class="cem-webhooks-wrap">',
			'    <div class="cem-webhook-rows"></div>',
			'    <div class="cem-webhook-add-row">',
			'      <button type="button" class="button cem-add-webhook">+ Add Outgoing Webhook</button>',
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
					if ( e.target.tagName === 'BUTTON' || e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'A' || e.target.tagName === 'LABEL' || e.target.tagName === 'TEXTAREA' ) {
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

		initCallbackTabs( card );
		initArgBuilder( card );
		initWebhooks( card );
		initCardAI( card );
	}

	// ── Webhook builder ──────────────────────────────────────────────
	function initWebhooks( card ) {
		var addWHBtn = card.querySelector( '.cem-add-webhook' );
		var wrap     = card.querySelector( '.cem-webhook-rows' );

		if ( ! wrap ) {
			return;
		}

		// Track a monotonically increasing index to avoid key collisions after removes.
		var existingRows    = wrap.querySelectorAll( '.cem-webhook-row' );
		var existingRowsLen = existingRows.length;
		wrap.setAttribute( 'data-wh-idx', String( existingRowsLen ) );

		// Wire existing webhook rows rendered by PHP.
		var eIdx;
		for ( eIdx = 0; eIdx < existingRowsLen; eIdx++ ) {
			initWebhookRow( existingRows[ eIdx ] );
		}

		if ( addWHBtn ) {
			addWHBtn.addEventListener(
				'click',
				function () {
					var cardIdx = getCardIndex( card );
					var whIdx   = getNextWebhookIdx( wrap );
					var row     = buildWebhookRow( cardIdx, whIdx );
					wrap.appendChild( row );
					initWebhookRow( row );
				}
			);
		}
	}

	function initWebhookRow( row ) {
		var removeBtn = row.querySelector( '.cem-wh-remove' );
		var toggleBtn = row.querySelector( '.cem-wh-toggle-body' );
		var bodyArea  = row.querySelector( '.cem-wh-body' );
		var bodyHint  = row.querySelector( '.cem-wh-body-hint' );

		if ( removeBtn ) {
			removeBtn.addEventListener(
				'click',
				function () {
					row.parentNode.removeChild( row );
				}
			);
		}

		if ( toggleBtn && bodyArea ) {
			toggleBtn.addEventListener(
				'click',
				function () {
					var isHidden = 'none' === bodyArea.style.display || '' === bodyArea.style.display && getComputedStyle( bodyArea ).display === 'none';
					bodyArea.style.display = isHidden ? '' : 'none';
					if ( bodyHint ) {
						bodyHint.style.display = isHidden ? '' : 'none';
					}
					toggleBtn.innerHTML = isHidden ? 'Body &#9650;' : 'Body &#9660;';
				}
			);
		}
	}

	function buildWebhookRow( cardIdx, whIdx ) {
		var row       = document.createElement( 'div' );
		row.className = 'cem-webhook-row';

		var methodOpts  = '';
		var whMethods   = [ 'POST', 'GET', 'PUT', 'PATCH', 'DELETE' ];
		var whMethodLen = whMethods.length;
		var wIdx;
		for ( wIdx = 0; wIdx < whMethodLen; wIdx++ ) {
			var sel  = ( 'POST' === whMethods[ wIdx ] ) ? ' selected' : '';
			methodOpts += '<option value="' + whMethods[ wIdx ] + '"' + sel + '>' + whMethods[ wIdx ] + '</option>';
		}

		var base = 'cem_endpoints[' + cardIdx + '][outgoing][' + whIdx + ']';

		row.innerHTML = [
			'<select name="' + base + '[method]" class="cem-wh-method">' + methodOpts + '</select>',
			'<input type="url" name="' + base + '[url]" class="cem-wh-url" placeholder="https://example.com/webhook" />',
			'<button type="button" class="cem-wh-toggle-body button button-small">Body &#9660;</button>',
			'<button type="button" class="cem-wh-remove button-link" title="Remove webhook">&#x2715;</button>',
			'<textarea name="' + base + '[body_template]" class="cem-wh-body" rows="3"',
			'  placeholder=\'{"key": "{{body.field}}", "id": "{{query.id"}}"\' style="display:none"></textarea>',
			'<p class="cem-wh-body-hint description" style="display:none">',
			'  Use {{body.field}}, {{query.param}}, {{json.key}} placeholders. Leave blank to forward the full payload.',
			'</p>'
		].join( '\n' );

		return row;
	}

	function getCardIndex( card ) {
		var m = card.id ? card.id.match( /cem-card-(\d+)/ ) : null;
		return m ? parseInt( m[ 1 ], 10 ) : 0;
	}

	function getNextWebhookIdx( wrap ) {
		var cur = parseInt( wrap.getAttribute( 'data-wh-idx' ) || '0', 10 );
		wrap.setAttribute( 'data-wh-idx', String( cur + 1 ) );
		return cur;
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
			nameEl.addEventListener( 'input', function () { syncArgsText( tbody, argsHid ); } );
		}
		if ( typeEl ) {
			typeEl.addEventListener( 'change', function () { syncArgsText( tbody, argsHid ); } );
		}
		if ( removeEl ) {
			removeEl.addEventListener( 'click', function () {
				tbody.removeChild( tr );
				syncArgsText( tbody, argsHid );
			} );
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
		var argsBtn = card.querySelector( '.cem-ai-suggest-args' );
		if ( argsBtn ) {
			argsBtn.style.display = '';
			argsBtn.addEventListener( 'click', function () {
				handleAISuggestArgs( card, argsBtn );
			} );
		}
	}

	function handleAISuggestArgs( card, btn ) {
		var slug    = card.querySelector( '.cem-slug-input' ) ? card.querySelector( '.cem-slug-input' ).value.trim() : '';
		var tbody   = card.querySelector( '.cem-arg-tbody' );
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

	// Kick off AI status check after DOM ready.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initAI );
	} else {
		initAI();
	}

} )();
