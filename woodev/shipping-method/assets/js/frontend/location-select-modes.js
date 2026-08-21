/**
 * Woodev Location Select Modes — the `related-list` and `ajax-select2` field renderers for the
 * location provider layer (spec D7, plan Task 13).
 *
 * REGISTRY, NOT A CALLER: this file never calls into `location-cascade.js` and is never
 * `require()`d by it. It only writes onto `window.WoodevLocationRenderers`, a plain object
 * `location-cascade.js`'s own `resolveModeRenderer()` reads defensively (missing entirely,
 * or missing this mode/level, both degrade to "use the baseline typeahead" — see that
 * function's own docblock). Load order matters only in ONE direction: this file must have
 * already run by the time `location-cascade.js`'s `boot()` calls `attachAll()` for the first
 * time, which the PHP enqueue wiring guarantees by declaring this script a hard dependency of
 * `location-cascade.js` (`class-checkout-handler.php::enqueue_assets()`).
 *
 * EVERY RENDERER SHARES THE SAME CONTRACT `location-cascade.js`'s baseline typeahead honours:
 * `attach( el, options ) -> {detach, el?}|null`. `options.onSelect( { record } )` is THE SAME
 * function every other level's typeahead already calls (`onSelectFor()` in the cascade file) —
 * a selection made through ANY renderer here flows through the identical backwards-fill +
 * single-flight `/select` persist route (D8) as Task 11's typeahead. Nothing in this file ever
 * calls `fetch()`/`XMLHttpRequest` directly or re-derives a suggest/list URL by hand — it only
 * ever uses `options.fetch` (suggest, already scoped by the cascade) and the generic
 * `options.buildUrl`/`options.fetchJson`/`options.nonceHeader`/`options.country`/
 * `options.parentKey` primitives the cascade hands over for exactly this purpose.
 *
 * THE EVENT-WORLD TRAP (gotcha `jquery-trigger-change-fires-no-native-event`): select2 (and
 * WooCommerce's own selectWoo enhancement of a plain `<select>`, which MAY independently apply
 * to the `related-list` region field WooCommerce itself renders) reports a pick via jQuery
 * `.trigger('change')` — no native DOM event at all. Every renderer in this file therefore
 * binds its `change` handler in BOTH worlds via {@see bindChangeBothWorlds}: a native
 * `addEventListener` AND, when jQuery is present, a `.on()` binding too. A REAL user pick on a
 * plain (non-enhanced) `<select>`/`<input>` still dispatches a genuine native event, which BOTH
 * bindings then see — so every handler here is written to be a no-op on the SECOND, redundant
 * delivery (tracked via a small "last handled value" remembered per instance), never merely
 * lucky about it.
 *
 * SELECT2 IS OPTIONAL AT RUNTIME, NOT AT BUILD TIME: this repo has no `select2`/`selectWoo` npm
 * package (it ships with WordPress/WooCommerce as the `selectWoo` script handle — declared as a
 * hard dependency of this file in `class-checkout-handler.php::enqueue_assets()`, same as
 * `checkout-field-classic.js` already requires it). `buildSelectField()` therefore checks
 * `jQuery.fn.select2` defensively before calling it — real production always has it once
 * `selectWoo` has loaded; a jsdom test harness (no such package) still gets a fully working,
 * fully testable plain `<select>` with the same dual-world `change` wiring, exactly what a
 * test needs to pin the event-world contract with REAL jQuery and no third-party plugin at
 * all.
 *
 * @file
 * @since 2.1.0
 */

