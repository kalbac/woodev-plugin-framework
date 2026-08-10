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
 * is recognised as OUR envelope — `source`/`type` are chosen so a carrier
 * embed only has to know it must `postMessage` that shape to the parent, and
 * so this file can tell "not for us" apart from "for us, but malformed" (see
 * below). Adapters (next section) NEVER see a message shaped like this — it
 * is handled here, first, exactly as before #251.
 *
 * ADAPTER HOOKS (issue #251) — `config.initAdapter`/`config.selectAdapter`,
 * each an OPTIONAL dotted global JS path (e.g. `'WoodevPochtaEmbed.toPoint'`),
 * never a callable — the value crosses into the browser as JSON, and is
 * resolved by walking `window` on `.` (never `eval`, never `new Function`); a
 * path that does not resolve to a function is treated as absent. They exist
 * so a plugin can point `embedUrl` straight at a carrier's OWN widget — no
 * bridge page needed — and translate its native protocol in the browser
 * instead of requiring it to speak this file's envelope. Only reached for a
 * message that passed the origin+source gate AND did not match this file's
 * own envelope above:
 *   1. `initAdapter( data )` runs first. A non-`null`/`undefined` return is
 *      posted back into the SAME iframe this message came from —
 *      `iframe.contentWindow.postMessage( payload, config.expectedOrigin )`,
 *      the expected origin as `targetOrigin`, NEVER `"*"` — and handling
 *      stops there. Both the ADAPTER CALL and the `postMessage()` CALL are
 *      inside the same guarded region, and a throw from EITHER is SWALLOWED
 *      (issue #251 follow-up, Codex review: `postMessage()` itself throws
 *      `DataCloneError` for a return value the structured-clone algorithm
 *      cannot handle — a function, a `Symbol`, a cyclic object — and that
 *      fault is the adapter's, not the framework's, exactly like a throw
 *      from `initAdapter()` itself; the message bus is shared with whatever
 *      else the carrier's page posts, so neither may break the picker).
 *   2. `selectAdapter( data )` runs when `initAdapter` did not claim the
 *      message. A non-`null`/`undefined` return goes through
 *      {@see normalizePoint} and then the same success/failure emit path as
 *      the framework's own envelope. A throw here is NOT swallowed — it
 *      emits `error`, because the message already proved it came from this
 *      instance's own trusted iframe, so a translation failure is a real,
 *      reportable fault, not routine cross-talk. EXCEPTION: a throw is
 *      swallowed too when a synchronous callback-style emission already
 *      reported this same selection before the throw — see the RE-ENTRY
 *      GUARD paragraph below.
 *   3. Neither adapter is configured, or both decline (return `null`/
 *      `undefined`) — the message is ignored silently, same as any other
 *      unrecognised traffic from the trusted origin.
 * An empty/missing `config.expectedOrigin` already rejects every inbound
 * message via check 3 above (so step 1's `postMessage` is unreachable in
 * that case regardless); the outbound post in step 1 ALSO checks it
 * explicitly before calling `postMessage`, as a second, independent guard
 * against ever computing a `targetOrigin` that is not a real trusted origin.
 * ADAPTERS NEVER WIDEN THE TRUST BOUNDARY: they run strictly AFTER the
 * origin+source gate above and only ever translate a message this file has
 * already proven came from ITS OWN iframe at the expected origin — they are
 * not a second way for an untrusted sender to reach the picker.
 *
 * RE-ENTRY GUARD — AT MOST ONE EMISSION PER INBOUND MESSAGE (issue #251
 * follow-up, Codex review): `selectAdapter` is legal in TWO styles — it may
 * report a selection by calling the callback-style hook
 * (`window.WoodevPickupEmbedded.select()`, see "CALLBACK-STYLE WIDGETS"
 * below) and return `null`, OR it may simply RETURN the point directly.
 * Nothing stops a careless (or defensive-but-redundant) adapter from doing
 * BOTH for the same inbound message — calling the callback synchronously
 * AND also returning a non-null point. Without a guard, both paths reach
 * {@see WoodevPickupMapProviderEmbedded#_handlePayload}, so one inbound
 * carrier message would produce TWO `select`/`error` emissions, and
 * `pickup-mount.js` would start two confirmation round trips for one
 * customer click. The rule: once an emission has already happened
 * SYNCHRONOUSLY during a `selectAdapter` call (via the callback route), the
 * adapter's own return value is ignored, not processed a second time — see
 * {@see WoodevPickupMapProviderEmbedded#_reentrantEmit}'s own docblock
 * (constructor) for the exact mechanics. This preserves BOTH legal adapter
 * styles unchanged (callback-then-null: one emission from the callback;
 * return-only: one emission from the return value) and collapses the
 * pathological both-at-once case to exactly one emission too.
 *
 * NORMALIZATION, NOT NORMALIZATION-OR-DIE: once a message IS recognised as our
 * envelope, or an adapter has translated one, `point`/the adapter's return
 * value is run through {@see normalizePoint}. It is NOT a field-for-field
 * mirror of {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array()}
 * — only its OPTIONAL-field handling (defaults, list filtering, int/bool
 * casts) is kept deliberately aligned with that PHP method, field by field.
 * Its REQUIRED-field set is this file's OWN, currently `id`/`name`/`address`/
 * `type.code`/`type.label`; `lat`/`lng` are OPTIONAL-BUT-VALIDATED (issue
 * #251 — a real carrier embed, measured, sends neither) — see
 * {@see normalizePoint}'s own docblock for the exact rules. Failing
 * normalization here emits `error`, NEVER a malformed `select` — a provider
 * that forwarded whatever the carrier sent verbatim would let a broken/
 * malicious carrier page write garbage straight into the order.
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
	 * A plain decimal numeric-string literal: an optional sign, digits (with an
	 * optional fractional part, either side of the `.` allowed to be empty as
	 * long as at least one digit exists somewhere), and an optional exponent.
	 * Deliberately narrower than JS's own `Number()`/`parseFloat()` coercion —
	 * see {@see isNumeric}'s docblock for why.
	 *
	 * @type {RegExp}
	 */
	var DECIMAL_NUMBER_RE = /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/;

	/**
	 * Whether `value` is numeric in the sense PHP's `is_numeric()` would accept
	 * it for a coordinate — a finite number, or a non-empty string that is a
	 * plain DECIMAL numeric literal (optional sign, digits, optional fractional
	 * part, optional exponent — see {@see DECIMAL_NUMBER_RE}). Mirrors the guard
	 * `Pickup_Point::from_array()` applies to `lat`/`lng` before casting to
	 * float, which itself mirrors PHP's `is_numeric()` (PHP 7+): notably, that
	 * means a hex/octal/binary literal string (`'0x20'`, `'0b11'`, `'0o17'`) is
	 * REJECTED here, not accepted, even though it looks "numeric" — this is the
	 * whole point, not an oversight.
	 *
	 * Issue #251 follow-up (Codex review): this used to read
	 * `isFinite( Number( value ) )`, which — unlike PHP's `is_numeric()` —
	 * ACCEPTS a hex string (`Number( '0x20' ) === 32`), while
	 * {@see normalizePoint}'s conversion step uses `parseFloat()`, which reads
	 * the very same string as `0` (`parseFloat` stops at the first character it
	 * cannot parse as a decimal digit, and `'0x20'` starts with a valid `'0'`
	 * digit followed by non-digit `'x'`). The two disagreed silently: a hex
	 * coordinate string PASSED validation here and was then silently coerced to
	 * `0` — null island — by `parseFloat()`, exactly the "junk is coerced, not
	 * rejected" outcome `normalizePoint()`'s own docblock forbids. This was a
	 * KNOWN, deliberately accepted divergence from PHP's `is_numeric()` when
	 * #201 landed — `lat`/`lng` were still REQUIRED then, so a hex string
	 * still produced a number and the divergence was provably unreachable.
	 * Making them OPTIONAL (issue #251) is what turned it into a live defect:
	 * an adapter now controls whether `lat`/`lng` are present at all, and a
	 * carrier/adapter bug that hands through a hex-looking id fragment as a
	 * coordinate would previously have been silently accepted as `(0, 0)`.
	 * Validation and conversion must agree on what "numeric" means; this
	 * regex-based check is that single source of truth — `parseFloat()` in
	 * `normalizePoint()` is only ever reached for a value this function has
	 * already accepted, and never disagrees with it, because every string this
	 * regex matches parses via `parseFloat()` to the exact same value.
	 *
	 * Exponent notation (`'1e2'`) IS accepted: it is a legitimate decimal
	 * number, unlike a hex/octal/binary literal, which is a different RADIX
	 * entirely, not an alternate way to write the same decimal value.
	 *
	 * @param {*} value
	 * @returns {boolean}
	 */
	function isNumeric( value ) {
		if ( 'number' === typeof value ) {
			return isFinite( value );
		}

		if ( 'string' === typeof value ) {
			var trimmed = value.trim();

			return trimmed.length > 0 && DECIMAL_NUMBER_RE.test( trimmed );
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
	 * Normalizes a raw carrier payload into this provider's point shape, or
	 * returns `null` when it cannot be. This is a deliberate re-implementation,
	 * not a call into PHP, since the carrier's embed talks to the BROWSER
	 * directly and never touches `woodev/v1`.
	 *
	 * NOT a field-for-field mirror of
	 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array()} — only
	 * the OPTIONAL-field handling below is kept aligned with that PHP method,
	 * field by field. The REQUIRED-field set is this file's own and diverges on
	 * purpose:
	 *
	 * Required: `id`/`name`/`address`/`type.code`/`type.label`. `lat`/`lng` are
	 * OPTIONAL-BUT-VALIDATED (issue #251, resolved): present, both must be
	 * numeric and in range (`lat` in [-90,90], `lng` in [-180,180]) — junk is
	 * still REJECTED, never coerced; absent, BOTH must be absent (exactly one
	 * present is a half-coordinate — an adapter bug, not a partial datum — and
	 * is REJECTED); omitted from the emitted point when absent, with no `0.0`
	 * fallback, ever. `from_array()` requires `lat`/`lng` unconditionally, but
	 * for a different reason — the framework's own map (`map-provider-yandex.js`)
	 * needs coordinates to place a marker. THIS provider draws no map of ours
	 * (see the file docblock's opening paragraph), and a real carrier embed does
	 * not necessarily supply coordinates at all: Почта России's widget, measured
	 * against this seam on the rig, returns a selection payload with no `lat`/
	 * `lng` and no `name` (`{ id, mailType, pvzType, indexTo, cashOfDelivery,
	 * regionTo, cityTo, addressTo, weight, ... }`). `name` stays REQUIRED even
	 * so — Почта sends none, but a name is domain knowledge the owning plugin's
	 * adapter can build (e.g. `Почтомат №918872` from `pvzType` + `indexTo`; see
	 * the fixture's `WoodevPochtaEmbed.toPoint`), and it is what the checkout
	 * field and trigger label display. This function must not invent one.
	 *
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

		var required = [ 'id', 'name', 'address' ];
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

		// Issue #251: lat/lng are OPTIONAL-BUT-VALIDATED, not required — see this
		// function's own docblock for why (a real carrier embed, measured, sends
		// neither). Absent means BOTH must be absent; exactly one present is a
		// half-coordinate, which is an adapter bug, not a partial datum, and is
		// REJECTED rather than silently dropped or defaulted.
		var hasLat = undefined !== payload.lat && null !== payload.lat;
		var hasLng = undefined !== payload.lng && null !== payload.lng;

		if ( hasLat !== hasLng ) {
			return null;
		}

		var lat = null;
		var lng = null;

		if ( hasLat ) {
			if ( ! isNumeric( payload.lat ) || ! isNumeric( payload.lng ) ) {
				return null;
			}

			lat = parseFloat( payload.lat );
			lng = parseFloat( payload.lng );

			if ( lat < -90 || lat > 90 || lng < -180 || lng > 180 ) {
				return null;
			}
		}

		var point = {
			id: String( payload.id ),
			name: String( payload.name ),
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

		// No `0.0` fallback, ever — omitted from the emitted point entirely when
		// absent, per this function's docblock.
		if ( hasLat ) {
			point.lat = lat;
			point.lng = lng;
		}

		return point;
	}

	/**
	 * Resolves a dotted global JS path (e.g. `'WoodevPochtaEmbed.toPoint'`) to a
	 * function, by walking `window` one `.`-separated segment at a time — see
	 * the file docblock's "ADAPTER HOOKS" section (issue #251). NEVER `eval`,
	 * NEVER `new Function`: a segment that resolves to anything other than an
	 * object/function part-way through, or a final value that is not itself a
	 * function, makes the whole path resolve to `null` (treated as "no
	 * adapter configured"), not a thrown error.
	 *
	 * @param {*} path
	 * @returns {Function|null}
	 */
	function resolveAdapter( path ) {
		if ( 'string' !== typeof path || '' === path ) {
			return null;
		}

		var segments = path.split( '.' );
		var target = window;
		var i;

		for ( i = 0; i < segments.length; i++ ) {
			if ( ! target || ( 'object' !== typeof target && 'function' !== typeof target ) ) {
				return null;
			}

			target = target[ segments[ i ] ];
		}

		return 'function' === typeof target ? target : null;
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

		/**
		 * RE-ENTRY GUARD (issue #251 follow-up, Codex review): a `selectAdapter` is legally
		 * allowed to report a selection EITHER by calling the callback-style hook
		 * (`window.WoodevPickupEmbedded.select()`, routed through
		 * {@see WoodevPickupMapProviderEmbedded#_handleExternalSelect}) OR by RETURNING the
		 * point directly from the call `_buildMessageHandler()` makes into it — both are
		 * documented, legal adapter styles (see the file docblock's "CALLBACK-STYLE WIDGETS"
		 * section for the first). An adapter that does BOTH for the same inbound carrier
		 * message must still produce exactly ONE `select`/`error` emission for it, not two.
		 * `_buildMessageHandler()` resets this to `false` immediately before invoking
		 * `selectAdapter`; `_handleExternalSelect()` sets it to `true` if it runs
		 * SYNCHRONOUSLY during that call. When it reads `true` afterwards, the adapter's
		 * return value describes a selection ALREADY emitted through the callback route, and
		 * is ignored — see `_buildMessageHandler()`'s own comment at the check.
		 *
		 * @type {boolean}
		 */
		this._reentrantEmit = false;

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

			// Step 1 (issue #251 numbering, see the file docblock's "ADAPTER
			// HOOKS" section): this file's own envelope, handled exactly as
			// before #251 — adapters never see a message shaped like this.
			if ( data && 'object' === typeof data
				&& MESSAGE_SOURCE === data.source
				&& MESSAGE_TYPE_SELECT === data.type
			) {
				self._handlePayload( data.point, config );

				return;
			}

			// Step 2: initAdapter — answers the carrier's own handshake. A throw
			// is swallowed: `window` is a shared bus, and a plugin-supplied
			// adapter must never be able to break the picker for unrelated
			// traffic it does not recognise either. Issue #251 follow-up (Codex
			// review): the `postMessage()` call below is INSIDE this same try —
			// it used to sit after the catch, unguarded, so an adapter return
			// value the structured-clone algorithm cannot handle (a function, a
			// `Symbol`, a cyclic object) threw `DataCloneError` straight out of
			// this handler and into the page, defeating the whole "an adapter
			// fault must not break the picker" rule this catch exists to
			// enforce. `postMessage()` throwing is swallowed exactly like
			// `initAdapter()` itself throwing — both are the adapter's fault,
			// not the framework's, and neither may propagate.
			var initAdapter = resolveAdapter( config.initAdapter );

			if ( initAdapter ) {
				var initClaimed = false;

				try {
					var initPayload = initAdapter( data );

					if ( undefined !== initPayload && null !== initPayload ) {
						initClaimed = true;

						// Belt-and-suspenders alongside check 3 above: never compute a
						// `postMessage` call without a real, non-empty targetOrigin.
						if ( config.expectedOrigin ) {
							self._iframe.contentWindow.postMessage( initPayload, config.expectedOrigin );
						}
					}
				} catch ( e ) {
					return;
				}

				if ( initClaimed ) {
					return;
				}
			}

			// Step 3: selectAdapter — translates the carrier's own selection
			// message. Unlike initAdapter, a throw here is NOT swallowed: this
			// message already passed the origin+source gate, so a translation
			// failure is a real, reportable fault.
			var selectAdapter = resolveAdapter( config.selectAdapter );

			if ( selectAdapter ) {
				var rawPoint;

				// RE-ENTRY GUARD (issue #251 follow-up, Codex review) — see `_reentrantEmit`'s
				// own docblock in the constructor. Reset immediately before the call: a stale
				// `true` left over from some earlier, unrelated `_handleExternalSelect()` call
				// (a genuine callback-style widget with no configured adapter at all, say) must
				// never be mistaken for THIS call's own re-entry.
				self._reentrantEmit = false;

				try {
					rawPoint = selectAdapter( data );
				} catch ( e ) {
					// A synchronous callback-style emission that happened before the throw
					// already reported this selection (or its own failure) — do not ALSO
					// emit `error` for the same inbound message.
					if ( ! self._reentrantEmit ) {
						self._emit( 'error', {
							code: 'woodev_pickup_embed_adapter_error',
							message: text( config, 'error' ),
						} );
					}

					return;
				}

				// The adapter already reported this exact selection synchronously via
				// `window.WoodevPickupEmbedded.select()` — the return value below describes
				// the SAME selection, not a second one, and must be ignored so one inbound
				// carrier message never produces two `select`/`error` emissions.
				if ( self._reentrantEmit ) {
					return;
				}

				if ( undefined !== rawPoint && null !== rawPoint ) {
					self._handlePayload( rawPoint, config );

					return;
				}
			}

			// Neither our envelope nor an adapter claimed this message — ignore
			// silently, same as any other unrelated same-origin traffic.
		};
	};

	/**
	 * Injects the carrier's `<iframe>` into `container` and starts listening
	 * for its selection signal. A missing/invalid `config.embedUrl` emits
	 * `error` and injects nothing — see {@see isAbsoluteHttpsUrl}.
	 *
	 * @param {HTMLElement} container
	 * @param {Object}      config     `{ embedUrl, expectedOrigin, initAdapter, selectAdapter,
	 *                                 strategy, i18n, locality }` — `strategy` and `locality`
	 *                                 describe the plugin's `Point_Source` and have no meaning
	 *                                 for a provider that never calls `woodev/v1`. `initAdapter`/
	 *                                 `selectAdapter` (issue #251) are optional dotted global JS
	 *                                 paths; see the file docblock's "ADAPTER HOOKS" section and
	 *                                 {@see WoodevPickupMapProviderEmbedded#_buildMessageHandler}.
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

		// See `_reentrantEmit`'s own docblock (constructor): marks that THIS route already
		// handled a selection, so a `selectAdapter` invocation still in progress on the call
		// stack above this one (see `_buildMessageHandler()`) knows to ignore its own return
		// value rather than emit the same selection a second time. Set unconditionally, not
		// only while a `selectAdapter` call is in flight — harmless when it is not (nothing
		// reads it until the next `selectAdapter` invocation resets it first).
		this._reentrantEmit = true;

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
