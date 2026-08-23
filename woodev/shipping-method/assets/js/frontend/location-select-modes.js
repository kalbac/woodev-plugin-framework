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
 * ever uses `options.fetch` (suggest) and `options.list` (the full scoped `/location/list`,
 * issue #463) — both already scoped AND already value-stamped by the cascade's own
 * `fieldValueFor()` — plus the generic `options.buildUrl`/`options.fetchJson`/
 * `options.nonceHeader`/`options.country`/`options.parentKey` primitives the cascade hands over
 * for a renderer (like `related-list:region`) that watches a WooCommerce-rendered field instead.
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
	 * The ajax-select2 `minimumInputLength` floor for `level` (issue #461 NOTED finding: a
	 * universal `2` had no provider invariant behind it — the critic found none, and neither
	 * did the research this PR already did, docs-internal/research/2026-08-21-select2-location-fields.md
	 * §2.2). No source consulted (WC's own `wc-enhanced-select.js`, `woocommerce-edostavka`'s
	 * `city-select.js`) states a floor for a REGION field specifically — both only cover
	 * settlement/customer/product-style searches. Picked per level rather than left universal:
	 *
	 * - `region`: the list is small and already server-cached per country
	 *   ({@see attachRelatedListRegion}'s own `fetchRegionList()` caches it too) — a 1-character
	 *   query is cheap here and useful for short region names/abbreviations, so it is not
	 *   floored beyond select2's own default of 1.
	 * - everything else (settlement, address): matches `woocommerce-edostavka`'s own city
	 *   adapter default of 2 (`city-select.js:58`) — the closest real precedent for a
	 *   locality-name search against the same DaData-shaped provider data this layer uses.
	 *
	 * @param {string} level
	 * @returns {number}
	 */
	function minimumInputLengthFor( level ) {
		return 'region' === level ? 1 : 2;
	}

	/**
	 * Builds the config object for `strategy` — the exact object `ensureSelect2()` passes to
	 * `.select2()`.
	 *
	 * @param {{ajax: boolean, fetchEntries: function(string): Promise<Array>}} strategy
	 * @param {{initialValue: string, placeholder: string, level: string, applyEntries: function(Array, boolean): Array}} seed
	 *   `applyEntries` is called with `(entries, false)` on every successful ajax response —
	 *   the SAME merge-only call `ensureSelect2()`'s own transport made before this extraction —
	 *   and now RETURNS the subset of `entries` it actually accepted (see `applyEntries()`'s own
	 *   docblock), so the results reported to select2 and the records resolvable via `dataByKey`
	 *   can never diverge (issue #461 BLOCKING 1/2).
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

			config.minimumInputLength = minimumInputLengthFor( seed.level );
			config.ajax = {
				delay: 250, // select2's own debounce, applied before transport() is ever invoked — WC's own baseline (§2.3); redundant to duplicate here.
				transport: function( params, success, failure ) {
					var term = params && params.data && params.data.term ? params.data.term : '';

					// issue #449: select2/selectWoo stores whatever this returns as `this._request`
					// and aborts it (only if it looks abortable) before starting the NEXT query —
					// see AjaxAdapter.prototype.query, selectWoo.full.js:3564-3571. Our own
					// `strategy.fetchEntries()` wraps `fetch()`, not `$.ajax()`, so there is no real
					// in-flight request object to hand back (threading an AbortController through
					// `options.fetch()` is a `location-cascade.js` change, out of scope here) — but
					// an `abort()` that marks THIS call's own eventual result stale is enough to stop
					// a superseded response from repainting the list, which is the actual symptom
					// (the "last-arrived-wins" flicker, §2.4). #449's cancellation half (actually
					// aborting the in-flight `fetch()`) is deliberately NOT done — see the PR
					// description.
					var stale = false;

					strategy.fetchEntries( term ).then( function( entries ) {
						if ( stale ) {
							return;
						}

						// issue #461 BLOCKING 1/2: `applyEntries()` is the SINGLE place that
						// decides which entries are selectable and what identifies each one —
						// reusing its return value here (never re-deriving the same filter) is
						// what keeps select2's own results and `dataByKey` from disagreeing.
						var accepted = seed.applyEntries( entries, false );

						success( {
							results: accepted.map( function( entry ) {
								return {
									id: undefined !== entry.value ? entry.value : entry.key,
									text: entry.record.label || entry.label,
									// Carried through select2/selectWoo's own normalized result
									// data — see `SelectAdapter.prototype.option()`/`.item()`,
									// selectWoo.full.js:3309-3350 — and handed back verbatim on
									// `select2:select` (`e.params.data.key`, EventRelay,
									// selectWoo.full.js:2174-2218). This is the STABLE identity
									// `buildSelectField()`'s `select2:select` handler resolves the
									// record by; `id`/`text` are select2's own display contract and
									// stay the submitted field VALUE, never this key (issue #455).
									key: entry.key,
								};
							} ),
						} );
					}, function( error ) {
						if ( stale ) {
							return;
						}

						failure( error );
					} );

					return {
						abort: function() {
							stale = true;
						},
					};
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

		// Issue #460: a WooCommerce-rebuilt state field (`country-select.js`'s own
		// `country_to_state_changed` handler, e.g. on a page-load `refresh`) carries neither a
		// `value` NOR a `placeholder`/`data-placeholder` attribute — a native `<select>` (what
		// this function's OWN previous pass here left behind) has no `placeholder` attribute at
		// all, so WC's handler, which reads the placeholder off whatever currently occupies the
		// field before rebuilding it, always finds nothing to carry forward. Without a
		// placeholder select2 renders a genuinely empty widget (zero content height — the
		// "thin strip" report) until the customer picks something. `entry.location.i18n.placeholder`
		// (server-supplied, translatable, {@see Checkout_Config::build_location_block()}) is the
		// SAME string this layer's own suggest/related-list fields already fall back to when the
		// DOM carries none — never a literal here.
		var placeholder = input.getAttribute( 'placeholder' ) || input.getAttribute( 'data-placeholder' )
			|| ( options.location && options.location.i18n && options.location.i18n.placeholder ) || '';

		// Issue #466: WooCommerce's own `country-select.js` rebuild reads `data-input-classes`
		// and `placeholder`/`data-placeholder` straight off whatever CURRENTLY occupies the
		// state field (`$statebox.attr('data-input-classes')`,
		// `$statebox.attr('placeholder') || $statebox.attr('data-placeholder')`,
		// `country-select.js:103,105`) before replacing it — never `class`. A `<select>` this
		// function built without either attribute makes WC's next rebuild carry forward
		// `undefined`/empty (measured on the rig: the field it left behind read
		// `class="input-text undefined"`), same defect the CDEK reference
		// (`plugins-reference/woocommerce-edostavka/assets/js/frontend/city-select.js:79-80`)
		// already carries both of for exactly this reason. `data-input-classes` is carried
		// verbatim (never fabricated) — an `<input>` WC never stamped one onto simply leaves
		// this unset, same as before. `placeholder`, already resolved above with its own
		// fallback chain, is written as BOTH attributes since WC's rebuild accepts either.
		var inputClasses = input.getAttribute( 'data-input-classes' );

		if ( null !== inputClasses ) {
			select.setAttribute( 'data-input-classes', inputClasses );
		}

		if ( placeholder ) {
			select.setAttribute( 'placeholder', placeholder );
			select.setAttribute( 'data-placeholder', placeholder );
		}

		input.parentNode.insertBefore( select, input );
		input.parentNode.removeChild( input );

		/** @type {Object.<string, Object>} the entry's STABLE identity (`entry.key`) -> the record it resolves to. */
		var dataByKey = {};
		var lastHandledKey = null;

		/**
		 * Applies a batch of `{key, label, level, record}` entries (Task 8/13's shared
		 * `to_response_records()` wire shape) — either REPLACING the select's whole option set
		 * (the static list strategy) or MERGING into the lookup map only, leaving the DOM to
		 * select2's own remote-results rendering (the ajax strategy).
		 *
		 * issue #461 BLOCKING 1: an entry whose derived field value is an EXPLICIT empty string
		 * (`fieldValueFor()` found no component AND no usable label at this level —
		 * `location-cascade.js`'s own docblock calls this "the lesser of two evils", not a
		 * value a form should ever submit) is excluded entirely, same as an entry with no
		 * record/key. Silently falling back to `entry.key` for it would resubmit the raw
		 * provider key — the exact #455 defect this PR already closed once. This is a real
		 * PRESENCE check (`undefined !== entry.value`), not a truthiness one — `undefined` (no
		 * renderer sharing this function leaves `.value` unset any more as of issue #463: both
		 * `options.fetch` and `options.list` stamp it via `location-cascade.js`'s own
		 * `fieldValueFor()`) and `''` (a value that WAS derived, and derived to nothing) are
		 * different states and must not collapse into the same branch.
		 *
		 * issue #461 BLOCKING 2: `dataByKey` is keyed by `entry.key` — the provider's own stable
		 * identity — never by the submitted option value. Two entries that legitimately share
		 * the same submitted name (two same-named localities) now resolve to their OWN records
		 * instead of whichever one happened to be merged in last.
		 *
		 * @param {Array}   entries
		 * @param {boolean} replaceOptions
		 * @returns {Array} the subset of `entries` actually accepted (has a record, a key, and a
		 *   non-empty derived value) — the caller's `results`/option list must be built from
		 *   THIS, never re-filtered independently, so they can never disagree with `dataByKey`.
		 */
		function applyEntries( entries, replaceOptions ) {
			if ( replaceOptions ) {
				while ( select.firstChild ) {
					select.removeChild( select.firstChild );
				}

				dataByKey = {};
			}

			var accepted = [];

			( entries || [] ).forEach( function( entry ) {
				var record = entry && entry.record;

				if ( ! record || ! entry.key || '' === entry.value ) {
					return;
				}

				// issues #455/#463: `entry.value` is the SAME field value every renderer sharing
				// this function submits (`location-cascade.js`'s `fetchFor()`/`listFor()` already
				// assign it via `fieldValueFor()` before an entry ever reaches here). This is what
				// the <select> itself SUBMITS. The `entry.key` fallback below is defensive only —
				// every current caller of `applyEntries()` now stamps `.value` upstream.
				var optionValue = ( undefined !== entry.value ) ? entry.value : entry.key;

				dataByKey[ entry.key ] = record;
				accepted.push( entry );

				if ( replaceOptions ) {
					var option = document.createElement( 'option' );

					option.value = optionValue;
					option.textContent = record.label || entry.label || '';
					// The RESOLUTION identity (issue #461 BLOCKING 2) — deliberately NOT the
					// submitted value, so `handleChange()` never has to disambiguate two options
					// that legitimately share one. Read back in `handleChange()` below.
					option.dataset.woodevKey = entry.key;

					select.appendChild( option );
				}
			} );

			return accepted;
		}

		/**
		 * Resolves `key` (the entry's STABLE identity, never the submitted value — issue #461
		 * BLOCKING 2) against `dataByKey` and calls `options.onSelect()`, once per distinct pick.
		 * Shared by both resolution paths below: a real select2 pick never touches the option's
		 * own `dataset` (see `handleChange()`'s docblock), and a non-select2/native pick never
		 * fires `select2:select` at all — the two are mutually exclusive per pick, so one shared
		 * "last handled" guard is enough to make either path idempotent without double-firing
		 * across the other.
		 *
		 * @param {string|null|undefined} key
		 * @returns {void}
		 */
		function resolveAndSelect( key ) {
			var record = key ? dataByKey[ key ] : null;

			if ( ! record || key === lastHandledKey ) {
				return;
			}

			lastHandledKey = key;

			options.onSelect( { record: record } );
		}

		/**
		 * The NATIVE/no-select2 resolution path: reads the STABLE identity `applyEntries()`
		 * stamped onto the selected `<option>`'s own `dataset` (issue #461 BLOCKING 2). Covers
		 * `related-list:settlement` (select2, when present there, is LOCAL/non-ajax and wraps
		 * these exact `<option>` elements without rebuilding them) and `ajax-select2` when no
		 * select2/selectWoo ever loaded. Does NOT cover a real select2 AJAX pick — select2's own
		 * `SelectAdapter.prototype.option()` builds that `<option>` itself and copies only
		 * `value`/`textContent`/`selected`/`disabled`/`title` onto it (selectWoo.full.js:3309-
		 * 3327), never a custom `dataset` entry — see the `select2:select` binding below for that
		 * case.
		 *
		 * @returns {void}
		 */
		function handleChange() {
			var option = select.options[ select.selectedIndex ];

			resolveAndSelect( option ? option.dataset.woodevKey : null );
		}

		var unbind = bindChangeBothWorlds( select, handleChange );

		var $select = window.jQuery ? window.jQuery( select ) : null;
		var select2Initialized = false;

		/**
		 * The REAL select2 resolution path (issue #461 BLOCKING 2): select2/selectWoo hands the
		 * full, un-stripped result object back on this event — `e.params.data` is exactly the
		 * `{id, text, key}` item this file's own `ajax.transport` `success()` reported
		 * (EventRelay relays the container's own `select`/`selecting` events onto the element
		 * verbatim, selectWoo.full.js:2174-2218; `ArrayAdapter.prototype.select` never mutates
		 * that object before triggering it, selectWoo.full.js:3454-3466). Using `.key` here —
		 * never the DOM `<option>`'s own value/dataset, which select2 itself built and does not
		 * carry it — is what makes a real select2 pick immune to two results sharing one
		 * submitted name.
		 *
		 * @param {Object} event jQuery's own `select2:select` event.
		 * @returns {void}
		 */
		function handleSelect2Select( event ) {
			var data = event && event.params ? event.params.data : null;

			resolveAndSelect( data ? data.key : null );
		}

		if ( $select ) {
			$select.on( 'select2:select', handleSelect2Select );
		}

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

			$select.select2( selectConfigFor( strategy, {
				initialValue: initialValue,
				placeholder: placeholder,
				applyEntries: applyEntries,
				level: options.node && options.node.level,
			} ) );

			// Set only AFTER a successful call — issue #457: setting this BEFORE `.select2()`
			// runs means a THROWING init still leaves this idempotency guard claiming success.
			select2Initialized = true;
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

				if ( $select ) {
					$select.off( 'select2:select', handleSelect2Select );
				}

				// issue #457: gated on the node's ACTUAL select2 data (the same
				// `$element.data('select2')` key select2 itself sets on init and clears on
				// destroy — selectWoo.full.js:5258,5782), never on the closure flag above.
				// WooCommerce's own `update_checkout` can replace the surrounding fragment via
				// jQuery `.html()`/`.empty()`, which runs `cleanData()` — and therefore purges
				// this exact data key — on the very node this closure still holds a reference to,
				// WITHOUT ever calling OUR `detach()`. By the time detach() finally does run (the
				// next `attachAll()` pass tearing down a stale renderer), the closure flag still
				// says "initialized" while select2's own data is already gone; calling
				// `.select2('destroy')` on it dereferences a null instance internally
				// (selectWoo.full.js:6562-6571) and throws a TypeError this file only ever
				// caught, never prevented.
				if ( $select && 'function' === typeof $select.select2 && $select.data( 'select2' ) ) {
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
	 * region." Scoped live at fetch time by `options.list` itself (the SAME live region-record-
	 * key scoping the baseline typeahead's own `within` param already uses for this level —
	 * country-wide when no region is selected yet, exactly like the suggest path).
	 *
	 * Issue #463: `options.list` — not a hand-rolled `options.fetchJson()` call against
	 * `/location/list` — is what supplies entries here. `location-cascade.js`'s own `listFor()`
	 * already stamps `entry.value` via `fieldValueFor()` before an entry ever reaches this file,
	 * the SAME contract `options.fetch` already honours for `ajax-select2` (issue #455) — so
	 * `buildSelectField()`'s shared `applyEntries()` never has to fall back to `entry.key` (the
	 * raw provider key) for this renderer either.
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
				return Promise.resolve( options.list() ).then( null, function( error ) {
					logError( error );

					return [];
				} );
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
