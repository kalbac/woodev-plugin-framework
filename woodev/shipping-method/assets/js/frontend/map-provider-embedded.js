/**
 * Woodev Embedded Map Provider — the carrier-widget/`<iframe>` half of the
 * {@see Map_Provider} seam (SP-5 Task 14). The other half,
 * `map-provider-yandex.js`, draws our own map on ymaps 2.1; this file draws
 * NOTHING of its own — it injects the carrier's embed and translates whatever
 * comes back into the framework's normalized point shape. See
 * `woodev/shipping-method/map/class-embedded-map-provider.php`, which supplies
 * this file's `embedUrl`/`expectedOrigin` config through
 * `Map_Provider::get_js_config()`.
 *
 * Plain constructor, ES5-safe, no jQuery, no build step — enqueued directly
 * under `Embedded_Map_Provider::get_script_handle()`, never bundled.
 *
 * CONTRACT (identical to every {@see Map_Provider}, spec §4.3):
 *   init( container, config, dataSource ) -> void
 *   on( 'select', cb )   // cb( normalizedPoint )
 *   on( 'error', cb )    // cb( { code, message } )
 *   destroy()
 * `dataSource` is accepted but unused: this provider never calls `woodev/v1`
 * itself — the carrier's own embed fetches and renders its own points, and
 * hands a selection back over `postMessage` or the callback hook below.
 * `init()` never needs to be asynchronous (no network call of our own to
 * await), so it returns `undefined`, which satisfies the `Promise<void>|void`
 * contract just as well as a resolved promise would; `pickup-mount.js` never
 * `.then()`s the return value either way.
 *
 * A RETRY IS ALWAYS A FRESH INSTANCE, NEVER A RE-`init()`: `pickup-mount.js`'s
 * `start()` destroys the current provider and constructs a new one before
 * calling `init()` again — see that file's own docblock. This file relies on
 * that: `init()` is written assuming it runs at most once per instance and
 * does not attempt to be idempotent against a second call.
 *
 * SECURITY BOUNDARY — `postMessage` ORIGIN CHECK (see {@see handleMessage}):
 * this is the ONE place in the whole pickup-point path where a THIRD PARTY
 * (the carrier's embedded page, or anything sharing its browsing context) can
 * inject data straight into the checkout form. Three independent checks gate
 * every incoming message, each closing a distinct attack:
 *   1. `event.origin` compared with STRICT `===` against `config.expectedOrigin`
 *      — never `indexOf`/`startsWith`/`lastIndexOf`. A prefix/substring test
 *      would let `https://carrier.ru.attacker.com` (or
 *      `https://attacker.com/https://carrier.ru`) pass a check written against
 *      `https://carrier.ru`; only exact equality is safe. `expected_origin` is
 *      already `untrailingslashit()`ed server-side
 *      ({@see \Woodev\Framework\Shipping\Map\Embedded_Map_Provider::__construct()}),
 *      and a browser's `event.origin` never carries a trailing slash either, so
 *      the comparison needs no normalization here.
 *   2. `event.source === iframe.contentWindow` — closes the case where a
 *      DIFFERENT window that merely happens to share the trusted origin (a
 *      same-origin popup, another tab, a same-origin ad slot elsewhere on the
 *      page) posts a look-alike message. Origin alone identifies WHERE a
 *      message came from, not WHICH window; only this check pins it to the
 *      exact iframe this instance created.
 *   3. An empty/missing `config.expectedOrigin` REJECTS EVERY MESSAGE. A
 *      plugin author who forgot to pass `expected_origin` into
 *      `Embedded_Map_Provider`'s constructor gets a picker that silently never
 *      accepts a selection over `postMessage` — loud in testing — rather than
 *      one that (with an empty-string `===` mistake) would trust literally any
 *      origin, including `null` (a sandboxed frame with no `allow-same-origin`,
 *      or a `data:` URL) which JS string-compares equal to `''` under a loose
 *      check but must never be trusted here.
 * A message that fails any of the three is silently ignored, not thrown —
 * `window` is a shared bus and other scripts on the page routinely post
 * messages that have nothing to do with this picker.
 *
 * MESSAGE SHAPE (this file's own small protocol, not carrier-defined): once a
 * message passes the origin/source gate, only an object shaped exactly like
 *   { source: 'woodev-pickup-embedded', type: 'select', point: { ... } }
 * is recognised — `source`/`type` are OUR envelope, chosen so a carrier embed
 * only has to know it must `postMessage` that shape to the parent, and so this
 * file can tell "not for us" apart from "for us, but malformed" (see below).
 * Anything else arriving from the trusted origin/source (no envelope, wrong
 * `type`, or simply unrelated traffic the carrier's own page happens to emit)
 * is likewise ignored without throwing — the origin check proves WHO sent it,
 * not that every message they ever send is meant for this picker.
 *
 * NORMALIZATION, NOT NORMALIZATION-OR-DIE: once a message IS recognised as our
 * envelope, `point` is run through {@see normalizePoint}, which mirrors
 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array()}'s rules
 * field-for-field (required `id`/`name`/`lat`/`lng`/`address`/`type.code`/
 * `type.label`, `lat` in [-90,90], `lng` in [-180,180]; see that function's own
 * docblock for the full optional-field list). Failing normalization here emits
 * `error`, NEVER a malformed `select` — a provider that forwarded whatever the
 * carrier sent verbatim would let a broken/malicious carrier page write
 * garbage straight into the order.
 *
 * `selectable` IS DELIBERATELY OMITTED from every point this file emits: it is
 * a server-computed constraint verdict ({@see Constraint_Checker}) that only
 * exists for points that passed through `woodev/v1`
 * ({@see \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller}) — a point
 * picked inside a carrier's own embed never made that round trip, so there is
 * no verdict to attach, and inventing one client-side here would duplicate
 * (and could disagree with) the real rules. The AUTHORITY for this path is
 * `Pickup_Handler::handle_checkout_process()`
 * (`woodev/shipping-method/pickup/class-pickup-handler.php`): on
 * `woocommerce_checkout_process` it re-fetches the chosen point id's details
 * server-side via `Point_Source::fetch_details()` and re-runs
 * `Constraint_Checker` against the live cart weight and chosen payment method,
 * BLOCKING the order there if the point turns out unselectable — regardless of
 * what (if anything) this provider showed the customer. This mirrors the A2
 * lesson: a client-side gate must never be the only gate, and here there isn't
 * even a client-side one.
 *
 * `icons` IS ALSO DELIBERATELY OMITTED, for a narrower reason than
 * `selectable`: {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array()}'s
 * `icons` field only ever feeds map PIN rendering — `map-provider-yandex.js`'s
 * own `_buildProperties()` is its only reader anywhere in this codebase
 * (neither `pickup-panels.js`'s confirmation card nor `pickup-mount.js` ever
 * reads `point.icons`). This file draws no map of its own (see the top of
 * this docblock), so there is no pin to skin and nothing would ever read the
 * field if it were added — carrying it through would be dead weight, not
 * parity.
 *
 * NO HTML IS EVER BUILT FROM A NORMALIZED POINT HERE. Unlike the `woodev/v1`
 * REST path — where every string is `esc_html`'d server-side exactly once,
 * see {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::to_browser_array()}
 * — a point normalized in THIS file never passed through the server at all, so
 * NONE of its strings are pre-escaped. This file only ever hands the object to
 * `emit( 'select', point )`; `pickup-mount.js` writes each field into a form
 * FIELD VALUE (`.value =`, never `.innerHTML =`), which is safe regardless of
 * content. If a future caller ever renders one of these strings as markup
 * instead, it MUST escape it first — this file provides no such guarantee.
 *
 * CALLBACK-STYLE WIDGETS: some carrier widget SDKs never use `postMessage` at
 * all — they run same-origin (a first-party `<script>` the carrier ships,
 * not an iframe) and expect the host page to expose a plain JS callback. This
 * file exposes exactly one, `window.WoodevPickupEmbedded.select( payload )`,
 * defined ONCE at module load (not per instance) and routed to whichever
 * instance most recently called `init()` — see {@see activeInstance}. A
 * payload arriving THIS way is not carried inside this file's own envelope
 * (there is no cross-frame message to wrap), so it is handed to
 * {@see normalizePoint} directly; the exact same rejection-emits-`error` rule
 * applies. A call that arrives with no live instance (nothing ever `init()`d,
 * or the live one was already `destroy()`d) is a harmless no-op — there is no
 * open picker left to report a selection to.
 *
 * IFRAME SANDBOX POSTURE (see {@see buildIframe}): `sandbox` is an allow-list,
 * so everything below is a deliberate grant, not a default:
 *   - `allow-scripts`   — the carrier's map/point-list widget is inevitably a
 *     script; without this the iframe would render nothing.
 *   - `allow-same-origin` — REQUIRED for the origin check above to mean
 *     anything: a sandboxed iframe with this flag OMITTED is forced to an
 *     opaque `"null"` origin regardless of its `src`, which would make
 *     `event.origin === config.expectedOrigin` permanently false (breaking the
 *     feature) or, worse, invite "just compare against `'null'`" as a
 *     "fix" — an origin every sandboxed frame on the internet shares.
 *   - `allow-forms`    — carrier point pickers commonly include an in-widget
 *     address/city search form.
 *   - `allow-popups`   — some carrier widgets open a detail view or a
 *     third-party auth popup in a new window/tab.
 * Deliberately NOT granted: `allow-top-navigation` (an embedded carrier page
 * must never be able to navigate the checkout page itself away — the classic
 * clickjacking/malicious-redirect vector this attribute exists to block),
 * `allow-modals`, `allow-downloads`, `allow-pointer-lock`,
 * `allow-popups-to-escape-sandbox`, `allow-top-navigation-by-user-activation`.
 * `referrerpolicy="no-referrer"` additionally stops the checkout page's own
 * URL (which can carry cart/session-identifying query parameters) from being
 * leaked to the carrier's server logs via the `Referer` header on the iframe's
 * own initial request.
 *
 * LOAD-FAILURE DETECTION (see {@see WoodevPickupMapProviderEmbedded#init}), spec §4.9:
 * "Map script fails to load / key rejected" must show an error state with a retry, NEVER
 * an empty rectangle — but a cross-origin `<iframe>` gives this file almost nothing to
 * observe. Two signals are wired, and BOTH are honestly incomplete:
 *   - `iframe.onerror` fires for a genuine network-level failure (DNS failure, connection
 *     refused) — but NOT for an `X-Frame-Options`/CSP framing refusal, which the browser
 *     renders as a blank/error frame while still firing `onload`, and not for a same-origin
 *     ad blocker that silently no-ops the request.
 *   - `IFRAME_LOAD_TIMEOUT_MS` (see below) catches exactly what `onerror` misses among the
 *     "never resolves" cases — a stalled DNS lookup, a carrier host that accepts the
 *     connection but never responds — by emitting `error` if neither `onload` nor
 *     `onerror` fires within the window.
 * NEITHER catches "loaded successfully, but the carrier served an error page" (a 404, a
 * framing-refusal page, a maintenance page): `onload` fires unconditionally once the
 * cross-origin document finishes loading, whatever its content or status code — the
 * browser gives this file no cross-origin visibility into that response. Closing that gap
 * would require the carrier's own page to `postMessage` a failure signal, which is outside
 * this file's control. This is a real, acknowledged gap, not an oversight.
 *
 * UMD-ish dual export (matches the sibling files in this directory):
 *   - Browser global: window.WoodevPickupMapProviders.embedded = Ctor
 *   - CommonJS:       module.exports = Ctor  (for jest)
 *
 * @file
 * @since 2.0.2
 */

