/**
 * WRM Mapping Builder + JSON Body Builder
 *
 * Two main components:
 *   WRM.MappingBuilder — visual editor for wrm_mapping CPT config
 *   WRM.BodyBuilder    — visual JSON payload constructor for webhook body_template
 *
 * No external dependencies beyond jQuery (WordPress-bundled) and wp.apiFetch.
 */
/* global jQuery, wp, wrmData */
(function ($, apiFetch) {
	'use strict';

	/* ====================================================================
	 * GLOBALS + UTILITIES
	 * ==================================================================== */

	const WRM = window.WRM = {};
	let _uid = 0;
	const uid  = ()  => ++_uid;
	const esc  = (s) => $('<div>').text(String(s ?? '')).html();
	// Mirror WP sanitize_key(): lowercase, allow a-z0-9_- only. Keeps the saved
	// config in sync with what the PHP stores so meta_json keys round-trip.
	const sanitizeKey = (s) => String(s ?? '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
	const i18n = (window.wrmData && window.wrmData.i18n) ? window.wrmData.i18n : {};
	const t    = (key, fallback) => i18n[key] || fallback;

	/* ====================================================================
	 * MERGE TAG MANAGER
	 * Fetches dot-paths from last capture and provides an inline picker.
	 * ==================================================================== */

	WRM.Tags = {
		list : [],       // [{ label: 'email', value: '{{email}}' }]
		_loaded: false,

		fetch (routeSlug) {
			if (!routeSlug) return;
			apiFetch({ path: '/wrm/v1/captures?route_slug=' + encodeURIComponent(routeSlug) + '&per_page=1' })
				.then(captures => {
					if (!captures || !captures.length) {
						WRM.Tags.list = [];
						WRM.Tags._render();
						return Promise.resolve([]);
					}
					return apiFetch({ path: '/wrm/v1/captures/' + captures[0].id + '/paths' });
				})
				.then(paths => {
					if (!paths) return;
					WRM.Tags.list = Array.isArray(paths)
						? paths.map(p => ({ label: p, value: '{{' + p + '}}' }))
						: [];
					WRM.Tags._loaded = true;
					WRM.Tags._render();
				})
				.catch(() => { WRM.Tags.list = []; WRM.Tags._render(); });
		},

		_render () {
			const $cloud = $('#wrm-available-tags');
			if (!$cloud.length) return;
			if (!WRM.Tags.list.length) {
				$cloud.html('<em class="wrm-muted">' + esc(t('noCaptures', 'No captures yet — send a test request to load available tags.')) + '</em>');
				return;
			}
			$cloud.html(
				WRM.Tags.list.map(tag =>
					'<code class="wrm-tag" data-value="' + esc(tag.value) + '" title="Click to copy">' + esc(tag.label) + '</code>'
				).join(' ')
			);
		},

		insert (value, el) {
			if (!el) return;
			const start = el.selectionStart ?? el.value.length;
			const end   = el.selectionEnd   ?? start;
			el.value = el.value.slice(0, start) + value + el.value.slice(end);
			el.dispatchEvent(new Event('input', { bubbles: true }));
			el.focus();
			el.setSelectionRange(start + value.length, start + value.length);
		},

		showPicker (inputEl, $anchor) {
			$('.wrm-tag-popup').remove();
			if (!WRM.Tags.list.length) {
				alert(t('noCaptures', 'No captures yet.'));
				return;
			}
			const $pop = $('<div class="wrm-tag-popup">');
			WRM.Tags.list.forEach(tag => {
				$pop.append(
					$('<div class="wrm-tag-pop-item">').text(tag.label).on('click', function (e) {
						e.stopPropagation();
						WRM.Tags.insert(tag.value, inputEl);
						$pop.remove();
					})
				);
			});
			const pos = $anchor.offset();
			$pop.css({ position: 'absolute', top: pos.top + $anchor.outerHeight() + 4, left: pos.left, zIndex: 99999 });
			$('body').append($pop);
			$(document).one('click.wrmTagPop', () => $pop.remove());
		}
	};

	/* ====================================================================
	 * JSON BODY BUILDER
	 *
	 * Builds a visual JSON tree. Each node:
	 *   { id, type, key, value?, children?, loop?, loop_source?, loop_template?, loop_join? }
	 *
	 * Types: string | number | boolean | null | object | array
	 *
	 * Array nodes support two modes:
	 *   loop=false — static array, children are items
	 *   loop=true  — iterate over payload.{loop_source}, render loop_template per item
	 *
	 * Output: serialize() returns a plain JS value; toJSON() returns JSON string.
	 * Loop arrays are stored as { __wrm_loop__: true, source, template, join } objects.
	 * The PHP WRM_Mapper processes these markers when rendering webhook bodies.
	 * ==================================================================== */

	WRM.BodyBuilder = (function () {

		function _uid () { return 'bb_' + uid(); }

		function _fromVal (val, key) {
			if (val === null)                              return { id: _uid(), type: 'null',    key };
			if (typeof val === 'boolean')                  return { id: _uid(), type: 'boolean', key, value: val };
			if (typeof val === 'number')                   return { id: _uid(), type: 'number',  key, value: val };
			if (typeof val === 'string')                   return { id: _uid(), type: 'string',  key, value: val };
			if (val && val.__wrm_loop__)                   return { id: _uid(), type: 'array', key, loop: true,  loop_source: val.source || '', loop_template: val.template || '', loop_join: val.join || '', children: [] };
			if (Array.isArray(val))                        return { id: _uid(), type: 'array',   key, loop: false, loop_source: '', loop_template: '', loop_join: '', children: val.map((v, i) => _fromVal(v, String(i))) };
			return { id: _uid(), type: 'object', key, children: Object.entries(val).map(([k, v]) => _fromVal(v, k)) };
		}

		function parse (jsonStr) {
			try {
				const v = JSON.parse(typeof jsonStr === 'string' && jsonStr ? jsonStr : '{}');
				return _fromVal(v, '__root__');
			} catch (e) {
				return { id: _uid(), type: 'object', key: '__root__', children: [] };
			}
		}

		function serialize (node) {
			switch (node.type) {
				case 'null':    return null;
				case 'boolean': return Boolean(node.value);
				case 'number':  return Number(node.value ?? 0);
				case 'string':  return String(node.value ?? '');
				case 'array':
					if (node.loop && node.loop_source) {
						return { __wrm_loop__: true, source: node.loop_source, template: node.loop_template || '', join: node.loop_join ?? '' };
					}
					return (node.children || []).map(c => serialize(c));
				case 'object': {
					const obj = {};
					(node.children || []).forEach(c => { if (c.key) obj[c.key] = serialize(c); });
					return obj;
				}
			}
			return null;
		}

		function toJSON (node) {
			return JSON.stringify(serialize(node), null, 2);
		}

		function removeById (node, targetId) {
			if (!node.children) return false;
			const i = node.children.findIndex(c => c.id === targetId);
			if (i !== -1) { node.children.splice(i, 1); return true; }
			return node.children.some(c => removeById(c, targetId));
		}

		/* Render the full builder into $container, with root node bound.
		   onChange() is called whenever state changes. */
		function render ($container, root, onChange) {
			$container.empty().data('bbRoot', root);
			const $tree = $('<div class="wrm-bb-tree">');
			_renderNode($tree, root, root, onChange, true);
			$container.append($tree);

			// Raw JSON toggle
			const $toolbar = $('<div class="wrm-bb-toolbar">');
			const $raw = $('<textarea class="wrm-bb-raw code" rows="8" spellcheck="false">').val(toJSON(root)).hide();
			const $toggle = $('<button type="button" class="button button-small wrm-bb-raw-toggle">⬌ Raw JSON</button>');
			$toggle.on('click', function () {
				if ($raw.is(':hidden')) {
					$raw.val(toJSON(root)).show();
					$tree.hide();
					$(this).text('⬌ Visual');
				} else {
					try {
						const parsed = JSON.parse($raw.val());
						const newRoot = _fromVal(parsed, '__root__');
						Object.assign(root, newRoot);
						$tree.show();
						$raw.hide();
						$(this).text('⬌ Raw JSON');
						render($container, root, onChange);
						onChange();
					} catch (ex) {
						$raw.addClass('wrm-error');
						setTimeout(() => $raw.removeClass('wrm-error'), 1000);
					}
				}
			});

			$container.append($toolbar.append($toggle)).append($raw);
		}

		const TYPES = ['string', 'number', 'boolean', 'null', 'object', 'array'];

		function _renderNode ($parent, node, root, onChange, isRoot) {
			const $row = $('<div class="wrm-bb-row">').attr('data-id', node.id);
			if (!isRoot) $row.addClass('wrm-bb-child');

			// Key input for object children
			if (!isRoot) {
				const $key = $('<input type="text" class="wrm-bb-key" placeholder="key">').val(node.key || '');
				$key.on('input', function () { node.key = $(this).val(); onChange(); });
				$row.append($key).append('<span class="wrm-bb-sep">:</span>');
			}

			// Type selector
			const $type = $('<select class="wrm-bb-type">').append(
				TYPES.map(t => $('<option>').val(t).text(t).prop('selected', t === node.type))
			);
			$type.on('change', function () {
				const nt = $(this).val();
				node.type = nt;
				// Re-init type-specific props
				if (nt === 'object' || nt === 'array') { if (!node.children) node.children = []; }
				if (nt === 'array') { node.loop = node.loop ?? false; node.loop_source = node.loop_source ?? ''; node.loop_template = node.loop_template ?? ''; node.loop_join = node.loop_join ?? ''; }
				if (nt === 'string')  { node.value = node.value ?? ''; }
				if (nt === 'number')  { node.value = Number(node.value) || 0; }
				if (nt === 'boolean') { node.value = Boolean(node.value); }
				onChange();
				// Re-render this node's value area only
				$valWrap.empty();
				_renderValue($valWrap, node, root, onChange);
			});
			$row.append($type);

			// Value/children area
			const $valWrap = $('<div class="wrm-bb-val">');
			_renderValue($valWrap, node, root, onChange);
			$row.append($valWrap);

			// Remove button (not root)
			if (!isRoot) {
				const $del = $('<button type="button" class="wrm-bb-del button-link" title="Remove">✕</button>');
				$del.on('click', function () {
					removeById(root, node.id);
					onChange();
					$row.remove();
				});
				$row.append($del);
			}

			$parent.append($row);
		}

		function _renderValue ($wrap, node, root, onChange) {
			switch (node.type) {
				case 'string': {
					const $inp = $('<input type="text" class="wrm-bb-string" placeholder="value or {{tag}}">').val(node.value ?? '');
					$inp.on('input', function () { node.value = $(this).val(); onChange(); });
					const $tagBtn = $('<button type="button" class="button-link wrm-bb-tag-btn" title="' + esc(t('insertTag', 'Insert merge tag')) + '">⟨…⟩</button>');
					$tagBtn.on('click', function () { WRM.Tags.showPicker($inp[0], $inp); });
					$wrap.append($inp).append($tagBtn);
					break;
				}
				case 'number': {
					const $inp = $('<input type="number" class="wrm-bb-number">').val(node.value ?? 0);
					$inp.on('input', function () { node.value = Number($(this).val()); onChange(); });
					$wrap.append($inp);
					break;
				}
				case 'boolean': {
					const $sel = $('<select class="wrm-bb-bool">').append(
						$('<option value="true">true</option>'),
						$('<option value="false">false</option>')
					).val(String(node.value ?? true));
					$sel.on('change', function () { node.value = $(this).val() === 'true'; onChange(); });
					$wrap.append($sel);
					break;
				}
				case 'null':
					$wrap.append('<em class="wrm-bb-null-label">null</em>');
					break;
				case 'object': {
					const $kids = $('<div class="wrm-bb-children">');
					(node.children || []).forEach(c => _renderNode($kids, c, root, onChange, false));
					const $add = $('<button type="button" class="button-link wrm-bb-add">+ field</button>');
					$add.on('click', function () {
						const c = { id: 'bb_' + uid(), type: 'string', key: 'key', value: '' };
						if (!node.children) node.children = [];
						node.children.push(c);
						_renderNode($kids, c, root, onChange, false);
						onChange();
					});
					$wrap.append($kids).append($add);
					break;
				}
				case 'array': {
					const $loopRow = $('<div class="wrm-bb-loop-row">');
					const $loopChk = $('<label class="wrm-bb-loop-label"><input type="checkbox" ' + (node.loop ? 'checked' : '') + '> Loop over array</label>');
					$loopRow.append($loopChk);
					$wrap.append($loopRow);

					const $loopCfg = $('<div class="wrm-bb-loop-cfg">').toggle(!!node.loop);
					const $staticCfg = $('<div class="wrm-bb-static-cfg">').toggle(!node.loop);

					// Loop config
					const $src = $('<input type="text" class="widefat wrm-bb-loop-src" placeholder="payload.items (dot-path)">').val(node.loop_source || '');
					$src.on('input', function () { node.loop_source = $(this).val(); onChange(); });
					const $tpl = $('<input type="text" class="widefat wrm-bb-loop-tpl" placeholder="{{item.name}} (template per item)">').val(node.loop_template || '');
					$tpl.on('input', function () { node.loop_template = $(this).val(); onChange(); });
					const $join = $('<input type="text" class="short-text wrm-bb-loop-join" placeholder="join or ARRAY">').val(node.loop_join || '');
					$join.attr('title', 'Use ARRAY to output a JSON array, or a string delimiter like comma to join as string');
					$join.on('input', function () { node.loop_join = $(this).val(); onChange(); });
					$loopCfg.append(
						$('<label>Source array path</label>'), $src,
						$('<label>Item template (use {{item}} or {{item.field}})</label>'), $tpl,
						$('<label>Output: ARRAY or join delimiter</label>'), $join
					);

					// Static children
					const $kids = $('<div class="wrm-bb-children">');
					(node.children || []).forEach(c => _renderNode($kids, c, root, onChange, false));
					const $addItem = $('<button type="button" class="button-link wrm-bb-add">+ item</button>');
					$addItem.on('click', function () {
						const c = { id: 'bb_' + uid(), type: 'string', key: '', value: '' };
						if (!node.children) node.children = [];
						node.children.push(c);
						_renderNode($kids, c, root, onChange, false);
						onChange();
					});
					$staticCfg.append($kids).append($addItem);

					$loopChk.find('input').on('change', function () {
						node.loop = $(this).is(':checked');
						$loopCfg.toggle(node.loop);
						$staticCfg.toggle(!node.loop);
						onChange();
					});

					$wrap.append($loopCfg).append($staticCfg);
					break;
				}
			}
		}

		return { parse, serialize, toJSON, render, _fromVal };
	}());

	/* ====================================================================
	 * MAPPING BUILDER
	 *
	 * Reads/writes #wrm_config_json hidden textarea.
	 * State shape:
	 *   { cpt, post_status, update_existing, match_source, match_field, fields[], chains[] }
	 *
	 * Field:
	 *   { source, target, loop, loop_type, loop_template, loop_join }
	 *
	 * Chain (webhook / dispatcher):
	 *   { type, url, method, timeout, headers{}, signing_secret, body_builder }
	 * Chain (email):
	 *   { type, to, subject, format(text|html|mjml), body_template, mjml_source, cc, bcc, track }
	 * Chain (sms):
	 *   { type, provider(twilio|whatsapp|sinch|messagemedia|webhook), to, from, body, track, ...creds }
	 * Chain (action):
	 *   { type, hook }
	 * Chain (function):
	 *   { type, function }
	 * Chain (mapping):
	 *   { type, mapping_id, chain_source, chain_source_key }
	 * ==================================================================== */

	WRM.MappingBuilder = (function () {

		let _state  = { cpt: 'post', post_status: 'publish', update_existing: false, match_source: '', match_field: '', fields: [], chains: [] };
		let $config = null;
		let $root   = null;

		function _save () {
			if ($config) $config.val(JSON.stringify(_state, null, 2));
			$(document).trigger('wrm:statechange', [_state]);
		}

		function init ($container) {
			$root   = $container;
			$config = $('#wrm_config_json');
			try {
				const raw = $config.val();
				if (raw && raw !== '{}') Object.assign(_state, JSON.parse(raw));
			} catch (e) { /* use defaults */ }

			// Ensure arrays exist
			if (!Array.isArray(_state.fields))  _state.fields  = [];
			if (!Array.isArray(_state.chains))  _state.chains  = [];

			render();

			// Fetch tags for associated route
			const routeSlug = document.getElementById('wrm_route_slug');
			if (routeSlug && routeSlug.value) WRM.Tags.fetch(routeSlug.value);
			$(document).on('change', '#wrm_route_slug', function () { WRM.Tags.fetch($(this).val()); });
		}

		function render () {
			if (!$root) return;
			$root.empty();
			$root.append(
				_panel('⚙ General Settings', _renderGeneral(), false),
				_panel('📋 Field Mappings', _renderFields(), false),
				_panel('⛓ Action Chains', _renderChains(), false),
				_panel('🏷 Available Merge Tags', _renderTagsPanel(), true),
				_panel('👁 Config Preview', _renderPreview(), true)
			);
		}

		/* ---- Shared panel wrapper ---------------------------------------- */
		function _panel (title, $body, collapsed) {
			const $p  = $('<div class="wrm-panel postbox">');
			const $h  = $('<div class="postbox-header">').append(
				$('<h2 class="hndle">').append(
					$('<span>').text(title),
					$('<button type="button" class="handle-order-higher" aria-label="Collapse">▾</button>').on('click', function () {
						const $inside = $(this).closest('.postbox').find('.inside');
						$inside.slideToggle(150);
						$(this).text($inside.is(':visible') ? '▾' : '▸');
					})
				)
			);
			const $inside = $('<div class="inside">').append($body);
			if (collapsed) $inside.hide();
			return $p.append($h).append($inside);
		}

		/* ---- GENERAL SETTINGS panel -------------------------------------- */
		function _renderGeneral () {
			const s    = _state;
			const $row = $('<div class="wrm-grid-2">');

			// CPT selector
			const $cptSel = _cptSelect(s.cpt, v => { s.cpt = v; _save(); });
			$row.append(_frow('Target Post Type', $cptSel));

			// Post status
			const $statusSel = $('<select>').append(
				['publish', 'draft', 'private', 'pending'].map(st => $('<option>').val(st).text(st).prop('selected', st === s.post_status))
			).on('change', function () { s.post_status = $(this).val(); _save(); });
			$row.append(_frow('Post Status', $statusSel));

			// Update existing toggle
			const $updateChk = $('<input type="checkbox" id="wrm_update_existing">').prop('checked', s.update_existing);
			const $matchBlock = $('<div class="wrm-match-block">').toggle(s.update_existing).append(
				_frow('Match source path', $('<input type="text" class="widefat" placeholder="e.g. email">').val(s.match_source).on('input', function () { s.match_source = $(this).val(); _save(); }), 'Dot-path in payload to match against'),
				_frow('Match post field', $('<input type="text" class="widefat" placeholder="post_title or meta:user_email">').val(s.match_field).on('input', function () { s.match_field = $(this).val(); _save(); }))
			);
			$updateChk.on('change', function () { s.update_existing = $(this).is(':checked'); $matchBlock.slideToggle(150); _save(); });

			const $wrap = $('<div class="wrm-full">').append(
				$('<div class="wrm-toggle-row">').append(
					$('<label class="wrm-toggle">').append($updateChk, ' Update existing post if found')
				),
				$matchBlock
			);

			return $('<div>').append($row).append($wrap);
		}

		/* ---- FIELDS MAPPINGS panel --------------------------------------- */
		function _renderFields () {
			const $list = $('<div class="wrm-fields-list">');
			_state.fields.forEach((f, idx) => $list.append(_fieldRow(f, idx)));

			const $add = $('<button type="button" class="button wrm-btn-add">+ Add Field Mapping</button>');
			$add.on('click', function () {
				const f = { source: '', target: 'post_title', loop: false, loop_type: 'join', loop_template: '{{item}}', loop_join: ',' };
				_state.fields.push(f);
				$list.append(_fieldRow(f, _state.fields.length - 1));
				_save();
			});

			return $('<div class="wrm-fields-wrap">').append($list).append($add);
		}

		function _fieldRow (field, idx) {
			const $row = $('<div class="wrm-field-row">').attr('data-idx', idx);

			// Drag handle
			$row.append('<span class="wrm-drag dashicons dashicons-move" draggable="true"></span>');

			// Source cell
			const $srcInp = $('<input type="text" class="widefat" placeholder="payload.field or {{tag}}">').val(field.source);
			$srcInp.on('input', function () { field.source = $(this).val(); _save(); });
			const $srcTag = $('<button type="button" class="button-link wrm-tag-btn" title="' + esc(t('insertTag', 'Insert tag')) + '">◉</button>');
			$srcTag.on('click', function () { WRM.Tags.showPicker($srcInp[0], $srcInp); });
			const $srcCell = $('<div class="wrm-cell wrm-cell-src">').append(
				$('<label>Source</label>'),
				$('<div class="wrm-input-btn">').append($srcInp, $srcTag)
			);

			// Loop toggle inside source cell
			const $loopChk = $('<input type="checkbox">').prop('checked', !!field.loop);
			const $loopOpts = $('<div class="wrm-loop-opts">').toggle(!!field.loop).append(
				_frow('Loop type', $('<select>').append(
					$('<option value="join">Join to string</option>'),
					$('<option value="create_each">Create post per item</option>'),
					$('<option value="json_array">Store as JSON array</option>')
				).val(field.loop_type || 'join').on('change', function () { field.loop_type = $(this).val(); $joinRow.toggle(field.loop_type === 'join'); _save(); })),
				_frow('Item template', $('<input type="text" class="widefat" placeholder="{{item}} or {{item.name}}">').val(field.loop_template || '{{item}}').on('input', function () { field.loop_template = $(this).val(); _save(); })),
				_frow('Separator', $('<input type="text" class="short-text" placeholder=",">').val(field.loop_join || ',').on('input', function () { field.loop_join = $(this).val(); _save(); }))
			);
			var $joinRow = $loopOpts.find('.wrm-form-row:last');
			$joinRow.toggle(field.loop_type === 'join');
			$loopChk.on('change', function () { field.loop = $(this).is(':checked'); $loopOpts.slideToggle(150); _save(); });
			$srcCell.append(
				$('<label class="wrm-loop-label">').append($loopChk, ' Loop (source is array)'),
				$loopOpts
			);

			// Arrow
			const $arrow = $('<div class="wrm-cell-arrow">→</div>');

			// Target cell
			const $tgtCell = $('<div class="wrm-cell wrm-cell-tgt">').append(
				$('<label>Target</label>'),
				_targetSelector(field)
			);

			// Remove button
			const $del = $('<button type="button" class="button-link wrm-del-row" title="Remove">✕</button>');
			$del.on('click', function () {
				const i = _state.fields.indexOf(field);
				if (i !== -1) _state.fields.splice(i, 1);
				$row.slideUp(150, function () { $(this).remove(); });
				_save();
			});

			return $row.append($srcCell, $arrow, $tgtCell, $del);
		}

		function _targetSelector (field) {
			let tType = 'post_field', tKey = field.target || 'post_title';
			if (tKey.startsWith('meta_json:'))  { tType = 'meta_json'; tKey = tKey.slice(10); }
			else if (tKey.startsWith('meta:'))  { tType = 'meta';      tKey = tKey.slice(5); }
			else if (tKey.startsWith('taxonomy:')) { tType = 'taxonomy'; tKey = tKey.slice(9); }

			const PREFIX = { post_field: '', meta: 'meta:', meta_json: 'meta_json:', taxonomy: 'taxonomy:' };

			const $wrap    = $('<div class="wrm-target-sel">');
			const $typesel = $('<select class="wrm-tgt-type">').append(
				$('<option value="post_field">Post field</option>'),
				$('<option value="meta">Post meta (meta:key)</option>'),
				$('<option value="meta_json">Meta JSON (meta_json:key)</option>'),
				$('<option value="taxonomy">Taxonomy (taxonomy:name)</option>')
			).val(tType);

			const POST_FIELDS = ['post_title', 'post_content', 'post_excerpt', 'post_name', 'post_date', 'post_author', 'post_status', 'menu_order'];
			const $pfsel = $('<select class="wrm-pf-sel">').append(
				POST_FIELDS.map(f => $('<option>').val(f).text(f).prop('selected', f === tKey))
			).toggle(tType === 'post_field');
			const $keyinp = $('<input type="text" class="wrm-key-inp widefat" placeholder="key name">').val(tKey).toggle(tType !== 'post_field');

			function commit () {
				const tt = $typesel.val();
				// Key-based targets (meta/meta_json/taxonomy) are sanitized server-side
				// with sanitize_key(); normalize here so the saved config matches storage.
				const tk = tt === 'post_field' ? $pfsel.val() : sanitizeKey($keyinp.val());
				field.target = (PREFIX[tt] || '') + tk;
				_save();
			}
			$typesel.on('change', function () {
				const tt = $(this).val();
				$pfsel.toggle(tt === 'post_field');
				$keyinp.toggle(tt !== 'post_field');
				commit();
			});
			$pfsel.on('change',  commit);
			$keyinp.on('input',  commit);
			// Reflect the sanitized key back into the field once focus leaves.
			$keyinp.on('blur', function () {
				if ($typesel.val() !== 'post_field') { $(this).val(sanitizeKey($(this).val())); }
			});

			return $wrap.append($typesel).append($pfsel).append($keyinp);
		}

		/* ---- ACTION CHAINS panel ----------------------------------------- */
		function _renderChains () {
			const $list = $('<div class="wrm-chains-list">');
			_state.chains.forEach((c) => $list.append(_chainItem(c)));

			const $addRow = $('<div class="wrm-add-chain-row">');
			['webhook', 'dispatcher', 'email', 'sms', 'action', 'function', 'mapping'].forEach(type => {
				const icons = { webhook: '🔗', dispatcher: '📡', email: '✉', sms: '💬', action: '⚡', function: '⚙', mapping: '🗺' };
				$addRow.append(
					$('<button type="button" class="button wrm-add-chain-btn">').text(icons[type] + ' ' + type)
						.on('click', function () {
							const chain = _newChain(type);
							_state.chains.push(chain);
							$list.append(_chainItem(chain));
							_save();
						})
				);
			});

			return $('<div class="wrm-chains-wrap">').append($list).append($addRow);
		}

		function _newChain (type) {
			const base = { type, _uid: uid() };
			const defaults = {
				webhook:    { url: '', method: 'POST', timeout: 15, headers: {}, signing_secret: '', body_builder: null },
				dispatcher: { url: '', method: 'POST', timeout: 15, headers: {}, signing_secret: '', body_builder: null },
				email:      { to: '', subject: '', format: 'html', body_template: '', mjml_source: '', cc: '', bcc: '', track: true },
				sms:        { provider: 'twilio', to: '', from: '', body: '', track: true, account_sid: '', auth_token: '', phone_number_id: '', access_token: '', api_version: 'v19.0', service_plan_id: '', api_token: '', api_key: '', api_secret: '', url: '', signing_secret: '' },
				action:     { hook: '' },
				function:   { function: '' },
				mapping:    { mapping_id: 0, chain_source: 'payload', chain_source_key: '' }
			};
			return Object.assign(base, defaults[type] || {});
		}

		function _chainItem (chain) {
			const icons = { webhook: '🔗', dispatcher: '📡', email: '✉', sms: '💬', action: '⚡', function: '⚙', mapping: '🗺' };
			const $item = $('<div class="wrm-chain-item">').attr('data-uid', chain._uid);

			const $hdr = $('<div class="wrm-chain-hdr">').append(
				$('<span class="wrm-drag dashicons dashicons-move" draggable="true">'),
				$('<span class="wrm-chain-badge wrm-chain-' + esc(chain.type) + '">').text((icons[chain.type] || '') + ' ' + chain.type),
				$('<button type="button" class="button-link wrm-chain-tog">▾</button>').on('click', function () {
					$body.slideToggle(150);
					$(this).text($body.is(':visible') ? '▾' : '▸');
				}),
				$('<button type="button" class="button-link wrm-chain-del" title="Remove">✕</button>').on('click', function () {
					const i = _state.chains.indexOf(chain);
					if (i !== -1) _state.chains.splice(i, 1);
					$item.slideUp(150, function () { $(this).remove(); });
					_save();
				})
			);

			const $body = $('<div class="wrm-chain-body">');
			const renderers = { webhook: _chainWebhook, dispatcher: _chainWebhook, email: _chainEmail, sms: _chainSms, action: _chainAction, function: _chainFunction, mapping: _chainMapping };
			(renderers[chain.type] || (() => $('<p>Unknown type</p>')))(chain, $body);

			return $item.append($hdr).append($body);
		}

		function _chainWebhook (chain, $body) {
			$body.append(
				_frow('URL',
					$('<input type="url" class="widefat" placeholder="https://api.example.com/webhook">').val(chain.url || '').on('input', function () { chain.url = $(this).val(); _save(); })
				),
				_frow('Method',
					$('<select>').append(
						['POST', 'GET', 'PUT', 'PATCH', 'DELETE'].map(m => $('<option>').val(m).text(m).prop('selected', m === (chain.method || 'POST')))
					).on('change', function () { chain.method = $(this).val(); _save(); })
				),
				_frow('Timeout (s)',
					$('<input type="number" min="1" max="60" class="small-text">').val(chain.timeout || 15).on('input', function () { chain.timeout = parseInt($(this).val(), 10) || 15; _save(); })
				),
				_frow('Signing Secret',
					$('<input type="text" class="widefat" placeholder="Leave blank to skip outbound HMAC signing">').val(chain.signing_secret || '').on('input', function () { chain.signing_secret = $(this).val(); _save(); }),
					'When set, adds <code>X-WRM-Signature: sha256=&lt;hmac&gt;</code> to the request.'
				)
			);

			// Custom headers
			const $hdrList = $('<div class="wrm-header-rows">');
			function _renderHeaders () {
				$hdrList.empty();
				Object.entries(chain.headers || {}).forEach(([k, v]) => {
					const $kr = $('<input type="text" class="wrm-hdr-key" placeholder="Header-Name">').val(k);
					const $vr = $('<input type="text" class="wrm-hdr-val" placeholder="value or {{tag}}">').val(v);
					const $dr = $('<button type="button" class="button-link">✕</button>').on('click', function () {
						delete chain.headers[k];
						_renderHeaders();
						_save();
					});
					const origKey = k;
					$kr.on('blur', function () {
						if ($(this).val() !== origKey) {
							delete chain.headers[origKey];
							chain.headers[$(this).val()] = $vr.val();
						}
						_save();
					});
					$vr.on('input', function () { chain.headers[$kr.val()] = $(this).val(); _save(); });
					$hdrList.append($('<div class="wrm-hdr-row">').append($kr, $('<span>:</span>'), $vr, $dr));
				});
			}
			_renderHeaders();
			const $addHdr = $('<button type="button" class="button-link">+ Add header</button>').on('click', function () {
				if (!chain.headers) chain.headers = {};
				chain.headers['X-Custom'] = '';
				_renderHeaders();
				_save();
			});
			$body.append(_frow('Headers', $('<div>').append($hdrList, $addHdr)));

			// Body Builder
			const $bbContainer = $('<div class="wrm-bb-container">');
			const bbRoot = WRM.BodyBuilder.parse(
				chain.body_builder ? JSON.stringify(chain.body_builder) : '{}'
			);
			$bbContainer.data('bbRoot', bbRoot);
			WRM.BodyBuilder.render($bbContainer, bbRoot, function () {
				chain.body_builder = JSON.parse(WRM.BodyBuilder.toJSON(bbRoot));
				_save();
			});
			$body.append(_frow('Payload Body Builder', $bbContainer,
				'Visually construct the outgoing JSON payload. Use {{payload.field}} for merge tags. Supports nested objects, static arrays, and loop arrays.'));
		}

		// Shared: a text input with a merge-tag picker button.
		function _tagInput (chain, key, placeholder) {
			const $inp = $('<input type="text" class="widefat">').val(chain[key] || '').attr('placeholder', placeholder || '');
			$inp.on('input', function () { chain[key] = $(this).val(); _save(); });
			const $tag = $('<button type="button" class="button-link wrm-tag-btn" title="Insert merge tag">◉</button>');
			$tag.on('click', function () { WRM.Tags.showPicker($inp[0], $inp); });
			return $('<div class="wrm-input-btn">').append($inp, $tag);
		}
		function _textInput (chain, key, placeholder, type) {
			return $('<input class="widefat">').attr('type', type || 'text').attr('placeholder', placeholder || '')
				.val(chain[key] || '').on('input', function () { chain[key] = $(this).val(); _save(); });
		}

		function _chainEmail (chain, $body) {
			$body.append(
				_frow('To', _tagInput(chain, 'to', '{{payload.email}}')),
				_frow('Subject', _tagInput(chain, 'subject', 'New submission: {{payload.name}}'))
			);

			// Format selector switches between the plain/HTML body and MJML source.
			const $bodyRow = _frow('Body',
				(function () {
					const $ta = $('<textarea class="widefat" rows="6" placeholder="Hi {{payload.name}}, ...">').val(chain.body_template || '');
					$ta.on('input', function () { chain.body_template = $(this).val(); _save(); });
					return $ta;
				})(),
				'Supports merge tags. Choose HTML to send text/html.'
			);
			const $mjmlRow = _frow('MJML source',
				(function () {
					const $ta = $('<textarea class="widefat code" rows="10" placeholder="<mjml><mj-body><mj-section><mj-column><mj-text>Hi {{payload.name}}</mj-text></mj-column></mj-section></mj-body></mjml>">').val(chain.mjml_source || '');
					$ta.on('input', function () { chain.mjml_source = $(this).val(); _save(); });
					return $ta;
				})(),
				'Compiled to responsive HTML (via the MJML API or the <code>wrm_render_mjml</code> filter; falls back to a safe responsive wrapper).'
			);

			const fmt = chain.format || (chain.html ? 'html' : 'text');
			const applyFormat = (f) => {
				$bodyRow.toggle(f === 'text' || f === 'html');
				$mjmlRow.toggle(f === 'mjml');
			};
			$body.append(_frow('Format',
				$('<select>').append(
					$('<option value="text">Plain text</option>'),
					$('<option value="html">HTML</option>'),
					$('<option value="mjml">MJML → responsive HTML</option>')
				).val(fmt).on('change', function () { chain.format = $(this).val(); applyFormat(chain.format); _save(); })
			));
			$body.append($bodyRow, $mjmlRow);
			applyFormat(fmt);

			$body.append(
				_frow('Cc (optional)', _tagInput(chain, 'cc', '')),
				_frow('Bcc (optional)', _tagInput(chain, 'bcc', '')),
				_frow('Tracking',
					$('<label class="wrm-toggle">').append(
						$('<input type="checkbox">').prop('checked', chain.track !== false).on('change', function () { chain.track = $(this).is(':checked'); _save(); }),
						' Track opens & clicks (injects a pixel and rewrites links in HTML/MJML emails)'
					)
				)
			);
		}

		// Per-provider credential field definitions for the SMS/messaging chain.
		const SMS_PROVIDERS = {
			twilio:       { label: 'Twilio',        fields: [['account_sid', 'Account SID', 'ACxxxx', 'text'], ['auth_token', 'Auth Token', '', 'password'], ['from', 'From number', '+15551234567', 'text']] },
			whatsapp:     { label: 'WhatsApp (Meta Cloud)', fields: [['phone_number_id', 'Phone Number ID', '', 'text'], ['access_token', 'Access Token', '', 'password'], ['api_version', 'Graph API version', 'v19.0', 'text']] },
			sinch:        { label: 'Sinch',         fields: [['service_plan_id', 'Service Plan ID', '', 'text'], ['api_token', 'API Token', '', 'password'], ['from', 'From', '', 'text']] },
			messagemedia: { label: 'MessageMedia',  fields: [['api_key', 'API Key', '', 'text'], ['api_secret', 'API Secret', '', 'password']] },
			webhook:      { label: 'Webhook',       fields: [['url', 'Webhook URL', 'https://...', 'text'], ['signing_secret', 'Signing Secret (optional)', '', 'password']] },
		};

		function _chainSms (chain, $body) {
			$body.append(
				_frow('Provider',
					$('<select>').append(
						Object.entries(SMS_PROVIDERS).map(([k, v]) => $('<option>').val(k).text(v.label))
					).val(chain.provider || 'twilio').on('change', function () {
						chain.provider = $(this).val();
						_save();
						renderProviderFields();
					}),
					'Other gateways: return a result array from the <code>wrm_send_message</code> PHP filter.'
				),
				_frow('To', _tagInput(chain, 'to', '{{payload.phone}}')),
				_frow('Message',
					(function () {
						const $ta = $('<textarea class="widefat" rows="3" placeholder="Hi {{payload.name}}, ...">').val(chain.body || '');
						$ta.on('input', function () { chain.body = $(this).val(); _save(); });
						return $ta;
					})()
				)
			);

			const $creds = $('<div class="wrm-sms-creds">');
			function renderProviderFields () {
				$creds.empty();
				const def = SMS_PROVIDERS[chain.provider || 'twilio'] || SMS_PROVIDERS.twilio;
				def.fields.forEach(([key, label, ph, type]) => {
					$creds.append(_frow(label, _textInput(chain, key, ph, type)));
				});
			}
			renderProviderFields();
			$body.append($creds);

			$body.append(
				_frow('Tracking',
					$('<label class="wrm-toggle">').append(
						$('<input type="checkbox">').prop('checked', chain.track !== false).on('change', function () { chain.track = $(this).is(':checked'); _save(); }),
						' Track delivery (store message; provider status webhooks update delivered/bounced/failed)'
					)
				)
			);
		}

		function _chainAction (chain, $body) {
			$body.append(_frow('WP Action Hook',
				$('<input type="text" class="widefat" placeholder="my_hook_name">').val(chain.hook || '').on('input', function () { chain.hook = $(this).val(); _save(); }),
				'Fires <code>do_action( hook, $post_id, $payload, $context )</code>'
			));
		}

		function _chainFunction (chain, $body) {
			$body.append(_frow('PHP Function Name',
				$('<input type="text" class="widefat" placeholder="my_callback_function">').val(chain.function || '').on('input', function () { chain['function'] = $(this).val(); _save(); }),
				'Must exist: called as <code>fn( $post_id, $payload, $context )</code>'
			));
		}

		function _chainMapping (chain, $body) {
			const $sel = $('<select class="widefat">').append($('<option value="0">— Select mapping —</option>'));
			apiFetch({ path: '/wrm/v1/mappings?per_page=100' })
				.then(maps => {
					maps.forEach(m => $sel.append($('<option>').val(m.id).text(m.title).prop('selected', parseInt(m.id) === chain.mapping_id)));
				}).catch(() => {});
			$sel.on('change', function () { chain.mapping_id = parseInt($(this).val(), 10); _save(); });

			const $srcSel = $('<select class="widefat">').append(
				$('<option value="payload">Original payload</option>'),
				$('<option value="post_meta_json">Decode JSON from post meta</option>')
			).val(chain.chain_source || 'payload');
			const $keyInp = $('<input type="text" class="widefat" placeholder="meta key containing JSON">').val(chain.chain_source_key || '');
			const $keyRow = _frow('Meta key', $keyInp, 'The post meta key written by a <code>meta_json:</code> target — its JSON value becomes the chained payload.');
			$keyRow.toggle((chain.chain_source || 'payload') === 'post_meta_json');
			$srcSel.on('change', function () {
				chain.chain_source = $(this).val();
				$keyRow.toggle(chain.chain_source === 'post_meta_json');
				_save();
			});
			$keyInp.on('input', function () { chain.chain_source_key = sanitizeKey($(this).val()); _save(); });
			$keyInp.on('blur', function () { $(this).val(chain.chain_source_key); });

			$body.append(
				_frow('Target Mapping', $sel, 'Chains the result through another mapping.'),
				_frow('Chain Source', $srcSel, 'What payload to pass into the chained mapping.'),
				$keyRow
			);
		}

		/* ---- TAGS PANEL -------------------------------------------------- */
		function _renderTagsPanel () {
			const $cloud = $('<div id="wrm-available-tags" class="wrm-tags-cloud">');
			// Trigger initial render from already-fetched tags
			if (WRM.Tags._loaded) {
				WRM.Tags._render();
			} else {
				$cloud.html('<em class="wrm-muted">' + esc(t('noCaptures', 'No captures yet.')) + '</em>');
			}
			return $('<div>').append(
				$('<p class="description">Click a tag to copy to clipboard. Paste into any source/value field.</p>'),
				$cloud
			);
		}

		/* ---- PREVIEW PANEL ----------------------------------------------- */
		function _renderPreview () {
			const $pre = $('<pre class="wrm-config-pre code">').text(JSON.stringify(_state, null, 2));
			$(document).on('wrm:statechange', function () {
				$pre.text(JSON.stringify(_state, null, 2));
			});
			return $('<div>').append(
				$('<p class="description">Read-only live JSON — this is what gets saved to the post meta.</p>'),
				$pre
			);
		}

		/* ---- HELPERS ----------------------------------------------------- */
		function _frow (label, $ctrl, desc) {
			const $row = $('<div class="wrm-form-row">').append(
				$('<label class="wrm-form-label">').html(esc(label)),
				$('<div class="wrm-form-ctrl">').append($ctrl)
			);
			if (desc) $row.find('.wrm-form-ctrl').append($('<p class="description">').html(desc));
			return $row;
		}

		function _cptSelect (current, onChange) {
			const $sel = $('<select class="widefat">');
			const pts  = (window.wrmData && window.wrmData.postTypes) ? window.wrmData.postTypes : [];
			if (pts.length) {
				pts.forEach(pt => $sel.append($('<option>').val(pt.slug).text(pt.label).prop('selected', pt.slug === current)));
			} else {
				$sel.append($('<option>').val(current || 'post').text(current || 'post'));
				apiFetch({ path: '/wp/v2/types?context=edit' })
					.then(types => {
						$sel.empty();
						Object.entries(types).forEach(([slug, type]) =>
							$sel.append($('<option>').val(slug).text(type.name).prop('selected', slug === current))
						);
					}).catch(() => {});
			}
			$sel.on('change', function () { onChange($(this).val()); });
			return $sel;
		}

		return { init };
	}());

	/* ====================================================================
	 * DOM INIT
	 * ==================================================================== */
	$(function () {

		// Boot the mapping builder
		const $container = $('#wrm-mapping-builder-container');
		if ($container.length) {
			WRM.MappingBuilder.init($container);
		}

		// Tag click → copy to clipboard
		$(document).on('click', '#wrm-available-tags .wrm-tag', function () {
			const val = $(this).data('value');
			if (navigator.clipboard) {
				navigator.clipboard.writeText(val).then(() => {
					const $t = $(this).addClass('wrm-tag-copied');
					setTimeout(() => $t.removeClass('wrm-tag-copied'), 1200);
				});
			} else {
				prompt('Copy tag:', val);
			}
		});

		// Generic drag-reorder for field rows and chain items
		let _dragSrc = null;
		$(document).on('dragstart', '.wrm-drag', function (e) {
			_dragSrc = $(this).closest('.wrm-field-row, .wrm-chain-item')[0];
			if (_dragSrc) {
				e.originalEvent.dataTransfer.effectAllowed = 'move';
				e.originalEvent.dataTransfer.setData('text/plain', '');
				$(_dragSrc).addClass('wrm-dragging');
			}
		});
		$(document).on('dragover', '.wrm-field-row, .wrm-chain-item', function (e) {
			if (_dragSrc && _dragSrc !== this) {
				e.preventDefault();
				e.originalEvent.dataTransfer.dropEffect = 'move';
				$(this).addClass('wrm-drag-over');
			}
		});
		$(document).on('dragleave', '.wrm-field-row, .wrm-chain-item', function () {
			$(this).removeClass('wrm-drag-over');
		});
		$(document).on('drop', '.wrm-field-row, .wrm-chain-item', function (e) {
			e.preventDefault();
			$(this).removeClass('wrm-drag-over');
			if (_dragSrc && _dragSrc !== this) {
				const $src  = $(_dragSrc);
				const $dest = $(this);
				const $list = $dest.parent();
				if ($src.parent()[0] === $list[0]) {
					const srcIdx  = $src.index();
					const destIdx = $dest.index();
					if (srcIdx < destIdx) $src.insertAfter($dest);
					else $src.insertBefore($dest);
					// Sync _state arrays
					$(document).trigger('wrm:reorder');
				}
			}
			$(_dragSrc).removeClass('wrm-dragging');
			_dragSrc = null;
		});

		// Nonce for wp.apiFetch
		if (window.wrmData && window.wrmData.nonce) {
			apiFetch.use(apiFetch.createNonceMiddleware(wrmData.nonce));
		}
	});

}(jQuery, (window.wp && window.wp.apiFetch) ? window.wp.apiFetch : function (opts) { return fetch(opts.path); }));
