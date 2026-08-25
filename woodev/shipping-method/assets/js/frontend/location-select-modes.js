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
 * `options.onResolving()` (issue #541) is the one primitive a renderer calls BEFORE it knows the
 * record: it announces "the customer picked at this level, the identity is still coming" and
 * returns a `release()` for the case where the identity never arrives. Only `related-list:region`
 * needs it — it holds nothing but WooCommerce's own label text and must match that against
 * `/location/list` first — and only it calls it; `ajax-select2` learns the record from the pick
 * itself and goes straight to `options.onSelect()`.
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

	/**
	 * Whether `error` is the rejection reason a self-aborted `fetch()` produces
	 * (`AbortController.abort()`'s own contract: the pending `fetch()` promise rejects with a
	 * `DOMException`/`Error` named `AbortError`). issue #449 (second half): this is the ONLY
	 * thing that tells a request WE cancelled apart from a genuine network failure — neither may
	 * ever paint "search failed" for the customer (see `selectConfigFor()`'s transport), but
	 * only a genuine failure is worth a `console.error`.
	 *
	 * @param {*} error
	 * @returns {boolean}
	 */
	function isAbortError( error ) {
		return !! ( error && 'AbortError' === error.name );
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

			// Issue #541. THE PICK IS ALREADY A FACT HERE; only its identity is not. Everything
			// below this line is a lookup — `fetchRegionList()` is a `GET /location/list` that
			// took 10.5 SECONDS on the rig for a cold region — and until it returns this renderer
			// has no record and therefore cannot call `options.onSelect()`, which is what
			// eventually raises the busy state for every other level.
			//
			// So for those 10.5 seconds the customer had clicked a new region and NOTHING had
			// changed on screen: no spinner on the region field, and — worse — a settlement field
			// still offering the OLD region's list, ready to take a click on a town that is no
			// longer reachable (measured 779 ms after the switch: all six popular entries still
			// there, three of them in the region just left).
			//
			// `options.onResolving()` is the cascade's seam for exactly this shape — announce the
			// pick by LEVEL now, name the record later. `ajax-select2` needs nothing of the sort
			// because its own pick carries the record; this renderer is the only one that has to
			// go and ask. See {@see onResolvingFor} in location-cascade.js.
			var release = 'function' === typeof options.onResolving ? options.onResolving() : null;

			function releaseIfHeld() {
				if ( release ) {
					release();
					release = null;
				}
			}

			fetchRegionList().then( function( entries ) {
				for ( var i = 0; i < entries.length; i++ ) {
					var candidate = entries[ i ];

					if ( candidate && candidate.record && candidate.record.label === text ) {
						options.onSelect( { record: candidate.record } );

						// A no-op by construction: `onSelect()` has raised a marker of its own
						// for the record it just accepted, so the token this one holds is no
						// longer the standing marker's. Called anyway, unconditionally, so the
						// release path is one path rather than two — a `mayEnterChain()` refusal
						// inside `onSelect()` reaches this line with NOTHING having replaced the
						// marker, and skipping it there would leave the field spinning forever.
						releaseIfHeld();

						return;
					}
				}

				// Searched, and this country's list does not carry the selected text — no record
				// will ever arrive for this pick, so nothing else is coming to clear the marker.
				releaseIfHeld();
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
	 * Issue #526: the `language` block for EVERY select2 this file builds, sourced from
	 * WooCommerce's OWN `wc_country_select_params` rather than from strings invented here
	 * (operator's ruling on #526: «нам не нужно свои переводы подсовывать, а брать уже готовые
	 * из `wc_country_select_params`»).
	 *
	 * Without it select2 falls back to its BUILT-IN English messages, which is what the
	 * operator saw on the rig three times — «No results found» on a Russian checkout. The gap
	 * is wider than that one string: `minimumInputLengthFor()` floors the settlement field at
	 * 2, so `inputTooShort` («Please enter 2 or more characters») is what the customer stares
	 * at BEFORE typing anything at all, on every single visit.
	 *
	 * Copied key-for-key from WooCommerce's own `assets/js/frontend/country-select.js`
	 * (measured in the rig container, `woocommerce.latest-stable`, lines 13-51) — the same
	 * block `plugins-reference/woocommerce-edostavka/assets/js/frontend/city-select.js:194-224`
	 * already copies. That includes the `errorLoading` line, which deliberately returns
	 * `i18n_searching` and NOT `i18n_ajax_error`: it is WooCommerce's documented workaround for
	 * select2/select2#4355, not a mistake to be corrected on the way past.
	 *
	 * `wc_country_select_params` is localized onto WooCommerce's `wc-country-select` handle,
	 * which `Checkout_Handler` now declares as a dependency of `woodev-location-select-modes`
	 * so the global is both present and printed first.
	 *
	 * A key whose params are missing is **OMITTED from the returned object entirely** rather
	 * than mapped to a callback returning `undefined`. That distinction is load-bearing and it
	 * is MEASURED, not reasoned: select2 merges our block over its English one with
	 * `customTranslation.extend( baseTranslation )`, which is
	 * `$.extend( {}, base.all(), this.dict )` — OUR dict wins for every key we define
	 * (`selectWoo.full.js:2236,4934-4940`, read in the rig container). So a defined callback
	 * SHADOWS the English string permanently: returning `undefined` from it does not fall back,
	 * it renders a blank message (`Results.prototype.append` still fires
	 * `results:message: 'noResults'` at `selectWoo.full.js:856-861`, now with nothing to show).
	 * Leaving the key absent is what actually lets select2's own default through.
	 *
	 * The first version of this function got that backwards and said so in this docblock as if
	 * it were a fact. Codex refuted it against the selectWoo source, and the refutation was
	 * re-verified here before the change was made.
	 *
	 * @since 2.0.2
	 *
	 * @param {{emptyText?: string}} seed
	 * @returns {Object} A select2 `language` object carrying only the keys this environment can
	 *   actually answer. Never null — the abandon-recording branch below WRAPS the `noResults`
	 *   this returns rather than replacing it, and must tolerate its absence.
	 */
	function select2LanguageFor( seed ) {
		var params = 'undefined' !== typeof window && window.wc_country_select_params
			? window.wc_country_select_params
			: {};
		var language = {};

		/**
		 * @param {string} key
		 * @returns {boolean} Whether WooCommerce actually localized this msgid.
		 */
		function has( key ) {
			return 'string' === typeof params[ key ];
		}

		/**
		 * WooCommerce ships the 1-item and n-item plurals as separate msgids with a `%qty%`
		 * placeholder — mirroring `country-select.js`'s own branch rather than pluralizing
		 * here. Wired only when BOTH msgids are present.
		 *
		 * On requiring both: only `inputTooShort` is actually live in this layer today
		 * (`minimumInputLengthFor()` returns 1 or 2, and both of ITS branches render — 2 with
		 * an empty box, 1 after one character). `inputTooLong` and `maximumSelected` are dead
		 * in practice, because this file never sets `maximumInputLength` or
		 * `maximumSelectionLength` and select2 defaults both to 0. They are wired anyway for
		 * the same reason the whole block is: a consumer that sets either option through a
		 * filter should not get an English message back. Requiring both msgids is therefore
		 * a uniform rule, not a per-key reachability claim — stated here because the first
		 * version of this comment asserted all three were live, and a re-critic measured
		 * otherwise.
		 *
		 * @param {string} name        The select2 `language` key.
		 * @param {string} singularKey
		 * @param {string} pluralKey
		 * @param {function(Object): number} qtyOf
		 * @returns {void}
		 */
		function addPlural( name, singularKey, pluralKey, qtyOf ) {
			if ( ! has( singularKey ) || ! has( pluralKey ) ) {
				return;
			}

			language[ name ] = function( args ) {
				var qty = qtyOf( args );

				return 1 === qty
					? params[ singularKey ]
					: params[ pluralKey ].replace( '%qty%', qty );
			};
		}

		/**
		 * @param {string} name The select2 `language` key.
		 * @param {string} key  The `wc_country_select_params` msgid backing it.
		 * @returns {void}
		 */
		function addSimple( name, key ) {
			if ( ! has( key ) ) {
				return;
			}

			language[ name ] = function() {
				return params[ key ];
			};
		}

		// `i18n_searching` here is WooCommerce's own select2#4355 workaround, deliberately
		// not `i18n_ajax_error` — see the docblock above.
		addSimple( 'errorLoading', 'i18n_searching' );
		addSimple( 'loadingMore', 'i18n_load_more' );
		addSimple( 'searching', 'i18n_searching' );

		addPlural( 'inputTooLong', 'i18n_input_too_long_1', 'i18n_input_too_long_n', function( args ) {
			return args.input.length - args.maximum;
		} );
		addPlural( 'inputTooShort', 'i18n_input_too_short_1', 'i18n_input_too_short_n', function( args ) {
			return args.minimum - args.input.length;
		} );
		addPlural( 'maximumSelected', 'i18n_selection_too_long_1', 'i18n_selection_too_long_n', function( args ) {
			return args.maximum;
		} );

		// The ONE key where this layer's own string wins over WooCommerce's generic
		// «No matches found»: `seed.emptyText` is `Checkout_Config`'s `i18n.noResults`
		// («Поиск не дал результатов. Попробуйте изменить запрос.»), already routed through
		// this plugin's text domain and already what `related-list` shows. Using WooCommerce's
		// string here instead would make the two settlement modes disagree about the same
		// outcome. `i18n_no_matches` is the FALLBACK.
		//
		// Wired only when at least one of the two can answer — with neither, omitting the key
		// leaves select2's own English «No results found», which is worse than a translation
		// and better than a blank dropdown.
		if ( seed.emptyText || has( 'i18n_no_matches' ) ) {
			language.noResults = function() {
				return seed.emptyText || params.i18n_no_matches;
			};
		}

		return language;
	}

	/**
	 * Builds the config object for `strategy` — the exact object `ensureSelect2()` passes to
	 * `.select2()`.
	 *
	 * @param {{ajax: boolean, fetchEntries: function(string, {signal?: AbortSignal}=): Promise<Array>}} strategy
	 * @param {{initialValue: string, placeholder: string, level: string, applyEntries: function(Array, boolean): Array, onRequestStart?: function(function(): void): void}} seed
	 *   `applyEntries` is called with `(entries, false)` on every successful ajax response —
	 *   the SAME merge-only call `ensureSelect2()`'s own transport made before this extraction —
	 *   and now RETURNS the subset of `entries` it actually accepted (see `applyEntries()`'s own
	 *   docblock), so the results reported to select2 and the records resolvable via `dataByKey`
	 *   can never diverge (issue #461 BLOCKING 1/2).
	 *   `onRequestStart`, when supplied, is called SYNCHRONOUSLY every time the transport issues a
	 *   new request, with that request's own cancel function — issue #449 (teardown gap, round 2):
	 *   `buildSelectField()`'s `detach()` has no other way to reach the CURRENT in-flight request,
	 *   since each transport call builds its own `AbortController` in a closure private to that one
	 *   call. See that function's own `activeAbort` docblock for why a single overwritten reference
	 *   is enough (never an array of every request ever made).
	 *   `popular`, when supplied (issue #530), returns the shop's popular-settlements list —
	 *   already country/region-scoped, already `.value`-stamped ({@see popularFor} in
	 *   location-cascade.js) — read LIVE by the ajax transport on every completed search so a
	 *   returned match already IN that list ranks above the rest of that same search (a stable
	 *   partition, never a re-sort within either group).
	 * @returns {Object}
	 */
	function selectConfigFor( strategy, seed ) {
		// Issue #526: wired for EVERY strategy, not just the ajax one. The card was filed
		// against «Список с поиском» because that is where the operator saw it, but the same
		// untranslated select2 defaults reach a `related-list` field that carries no
		// `onAbandon` (a region list) — the old code only ever set `language` on the
		// non-ajax branch, and only when `seed.onAbandon` was a function.
		var config = { width: '100%', language: select2LanguageFor( seed ) };

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

			// Issue #530 ROUND 2 (BLOCKER 2, s93 rig measurement): seeding real <option>
			// elements (`buildSelectField()`, above) is NOT sufficient in ajax mode. Select2's
			// `AjaxData` adapter never reads a <select>'s own DOM options for what it renders —
			// it renders only what the transport below hands back — and `minimumInputLength`
			// blocks the transport from ever running until the floor is met (verified against
			// the rig's own vendored `selectWoo.full.js`: the `MinimumInputLength` decorator,
			// `data/minimumInputLength.js`, is wired onto the data adapter at all only `if
			// (options.minimumInputLength > 0)` — `Defaults.prototype.apply()` — and its own
			// `query()` override rejects with `results:message: 'inputTooShort'` whenever
			// `params.term.length < this.minimumInputLength`). So a fresh customer who opens the
			// field and types nothing gets exactly what the rig measured: `dropdownRowCount: 1`,
			// «Please enter 2 or more characters» — the seeded options are never shown, and
			// `minimumInputLengthFor()` alone cannot be lowered globally: issue #461 is what put
			// that floor there, and this is still a locality-name search once the customer HAS
			// started typing.
			//
			// The floor is instead SCOPED to zero only where a popular list exists to answer an
			// empty term without one — `select2/base.js`'s own `open()` fires `trigger('query',
			// {})` immediately on open (verified in the same vendored bundle), which reaches the
			// transport below with an effectively empty term the moment the field opens, unmet
			// by any keystroke; `minimumInputLength: 0` is what lets that specific call through
			// while the decorator would otherwise reject it before the transport ever ran. A
			// level with no popular list (region, or a store with none configured) keeps the
			// ORIGINAL floor untouched — this scoping only ever loosens the gate where this
			// layer itself can answer what the loosened gate lets through.
			//
			// `popularAvailable` — not `seed.popular()` called here — decides this ONCE, at
			// config-build time: the floor itself must not flip between two calls just because a
			// live-scoped popular list happens to be momentarily empty for the current region
			// (the transport below still calls `seed.popular()` LIVE, per-request, same as the
			// ranking it already did before this round).
			var popularAvailable = 'function' === typeof seed.popular;

			config.minimumInputLength = popularAvailable ? 0 : minimumInputLengthFor( seed.level );

			if ( popularAvailable ) {
				// The floor's own "type N more characters" message (select2's
				// `MinimumInputLength` decorator, bypassed above) has no other hook once
				// `minimumInputLength` is 0 — `language.noResults` is select2's per-render
				// hook for a genuinely EMPTY result set, which a below-floor term now reaches
				// too (the transport's short-circuit for that case, below, never hits the
				// network but still reports zero results). Reusing `inputTooShort` (already
				// built by `select2LanguageFor()` above, when WooCommerce localized both its
				// msgids) keeps that wording intact for the one case it still applies to — a
				// non-empty term shorter than `minimumInputLengthFor( seed.level )`. An empty
				// term never reaches this at all: the transport answers it with the popular
				// list, which is either non-empty (no `noResults` call) or a genuine "shop has
				// no popular entries for this scope" empty state, correctly worded by whatever
				// `noResults`/`i18n_no_matches` already resolves to.
				var floor = minimumInputLengthFor( seed.level );
				var baseNoResults = config.language.noResults;
				var inputTooShort = config.language.inputTooShort;

				config.language.noResults = function( params ) {
					var term = params && 'string' === typeof params.term ? params.term : '';

					if ( term && term.length < floor && 'function' === typeof inputTooShort ) {
						return inputTooShort( { input: term, minimum: floor } );
					}

					return baseNoResults ? baseNoResults( params ) : undefined;
				};
			}

			// Issue #528 (critic MJ-B): the single source of truth `insertTag` below reads —
			// set ONLY on a genuinely completed (non-stale) response, to `entries.length` for a
			// real answer (0 or more) or back to `null` for a transport error/never-completed
			// request. `null` is also the starting value: nothing has answered yet, so no tag
			// is ever offered before the first completed search. Declared here, ABOVE the
			// `config.ajax.transport` closure below, so both that transport and `insertTag`
			// (defined inside the very next `if` block) close over the SAME variable.
			var lastCompletedEntriesLength = null;

			// Issue #528: the merchant's own opt-in — off by default. `tags` is select2's
			// own documented feature (select2/select2 docs, tags.md); `createTag`/`insertTag`
			// follow the SAME shape as the CDEK reference
			// (plugins-reference/woocommerce-edostavka/assets/js/frontend/city-select.js:179-188).
			//
			// Critic MJ-A: `'settlement' === seed.level` is NOT redundant with the option check
			// — `attachAjaxSelect2()`'s registry entry is bare (`registry['ajax-select2']`,
			// `location-cascade.js`'s own `resolveModeRenderer()`), so without this a REGION
			// field whose OWN axis is also `ajax-select2` would get the identical free-typed
			// tag for a value that posts as `billing_state`/`shipping_state` — permanent order
			// data this option's own label ("города НЕ ИЗ СПИСКА") and tooltip ("в поле
			// НАСЕЛЁННОГО ПУНКТА") never promised to touch. Every other layer already scopes
			// this to settlement (the `show_if`, the label, the tooltip), and so does the CDEK
			// reference this `insertTag` shape is copied from — `allowTags` is passed to the
			// CITY widget only, defaulting `false`.
			if ( seed.allowCustomSettlement && 'settlement' === seed.level ) {
				config.tags = true;

				// Refuses an empty/whitespace term (returning `null` tells select2 there is
				// nothing to offer) and stamps `newTag: true` so `handleSelect2Select()` can
				// tell a tag pick apart from a real record pick — a tag has no locality key.
				config.createTag = function( params ) {
					var term = ( params && 'string' === typeof params.term ? params.term : '' ).trim();

					if ( ! term ) {
						return null;
					}

					return {
						id: term,
						text: term,
						newTag: true,
					};
				};

				// Gates the tag row to the ZERO-result case, exactly like the CDEK reference:
				// a customer must never be able to free-type past a town the provider actually
				// carries. Critic MJ-B: reads `lastCompletedEntriesLength` (set by the transport
				// below from `entries.length`, the SAME provider-truth signal the abandon gate a
				// few lines down already answers this question from), never the rendered `data`
				// array select2 hands this hook — `data` is built from `accepted`
				// (`applyEntries()`'s FILTERED output), which can be empty for a reason that has
				// nothing to do with whether the provider carries this town: a transport error
				// resolves `entries` to `null` but `success()` still reports `results: []`
				// unconditionally, and a response whose rows all fail `applyEntries()`'s own
				// value-derivation filter is "the provider answered, this layer just could not
				// derive a submittable value" (critic MN-2's own reasoning, a few lines below in
				// the transport) — neither is "the provider carries nothing here", and offering
				// a free-text row for either misrepresents this option's own promise.
				config.insertTag = function( data, tag ) {
					if ( 0 === lastCompletedEntriesLength ) {
						data.push( tag );
					}
				};
			}

			/**
			 * Shapes one `{key, label, level, record, value}` entry (this layer's own wire
			 * shape, shared by `/suggest`, `/list`, and the popular map) into select2's own
			 * result-item contract — the SAME mapping the transport's completed-search branch
			 * already built inline before this round, now shared with the empty-term popular
			 * branch below so the two can never drift apart.
			 *
			 * @param {Object} entry
			 * @returns {{id: string, text: string, key: string}}
			 */
			function toSelect2Result( entry ) {
				return {
					id: undefined !== entry.value ? entry.value : entry.key,
					text: entry.record.label || entry.label,
					// Carried through select2/selectWoo's own normalized result data — see
					// `SelectAdapter.prototype.option()`/`.item()`, selectWoo.full.js:3309-3350
					// — and handed back verbatim on `select2:select` (`e.params.data.key`,
					// EventRelay, selectWoo.full.js:2174-2218). This is the STABLE identity
					// `buildSelectField()`'s `select2:select` handler resolves the record by;
					// `id`/`text` are select2's own display contract and stay the submitted
					// field VALUE, never this key (issue #455).
					key: entry.key,
				};
			}

			config.ajax = {
				delay: 250, // select2's own debounce, applied before transport() is ever invoked — WC's own baseline (§2.3); redundant to duplicate here.
				// issue #449 (teardown gap, round 2 — the `ajax.delay` question): a `detach()` that
				// lands WHILE a keystroke is still sitting in this 250ms window has nothing to
				// cancel — `this._queryTimeout` is select2's own PRIVATE `AjaxAdapter` field, set
				// and cleared entirely inside its `query()`/the timer callback that invokes
				// `transport()` above; there is no documented public hook to clear it, and
				// `$select.select2('destroy')` does not touch it either (same "no destroy override"
				// gap `activeAbort`'s own docblock already establishes for `_request`). Reaching for
				// it anyway (`$select.data('select2')._queryTimeout`) would mean depending on an
				// undocumented, version-coupled private field purely to shave 250ms off an already-
				// bounded window — worse than the leak it would close. Left deliberately OUT OF
				// SCOPE: a debounced call that fires after teardown still runs through this same
				// transport (a live closure, unaffected by the DOM/select2 teardown that already
				// happened), sets `activeAbort` to ITS OWN controller, and nothing ever cancels that
				// one specific request — a bounded, at-most-one-extra-call residual gap, not the
				// unbounded "every keystroke" cost #449 opened with.
				transport: function( params, success, failure ) {
					var term = params && params.data && params.data.term ? params.data.term : '';

					// Issue #530 ROUND 2 (BLOCKER 2): neither branch below ever reaches the
					// network — `/suggest` on the rig measures 6-10s per request, and neither
					// case is a real search this layer needs the provider to answer.
					//
					// EMPTY term — only reachable at all because `minimumInputLength: 0`
					// (`popularAvailable` above) let select2's own `open()` -> `trigger('query',
					// {})` (selectWoo.full.js:5667, verified in the rig's vendored bundle) reach
					// this far without a keystroke: answered synchronously from `seed.popular()`,
					// read LIVE so a region picked after this select2 instance was built still
					// scopes it (same discipline the ranking further down already applies).
					// Routed through `seed.applyEntries` (merge-only, `false` — the SAME call
					// every completed response already makes below) so a pick straight off this
					// list resolves through the identical `dataByKey`/`resolveAndSelect()` path a
					// search pick uses (spec D1) — never a separate mechanism. Deliberately does
					// NOT touch `lastCompletedEntriesLength` or the #517/#528 abandon machinery:
					// this is the field's own idle state, not a customer search that came back
					// empty or full.
					if ( ! term && 'function' === typeof seed.popular ) {
						success( { results: seed.applyEntries( seed.popular(), false ).map( toSelect2Result ) } );

						return { abort: function() {} };
					}

					// A NON-EMPTY term shorter than the REAL floor — only reachable when
					// `popularAvailable` scoped `minimumInputLength` to 0 above (a level with no
					// popular list keeps its original floor, and select2's own
					// `MinimumInputLength` decorator still blocks this call before `transport()`
					// ever runs). Also answered locally, with an empty result set —
					// `config.language.noResults`'s own wrap above turns this into the exact
					// "type N more characters" wording the bypassed decorator used to show
					// itself, so scoping the floor to 0 costs the customer nothing they can see.
					if ( term && term.length < minimumInputLengthFor( seed.level ) ) {
						success( { results: [] } );

						return { abort: function() {} };
					}

					// issue #449: select2/selectWoo stores whatever this returns as `this._request`
					// and aborts it (only if it looks abortable) before starting the NEXT query —
					// see AjaxAdapter.prototype.query, selectWoo.full.js:3564-3571. issue #449
					// (second half): a real `AbortController` is now threaded through
					// `strategy.fetchEntries()` -> `options.fetch()` (`location-cascade.js`'s
					// `fetchFor()`) into the underlying `fetch()` call's own `init.signal`, so
					// `abort()` below cancels the actual in-flight HTTP request, not merely this
					// call's own eventual result. Feature-detected: `window.AbortController` is
					// missing only in ancient browsers this store's own support matrix already
					// excludes elsewhere, and the `stale` flag below still guarantees correctness
					// even then.
					//
					// The guarantee this must hold is on the last REQUESTED term, never the last
					// ARRIVED response (§2.4's "last-arrived-wins" flicker) — `stale` stays as
					// belt-and-braces alongside real cancellation, not redundant to it: an aborted
					// `fetch()` rejects ASYNCHRONOUSLY (never synchronously inside `abort()`), so a
					// response whose `.then()` callback is already scheduled by the time `abort()`
					// runs still needs a synchronous guard against repainting the list.
					var controller = 'function' === typeof window.AbortController ? new window.AbortController() : null;
					var stale = false;

					// issue #449 (teardown gap, round 2): the SAME cancel function select2 gets
					// back below is also handed to `seed.onRequestStart()`, so `buildSelectField()`'s
					// `detach()` can cancel THIS exact request too — select2's own `destroy()` never
					// does (its AjaxAdapter has no destroy override that touches `_request`, and the
					// base `Adapter.prototype.destroy()` is a no-op), so without this a request still
					// in flight when `updated_checkout` tears the widget down would otherwise run to
					// completion regardless of anything select2 itself does at teardown.
					var abortRequest = function() {
						stale = true;

						if ( controller ) {
							controller.abort();
						}
					};

					if ( 'function' === typeof seed.onRequestStart ) {
						seed.onRequestStart( abortRequest );
					}

					strategy.fetchEntries( term, { signal: controller ? controller.signal : undefined } ).then( function( entries ) {
						if ( stale ) {
							return;
						}

						// Issue #528 (critic MJ-B): `insertTag`'s own single source of truth,
						// above — `entries.length` for a genuinely completed response (`entries`
						// an array, possibly empty), or back to `null` for a transport error
						// (`entries === null`, the SAME signal the abandon gate below treats as
						// "proves nothing either way").
						lastCompletedEntriesLength = entries ? entries.length : null;

						// issue #461 BLOCKING 1/2: `applyEntries()` is the SINGLE place that
						// decides which entries are selectable and what identifies each one —
						// reusing its return value here (never re-deriving the same filter) is
						// what keeps select2's own results and `dataByKey` from disagreeing.
						var accepted = seed.applyEntries( entries, false );

						// Issue #517: this callback only ever runs for a COMPLETED, non-stale
						// response (the `stale`/`isAbortError()` guards around this transport
						// already excluded a cancelled/superseded request, and a genuine
						// transport error takes the separate `failure()` branch below, never
						// this one). `seed.onAbandon` here is `recordAbandonCandidate` (see that
						// function's own docblock) — this only RECORDS the condition
						// `location-cascade.js`'s `onAbandonFor()` exists to hear about;
						// round 2/MJ-1 moved the actual fire to `select2:close`, since this
						// transport can complete mid-typing on a term the customer has already
						// typed past. `term` is never empty here: selectWoo's own
						// `minimumInputLength` decorator (wired below) never calls this
						// transport for anything shorter, and that floor is always >= 1 (see
						// `minimumInputLengthFor()`).
						//
						// Below-`minimumInputLength` text — the select2 analogue of
						// `location-typeahead.js`'s own `resolved: false` report — is
						// deliberately NOT reproduced here: unlike that widget's plain `<input>`,
						// select2's search box is an internal implementation detail with no
						// public event exposing its raw query below the floor, and reaching for
						// it would mean depending on the same kind of undocumented private field
						// this file already refuses elsewhere (see `activeAbort`'s own
						// docblock). THIS IS A NEW GAP, not merely a matched floor: unlike this
						// function, `location-typeahead.js` closes it explicitly —
						// `handleBlur()` reports `onAbandon( { resolved: false } )` for a
						// below-`minChars` blur (17.08.2026 follow-up) — so the baseline widget
						// has NO gap at its floor to compare against, and the select modes
						// re-open one the baseline does not have. Narrow in practice (it needs a
						// real settlement name one character long), tracked as its own card
						// rather than closed here.
						//
						// `entries` (never just `accepted.length`) is the transport-error guard:
						// `attachAjaxSelect2()`'s own `fetchEntries()` resolves with `null` — not
						// `[]` — for a genuine (non-abort) fetch failure it otherwise renders as
						// an ordinary empty result (see that function's own docblock), so `null`
						// here means "this never completed a real search" and must never read as
						// the #350/#517 zero-result condition, only a truly returned (possibly
						// empty) array does.
						//
						// `entries.length` (never `accepted.length`) is the RIGHT gate for "the
						// provider has nothing for this town" (critic MN-2): `accepted` is
						// `applyEntries()`'s output, which additionally drops any row with no
						// usable derived value (`fieldValueFor()` returning `''` — "the lesser of
						// two evils", not an absence of data, per that function's own docblock).
						// A response whose rows all fail THAT filter is "the provider answered
						// but we could not derive a submittable value", never "the provider
						// carries nothing here" — gating on `accepted.length` would report the
						// #350/#517 marker for the wrong reason. A fully-filtered response falls
						// through to `success()` below with an empty result list and no abandon
						// recorded — no results shown, the address lock stands, exactly like an
						// in-flight/never-completed search.
						// Issue #528: gates the WHOLE abandon-recording mechanism on the
						// merchant's opt-in, not just the tag row above — when the option is
						// OFF, unlocking `address` with nothing able to hold the customer's
						// free-typed settlement text just moves the rejection from the client
						// to the server (the card's own measured finding). No candidate
						// recorded, no flush, the address lock stands.
						if ( entries && 0 === entries.length && term && seed.allowCustomSettlement && 'function' === typeof seed.onAbandon ) {
							seed.onAbandon( { query: term, resolved: true } );
						} else if ( entries && entries.length > 0 && seed.allowCustomSettlement && 'function' === typeof seed.onAbandon ) {
							// Critic BL-2 (round 3, BLOCKER): a candidate recorded for an EARLIER,
							// failed intermediate term (e.g. "Тве") otherwise survives a LATER
							// completed search that the provider actually answered (e.g. "Тверь",
							// found on screen) — nothing ever cleared it, so closing without
							// picking still fired the stale "Тве" abandon and unlocked the address
							// in a town the provider demonstrably carries. `seed.onAbandon( null )`
							// is `recordAbandonCandidate`'s own CLEAR signal (see that function's
							// own docblock) — the candidate must always describe the LAST completed
							// search, never a stale earlier one that a later search has already
							// disproven. Gated on `entries.length`, the same provider-truth signal
							// the fire branch above uses (never `accepted.length` — a row this
							// layer merely couldn't derive a value for is not "the provider found
							// nothing", see MN-2's own reasoning) — and, like the fire branch,
							// never reached for a stale/aborted request (the `stale` guard above
							// already returned) or a transport error (`entries === null` fails
							// this same truthy check, so an error neither fires NOR clears —
							// correct: it proves nothing either way).
							seed.onAbandon( null );
						}

						// Issue #530: popular entries rank ABOVE the rest of this same completed
						// search — a stable partition (never a re-sort of provider relevance
						// within either group), read LIVE via `seed.popular()` so a region
						// picked after select2 init still scopes it correctly (see that
						// function's own docblock, `popularFor()` in location-cascade.js).
						var popularKeys = {};

						if ( 'function' === typeof seed.popular ) {
							seed.popular().forEach( function( item ) {
								if ( item && item.key ) {
									popularKeys[ item.key ] = true;
								}
							} );
						}

						var ranked = accepted.filter( function( item ) {
							return !! popularKeys[ item.key ];
						} ).concat( accepted.filter( function( item ) {
							return ! popularKeys[ item.key ];
						} ) );

						success( {
							results: ranked.map( toSelect2Result ),
						} );
					}, function( error ) {
						// `isAbortError()`: a request WE cancelled must never paint "search
						// failed" for the customer (issue #449). `stale` alone already guards
						// this in practice (see the block comment above), but the explicit check
						// keeps this branch correct even if a future caller reuses this transport
						// without wiring the `stale` flag the same way.
						if ( stale || isAbortError( error ) ) {
							return;
						}

						failure( error );
					} );

					return {
						abort: abortRequest,
					};
				},
			};
		} else if ( 'function' === typeof seed.onAbandon ) {
			// related-list:settlement has no transport of its own to observe — the full,
			// region-scoped list is already loaded (buildSelectField()'s own one-time
			// fetchEntries('') call) and select2 filters it LOCALLY, with no network
			// round-trip and no `ajax.transport` this file controls. `language.noResults` is
			// select2's own PUBLIC, documented per-query message hook (select2/select2 docs,
			// i18n.md; the same "language.*" callback family the CDEK reference
			// (plugins-reference/woocommerce-edostavka/assets/js/frontend/city-select.js:220-222)
			// already overrides for its own noResults string) — select2 calls it, with the
			// CURRENT query params, exactly when the filtered result set is empty, and never
			// for a blank term (an empty query always shows the full loaded list, never zero
			// results). Reusing it here as an observation point is the honest seam this file's
			// own docblock already commits to: no network, and no private/underscored select2
			// field the way `activeAbort`'s own docblock explicitly refuses to touch.
			//
			// Issue #517 round 2 (MJ-1): `language.noResults` is a RENDER-TIME hook — select2
			// calls it on every keystroke that still matches nothing, not once per search. So
			// `seed.onAbandon` here is `recordAbandonCandidate` (see that function's own
			// docblock), never a direct fire — calling straight through to
			// `location-cascade.js`'s `onAbandonFor()` on every matching keystroke would consume
			// `restoreClearedDescendants()`'s snapshot mid-typing, restoring a stale address
			// before the customer finishes searching. The actual fire is deferred to
			// `select2:close`, mirroring `location-typeahead.js`'s own blur-only timing.
			//
			// The returned string is never invented here: `seed.emptyText` is the SAME
			// server-supplied "no results" text `location-cascade.js`'s `attachOne()` already
			// resolves for every renderer at this node.
			//
			// Issue #526 changed the SHAPE of this, not its timing: `config.language` is now
			// built for every strategy by `select2LanguageFor()` above, so this branch WRAPS
			// the `noResults` already there instead of replacing the whole `language` object.
			// Replacing it would silently drop the seven other WooCommerce-sourced messages.
			// The wrapped callback keeps `select2LanguageFor()`'s own return value — the same
			// `seed.emptyText`, now with `i18n_no_matches` behind it instead of `''`.
			//
			// The critic MN-4/MN-5 note that used to sit here asserted that «`ajax-select2`
			// never wires `config.language` at all». That was a MEASUREMENT of the old code,
			// and #526 made it false — it is removed rather than left to mislead the next
			// reader (this file has been bitten three times by an inference left standing in a
			// docblock as a fact).
			//
			// `select2LanguageFor()` OMITS `noResults` when neither `seed.emptyText` nor
			// `i18n_no_matches` can answer (see its own docblock for why omission and not an
			// `undefined`-returning callback). The abandon observation still has to happen in
			// that case, so the wrap installs itself either way and returns `undefined` only
			// when there was no string to begin with.
			//
			// KNOWN, ACCEPTED, and NOT unreachable — stated precisely because a re-critic
			// refuted the first version of this comment, which called it impossible. Defining
			// the key here costs select2's English fallback, so that one corner renders a
			// BLANK zero-result message. Reaching it takes BOTH of two public filters used
			// destructively at once: `woodev_location_i18n` emptying `noResults` (it is a
			// hardcoded `__()` string otherwise) AND WooCommerce's own
			// `woocommerce_get_script_data` suppressing `i18n_no_matches`. Neither default
			// gets there. The trade is deliberate: the RECORD of an abandoned search is what
			// #350/#517 exist for and outranks the message shown for it, and this branch is
			// only reached at all when `onAbandon` is wired.
			var localizedNoResults = config.language.noResults;

			config.language.noResults = function( params ) {
				var term = params && 'string' === typeof params.term ? params.term : '';

				// `! seed.listLoadFailed`: see that flag's own docblock — a region whose
				// FULL list never loaded reports zero matches for every term, but that is
				// a transport failure, never a completed search proving the provider has
				// nothing for this exact town.
				if ( term && ! seed.listLoadFailed ) {
					seed.onAbandon( { query: term, resolved: true } );
				}

				return localizedNoResults ? localizedNoResults( params ) : undefined;
			};

			// Critic BL-2 (round 3, BLOCKER) — the local/related-list counterpart of the
			// `entries.length > 0` clear above: `language.noResults` only ever tells us about a
			// ZERO-match render pass, never a matched one, so a candidate recorded for an
			// earlier failed prefix (e.g. "Тве") would otherwise survive a LATER keystroke that
			// DOES match (e.g. "Тверь", rendered as a real row) with nothing to clear it.
			// `templateResult` is select2's own PUBLIC, documented per-result rendering hook
			// (select2/select2 docs, dropdown.md) — called once for every row select2 is ABOUT
			// TO RENDER, i.e. exactly the "a match exists for the current term" signal this
			// layer needs, with no re-derivation of select2's own matching logic (unlike a
			// custom `matcher`, which would risk diverging from select2's real default
			// text-matching behaviour — a risk this file already refuses elsewhere for
			// `activeAbort`'s private-field reasons, just a different flavour of it here).
			// Returning `data.text` unchanged is EXACTLY select2's own default `templateResult`
			// (per the docs: "if no template function is specified... text property... is
			// used"), so this changes nothing about what renders — pure observation.
			config.templateResult = function( data ) {
				// `data.loading` is select2's own placeholder object for an in-flight AJAX
				// page — never true for this LOCAL, non-ajax strategy, but guarded anyway since
				// this config object is built by the SAME function the ajax branch shares.
				if ( ! data || data.loading ) {
					return data && data.text;
				}

				seed.onAbandon( null );

				return data.text;
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
	 * @param {{ajax: boolean, fetchEntries: function(string, {signal?: AbortSignal}=): Promise<Array>}} strategy
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

		// Issue #528: the merchant opt-in for letting the customer submit a settlement the
		// active provider does not carry — meaningful ONLY for the `ajax-select2` strategy
		// (the settlement axis's `related-list` mode is clamped away unconditionally, #486,
		// and never reads this; `typeahead` already carries free text in a plain `<input>`
		// and never reaches this function at all). See `selectConfigFor()`'s ajax branch for
		// where this gates `tags`/`insertTag`/`createTag` AND the abandon-recording calls
		// together — when this is `false`, #517's onAbandon must not fire at all here (the
		// operator's own #528 reasoning: unlocking `address` with nothing able to hold the
		// customer's free-typed text just moves the rejection from the client to the server).
		var allowCustomSettlement = !! ( options.location && options.location.allowCustomSettlement );

		// Issue #466: WooCommerce's own `country-select.js` rebuild reads `data-input-classes`
		// and `placeholder`/`data-placeholder` straight off whatever CURRENTLY occupies the
		// state field (`$statebox.attr('data-input-classes')`,
		// `$statebox.attr('placeholder') || $statebox.attr('data-placeholder')`,
		// `country-select.js:103,105`) before replacing it — never `class`. A `<select>` this
		// function built without either attribute makes WC's next rebuild carry forward
		// `undefined`/empty (measured on the rig: the field it left behind read
		// `class="input-text undefined"`), same defect the CDEK reference
		// (`plugins-reference/woocommerce-edostavka/assets/js/frontend/city-select.js:79-80`)
		// already carries both of for exactly this reason. `placeholder`, already resolved above
		// with its own fallback chain, is written as BOTH attributes since WC's rebuild accepts
		// either.
		//
		// Issue #469: the attribute is carried VERBATIM when the input has one, and set to the
		// EMPTY STRING when it does not — never left unset. Leaving it unset is what produced the
		// `undefined` in the first place: `$statebox.attr()` yields `undefined` for a missing
		// attribute, and `country-select.js:120` concatenates that straight into a class list
		// (`.addClass( 'state_select ' + input_classes )`). The empty string is not a fabricated
		// value — it is exactly what WooCommerce's own `state` branch emits for a field with no
		// `input_class`, and a stock install always lands there because WC core sets none on
		// address fields. The server cannot supply it: `woocommerce_form_field()` drops
		// empty-string entries from `custom_attributes` via `array_filter( …, 'strlen' )`, which
		// is why this half exists at all ({@see Checkout_Handler::inject()}).
		var inputClasses = input.getAttribute( 'data-input-classes' );

		select.setAttribute( 'data-input-classes', null === inputClasses ? '' : inputClasses );

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
		 * @type {function(): void|null} cancels the MOST RECENTLY started `ajax-select2` request,
		 * or `null` when none has ever started (or the last one already settled/was cancelled).
		 * issue #449 (teardown gap, round 2): `selectConfigFor()`'s transport overwrites this on
		 * EVERY new request it issues via `seed.onRequestStart()` — a single reference, never an
		 * array of every request this field has ever made, since select2's own store-then-abort
		 * sequence (`AjaxAdapter.prototype.query`) already guarantees at most one of our requests
		 * is genuinely "current" at a time; anything earlier was already cancelled BY SELECT2
		 * before the next one started. `detach()` below is the one caller select2 itself cannot
		 * reach on our behalf — its own `destroy()` never touches `_request`. A plain closure
		 * variable survives regardless of whether select2's own jQuery `.data('select2')` has
		 * already been purged by an ancestor `cleanData()` (issue #457's own trap), so this stays
		 * reachable even when `detach()` runs on an already half-torn-down widget.
		 */
		var activeAbort = null;

		/**
		 * @type {boolean} Issue #517 (related-list:settlement's own transport-error guard):
		 * `true` once the ONE-TIME full-list load below has settled having FAILED. Set
		 * synchronously, in the SAME `.then()` callback that also calls `ensureSelect2()` for
		 * the first (and only — this strategy never re-fetches) time, so `selectConfigFor()`'s
		 * `language.noResults` hook — built inside that same `ensureSelect2()` call — always
		 * reads this at its own final, settled value; never a race. A field whose region-scoped
		 * list genuinely failed to load has zero options for a reason that says nothing about
		 * whether the provider carries the customer's typed town — the same "never on a
		 * transport error" exclusion `attachAjaxSelect2()`'s own `null`-vs-`[]` distinction
		 * already enforces for the ajax strategy.
		 */
		var listLoadFailed = false;

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
		 * Issue #488 slice 3 (D7 Seam D): `lastHandledKey` deliberately never expires on its own —
		 * that is what makes it a same-pick guard rather than a debounce. But that means an owner
		 * that clears this field's value OUT FROM UNDER the widget (a D7 cancelled `/select`
		 * re-locking a stale pick — {@see location-cascade.js}'s `clearChainField()`) leaves this
		 * closure still remembering the dead key, so the most natural recovery — the customer
		 * re-picking the SAME still-rendered entry — resolves to a no-op here and never re-fires
		 * `/select` at all. `forgetLastHandled()` below exists so that owner can explicitly say "the
		 * field's value is no longer this widget's own doing" and make the next pick of that same
		 * key live again, without weakening the idempotency guard itself for an ordinary double-fire.
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

			// Issue #517 round 3 (MJ-3): a real pick disproves any outstanding zero-result
			// candidate {@see recordAbandonCandidate} is still holding. MEASURED on the rig
			// (round 3): a real select2 pick fires `change` → `select2:closing` →
			// `select2:close` → `select2:select`, in that order — `close` arrives BEFORE
			// `select`, not after. {@see handleSelect2Close}'s own docblock explains why it
			// only SCHEDULES a flush rather than firing it inline: this line, clearing the
			// live candidate before the scheduled flush's callback ever runs, is the other
			// half of that mechanism — see that docblock for the full reasoning and the
			// measured event order it depends on.
			cancelScheduledAbandonFlush();
			pendingAbandon = null;

			options.onSelect( { record: record } );
		}

		/**
		 * Forgets `lastHandledKey` — see {@see resolveAndSelect}'s own docblock for why this
		 * exists and when it is safe to call: only when the caller has ALREADY overwritten this
		 * field's DOM value out from under the widget, so the next select2/native pick is genuinely
		 * a fresh user action, not a double-fire of the one `resolveAndSelect()` already handled.
		 *
		 * @returns {void}
		 */
		function forgetLastHandled() {
			lastHandledKey = null;
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

			// Issue #528: a tag has no locality key — it is NOT a record pick.
			// `createTag()`'s own `newTag` stamp (see `selectConfigFor()`'s ajax
			// branch) survives select2/selectWoo's relay verbatim (same EventRelay
			// path `resolveAndSelect()`'s own docblock cites for `.key`), so it is
			// safe to branch on here before ever touching `dataByKey`. Deliberately
			// NEVER routed through `resolveAndSelect()`: no `/select` call, no
			// `entry.records.settlement` write — only the #350/#517 unresolved
			// marker `options.onAbandon` (== `location-cascade.js`'s own
			// `onAbandonFor()`) already writes for a genuine zero-result search, and
			// this pick is exactly that same evidence, just customer-confirmed
			// rather than provider-proven.
			if ( data && data.newTag ) {
				handleTagSelect( data );

				return;
			}

			resolveAndSelect( data ? data.key : null );
		}

		/**
		 * Handles a `createTag()`-produced pick (#528) — the customer explicitly
		 * chose to submit a settlement the active provider does not carry. Composes
		 * with the SAME deferred-flush mechanism a real pick uses
		 * ({@see resolveAndSelect}'s own docblock): the measured event order
		 * (`change → closing → close → select`) means `handleSelect2Close()` has
		 * already run and set `dropdownOpen = false` by the time this fires, so
		 * `recordAbandonCandidate()` below fires IMMEDIATELY rather than waiting for
		 * another close — there is no future close left to defer to.
		 *
		 * Cancelling any already-SCHEDULED flush first (round-2 critic MN-A: NOT
		 * what actually prevents a double-fire — {@see fireAbandonNow} nulls
		 * `pendingAbandon` BEFORE calling `options.onAbandon`, so a scheduled
		 * timer that still fires a tick later already finds nothing pending and
		 * no-ops on its own) is cheap insurance against an unnecessary macrotask
		 * outliving this synchronous pick, not a correctness requirement.
		 *
		 * @param {{id?: string, text?: string}} tag
		 * @returns {void}
		 */
		function handleTagSelect( tag ) {
			cancelScheduledAbandonFlush();

			var term = tag && 'string' === typeof tag.id && tag.id ? tag.id : ( tag && tag.text ) || '';

			recordAbandonCandidate( term ? { query: term, resolved: true } : null );
		}

		/**
		 * @type {{query: string, resolved: boolean}|null} Issue #517: the most recent
		 * completed-search outcome reported via `selectConfigFor()`'s `seed.onAbandon` — see
		 * {@see recordAbandonCandidate}'s own docblock for why this is a RECORD, not a fire.
		 * Holds the full `resolved` flag `onAbandonFor()` expects (critic MN-7, round 3) —
		 * never re-synthesised at flush time, so a future `resolved: false` caller (issue #523)
		 * is not silently upgraded to `true`.
		 */
		var pendingAbandon = null;

		/** @type {boolean} whether the dropdown is CURRENTLY open — see {@see recordAbandonCandidate}'s MJ-4 half. */
		var dropdownOpen = false;

		/** @type {number|null} the pending `setTimeout` id from {@see scheduleAbandonFlush}, if any. */
		var abandonFlushTimer = null;

		/**
		 * Cancels a scheduled flush without firing it — called whenever something proves the
		 * pending candidate should not fire as-is (a pick, in {@see resolveAndSelect}) or when
		 * tearing the widget down (`detach()`).
		 *
		 * @returns {void}
		 */
		function cancelScheduledAbandonFlush() {
			if ( null !== abandonFlushTimer ) {
				clearTimeout( abandonFlushTimer );
				abandonFlushTimer = null;
			}
		}

		/**
		 * Fires `options.onAbandon` for {@see pendingAbandon} RIGHT NOW, if there is one —
		 * the only place that ever actually calls the primitive. Idempotent: a second call
		 * with nothing pending is a silent no-op, which is what lets both
		 * {@see recordAbandonCandidate}'s immediate-fire branch and the deferred flush below
		 * share this one implementation without coordinating who "owns" the call.
		 *
		 * @returns {void}
		 */
		function fireAbandonNow() {
			if ( null === pendingAbandon || 'function' !== typeof options.onAbandon ) {
				return;
			}

			var detail = pendingAbandon;

			pendingAbandon = null;

			options.onAbandon( detail );
		}

		/**
		 * Records `detail` as the latest completed-search outcome — called from
		 * `selectConfigFor()` in place of firing `options.onAbandon` directly (that function's
		 * own docblock still names it `onAbandon` in the seed it builds; this is what that name
		 * now resolves to for both strategies sharing this file). `detail === null` is the
		 * CLEAR signal (critic BL-2, round 3): a completed search that DID return results, or
		 * an explicit "nothing pending any more" — see the two call sites in `selectConfigFor()`
		 * for why a candidate must never survive a LATER search that disproves it.
		 *
		 * WHY A RECORD, NOT A FIRE (critic MJ-1, corrected in round 3 — see the file's own
		 * `select2:close` binding docblock for the measured event order this now accounts for):
		 * `language.noResults` and the ajax transport's success path are both RENDER-TIME/
		 * per-request hooks — select2 calls the former on every keystroke that still matches
		 * nothing, and a debounced ajax query can complete mid-typing on an INTERMEDIATE term
		 * the customer has already typed past. Recording rather than firing inline keeps a
		 * mid-search completion from deciding anything by itself.
		 *
		 * MJ-4 (round 3): a response that lands with the dropdown ALREADY CLOSED will never see
		 * another `close` event to flush it via {@see scheduleAbandonFlush} — the customer who
		 * clicks away while the request is still in flight (the rig's own `/suggest` genuinely
		 * takes seconds) would otherwise be stuck locked forever, on the exact mode #517 was
		 * filed to fix. So a candidate recorded while `dropdownOpen` is already `false` fires
		 * IMMEDIATELY instead of waiting — there is no future pick or close left to race against
		 * on this closed dropdown, so none of MJ-1's mid-typing concerns apply.
		 *
		 * @param {{query: string, resolved: boolean}|null} detail
		 * @returns {void}
		 */
		function recordAbandonCandidate( detail ) {
			pendingAbandon = ( detail && 'string' === typeof detail.query && detail.query )
				? { query: detail.query, resolved: !! detail.resolved }
				: null;

			if ( ! dropdownOpen ) {
				fireAbandonNow();
			}
		}

		function handleSelect2Open() {
			dropdownOpen = true;
		}

		/**
		 * `select2:close`, `select2:select` — the ONE fact this whole mechanism turns on, and it
		 * is MEASURED, not assumed (critic MJ-3, round 3): against the live rig
		 * (`:8973/classic-checkout/`, `shipping_city` under `ajax-select2`, provider
		 * `test-cdek`), a real pick fires, in this exact order,
		 * `select2:opening → change (jQuery) → select2:closing → select2:close →
		 * select2:select` — reproduced identically across WooCommerce's own `billing_state`
		 * selectWoo instance (mouse ×2, keyboard Enter ×1) and this file's own ajax-backed
		 * field. **`close` arrives BEFORE `select`, not after.**
		 *
		 * That is the opposite of what a same-tick `select2:close` flush could safely assume: a
		 * flush running INLINE here would fire {@see fireAbandonNow} before
		 * {@see resolveAndSelect} ever gets a chance to clear {@see pendingAbandon} — and because
		 * `change` (which runs `location-cascade.js`'s `clearDescendants()`, snapshotting the
		 * address field) is FIRST in the measured order, a live snapshot already exists by the
		 * time an inline flush would run, so `restoreClearedDescendants()` would have something
		 * to consume — writing the PREVIOUS settlement's street back under the newly picked town.
		 * That was round 2's actual bug (critic PROBE P2).
		 *
		 * The fix: SCHEDULE the flush (`setTimeout( fn, 0 )`, a macrotask) instead of running it
		 * inline. `select2:select` — confirmed synchronous with `close` in the SAME browser event
		 * dispatch, per the measured order above — always reaches {@see resolveAndSelect} and
		 * clears `pendingAbandon` BEFORE any macrotask can run, so a real pick always wins the
		 * race; a genuine close-without-picking has nothing racing it at all, and the deferred
		 * flush runs on the very next tick exactly as if it had fired inline. `detach()` cancels
		 * this timer AND flushes immediately itself (MJ-4's other half — a candidate must not be
		 * silently dropped on teardown either).
		 *
		 * STILL NOT SETTLED: whether re-opening and closing the SAME still-unmatched term
		 * re-fires this (a repeat close would re-fire — matching `location-typeahead.js`'s own
		 * `handleBlur()`, which already re-reports on every subsequent blur over unchanged text).
		 * `tests/js/support/fake-select2.js` now reproduces the MEASURED pick order (see its own
		 * docblock) so the pick-before-close tests exercise it directly; open/close repetition
		 * frequency remains rig-only evidence.
		 *
		 * @returns {void}
		 */
		function handleSelect2Close() {
			dropdownOpen = false;
			cancelScheduledAbandonFlush();
			abandonFlushTimer = setTimeout( function() {
				abandonFlushTimer = null;
				fireAbandonNow();
			}, 0 );
		}

		if ( $select ) {
			$select.on( 'select2:open', handleSelect2Open );
			$select.on( 'select2:select', handleSelect2Select );
			$select.on( 'select2:close', handleSelect2Close );
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
				// issue #449 (teardown gap, round 2): see `activeAbort`'s own docblock above.
				onRequestStart: function( abortFn ) {
					activeAbort = abortFn;
				},
				// Issue #517: OPTIONAL, same as every other primitive `location-cascade.js`'s
				// `attachOne()` hands over — see `selectConfigFor()`'s own docblock for where
				// each strategy fires it. Issue #517 round 2 (MJ-1): resolves to
				// `recordAbandonCandidate`, NEVER `options.onAbandon` directly — see that
				// function's own docblock for why the actual fire is deferred to
				// `select2:close`.
				onAbandon: 'function' === typeof options.onAbandon ? recordAbandonCandidate : null,
				// Issue #528: see the local `allowCustomSettlement` var's own docblock above.
				allowCustomSettlement: allowCustomSettlement,
				emptyText: 'string' === typeof options.emptyText ? options.emptyText : '',
				// Issue #517: see `listLoadFailed`'s own docblock above — always `false` for the
				// `ajax` strategy (never assigned there), meaningful only for `related-list:settlement`.
				listLoadFailed: listLoadFailed,
				// Issue #530: OPTIONAL, same discipline as `onAbandon` above — `location-cascade.js`'s
				// `attachOne()` only hands this over when a level actually carries a popular list
				// (currently `settlement`). Read LIVE by `selectConfigFor()`'s transport on every
				// completed search, never captured once here, so a region picked AFTER this select2
				// instance was built still scopes the ranking correctly.
				popular: 'function' === typeof options.popular ? options.popular : null,
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
			} else {
				// Issue #530: seed the shop's popular-settlements list as real <option>
				// elements so the field has something useful before the customer types.
				// `minimumInputLengthFor()` floors this field at 2 characters, so an
				// ajax-select2 field structurally cannot serve an empty state any other
				// way — a round-trip at attach time would mean seconds of empty field (the
				// rig's own `/suggest` measured 8.5s) plus a race with select2 init. `true`
				// (REPLACE, not merge) is what actually builds the `<option>` DOM nodes —
				// `applyEntries()`'s own docblock: the ajax `false` call `selectConfigFor()`'s
				// transport makes is merge-only because select2 renders ITS OWN `<option>`s
				// for a remote result; this is the empty state BEFORE select2 ever runs, so
				// it needs the real nodes. Safe to REPLACE here: `select` is still empty at
				// this point in `buildSelectField()`, nothing to lose. Routed through the SAME
				// `applyEntries()` every ajax response uses, so a pick here is indistinguishable
				// from a search pick (spec D1) — real `dataset.woodevKey`, real `dataByKey`
				// entry, same `resolveAndSelect()` → `options.onSelect()` → `/select` path,
				// never a separate selection mechanism.
				if ( 'function' === typeof options.popular ) {
					applyEntries( options.popular(), true );
				}

				if ( placeholder ) {
					// The empty leading <option> select2's placeholder requires (see
					// ensureSelect2()) — MUST be the first child for select2's placeholder
					// mechanism to suppress it, so this is inserted BEFORE whatever popular
					// seeding above produced, never appended after it.
					//
					// Issue #530 ROUND 2 (BLOCKER 1, s93 rig measurement): `.selected = true`
					// is set EXPLICITLY here, never left implicit. `applyEntries()` above
					// (when `options.popular` ran) appended each popular <option> WITHOUT
					// setting `.selected` on any of them — the instant the first one landed,
					// the browser's own reset algorithm for a non-`multiple` <select> ("if no
					// option has selectedness true, select the first option in tree order")
					// auto-selected it. Inserting this blank option AFTERWARD, at position 0,
					// does not undo that on its own: a fresh customer who never touched the
					// field saw the top popular city pre-filled, and it posted as
					// `shipping_city` if they submitted without opening the dropdown.
					//
					// `.selected = true` is set AFTER `insertBefore`, deliberately, not before:
					// the "selecting one option deselects every sibling" side effect only
					// applies to an option that already belongs to a `<select>` at the moment
					// it is set (measured against jsdom, which does not retroactively enforce
					// single-selection for a detached option's `.selected` set before it is
					// attached) — setting it first would leave the earlier auto-selected
					// popular option's own selectedness untouched. Attaching first and
					// selecting second makes the deselect-siblings side effect actually run,
					// regardless of what was auto-selected before it. A popular list is a
					// SUGGESTION, never a selection (#350, #502's own rule).
					var blankOption = document.createElement( 'option' );

					select.insertBefore( blankOption, select.firstChild );

					blankOption.selected = true;
				}
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
					listLoadFailed = true;
					applyEntries( [], true );
					ensureSelect2();
				}
			);
		}

		return {
			el: select,
			// Issue #488 slice 3 (D7 Seam D): {@see forgetLastHandled}'s own docblock — the one
			// hook `location-cascade.js`'s D7 cancel path uses to make a same-entry re-pick live
			// again after it clears this field's value out from under the widget.
			reset: forgetLastHandled,
			detach: function() {
				// issue #449 (teardown gap, round 2): cancels whatever `ajax-select2` request is
				// still in flight FIRST — before anything below touches select2's own instance data
				// or the DOM. select2's own `destroy()` (just below) never does this itself (no
				// `AjaxAdapter` destroy override touches `_request`; the base `Adapter.prototype.
				// destroy()` is a no-op), and it is exactly the same "runs to completion, still
				// billing DaData/CDEK" cost #449 already fixed for a superseded KEYSTROKE — this is
				// that same leak on the teardown trigger instead (`updated_checkout` tears this
				// widget down on every re-render, not a rare path). Works unconditionally, even when
				// select2's own jQuery data was already purged by an ancestor `cleanData()` (see the
				// #457 comment right below) — `activeAbort` is a plain closure variable, not
				// anything select2 owns or clears.
				if ( activeAbort ) {
					activeAbort();
					activeAbort = null;
				}

				// Issue #517 round 3 (MJ-4, second half): a candidate can be sitting in
				// `pendingAbandon` with no `select2:close` ever coming again — `detach()` IS the
				// only remaining "leaving the field" moment for a widget WooCommerce is about to
				// tear down (`updated_checkout` replacing this exact fragment). Cancel any
				// already-scheduled flush first (never let a stale timer double-fire after this
				// synchronous one), then fire whatever is left, unconditionally: `fireAbandonNow()`
				// is a no-op with nothing pending, so this is always safe to call.
				cancelScheduledAbandonFlush();
				fireAbandonNow();

				unbind();

				if ( $select ) {
					$select.off( 'select2:open', handleSelect2Open );
					$select.off( 'select2:select', handleSelect2Select );
					$select.off( 'select2:close', handleSelect2Close );
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
			// Issue #517: a rejection is left to propagate, UNCAUGHT, straight to
			// `buildSelectField()`'s own non-ajax `.then( success, error )` — which already
			// logs and degrades to an empty list itself (previously dead code for this
			// renderer, since this wrapper used to swallow every rejection right here) and, as
			// of #517, also sets `listLoadFailed` so a genuinely failed list load can never be
			// mistaken for the provider having nothing to offer.
			//
			// Round 2 correction (critic MJ-2): `Promise.resolve( … )` stays, even with the
			// `.then( null, … )` swallow gone — it was doing a SECOND job the first pass of
			// this diff missed: normalising a non-thenable return. `buildSelectField()` calls
			// `strategy.fetchEntries('').then(…)` directly; the renderer contract this file's
			// own docblock states never forbids `options.list()` returning a plain array
			// synchronously (any test double or third-party `related-list` consumer could
			// reasonably do so), and without this wrapper that throws `TypeError:
			// …fetchEntries(...).then is not a function` straight out of `attach()`, leaving a
			// bare `<select>` already swapped in for the `<input>` but never populated. Not
			// reachable through this framework's own wiring (`location-cascade.js`'s
			// `listFor()` always returns a real promise) — a contract regression for callers
			// outside it, not a live bug in this repo, but a one-token fix.
			fetchEntries: function() {
				return Promise.resolve( options.list() );
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
			fetchEntries: function( term, opts ) {
				return Promise.resolve( options.fetch( term, opts ) ).then( null, function( error ) {
					// issue #449 (second half): a request THIS module aborted (via
					// `selectConfigFor()`'s transport) must reach `selectConfigFor()`'s own
					// `stale`/`isAbortError()` guard untouched, never get folded into the
					// "results: []" success path a genuine fetch failure gets below — that would
					// mean select2 briefly shows "nothing found" for a term the customer has
					// already moved on from.
					if ( isAbortError( error ) ) {
						throw error;
					}

					logError( error );

					// Issue #517: `null`, never `[]` — `buildSelectField()`'s own `applyEntries()`
					// treats a missing entries array exactly like an empty one (`(entries ||
					// []).forEach(...)`), so the customer still sees "nothing found" here,
					// UNCHANGED from before this card (see this function's own docblock above —
					// that contract is not being touched). `selectConfigFor()`'s transport uses
					// the difference between `null` and `[]` for its OWN purpose only: telling a
					// genuinely completed, zero-result search (the #350/#517 condition
					// `onAbandonFor()` must hear about) apart from a swallowed transport error
					// that merely LOOKS like one by the time it gets there — the two are
					// indistinguishable once collapsed to the same empty array.
					return null;
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