( function() {
	'use strict';

	/** @type {string} envelope marker for this file's own `postMessage` protocol. */
	var MESSAGE_SOURCE = 'woodev-pickup-embedded';

	/** @type {string} the only recognised envelope `type` so far. */
	var MESSAGE_TYPE_SELECT = 'select';

	/** @type {string} CSS class on the injected `<iframe>` — see the SP-5 Task 15 styles. */
	var IFRAME_CLASS = 'woodev-pickup-embedded-frame';

	/**
	 * How long {@link WoodevPickupMapProviderEmbedded#init} waits for the injected
	 * `<iframe>` to fire `onload` (or `onerror`) before treating it as failed — see the
	 * file docblock's "LOAD-FAILURE DETECTION" section for exactly what this does and does
	 * not catch. Ten seconds: long enough that a slow-but-working carrier host on a
	 * throttled mobile connection is not falsely flagged, short enough that a customer is
	 * not left staring at a blank modal for long before spec §4.9's error state appears.
	 *
	 * @type {number}
	 */
	var IFRAME_LOAD_TIMEOUT_MS = 10000;

	/**
	 * The most recently `init()`d, not-yet-`destroy()`ed instance — the target
	 * of the module-level `window.WoodevPickupEmbedded.select()` hook. Only one
	 * picker session is ever open at a time in practice (see `pickup-mount.js`'s
	 * per-field session tracking), so "most recent" is an adequate, simple
	 * routing rule rather than a real multi-instance registry.
	 *
	 * @type {Object|null}
	 */
	var activeInstance = null;

	// -------------------------------------------------------------------------
	// Small helpers
	// -------------------------------------------------------------------------

	/**
	 * Reads an i18n string off a config — empty string when absent/blank, NEVER
	 * a JS-side hardcoded default. Mirrors `pickup-mount.js`'s own `text()`
	 * exactly (see that file's docblock for why a missing key must render
	 * blank rather than a same-reading fallback string).
	 *
	 * @param {Object} config
	 * @param {string} key
	 * @returns {string}
	 */
	function text( config, key ) {
		var i18n = ( config && config.i18n ) || {};

		return 'string' === typeof i18n[ key ] && i18n[ key ].length > 0 ? i18n[ key ] : '';
	}

	/**
	 * Whether `value` is one of PHP's "scalar" types for the purposes of
	 * mirroring {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array()}'s
	 * `is_scalar()` guard — string, number or boolean.
	 *
	 * @param {*} value
	 * @returns {boolean}
	 */
	function isScalar( value ) {
		var t = typeof value;

		return 'string' === t || 'number' === t || 'boolean' === t;
	}

	/**
	 * Whether `value` is numeric in the sense PHP's `is_numeric()` would accept
	 * it for a coordinate — a finite number, or a non-empty string that parses
	 * to one. Mirrors the guard `Pickup_Point::from_array()` applies to `lat`/
	 * `lng` before casting to float.
	 *
	 * @param {*} value
	 * @returns {boolean}
	 */
	function isNumeric( value ) {
		if ( 'number' === typeof value ) {
			return isFinite( value );
		}

		if ( 'string' === typeof value && value.trim().length > 0 ) {
			return isFinite( Number( value ) );
		}

		return false;
	}

	/**
	 * Reads an optional string field off a raw payload — empty string when
	 * absent/null, matching `Pickup_Point::from_array()`'s own
	 * `isset(...) ? (string) ... : ''` treatment of every optional field.
	 *
	 * @param {Object} payload
	 * @param {string} key
	 * @returns {string}
	 */
	function optionalString( payload, key ) {
		var value = payload[ key ];

		return undefined !== value && null !== value ? String( value ) : '';
	}

	/**
	 * Reads an optional integer field off a raw payload — `null` when
	 * absent/null, matching `Pickup_Point::from_array()`'s own
	 * `isset(...) ? (int) ... : null` treatment of `max_weight`. Falls back to
	 * `0` for a present-but-unparseable value (`parseInt` returning `NaN`),
	 * mirroring PHP's lenient `(int)` cast of a non-numeric string (`(int)
	 * "abc" === 0`) rather than letting `NaN` leak into `max_weight` and print
	 * as the literal text "NaN" wherever `pickup-panels.js`'s
	 * `formatWeightKg()` renders it.
	 *
	 * @param {Object} payload
	 * @param {string} key
	 * @returns {number|null}
	 */
	function optionalInt( payload, key ) {
		var value = payload[ key ];

		if ( undefined === value || null === value ) {
			return null;
		}

		var parsed = parseInt( value, 10 );

		return isNaN( parsed ) ? 0 : parsed;
	}

	/**
	 * Filters a raw carrier list down to non-empty strings — mirrors
	 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::sanitize_string_list()}
	 * exactly: a non-string element (a nested object/array a carrier adapter
	 * forgot to flatten, a stray number or boolean) is DROPPED, not coerced —
	 * unlike a naive `.map( String )`, which would turn a nested array into the
	 * literal text "" and an object into "[object Object]", either of which
	 * could reach the customer as a payment method or photo URL (the JS
	 * analogue of PHP's `(string)` "Array" bug, issue #154; see that PHP
	 * method's docblock for the full rationale, including why this replaced
	 * this file's own former `payment_methods`/`photos` coercion pattern). A
	 * whitespace-only entry is treated as absent and dropped via `trim()`; the
	 * string `'0'` is a legitimate label and is deliberately kept.
	 *
	 * @param {*} value Raw `payment_methods`/`photos`/`services` payload value.
	 * @returns {string[]}
	 */
	function sanitizeStringList( value ) {
		if ( ! Array.isArray( value ) ) {
			return [];
		}

		var result = [];
		var i;

		for ( i = 0; i < value.length; i++ ) {
			if ( 'string' === typeof value[ i ] && '' !== value[ i ].trim() ) {
				result.push( value[ i ] );
			}
		}

		return result;
	}

	/**
	 * Normalizes a raw carrier payload into the framework's point shape, or
	 * returns `null` when it cannot be — mirroring
	 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array()}
	 * field-for-field (see that class for the authoritative rules; this is a
	 * deliberate re-implementation, not a call into PHP, since the carrier's
	 * embed talks to the BROWSER directly and never touches `woodev/v1`).
	 *
	 * Required: `id`/`name`/`lat`/`lng`/`address`/`type.code`/`type.label`.
	 * Optional, default `''` via {@see optionalString}: `short_address`/
	 * `locality`/`postal_code`/`phone`/`instruction`/`work_time`/
	 * `point_short_name`. Optional, default `[]`, filtered through
	 * {@see sanitizeStringList} exactly like `Pickup_Point::sanitize_string_list()`
	 * — non-string and whitespace-only elements DROPPED, not coerced:
	 * `payment_methods`/`photos`/`services`. Optional, default `null`:
	 * `accepts_cod` (`Boolean()`-cast) and `max_weight` (via
	 * {@see optionalInt}, PHP `(int)`-cast parity).
	 *
	 * `selectable` is never added — see the file docblock's "AUTHORITY for
	 * this path" section for why the server re-check at
	 * `Pickup_Handler::handle_checkout_process()` is what actually gates this.
	 * `icons` is never added either — see the file docblock's "`icons` IS ALSO
	 * DELIBERATELY OMITTED" paragraph.
	 *
	 * @param {*} payload
	 * @returns {Object|null}
	 */
	function normalizePoint( payload ) {
		if ( ! payload || 'object' !== typeof payload ) {
			return null;
		}

		var required = [ 'id', 'name', 'lat', 'lng', 'address' ];
		var i, key, value;

		for ( i = 0; i < required.length; i++ ) {
			key = required[ i ];
			value = payload[ key ];

			if ( undefined === value || null === value || '' === value || ! isScalar( value ) ) {
				return null;
			}
		}

		var type = payload.type;

		if ( ! type || 'object' !== typeof type
			|| undefined === type.code || null === type.code
			|| undefined === type.label || null === type.label
		) {
			return null;
		}

		if ( ! isNumeric( payload.lat ) || ! isNumeric( payload.lng ) ) {
			return null;
		}

		var lat = parseFloat( payload.lat );
		var lng = parseFloat( payload.lng );

		if ( lat < -90 || lat > 90 || lng < -180 || lng > 180 ) {
			return null;
		}

		return {
			id: String( payload.id ),
			name: String( payload.name ),
			lat: lat,
			lng: lng,
			address: String( payload.address ),
			type: {
				code: String( type.code ),
				label: String( type.label ),
			},
			short_address: optionalString( payload, 'short_address' ),
			locality: optionalString( payload, 'locality' ),
			postal_code: optionalString( payload, 'postal_code' ),
			phone: optionalString( payload, 'phone' ),
			instruction: optionalString( payload, 'instruction' ),
			work_time: optionalString( payload, 'work_time' ),
			point_short_name: optionalString( payload, 'point_short_name' ),
			payment_methods: sanitizeStringList( payload.payment_methods ),
			photos: sanitizeStringList( payload.photos ),
			services: sanitizeStringList( payload.services ),
			accepts_cod: undefined !== payload.accepts_cod && null !== payload.accepts_cod
				? Boolean( payload.accepts_cod )
				: null,
			max_weight: optionalInt( payload, 'max_weight' ),
		};
	}

	/**
	 * Whether `url` is a non-empty, absolute `https:` URL — the only shape
	 * `config.embedUrl` may take before this file will inject an iframe for
	 * it. Rejects a relative path, `http:`, `javascript:`, `data:`, etc.
	 *
	 * @param {*} url
	 * @returns {boolean}
	 */
	function isAbsoluteHttpsUrl( url ) {
		if ( 'string' !== typeof url || '' === url ) {
			return false;
		}

		try {
			return 'https:' === new URL( url ).protocol;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * Builds the sandboxed `<iframe>` — see the file docblock's "IFRAME
	 * SANDBOX POSTURE" section for why each `sandbox` token is present, and why
	 * `allow-top-navigation` and friends are deliberately absent. `onload`/
	 * `onerror` are wired by the caller ({@see WoodevPickupMapProviderEmbedded#init}),
	 * not here — they need `self`/`_emit`, which this free function has no access to.
	 *
	 * @param {string} embedUrl
	 * @param {Object} config
	 * @returns {HTMLIFrameElement}
	 */
	function buildIframe( embedUrl, config ) {
		var iframe = document.createElement( 'iframe' );

		iframe.src = embedUrl;
		iframe.className = IFRAME_CLASS;
		iframe.setAttribute( 'sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups' );
		iframe.setAttribute( 'referrerpolicy', 'no-referrer' );
		iframe.setAttribute( 'title', text( config, 'modalTitle' ) );
		iframe.style.width = '100%';
		iframe.style.height = '100%';
		iframe.style.border = '0';

		return iframe;
	}

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * @constructor
	 */
	function WoodevPickupMapProviderEmbedded() {
		/** @type {Object.<string, Function[]>} */
		this._handlers = { select: [], error: [] };

		/** @type {HTMLElement|null} the container passed to init(). */
		this._container = null;

		/** @type {HTMLIFrameElement|null} */
		this._iframe = null;

		/** @type {Function|null} bound `message` listener, or null once destroyed/never attached. */
		this._onMessage = null;

		/**
		 * The config `init()` was called with — `null` until `init()` has actually built an
		 * iframe (a failed `init()`, e.g. an invalid `embedUrl`, leaves this `null`; see
		 * {@see WoodevPickupMapProviderEmbedded#_handleExternalSelect}, which must never
		 * silently fall back to an empty object once THIS instance can legitimately be live).
		 *
		 * @type {Object|null}
		 */
		this._config = null;

		/**
		 * The pending `window.setTimeout()` handle for the load-failure timeout (see
		 * {@see IFRAME_LOAD_TIMEOUT_MS}), or `null` once cleared — by `onload`, by `onerror`,
		 * by the timeout firing itself, or by `destroy()` (so a closed modal never fires a
		 * late `error` into a dead session).
		 *
		 * @type {number|null}
		 */
		this._loadTimer = null;

		/** @type {boolean} guards destroy() against a second call. */
		this._destroyed = false;
	}

	/**
	 * Registers a listener for `'select'` or `'error'`. Multiple listeners per
	 * event are supported (all are called, in registration order) though the
	 * framework only ever registers one of each — see `pickup-mount.js`'s
	 * `start()`.
	 *
	 * @param {string}   event `'select'` or `'error'`.
	 * @param {Function} cb
	 * @returns {void}
	 */
	WoodevPickupMapProviderEmbedded.prototype.on = function( event, cb ) {
		if ( this._handlers[ event ] && 'function' === typeof cb ) {
			this._handlers[ event ].push( cb );
		}
	};

	/**
	 * Calls every listener registered for `event` with `payload`.
	 *
	 * @param {string} event
	 * @param {*}      payload
	 * @returns {void}
	 */
	WoodevPickupMapProviderEmbedded.prototype._emit = function( event, payload ) {
		( this._handlers[ event ] || [] ).forEach( function( cb ) {
			cb( payload );
		} );
	};

	/**
	 * Runs a raw payload — from either the `postMessage` envelope or the
	 * `window.WoodevPickupEmbedded.select()` callback hook — through
	 * {@see normalizePoint} and emits `select` or `error` accordingly. Shared
	 * by both entry points so the "cannot normalize -> error, never a
	 * malformed select" rule is enforced in exactly one place.
	 *
	 * @param {*}      rawPoint
	 * @param {Object} config
	 * @returns {void}
	 */
	WoodevPickupMapProviderEmbedded.prototype._handlePayload = function( rawPoint, config ) {
		var point = normalizePoint( rawPoint );

		if ( null === point ) {
			this._emit( 'error', {
				code: 'woodev_pickup_embed_invalid_payload',
				message: text( config, 'error' ),
			} );

			return;
		}

		this._emit( 'select', point );
	};

	/**
	 * The `window` `'message'` handler for this instance — see the file
	 * docblock's "SECURITY BOUNDARY" section for what each check closes.
	 * Bound (via `.bind(this)`-equivalent closure) once per instance in
	 * {@link WoodevPickupMapProviderEmbedded#init} so it can be removed by
	 * reference in {@link WoodevPickupMapProviderEmbedded#destroy}.
	 *
	 * @param {string} expectedOrigin
	 * @param {Object} config
	 * @returns {Function}
	 */
	WoodevPickupMapProviderEmbedded.prototype._buildMessageHandler = function( expectedOrigin, config ) {
		var self = this;

		return function( event ) {
			// Check 3: an empty/missing expectedOrigin trusts NOTHING, ever.
			if ( ! expectedOrigin ) {
				return;
			}

			// Check 1: STRICT equality — never a prefix/substring test. See the
			// file docblock for the exact spoofing shape this closes.
			if ( event.origin !== expectedOrigin ) {
				return;
			}

			// Check 2: the message must come from THIS instance's own iframe, not
			// merely from some other window that shares the trusted origin.
			if ( ! self._iframe || event.source !== self._iframe.contentWindow ) {
				return;
			}

			var data = event.data;

			// Not our envelope (or not meant for us) — ignore silently; `window`
			// is a shared bus and unrelated same-origin traffic is routine.
			if ( ! data || 'object' !== typeof data
				|| MESSAGE_SOURCE !== data.source
				|| MESSAGE_TYPE_SELECT !== data.type
			) {
				return;
			}

			self._handlePayload( data.point, config );
		};
	};

	/**
	 * Injects the carrier's `<iframe>` into `container` and starts listening
	 * for its selection signal. A missing/invalid `config.embedUrl` emits
	 * `error` and injects nothing — see {@see isAbsoluteHttpsUrl}.
	 *
	 * @param {HTMLElement} container
	 * @param {Object}      config     `{ embedUrl, expectedOrigin, strategy, i18n, locality }` —
	 *                                 only `embedUrl`/`expectedOrigin`/`i18n` are used; `strategy`
	 *                                 and `locality` describe the plugin's `Point_Source` and have
	 *                                 no meaning for a provider that never calls `woodev/v1`.
	 * @param {Object}      dataSource unused — see the file docblock.
	 * @returns {void}
	 */
	// `dataSource` (3rd arg, per the fixed provider contract) is intentionally unused — see the file docblock.
	//
	// `activeInstance` IS ONLY EVER SET BELOW, AFTER THE IFRAME IS ACTUALLY BUILT: an
	// `init()` that returns early (no `container`, an invalid `embedUrl`) never rendered
	// anything, and must not become the target of
	// `window.WoodevPickupEmbedded.select()` — a call arriving for a picker that was never
	// live has no live session to report a selection to (see
	// {@see WoodevPickupMapProviderEmbedded#_handleExternalSelect}).
	WoodevPickupMapProviderEmbedded.prototype.init = function( container, config, dataSource ) {
		if ( ! container ) {
			return;
		}

		this._container = container;
		container.innerHTML = ''; // safe: clears leftover markup from a prior failed init; inserts nothing untrusted.

		var cfg = config || {};
		var embedUrl = cfg.embedUrl;

		if ( ! isAbsoluteHttpsUrl( embedUrl ) ) {
			this._emit( 'error', {
				code: 'woodev_pickup_embed_invalid_url',
				message: text( cfg, 'error' ),
			} );

			return;
		}

		this._config = cfg;
		this._iframe = buildIframe( embedUrl, cfg );

		var self = this;

		/**
		 * Clears the pending load-failure timeout, when one is still pending. Shared by
		 * `onload`, `onerror` and the timeout callback itself so each only ever fires once.
		 *
		 * @returns {void}
		 */
		function clearLoadTimer() {
			if ( null !== self._loadTimer ) {
				window.clearTimeout( self._loadTimer );
				self._loadTimer = null;
			}
		}

		// See the file docblock's "LOAD-FAILURE DETECTION" section for exactly what
		// `onerror` and this timeout each catch, and what neither can.
		this._loadTimer = window.setTimeout( function() {
			self._loadTimer = null;

			self._emit( 'error', {
				code: 'woodev_pickup_embed_load_failed',
				message: text( cfg, 'error' ),
			} );
		}, IFRAME_LOAD_TIMEOUT_MS );

		this._iframe.onload = clearLoadTimer;

		this._iframe.onerror = function() {
			clearLoadTimer();

			// A closed/destroyed session must never emit into a dead session — see
			// destroy()'s own docblock. Removing the iframe from the DOM does not
			// reliably suppress an `error` event already queued by the browser.
			if ( self._destroyed ) {
				return;
			}

			self._emit( 'error', {
				code: 'woodev_pickup_embed_load_failed',
				message: text( cfg, 'error' ),
			} );
		};

		container.appendChild( this._iframe );

		activeInstance = this;

		this._onMessage = this._buildMessageHandler( String( cfg.expectedOrigin || '' ), cfg );
		window.addEventListener( 'message', this._onMessage );
	};

	/**
	 * Routes a payload delivered through the callback-style hook (see the file
	 * docblock) to THIS instance's normalization/emit path. Only ever called by
	 * the module-level `window.WoodevPickupEmbedded.select()` dispatcher.
	 *
	 * @param {*} payload
	 * @returns {void}
	 */
	WoodevPickupMapProviderEmbedded.prototype._handleExternalSelect = function( payload ) {
		if ( this._destroyed ) {
			return;
		}

		this._handlePayload( payload, this._config || {} );
	};

	/**
	 * Removes the `message` listener, clears the pending load-failure timeout
	 * (see {@see IFRAME_LOAD_TIMEOUT_MS}) — so a modal closed before the iframe
	 * ever settles never fires a late `error` into a dead session — clears the
	 * callback-hook routing (when it still points at this instance — never a
	 * newer one, see {@see activeInstance}), and empties the container.
	 * Idempotent, and safe to call even when `init()` never ran or emitted
	 * `error` before building an iframe.
	 *
	 * @returns {void}
	 */
	WoodevPickupMapProviderEmbedded.prototype.destroy = function() {
		if ( this._destroyed ) {
			return;
		}

		this._destroyed = true;

		if ( null !== this._loadTimer ) {
			window.clearTimeout( this._loadTimer );
			this._loadTimer = null;
		}

		if ( this._onMessage ) {
			window.removeEventListener( 'message', this._onMessage );
			this._onMessage = null;
		}

		if ( activeInstance === this ) {
			activeInstance = null;
		}

		if ( this._container ) {
			this._container.innerHTML = ''; // safe: clears children only, inserts nothing untrusted.
		}

		this._container = null;
		this._iframe = null;
		this._config = null;
	};

	// -------------------------------------------------------------------------
	// Callback-style widget hook — module-level, routed to activeInstance.
	// -------------------------------------------------------------------------

	if ( typeof window !== 'undefined' ) {
		window.WoodevPickupEmbedded = {
			/**
			 * Entry point for a carrier widget script that talks to the host page
			 * directly instead of over `postMessage` — see the file docblock's
			 * "CALLBACK-STYLE WIDGETS" section.
			 *
			 * @param {*} payload raw carrier point payload.
			 * @returns {void}
			 */
			select: function( payload ) {
				if ( activeInstance ) {
					activeInstance._handleExternalSelect( payload );
				}
			},
		};
	}

	// -------------------------------------------------------------------------
	// UMD-ish dual export
	// -------------------------------------------------------------------------

	// Browser global
	if ( typeof window !== 'undefined' ) {
		window.WoodevPickupMapProviders = window.WoodevPickupMapProviders || {};
		window.WoodevPickupMapProviders.embedded = WoodevPickupMapProviderEmbedded;
	}

	// CommonJS (jest)
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = WoodevPickupMapProviderEmbedded;
	}

}() );
