/**
 * Admin JS for Custom Endpoints Manager.
 *
 * @package Custom_Endpoints_Manager
 */

( function () {
	'use strict';

	var stack    = document.getElementById( 'cem-endpoints-stack' );
	var addBtn   = document.getElementById( 'cem-add-endpoint' );
	var methods  = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ];
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
	var existingCards = stack.querySelectorAll( '.cem-endpoint-card' );
	var cardCount     = existingCards.length;
	var i;
	for ( i = 0; i < existingCards.length; i++ ) {
		initCard( existingCards[ i ] );
	}

	// ── Add endpoint button ──────────────────────────────────────────
	if ( addBtn ) {
		addBtn.addEventListener( 'click', function () {
			var card = buildNewCard( cardCount );
			stack.appendChild( card );
			initCard( card );
			cardCount++;
		} );
	}

	// ── Build a new blank card ───────────────────────────────────────
	function buildNewCard( idx ) {
		var div   = document.createElement( 'div' );
		div.className = 'cem-endpoint-card is-empty';
		div.id        = 'cem-card-' + idx;

		// Build microplugin options.
		var mpOpts = '<option value="">' + ( cemI18n.selectMicroplugin || '— Select Microplugin —' ) + '</option>';
		if ( typeof cemMicropluginOptions !== 'undefined' ) {
			var mpIdx;
			for ( mpIdx = 0; mpIdx < cemMicropluginOptions.length; mpIdx++ ) {
				var mp = cemMicropluginOptions[ mpIdx ];
				mpOpts += '<option value="' + mp.id + '">' + escHtml( mp.title ) + '</option>';
			}
		}

		// Build method pills.
		var pillsHtml = '';
		var mIdx;
		for ( mIdx = 0; mIdx < methods.length; mIdx++ ) {
			var m = methods[ mIdx ];
			var active = ( 'GET' === m ) ? ' is-active' : '';
			pillsHtml += '<label class="cem-method-pill' + active + '" data-method="' + m + '">'
				+ '<input type="checkbox" class="cem-method-cb" value="' + m + '"' + ( active ? ' checked' : '' ) + ' />'
				+ m + '</label>';
		}

		// Build capability datalist.
		var capPresets = [
			{ val: 'read',           lbl: 'read — logged-in user' },
			{ val: 'edit_posts',     lbl: 'edit_posts — editor+' },
			{ val: 'publish_posts',  lbl: 'publish_posts — author+' },
			{ val: 'manage_options', lbl: 'manage_options — admin only' }
		];
		var datalistOpts = '';
		var cIdx;
		for ( cIdx = 0; cIdx < capPresets.length; cIdx++ ) {
			datalistOpts += '<option value="' + capPresets[ cIdx ].val + '">' + escHtml( capPresets[ cIdx ].lbl ) + '</option>';
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
			'  <div class="cem-fields-grid">',

			'    <div class="cem-field-group">',
			'      <label for="cem-slug-' + idx + '">Route Slug</label>',
			'      <div class="cem-slug-row">',
			'        <span class="cem-slug-prefix">/wp-json/cem/v1/</span>',
			'        <input type="text" id="cem-slug-' + idx + '" name="cem_endpoints[' + idx + '][slug]" class="cem-slug-input" placeholder="my-endpoint" />',
			'      </div>',
			'    </div>',

			'    <div class="cem-field-group">',
			'      <label for="cem-mp-' + idx + '">Microplugin (Callback)</label>',
			'      <select id="cem-mp-' + idx + '" name="cem_endpoints[' + idx + '][microplugin_id]" class="cem-mp-select">' + mpOpts + '</select>',
			'      <p class="description">No callback assigned — endpoint returns 403 until a microplugin is selected.</p>',
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

			'    <div class="cem-field-group cem-field-full">',
			'      <label for="cem-args-' + idx + '">Arguments</label>',
			'      <input type="text" id="cem-args-' + idx + '" name="cem_endpoints[' + idx + '][args]" placeholder="id:integer, name:string, active:boolean" />',
			'      <p class="description">Comma-separated name:type pairs. Types: string, integer, number, boolean.</p>',
			'    </div>',

			'    <div class="cem-field-group cem-field-full">',
			'      <span class="cem-field-label">Async Execution</span>',
			'      <div class="cem-async-row">',
			'        <label>',
			'          <input type="checkbox" name="cem_endpoints[' + idx + '][async]" value="1" class="cem-async-toggle" />',
			'          Run asynchronously &mdash; returns job_id immediately, executes via WP Cron',
			'        </label>',
			'        <span class="cem-async-attempts">',
			'          <label for="cem-att-' + idx + '">Max attempts:</label>',
			'          <input type="number" id="cem-att-' + idx + '" name="cem_endpoints[' + idx + '][max_attempts]" value="3" min="1" max="10" />',
			'        </span>',
			'      </div>',
			'    </div>',

			'  </div>',
			'</div>'
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

		// Collapse / expand.
		if ( header ) {
			header.addEventListener( 'click', function ( e ) {
				// Don't collapse when clicking inputs or buttons inside the header.
				if ( e.target.tagName === 'BUTTON' || e.target.tagName === 'INPUT' ) {
					return;
				}
				card.classList.toggle( 'is-collapsed' );
			} );
		}

		if ( toggleBtn ) {
			toggleBtn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				card.classList.toggle( 'is-collapsed' );
			} );
		}

		// Remove.
		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				card.parentNode.removeChild( card );
				reindex();
			} );
		}

		// Slug → update header route.
		if ( slugInput ) {
			slugInput.addEventListener( 'input', function () {
				updateHeaderRoute( card, slugInput.value );
			} );
		}

		// Method pills → sync to hidden input + update header.
		var pillIdx;
		for ( pillIdx = 0; pillIdx < pills.length; pillIdx++ ) {
			( function ( pill ) {
				var cb = pill.querySelector( 'input[type="checkbox"]' );
				if ( cb ) {
					cb.addEventListener( 'change', function () {
						if ( cb.checked ) {
							pill.classList.add( 'is-active' );
						} else {
							pill.classList.remove( 'is-active' );
						}
						syncMethods( card, methodsHid, pills );
						updateHeaderMethods( card );
					} );
				}
			} )( pills[ pillIdx ] );
		}

		// Async toggle → show/hide attempts.
		if ( asyncToggle && asyncOpts ) {
			asyncToggle.addEventListener( 'change', function () {
				if ( asyncToggle.checked ) {
					asyncOpts.classList.add( 'is-visible' );
				} else {
					asyncOpts.classList.remove( 'is-visible' );
				}
			} );
		}
	}

	// ── Sync method checkboxes → hidden input ────────────────────────
	function syncMethods( card, hiddenInput, pills ) {
		if ( ! hiddenInput ) {
			return;
		}
		var checked = [];
		var pIdx;
		for ( pIdx = 0; pIdx < pills.length; pIdx++ ) {
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
		var active = methodsHid.value
			? methodsHid.value.split( ',' )
			: [];
		var html = '';
		if ( active.length === 0 ) {
			html = '<span class="cem-hdr-method cem-hdr-method--other">—</span>';
		} else {
			var mIdx;
			for ( mIdx = 0; mIdx < active.length; mIdx++ ) {
				var m   = active[ mIdx ].trim().toUpperCase();
				var cls = methodColors[ m ] ? methodColors[ m ] : 'cem-hdr-method--other';
				html   += '<span class="cem-hdr-method ' + cls + '">' + escHtml( m ) + '</span>';
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
		var cIdx;
		for ( cIdx = 0; cIdx < cards.length; cIdx++ ) {
			var c      = cards[ cIdx ];
			var inputs = c.querySelectorAll( '[name^="cem_endpoints["]' );
			var iIdx;
			for ( iIdx = 0; iIdx < inputs.length; iIdx++ ) {
				inputs[ iIdx ].name = inputs[ iIdx ].name.replace(
					/cem_endpoints\[\d+\]/,
					'cem_endpoints[' + cIdx + ']'
				);
			}
			c.id = 'cem-card-' + cIdx;
		}
		cardCount = cards.length;
	}

	// ── Minimal HTML escaping for dynamic content ─────────────────────
	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

} )();