( function() {
	'use strict';

	var LOG = '[woodev-location-select-modes]';

	/**
	 * Logs an error with this module's prefix, never throwing — mirrors
	 * `location-cascade.js`'s own `logError()`.
	 *
	 * @param {*} error
	 * @returns {void}
	 */
	function logError( error ) {
		if ( window.console && 'function' === typeof console.error ) {
			console.error( LOG, error );
		}
	}

	/** @type {Object.<string, function>} the registry `location-cascade.js` reads from. */
	var registry = window.WoodevLocationRenderers = window.WoodevLocationRenderers || {};

	// -------------------------------------------------------------------------
	// Shared dual-event-world `change` binder — see the file docblock's EVENT-WORLD TRAP.
	// -------------------------------------------------------------------------

	/**
	 * Binds `handler` to `el`'s `change` event in both the native and jQuery worlds. Returns an
	 * `unbind()` that reverses exactly what was bound (never more): a jQuery-less page (or a
	 * jsdom test that never loaded it) only ever gets the native half bound/unbound.
	 *
	 * @param {Element}  el
	 * @param {Function} handler
	 * @returns {function(): void}
	 */
	function bindChangeBothWorlds( el, handler ) {
		el.addEventListener( 'change', handler );

		var jquery = false;

		if ( window.jQuery ) {
			var $el = window.jQuery( el );

			if ( $el && 'function' === typeof $el.on ) {
				$el.on( 'change', handler );
				jquery = true;
			}
		}

		return function unbind() {
			el.removeEventListener( 'change', handler );

			if ( jquery && window.jQuery ) {
				window.jQuery( el ).off( 'change', handler );
			}
		};
	}

	// -------------------------------------------------------------------------
	// related-list: region — a watcher on the NATIVE WooCommerce <select>
	// -------------------------------------------------------------------------

	/**
	 * Attaches the `related-list` region renderer — spec D7, Task 13 (issue #294 arbitration).
	 *
	 * Does NOT create any widget: under `related-list` mode WooCommerce itself renders the
	 * region `<select>`, populated server-side by `Location_Provider_Registry::
	 * inject_related_list_states()` through the EXISTING §8 `woocommerce_states` filter — the
	 * `<option>` VALUE is `wc_strtoupper(trim(record.label))` (uppercased so WooCommerce's own
	 * `validate_posted_data()` uppercasing of the posted value still matches the registered
	 * key — see `class-checkout-config.php::build_location_block()`'s own docblock for the
	 * measured rig failure this fixed), the `<option>` TEXT is the human label unchanged. This
	 * function only WATCHES that select: it looks the selected TEXT up against the SAME
	 * country's `GET /location/list?level=region` response (`entry.record.label`, the RAW
	 * label — never the top-level `label` field, which is `esc_html()`-escaped for display and
	 * would never equality-match) and, on a match, hands the matched record to
	 * `options.onSelect()` — the exact same persist route every other level already uses.
	 *
	 * Declines (`null`) when `el` is not a `<select>` — the country's region field is a plain
	 * `<input>` because the D15 chain did not want it enumerated there, or a genuine conflict
	 * left WooCommerce's own/another source's states in place (`class-checkout-config.php`'s
	 * own docblock: the client cannot and need not tell these apart from the config alone).
	 * `location-cascade.js`'s `attachOne()` falls back to the baseline typeahead in that case,
	 * gated by the ordinary D15 `levels[country].region` flag.
	 *
	 * @param {Element} el
	 * @param {Object}  options See the file docblock's shared contract.
	 * @returns {{detach: function(): void, el: Element}|null}
	 */
	function attachRelatedListRegion( el, options ) {
		if ( ! el || 'SELECT' !== el.tagName ) {
			return null;
		}

		/** @type {Promise<Array>|null} cached per country — see fetchRegionList(). */
		var listPromise = null;
		var listCountry = null;
		var lastHandledText = null;

		/**
		 * Fetches (and caches, per country) the region-level `/location/list` entries.
		 *
		 * @returns {Promise<Array>}
		 */
		function fetchRegionList() {
			var country = options.country();

			if ( listPromise && listCountry === country ) {
				return listPromise;
			}

			listCountry = country;
			listPromise = options.fetchJson(
				options.buildUrl( options.location.endpoints.list, { level: 'region', country: country } ),
				{ method: 'GET', headers: options.nonceHeader() }
			).then(
				function( body ) {
					return body && Array.isArray( body.localities ) ? body.localities : [];
				},
				function( error ) {
					logError( error );

					return [];
				}
			);

			return listPromise;
		}

		function handleChange() {
			var selected = el.options[ el.selectedIndex ];
			var text = selected ? selected.text : '';

			if ( ! text || text === lastHandledText ) {
				return; // nothing selected, or the SAME event delivered a second time (dual-world binding).
			}

			lastHandledText = text;

			fetchRegionList().then( function( entries ) {
				for ( var i = 0; i < entries.length; i++ ) {
					var candidate = entries[ i ];

					if ( candidate && candidate.record && candidate.record.label === text ) {
						options.onSelect( { record: candidate.record } );

						return;
					}
				}
			} );
		}

		var unbind = bindChangeBothWorlds( el, handleChange );

		return {
			el: el,
			detach: function() {
				unbind();
			},
		};
	}

	registry[ 'related-list:region' ] = attachRelatedListRegion;

	// -------------------------------------------------------------------------
	// Pure select2 config builder (issue #450, harness option 2) — no DOM read beyond its own
	// arguments, no jQuery, no `.select2()` call. Testable with NO select2 present in the
	// environment at all, and independently of `buildSelectField()`'s init-guard/idempotency
	// wrapper — this is the SAME object `ensureSelect2()` hands to `.select2()`, extracted so
	// a test can assert its shape directly instead of inferring it from a stubbed `select2()`
	// call (issue #450's own point: jsdom has no select2, so nothing here ran under any test
	// before this function existed to be called on its own).
	// -------------------------------------------------------------------------

	/**
	 * Builds the config object for `strategy` — the exact object `ensureSelect2()` passes to
	 * `.select2()`.
	 *
	 * @param {{ajax: boolean, fetchEntries: function(string): Promise<Array>}} strategy
	 * @param {{initialValue: string, placeholder: string, applyEntries: function(Array, boolean): void}} seed
	 *   `applyEntries` is called with `(entries, false)` on every successful ajax response —
	 *   the SAME merge-only call `ensureSelect2()`'s own transport made before this extraction.
	 * @returns {Object}
	 */
	function selectConfigFor( strategy, seed ) {
		var config = { width: '100%' };

		if ( strategy.ajax ) {
			// Only meaningful when the field starts EMPTY — select2's own docs require a
			// blank leading <option> for this to render at all (verified against
			// select2/select2 docs/placeholders.md, "Single select placeholders" AND "Using
			// placeholders with AJAX" — the empty <option> is required in BOTH cases).
			// `attachAjaxSelect2()`'s own seeding only appends that leading option in this
			// same empty case — see `buildSelectField()`.
			if ( seed.placeholder && ! seed.initialValue ) {
				config.placeholder = seed.placeholder;
			}

			config.ajax = {
				transport: function( params, success, failure ) {
					var term = params && params.data && params.data.term ? params.data.term : '';

					strategy.fetchEntries( term ).then( function( entries ) {
						seed.applyEntries( entries, false );

						success( {
							results: ( entries || [] ).map( function( entry ) {
								return {
									id: entry.key,
									text: entry.record && entry.record.label ? entry.record.label : entry.label,
								};
							} ),
						} );
					}, failure );
				},
			};
		}

		return config;
	}

	// -------------------------------------------------------------------------
	// Shared select2-ish field builder — turns a plain <input> into a <select> select2 CAN
	// enhance (select2 requires a real <select>; it cannot attach to an arbitrary text input).
	// -------------------------------------------------------------------------

	/**
	 * Replaces `input` with a fresh `<select>` carrying the SAME id/name/class, populated and
	 * kept in sync by `strategy`, dual-world bound for selection, and select2-enhanced when the
	 * `selectWoo`/select2 script has actually loaded (see the file docblock's SELECT2 IS
	 * OPTIONAL section).
	 *
	 * `input` is fully removed from the document while the `<select>` is live — never left in
	 * place under a different id, and never left in place under the SAME id (a duplicate id
	 * would make every `getElementById( fieldId )` lookup elsewhere in this layer ambiguous).
	 * `detach()` restores it verbatim, in the same DOM position.
	 *
	 * @param {HTMLInputElement} input
	 * @param {Object}           options   See the file docblock's shared contract.
	 * @param {{ajax: boolean, fetchEntries: function(string): Promise<Array>}} strategy
	 *   `ajax: false` — `fetchEntries()` is called ONCE (a static, region-scoped full list —
	 *   `related-list` settlement); the `<select>` is populated with real `<option>` elements
	 *   up front, and select2 (when present) gets NO `ajax` config at all — it search-filters
	 *   the already-fetched options locally, which is exactly the "related list" UX (spec D7).
	 *   `ajax: true` — `fetchEntries( term )` is wired as select2's OWN `ajax.transport`
	 *   (`ajax-select2` mode); each response's entries are MERGED into the lookup map (never
	 *   replaced — a later pick may resolve an item fetched several keystrokes ago), and
	 *   nothing is pre-populated: the field starts genuinely empty, matching a live suggest
	 *   search rather than a bounded list.
	 * @returns {{detach: function(): void, el: Element}|null}
	 */
	function buildSelectField( input, options, strategy ) {
		if ( ! input || ! input.parentNode ) {
			return null;
		}

		var select = document.createElement( 'select' );

		select.id = input.id;
		select.name = input.name || '';
		select.className = input.className;

		// Captured BEFORE the <input> is detached — issue #447: a field re-rendered with an
		// existing value (a page reload, or a sibling level's re-render) must not lose it.
		var initialValue = input.value || '';
		var placeholder = input.getAttribute( 'placeholder' ) || input.getAttribute( 'data-placeholder' ) || '';

		input.parentNode.insertBefore( select, input );
		input.parentNode.removeChild( input );

		/** @type {Object.<string, Object>} locality key -> the record it resolves to. */
		var dataByKey = {};
		var lastHandledValue = null;

		/**
		 * Applies a batch of `{key, label, level, record}` entries (Task 8/13's shared
		 * `to_response_records()` wire shape) — either REPLACING the select's whole option set
		 * (the static list strategy) or MERGING into the lookup map only, leaving the DOM to
		 * select2's own remote-results rendering (the ajax strategy).
		 *
		 * @param {Array}   entries
		 * @param {boolean} replaceOptions
		 * @returns {void}
		 */
		function applyEntries( entries, replaceOptions ) {
			if ( replaceOptions ) {
				while ( select.firstChild ) {
					select.removeChild( select.firstChild );
				}

				dataByKey = {};
			}

			( entries || [] ).forEach( function( entry ) {
				var record = entry && entry.record;

				if ( ! record || ! entry.key ) {
					return;
				}

				dataByKey[ entry.key ] = record;

				if ( replaceOptions ) {
					var option = document.createElement( 'option' );

					option.value = entry.key;
					option.textContent = record.label || entry.label || '';

					select.appendChild( option );
				}
			} );
		}

		function handleChange() {
			var value = select.value;
			var record = dataByKey[ value ];

			if ( ! record || value === lastHandledValue ) {
				return;
			}

			lastHandledValue = value;

			options.onSelect( { record: record } );
		}

		var unbind = bindChangeBothWorlds( select, handleChange );

		var $select = window.jQuery ? window.jQuery( select ) : null;
		var select2Initialized = false;

		/**
		 * Initializes select2 on `select`, once — a no-op when the `selectWoo`/select2 script
		 * never loaded (jQuery absent entirely, or present without the plugin — see the file
		 * docblock). Safe to call more than once; only the FIRST call does anything.
		 *
		 * @returns {void}
		 */
		function ensureSelect2() {
			if ( select2Initialized || ! $select || 'function' !== typeof $select.select2 ) {
				return;
			}

			select2Initialized = true;

			$select.select2( selectConfigFor( strategy, {
				initialValue: initialValue,
				placeholder: placeholder,
				applyEntries: applyEntries,
			} ) );
		}

		if ( strategy.ajax ) {
			// select2's own `ajax.transport` (wired above) drives population per keystroke —
			// nothing to PRE-FETCH. But the field's OWN current value (issue #447) is not a
			// fetch result at all: it is the label the field already carries, exactly the
			// select2-documented "Preselect option in AJAX Select2" pattern (append a real,
			// pre-selected <option> before init — see the CDEK reference,
			// plugins-reference/woocommerce-edostavka/assets/js/frontend/city-select.js:69,90-98,
			// which does the same thing for the same reason) — without it the select renders
			// with NO options at all until the first keystroke.
			if ( initialValue ) {
				var seededOption = document.createElement( 'option' );

				seededOption.value = initialValue;
				seededOption.textContent = initialValue;
				seededOption.selected = true;

				select.appendChild( seededOption );
			} else if ( placeholder ) {
				// The empty leading <option> select2's placeholder requires (see ensureSelect2()).
				select.appendChild( document.createElement( 'option' ) );
			}

			// Without select2 available at all, the field is simply a native <select> carrying
			// the option above (or genuinely empty) — a customer cannot search it; `ajax-select2`
			// mode is only ever offered by the store setting when the real plugin is expected to
			// be present.
			ensureSelect2();
		} else {
			strategy.fetchEntries( '' ).then(
				function( entries ) {
					applyEntries( entries, true );
					ensureSelect2();
				},
				function( error ) {
					logError( error );
					applyEntries( [], true );
					ensureSelect2();
				}
			);
		}

		return {
			el: select,
			detach: function() {
				unbind();

				if ( select2Initialized && $select && 'function' === typeof $select.select2 ) {
					try {
						$select.select2( 'destroy' );
					} catch ( e ) {
						logError( e );
					}
				}

				if ( select.parentNode ) {
					select.parentNode.insertBefore( input, select );
					select.parentNode.removeChild( select );
				}
			},
		};
	}

	// -------------------------------------------------------------------------
	// related-list: settlement — select2 fed by the FULL per-region /location/list
	// -------------------------------------------------------------------------

	/**
	 * Attaches the `related-list` settlement (city) renderer — spec D7, Task 13: "the city
	 * level in this mode is a select2 populated from `/location/list` scoped to the chosen
	 * region." Scoped via `options.parentKey()` (the SAME live region-record-key scoping the
	 * baseline typeahead's own `within` param already uses for this level — country-wide when
	 * no region is selected yet, exactly like the suggest path).
	 *
	 * @param {Element} el
	 * @param {Object}  options
	 * @returns {{detach: function(): void, el: Element}|null}
	 */
	function attachRelatedListSettlement( el, options ) {
		if ( ! el || 'INPUT' !== el.tagName ) {
			return null;
		}

		return buildSelectField( el, options, {
			ajax: false,
			fetchEntries: function() {
				var params = { level: 'settlement', country: options.country() };
				var within = options.parentKey();

				if ( within ) {
					params.within = within;
				}

				return options.fetchJson(
					options.buildUrl( options.location.endpoints.list, params ),
					{ method: 'GET', headers: options.nonceHeader() }
				).then(
					function( body ) {
						return body && Array.isArray( body.localities ) ? body.localities : [];
					},
					function( error ) {
						logError( error );

						return [];
					}
				);
			},
		} );
	}

	registry[ 'related-list:settlement' ] = attachRelatedListSettlement;

	// -------------------------------------------------------------------------
	// ajax-select2 — select2 remote data through the SAME /location/suggest fetch the
	// baseline typeahead uses (shared code path, spec Task 13: "not a copy of it").
	// -------------------------------------------------------------------------

	/**
	 * Attaches the `ajax-select2` renderer for ANY level (registered under the bare mode key —
	 * see `location-cascade.js`'s `resolveModeRenderer()`). Uses `options.fetch` — the EXACT
	 * function `location-cascade.js`'s `fetchFor()` builds for the baseline typeahead at this
	 * SAME node, already scoped (level/country/within) and already carrying the per-level
	 * `value` derivation (`fieldValueFor()`) — as select2's own `ajax.transport` data source, so
	 * a selection here produces the identical record shape a typeahead pick would, through the
	 * identical `options.onSelect()` → `onSelectFor()` → backwards-fill/`/select` route.
	 *
	 * @param {Element} el
	 * @param {Object}  options
	 * @returns {{detach: function(): void, el: Element}|null}
	 */
	function attachAjaxSelect2( el, options ) {
		if ( ! el || 'INPUT' !== el.tagName ) {
			return null;
		}

		return buildSelectField( el, options, {
			ajax: true,
			fetchEntries: function( term ) {
				return Promise.resolve( options.fetch( term ) ).then( null, function( error ) {
					logError( error );

					return [];
				} );
			},
		} );
	}

	registry[ 'ajax-select2' ] = attachAjaxSelect2;

	// -------------------------------------------------------------------------
	// CommonJS (jest) — individual functions, for direct unit testing without going through
	// `window.WoodevLocationRenderers` for every single test.
	// -------------------------------------------------------------------------

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = {
			attachRelatedListRegion: attachRelatedListRegion,
			attachRelatedListSettlement: attachRelatedListSettlement,
			attachAjaxSelect2: attachAjaxSelect2,
			bindChangeBothWorlds: bindChangeBothWorlds,
			selectConfigFor: selectConfigFor,
		};
	}

}() );
