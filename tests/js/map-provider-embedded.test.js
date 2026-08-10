/**
 * @jest-environment jsdom
 */

/**
 * Tests for map-provider-embedded.js
 *
 * Covers SP-5 Task 14: the `postMessage` origin/source security boundary
 * (exact-match, not prefix; the iframe's own `contentWindow`; fail-closed on
 * an empty `expectedOrigin`), point normalization against this file's OWN
 * required-field/range rules (a KNOWN, deliberate divergence from
 * `Pickup_Point::from_array()`'s required set — see `normalizePoint()`'s own
 * docblock), the invalid/missing `embedUrl` guard, and `destroy()`'s listener
 * detachment + callback hook clearing + idempotency. Also covers the #201
 * field-parity fix, which is scoped to OPTIONAL-field handling only:
 * `services`/`point_short_name` now normalize at all, and `payment_methods`/
 * `photos`/`services` filter out non-string/whitespace-only elements instead
 * of `String()`-coercing them (matching `Pickup_Point::sanitize_string_list()`),
 * and `max_weight` falls back to `0`, never `NaN`, for a garbage value
 * (matching PHP's `(int)` cast).
 *
 * Issue #251 (resolved by this file): `lat`/`lng` became OPTIONAL-BUT-VALIDATED
 * (present → still numeric/in-range or rejected; absent → BOTH must be absent,
 * one alone is a half-coordinate and is rejected; present → carried through,
 * absent → omitted entirely, no `0.0` fallback) — see the "Optional lat/lng"
 * section below. The same change adds `config.initAdapter`/`config.selectAdapter`,
 * two optional dotted-global-path hooks that translate a carrier's OWN protocol
 * message (reached only for a message that passed the origin+source gate and
 * did not match this file's own envelope) — see the "Adapter hooks" section.
 *
 * @see woodev/shipping-method/assets/js/frontend/map-provider-embedded.js
 */

'use strict';

const WoodevPickupMapProviderEmbedded = require(
	'../../woodev/shipping-method/assets/js/frontend/map-provider-embedded'
);

const EXPECTED_ORIGIN = 'https://carrier.ru';
const EMBED_URL = 'https://carrier.ru/widget';

function validPointPayload() {
	return {
		id: 'P1',
		name: 'Точка',
		lat: 55.75,
		lng: 37.61,
		address: 'Москва, ул. Тверская, 1',
		type: { code: 'PVZ', label: 'ПВЗ' },
	};
}

function baseConfig( overrides ) {
	return Object.assign(
		{
			embedUrl: EMBED_URL,
			expectedOrigin: EXPECTED_ORIGIN,
			strategy: 'bulk',
			i18n: { error: 'Не удалось загрузить пункты выдачи.', modalTitle: 'Выберите пункт выдачи' },
		},
		overrides
	);
}

/**
 * Builds a fresh provider, `init()`s it against a container attached to
 * `document.body` (required for the injected iframe to have a real
 * `contentWindow`), and returns everything a test needs.
 *
 * @param {Object} [configOverrides]
 * @returns {{provider: Object, container: HTMLElement, iframe: (HTMLIFrameElement|null),
 *            onSelect: Function, onError: Function}}
 */
function initProvider( configOverrides ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	const provider = new WoodevPickupMapProviderEmbedded();
	const onSelect = jest.fn();
	const onError = jest.fn();

	provider.on( 'select', onSelect );
	provider.on( 'error', onError );

	provider.init( container, baseConfig( configOverrides ), {} );

	const iframe = container.querySelector( 'iframe' );

	return { provider: provider, container: container, iframe: iframe, onSelect: onSelect, onError: onError };
}

/**
 * Dispatches a `postMessage`-shaped `message` event on `window`, with
 * `origin`/`source` forced via the `MessageEvent` constructor's init
 * dictionary (jsdom supports both as constructor options even though they
 * are otherwise read-only attributes).
 *
 * @param {string} origin
 * @param {Window} source
 * @param {*}      data
 */
function dispatchMessage( origin, source, data ) {
	window.dispatchEvent( new MessageEvent( 'message', { origin: origin, source: source, data: data } ) );
}

function envelope( point ) {
	return { source: 'woodev-pickup-embedded', type: 'select', point: point };
}

// `window.WoodevPickupEmbedded` is installed ONCE, as a side effect of the single
// `require()` above, and is deliberately left in place for the whole file — it is
// stable, stateless routing plumbing (see the module's own docblock); only
// `activeInstance`, which each `initProvider()`/`destroy()` call updates, changes
// between tests.
afterEach( () => {
	document.body.innerHTML = '';
	jest.useRealTimers();
} );

// -----------------------------------------------------------------------
// Happy path
// -----------------------------------------------------------------------

test( 'a valid message from the expected origin and the iframe as source emits a normalized select', () => {
	const { iframe, onSelect, onError } = initProvider();

	expect( iframe ).not.toBeNull();

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( validPointPayload() ) );

	expect( onError ).not.toHaveBeenCalled();
	expect( onSelect ).toHaveBeenCalledTimes( 1 );

	const point = onSelect.mock.calls[ 0 ][ 0 ];
	expect( point.id ).toBe( 'P1' );
	expect( point.lat ).toBe( 55.75 );
	expect( point.lng ).toBe( 37.61 );
	expect( point.type ).toEqual( { code: 'PVZ', label: 'ПВЗ' } );
	expect( point.selectable ).toBeUndefined();
} );

// -----------------------------------------------------------------------
// Origin / source security boundary
// -----------------------------------------------------------------------

test( 'a message from a different origin is ignored', () => {
	const { iframe, onSelect, onError } = initProvider();

	dispatchMessage( 'https://other-carrier.ru', iframe.contentWindow, envelope( validPointPayload() ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

test( 'a message from an origin that merely PREFIXES the expected one is ignored (not startsWith)', () => {
	const { iframe, onSelect, onError } = initProvider();

	// https://carrier.ru.evil.com prefix-matches https://carrier.ru under a startsWith() check —
	// this test must fail if the origin comparison is ever loosened from strict `===`.
	dispatchMessage( EXPECTED_ORIGIN + '.evil.com', iframe.contentWindow, envelope( validPointPayload() ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

test( 'the right origin but a foreign event.source is ignored', () => {
	const { onSelect, onError } = initProvider();

	const otherFrame = document.createElement( 'iframe' );
	document.body.appendChild( otherFrame );

	dispatchMessage( EXPECTED_ORIGIN, otherFrame.contentWindow, envelope( validPointPayload() ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

test( 'an empty expectedOrigin rejects every message, even one with a matching origin/source', () => {
	const { iframe, onSelect, onError } = initProvider( { expectedOrigin: '' } );

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( validPointPayload() ) );
	dispatchMessage( '', iframe.contentWindow, envelope( validPointPayload() ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

test( 'a message that is not this protocol\'s envelope shape is ignored, not thrown', () => {
	const { iframe, onSelect, onError } = initProvider();

	expect( () => {
		dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { some: 'unrelated payload' } );
		dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( validPointPayload() ) === null );
		dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, 'a plain string' );
	} ).not.toThrow();

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

test( 'a message with the right envelope source but the WRONG type is ignored', () => {
	const { iframe, onSelect, onError } = initProvider();

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, {
		source: 'woodev-pickup-embedded',
		type: 'something-else',
		point: validPointPayload(),
	} );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

test( 'a message with the right type but the WRONG envelope source is ignored', () => {
	const { iframe, onSelect, onError } = initProvider();

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, {
		source: 'some-other-library',
		type: 'select',
		point: validPointPayload(),
	} );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

// -----------------------------------------------------------------------
// Normalization
// -----------------------------------------------------------------------

// Every REQUIRED field is dropped individually — not just `address` — so a mutant that
// drops `id`/`name` from the `required` array (or the `type.code`/`type.label` presence
// guard entirely) cannot hide behind only `address` ever being tested missing. Before
// this fix, a payload missing `id` was accepted and yielded `point.id === 'undefined'`.
test.each( [ 'id', 'name', 'lat', 'lng', 'address' ] )(
	'a payload missing the required field %s emits error, not select',
	( field ) => {
		const { iframe, onSelect, onError } = initProvider();

		const payload = validPointPayload();
		delete payload[ field ];

		dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

		expect( onSelect ).not.toHaveBeenCalled();
		expect( onError ).toHaveBeenCalledTimes( 1 );
		expect( onError.mock.calls[ 0 ][ 0 ].code ).toBe( 'woodev_pickup_embed_invalid_payload' );
	}
);

// `type` itself, and each of its two required sub-fields, get the same individual-guard
// treatment as the top-level required fields above.
test( 'a payload with a missing type emits error, not select', () => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	delete payload.type;

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).toHaveBeenCalledTimes( 1 );
} );

test( 'a payload with a non-object type emits error, not select', () => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload.type = 'PVZ';

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).toHaveBeenCalledTimes( 1 );
} );

test( 'a payload with a missing type.code emits error, not select', () => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	delete payload.type.code;

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).toHaveBeenCalledTimes( 1 );
} );

test( 'a payload with a missing type.label emits error, not select', () => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	delete payload.type.label;

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).toHaveBeenCalledTimes( 1 );
} );

// Every one of the four bounds is tested individually, each against an OTHERWISE
// valid payload — isolating each guard so a single loosened comparison (e.g. `180`
// drifting to `181`) cannot hide behind another bound rejecting the same payload.
test.each( [
	[ 'lat', 91 ],
	[ 'lat', -91 ],
	[ 'lng', 181 ],
	[ 'lng', -181 ],
] )( 'an out-of-range %s (%d) emits error, not select', ( field, value ) => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload[ field ] = value;

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).toHaveBeenCalledTimes( 1 );
} );

// Boundary ACCEPTANCE matters as much as rejection: a mutant that rejects a
// legitimate pole/antimeridian-touching point is just as wrong as one that
// accepts an out-of-range one. Each bound tested individually, same reasoning
// as above.
test.each( [
	[ 'lat', 90 ],
	[ 'lat', -90 ],
	[ 'lng', 180 ],
	[ 'lng', -180 ],
] )( 'the boundary coordinate %s = %d is ACCEPTED', ( field, value ) => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload[ field ] = value;

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onError ).not.toHaveBeenCalled();
	expect( onSelect ).toHaveBeenCalledTimes( 1 );
} );

// -----------------------------------------------------------------------
// Optional lat/lng (issue #251)
// -----------------------------------------------------------------------
//
// `lat`/`lng` used to be REQUIRED, same as `id`/`name`/`address`; the
// `test.each` block above (which deletes ONE field at a time) still passes
// unchanged for `lat`/`lng` because deleting just one now trips the
// half-coordinate rule below, not a "required field missing" rule — the
// tests here pin the NEW rule directly: fully absent is fine, half-present
// is not.

test( 'a point with no lat/lng at all normalizes successfully and emits no coordinate keys', () => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	delete payload.lat;
	delete payload.lng;

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onError ).not.toHaveBeenCalled();
	expect( onSelect ).toHaveBeenCalledTimes( 1 );

	const point = onSelect.mock.calls[ 0 ][ 0 ];
	expect( 'lat' in point ).toBe( false );
	expect( 'lng' in point ).toBe( false );
} );

test.each( [ 'lat', 'lng' ] )(
	'a point with only %s present (the other absent) is REJECTED as a half-coordinate',
	( presentField ) => {
		const { iframe, onSelect, onError } = initProvider();

		const payload = validPointPayload();
		const absentField = 'lat' === presentField ? 'lng' : 'lat';
		delete payload[ absentField ];

		dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

		expect( onSelect ).not.toHaveBeenCalled();
		expect( onError ).toHaveBeenCalledTimes( 1 );
		expect( onError.mock.calls[ 0 ][ 0 ].code ).toBe( 'woodev_pickup_embed_invalid_payload' );
	}
);

// -----------------------------------------------------------------------
// Normalization — TYPED, DEFAULTED output shape (not just field presence)
// -----------------------------------------------------------------------
//
// A mutant that replaces the whole normalized-object construction with
// `return payload;` passes every test above: the fixture already happens to carry the
// right types (numbers, not numeric strings) and every optional field, so bypassing
// every `String()`/`Boolean()`/`parseInt()`/`parseFloat()` coercion and every optional-
// field default is invisible to a suite that only checks presence/equality of fields the
// fixture already got right. See `docs-internal/gotchas/mutation-sweep-branch-only-false-
// confidence.md`. These tests pin the ACTUAL coercion/defaulting behaviour instead.

test( 'numeric-STRING lat/lng normalize to actual numbers, not strings', () => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload.lat = '55.75';
	payload.lng = '37.61';

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onError ).not.toHaveBeenCalled();
	const point = onSelect.mock.calls[ 0 ][ 0 ];
	expect( typeof point.lat ).toBe( 'number' );
	expect( typeof point.lng ).toBe( 'number' );
	expect( point.lat ).toBe( 55.75 );
	expect( point.lng ).toBe( 37.61 );
} );

test( 'absent optional fields default to the empty-string/empty-array/null shape, not undefined', () => {
	const { iframe, onSelect, onError } = initProvider();

	// validPointPayload() already omits every optional field — nothing to delete.
	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( validPointPayload() ) );

	expect( onError ).not.toHaveBeenCalled();
	const point = onSelect.mock.calls[ 0 ][ 0 ];
	// `short_address` deliberately LEFT OUT of this group as of issue #263: it is no longer an
	// independent optional field that blanks to `''`, but a DERIVED view of the required
	// `address`, filled at this boundary. Its own three tests are in the "Derived short_address"
	// section above. Every field below stays here because it has no derivation source — its
	// absence is information («the carrier does not publish it»), and inventing a value would be
	// worse data than an honest blank.
	expect( point.locality ).toBe( '' );
	expect( point.postal_code ).toBe( '' );
	expect( point.phone ).toBe( '' );
	expect( point.instruction ).toBe( '' );
	expect( point.work_time ).toBe( '' );
	expect( point.point_short_name ).toBe( '' );
	expect( point.payment_methods ).toEqual( [] );
	expect( point.photos ).toEqual( [] );
	expect( point.services ).toEqual( [] );
	expect( point.accepts_cod ).toBeNull();
	expect( point.max_weight ).toBeNull();
} );

test( 'point_short_name, when present, passes through as a string (issue #199/#201 card label source)', () => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload.point_short_name = 'ПВЗ у дома';

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onError ).not.toHaveBeenCalled();
	const point = onSelect.mock.calls[ 0 ][ 0 ];
	expect( point.point_short_name ).toBe( 'ПВЗ у дома' );
} );

test( 'point_short_name, when a non-string scalar, is coerced via String() like every other optional string field', () => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload.point_short_name = 12345;

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onError ).not.toHaveBeenCalled();
	const point = onSelect.mock.calls[ 0 ][ 0 ];
	expect( point.point_short_name ).toBe( '12345' );
} );

test( 'services, when present with valid strings, passes through unchanged (issue #201: was never read at all)', () => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload.services = [ 'Примерка', 'Хранение' ];

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onError ).not.toHaveBeenCalled();
	const point = onSelect.mock.calls[ 0 ][ 0 ];
	expect( point.services ).toEqual( [ 'Примерка', 'Хранение' ] );
} );

// Mirrors `Pickup_Point::sanitize_string_list()` exactly (not the old naive `.map( String )`
// this file used to apply to `payment_methods`/`photos` before #201): non-string elements
// are DROPPED, not coerced into "42"/"true"/"[object Object]"; a whitespace-only entry is
// dropped too; the string '0' is a legitimate label and must survive.
test.each( [ 'payment_methods', 'photos', 'services' ] )(
	'%s filters out non-string and whitespace-only elements, keeps the string \'0\'',
	( field ) => {
		const { iframe, onSelect, onError } = initProvider();

		const payload = validPointPayload();
		payload[ field ] = [ 'Наличные', 42, true, null, [ 'nested' ], { obj: 1 }, '   ', '0', 'Карта' ];

		dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

		expect( onError ).not.toHaveBeenCalled();
		const point = onSelect.mock.calls[ 0 ][ 0 ];
		expect( point[ field ] ).toEqual( [ 'Наличные', '0', 'Карта' ] );
		point[ field ].forEach( ( value ) => expect( typeof value ).toBe( 'string' ) );
	}
);

// A non-array value for any of the three list fields (a carrier sending an object or a
// bare string instead of an array) must degrade to `[]`, not throw.
test.each( [ 'payment_methods', 'photos', 'services' ] )(
	'a non-array %s normalizes to an empty list rather than throwing',
	( field ) => {
		const { iframe, onSelect, onError } = initProvider();

		const payload = validPointPayload();
		payload[ field ] = 'not-an-array';

		dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

		expect( onError ).not.toHaveBeenCalled();
		const point = onSelect.mock.calls[ 0 ][ 0 ];
		expect( point[ field ] ).toEqual( [] );
	}
);

test.each( [
	[ 1, true ],
	[ 0, false ],
	[ 'yes', true ],
	[ '', false ],
] )( 'accepts_cod %j normalizes to the real boolean %j', ( raw, expected ) => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload.accepts_cod = raw;

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onError ).not.toHaveBeenCalled();
	const point = onSelect.mock.calls[ 0 ][ 0 ];
	expect( point.accepts_cod ).toBe( expected );
	expect( typeof point.accepts_cod ).toBe( 'boolean' );
} );

test( 'max_weight as a numeric string normalizes to an integer', () => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload.max_weight = '15.9';

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onError ).not.toHaveBeenCalled();
	const point = onSelect.mock.calls[ 0 ][ 0 ];
	expect( point.max_weight ).toBe( 15 );
	expect( Number.isInteger( point.max_weight ) ).toBe( true );
} );

// PHP `(int) "abc"` is `0`, never a fatal or a NaN — `Pickup_Point::from_array()`'s
// `(int) $payload['max_weight']` cast is that lenient, and `pickup-panels.js`'s
// `formatWeightKg()` has no NaN guard of its own, so letting `NaN` through here would
// print the literal text "NaN kg" in the confirmation card (issue #201 audit finding).
test( 'max_weight as a non-numeric garbage string normalizes to 0, never NaN', () => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload.max_weight = 'abc';

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onError ).not.toHaveBeenCalled();
	const point = onSelect.mock.calls[ 0 ][ 0 ];
	expect( point.max_weight ).toBe( 0 );
	expect( Number.isNaN( point.max_weight ) ).toBe( false );
} );

// -----------------------------------------------------------------------
// embedUrl validation
// -----------------------------------------------------------------------

test( 'an empty embedUrl emits error and injects no iframe', () => {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	const provider = new WoodevPickupMapProviderEmbedded();
	const onError = jest.fn();
	provider.on( 'error', onError );

	provider.init( container, baseConfig( { embedUrl: '' } ), {} );

	expect( onError ).toHaveBeenCalledTimes( 1 );
	expect( onError.mock.calls[ 0 ][ 0 ].code ).toBe( 'woodev_pickup_embed_invalid_url' );
	expect( container.querySelector( 'iframe' ) ).toBeNull();
} );

test( 'a non-https embedUrl emits error and injects no iframe', () => {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	const provider = new WoodevPickupMapProviderEmbedded();
	const onError = jest.fn();
	provider.on( 'error', onError );

	provider.init( container, baseConfig( { embedUrl: 'http://carrier.ru/widget' } ), {} );

	expect( onError ).toHaveBeenCalledTimes( 1 );
	expect( container.querySelector( 'iframe' ) ).toBeNull();
} );

// -----------------------------------------------------------------------
// Load-failure detection (spec §4.9: never an empty rectangle)
// -----------------------------------------------------------------------

test( "iframe.onerror emits error immediately, without waiting for the load timeout", () => {
	const { iframe, onSelect, onError } = initProvider();

	iframe.onerror();

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).toHaveBeenCalledTimes( 1 );
	expect( onError.mock.calls[ 0 ][ 0 ].code ).toBe( 'woodev_pickup_embed_load_failed' );
} );

test( 'neither onload nor onerror firing emits error once the load timeout elapses', () => {
	jest.useFakeTimers();

	const { onSelect, onError } = initProvider();

	jest.runOnlyPendingTimers();

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).toHaveBeenCalledTimes( 1 );
	expect( onError.mock.calls[ 0 ][ 0 ].code ).toBe( 'woodev_pickup_embed_load_failed' );
} );

test( 'onload firing before the timeout suppresses the load-timeout error', () => {
	jest.useFakeTimers();

	const { iframe, onError } = initProvider();

	iframe.onload();
	jest.runOnlyPendingTimers();

	expect( onError ).not.toHaveBeenCalled();
} );

test( 'onerror firing before the timeout suppresses a SECOND error from the timeout', () => {
	jest.useFakeTimers();

	const { iframe, onError } = initProvider();

	iframe.onerror();
	jest.runOnlyPendingTimers();

	expect( onError ).toHaveBeenCalledTimes( 1 );
} );

// -----------------------------------------------------------------------
// Derived short_address (issue #263)
// -----------------------------------------------------------------------

// The JS half of the boundary rule `Pickup_Point::from_array()` applies on the REST path, and
// it must not diverge from it: `short_address` is a shortened VIEW of the already-required
// `address`, so a carrier that sends none gets it derived here rather than leaving every
// display site to patch up an empty field on its own (which is how the search row ended up
// with no address at all — see `pickup-panels.js`).
test( 'short_address falls back to address when the carrier sends none', () => {
	const { iframe, onSelect } = initProvider();
	const payload = validPointPayload();

	delete payload.short_address;

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onSelect ).toHaveBeenCalledTimes( 1 );
	expect( onSelect.mock.calls[ 0 ][ 0 ].short_address ).toBe( 'Москва, ул. Тверская, 1' );
} );

test( 'an empty short_address is treated as absent, not as a deliberate blank', () => {
	const { iframe, onSelect } = initProvider();

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope(
		Object.assign( validPointPayload(), { short_address: '' } )
	) );

	expect( onSelect.mock.calls[ 0 ][ 0 ].short_address ).toBe( 'Москва, ул. Тверская, 1' );
} );

// A DEFAULT, never an override — a carrier with a genuinely shorter form still wins, which is
// the only reason the field exists apart from `address`.
test( 'a supplied short_address is kept', () => {
	const { iframe, onSelect } = initProvider();

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope(
		Object.assign( validPointPayload(), { short_address: 'Тверская, 1' } )
	) );

	expect( onSelect.mock.calls[ 0 ][ 0 ].short_address ).toBe( 'Тверская, 1' );
} );

// -----------------------------------------------------------------------
// init() wiring order (issue #259)
// -----------------------------------------------------------------------

// `appendChild()` is what starts the carrier's load, and since #251 the carrier's
// FIRST message is its readiness handshake — the one `initAdapter` must answer or the
// widget never initialises (measured: an empty map, forever, with no console error).
// The old order (append, then listen) was safe ONLY because both statements ran in one
// synchronous task; this test removes the dependency on that reasoning, so a future
// refactor that puts anything asynchronous between them fails here instead of shipping
// a silently dead picker. jsdom never loads the cross-origin frame, but the ORDER of
// our own two calls is fully observable, which is the invariant at stake.
test( 'the message listener is attached BEFORE the iframe enters the DOM', () => {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	const order = [];
	const realAddEventListener = window.addEventListener.bind( window );
	const realAppendChild = container.appendChild.bind( container );

	window.addEventListener = function ( type, listener, options ) {
		if ( 'message' === type ) {
			order.push( 'listen' );
		}

		return realAddEventListener( type, listener, options );
	};

	container.appendChild = function ( node ) {
		order.push( 'append' );

		return realAppendChild( node );
	};

	const provider = new WoodevPickupMapProviderEmbedded();

	try {
		provider.init( container, baseConfig(), {} );
	} finally {
		delete window.addEventListener;
		delete container.appendChild;
	}

	expect( order ).toEqual( [ 'listen', 'append' ] );

	provider.destroy();
} );

// The source check must IDENTIFY the sending window, not merely fail to disagree with
// it: an iframe with no browsing context reports `contentWindow === null`, and
// `event.source` is `null` for a message posted by a window that has since closed, so a
// bare `!==` comparison of the two passes VACUOUSLY and pins the message to nothing.
// Now that the listener is attached before `appendChild()` (issue #259), the provider
// can legitimately hold a frame in that state while a message arrives.
//
// The contextless frame is stubbed rather than produced by `iframe.remove()`: jsdom
// THROWS (`Cannot read properties of null (reading '_history')`) when `contentWindow` is
// read off a detached iframe, where a real browser simply returns `null`. That is a
// jsdom limitation, not a shape the production code has to survive — so the stub is the
// only way to exercise the real browser's behaviour here.
test( 'a null-source message is rejected when the iframe has no browsing context', () => {
	const { provider, onSelect, onError } = initProvider();

	provider._iframe = { contentWindow: null };

	dispatchMessage( EXPECTED_ORIGIN, null, envelope( validPointPayload() ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();

	provider.destroy();
} );

// -----------------------------------------------------------------------
// destroy()
// -----------------------------------------------------------------------

test( 'destroy() clears the pending load-timeout — no late error after the modal is closed', () => {
	jest.useFakeTimers();

	const { provider, onError } = initProvider();

	provider.destroy();
	jest.runOnlyPendingTimers();

	expect( onError ).not.toHaveBeenCalled();
} );

test( 'destroy() detaches the message listener — a message after destroy emits nothing', () => {
	const { provider, iframe, onSelect } = initProvider();
	const capturedIframeWindow = iframe.contentWindow;

	provider.destroy();

	dispatchMessage( EXPECTED_ORIGIN, capturedIframeWindow, envelope( validPointPayload() ) );

	expect( onSelect ).not.toHaveBeenCalled();
} );

test( 'destroy() is idempotent', () => {
	const { provider } = initProvider();

	expect( () => {
		provider.destroy();
		provider.destroy();
		provider.destroy();
	} ).not.toThrow();
} );

test( 'destroy() empties the container', () => {
	const { provider, container } = initProvider();

	expect( container.children.length ).toBeGreaterThan( 0 );

	provider.destroy();

	expect( container.children.length ).toBe( 0 );
} );

// -----------------------------------------------------------------------
// Callback-style widget hook
// -----------------------------------------------------------------------

test( 'window.WoodevPickupEmbedded.select() routes to the live instance', () => {
	const { onSelect, onError } = initProvider();

	window.WoodevPickupEmbedded.select( validPointPayload() );

	expect( onError ).not.toHaveBeenCalled();
	expect( onSelect ).toHaveBeenCalledTimes( 1 );
} );

// Regression: a provider whose init() failed on an invalid embedUrl (no iframe ever
// built) must never become activeInstance — before this fix, `activeInstance = this;`
// ran as init()'s literal first statement, so a subsequent select() call still routed
// to (and emitted from) an instance that never rendered anything.
test( 'a select() call after init() failed on an invalid embedUrl reaches no listener at all', () => {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	const provider = new WoodevPickupMapProviderEmbedded();
	const onSelect = jest.fn();
	const onError = jest.fn();
	provider.on( 'select', onSelect );
	provider.on( 'error', onError );

	provider.init( container, baseConfig( { embedUrl: '' } ), {} );

	// init() itself already emitted one invalid-url error — clear it before checking
	// that a subsequent select() call adds nothing further.
	onError.mockClear();

	expect( () => {
		window.WoodevPickupEmbedded.select( validPointPayload() );
	} ).not.toThrow();

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

test( 'destroy() clears the callback hook routing — a call after destroy is a harmless no-op', () => {
	const { provider, onSelect, onError } = initProvider();

	provider.destroy();

	expect( () => {
		window.WoodevPickupEmbedded.select( validPointPayload() );
	} ).not.toThrow();

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

// -----------------------------------------------------------------------
// Adapter hooks (issue #251)
// -----------------------------------------------------------------------
//
// `config.initAdapter`/`config.selectAdapter` are optional dotted global JS
// paths (never a callable) that translate a carrier's OWN protocol message —
// reached only for a message that already passed the origin+source gate AND
// did not match this file's own `{ source: 'woodev-pickup-embedded', ... }`
// envelope. See the file docblock's "ADAPTER HOOKS" section.

function adapterConfig( overrides ) {
	return Object.assign(
		{ initAdapter: 'WoodevTestAdapter.onReady', selectAdapter: 'WoodevTestAdapter.toPoint' },
		overrides
	);
}

afterEach( () => {
	delete window.WoodevTestAdapter;
} );

test( 'initAdapter returning a payload posts exactly one message into the iframe with expectedOrigin as targetOrigin', () => {
	const onReady = jest.fn().mockReturnValue( { postData: { foo: 'bar' } } );
	window.WoodevTestAdapter = { onReady: onReady, toPoint: jest.fn() };

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );
	const postMessageSpy = jest.spyOn( iframe.contentWindow, 'postMessage' );

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { isMapLoad: true } );

	expect( onReady ).toHaveBeenCalledTimes( 1 );
	expect( onReady ).toHaveBeenCalledWith( { isMapLoad: true } );
	expect( postMessageSpy ).toHaveBeenCalledTimes( 1 );
	expect( postMessageSpy ).toHaveBeenCalledWith( { postData: { foo: 'bar' } }, EXPECTED_ORIGIN );
	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

test( 'initAdapter returning null posts nothing into the iframe', () => {
	const onReady = jest.fn().mockReturnValue( null );
	window.WoodevTestAdapter = { onReady: onReady, toPoint: jest.fn() };

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );
	const postMessageSpy = jest.spyOn( iframe.contentWindow, 'postMessage' );

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { isMapLoad: true } );

	expect( postMessageSpy ).not.toHaveBeenCalled();
	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

test( 'selectAdapter translating a carrier-shaped message into a valid point emits select', () => {
	const toPoint = jest.fn().mockImplementation( ( data ) => {
		if ( ! data || ! data.pvzData ) {
			return null;
		}

		return {
			id: String( data.pvzData.id ),
			name: 'Почтомат №' + data.pvzData.indexTo,
			address: 'Москва, тестовый адрес',
			type: { code: data.pvzData.pvzType, label: 'Почтомат' },
		};
	} );
	window.WoodevTestAdapter = { onReady: jest.fn().mockReturnValue( null ), toPoint: toPoint };

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, {
		pvzData: { id: 43213, indexTo: '918872', pvzType: 'postamat' },
	} );

	expect( toPoint ).toHaveBeenCalledTimes( 1 );
	expect( onError ).not.toHaveBeenCalled();
	expect( onSelect ).toHaveBeenCalledTimes( 1 );

	const point = onSelect.mock.calls[ 0 ][ 0 ];
	expect( point.id ).toBe( '43213' );
	expect( point.name ).toBe( 'Почтомат №918872' );
	expect( 'lat' in point ).toBe( false );
} );

test( 'selectAdapter returning null emits nothing', () => {
	window.WoodevTestAdapter = {
		onReady: jest.fn().mockReturnValue( null ),
		toPoint: jest.fn().mockReturnValue( null ),
	};

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { some: 'carrier message' } );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

test( 'initAdapter throwing is swallowed — no error, no select, selectAdapter never runs', () => {
	window.WoodevTestAdapter = {
		onReady: jest.fn().mockImplementation( () => {
			throw new Error( 'boom' );
		} ),
		toPoint: jest.fn(),
	};

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );

	expect( () => {
		dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { isMapLoad: true } );
	} ).not.toThrow();

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
	expect( window.WoodevTestAdapter.toPoint ).not.toHaveBeenCalled();
} );

test( 'selectAdapter throwing emits error, not select', () => {
	window.WoodevTestAdapter = {
		onReady: jest.fn().mockReturnValue( null ),
		toPoint: jest.fn().mockImplementation( () => {
			throw new Error( 'boom' );
		} ),
	};

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { some: 'carrier message' } );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).toHaveBeenCalledTimes( 1 );
	expect( onError.mock.calls[ 0 ][ 0 ].code ).toBe( 'woodev_pickup_embed_adapter_error' );
} );

test( 'an empty expectedOrigin suppresses the outbound initAdapter post AND still rejects every inbound message', () => {
	const onReady = jest.fn().mockReturnValue( { postData: {} } );
	window.WoodevTestAdapter = { onReady: onReady, toPoint: jest.fn() };

	const { iframe, onSelect, onError } = initProvider( adapterConfig( { expectedOrigin: '' } ) );
	const postMessageSpy = jest.spyOn( iframe.contentWindow, 'postMessage' );

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { isMapLoad: true } );
	dispatchMessage( '', iframe.contentWindow, { isMapLoad: true } );

	expect( onReady ).not.toHaveBeenCalled();
	expect( postMessageSpy ).not.toHaveBeenCalled();
	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

test( 'a message failing the origin gate never reaches either adapter', () => {
	const onReady = jest.fn();
	const toPoint = jest.fn();
	window.WoodevTestAdapter = { onReady: onReady, toPoint: toPoint };

	const { iframe } = initProvider( adapterConfig() );

	dispatchMessage( 'https://other-carrier.ru', iframe.contentWindow, { isMapLoad: true } );

	expect( onReady ).not.toHaveBeenCalled();
	expect( toPoint ).not.toHaveBeenCalled();
} );

test( 'a message failing the source gate never reaches either adapter', () => {
	const onReady = jest.fn();
	const toPoint = jest.fn();
	window.WoodevTestAdapter = { onReady: onReady, toPoint: toPoint };

	initProvider( adapterConfig() );

	const otherFrame = document.createElement( 'iframe' );
	document.body.appendChild( otherFrame );

	dispatchMessage( EXPECTED_ORIGIN, otherFrame.contentWindow, { isMapLoad: true } );

	expect( onReady ).not.toHaveBeenCalled();
	expect( toPoint ).not.toHaveBeenCalled();
} );

test( 'a dotted adapter path that does not resolve to a function is treated as absent, not thrown', () => {
	window.WoodevTestAdapter = { onReady: 'not-a-function' };

	const { iframe, onSelect, onError } = initProvider( {
		initAdapter: 'WoodevTestAdapter.onReady',
		selectAdapter: 'WoodevTestAdapter.missing.deeper',
	} );

	expect( () => {
		dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { isMapLoad: true } );
	} ).not.toThrow();

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

// this file's own envelope still takes priority over any configured adapter —
// step 1 always runs first, and adapters never see an envelope-shaped message.
test( 'this file\'s own envelope is still handled directly and never reaches a configured adapter', () => {
	const onReady = jest.fn();
	const toPoint = jest.fn();
	window.WoodevTestAdapter = { onReady: onReady, toPoint: toPoint };

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( validPointPayload() ) );

	expect( onReady ).not.toHaveBeenCalled();
	expect( toPoint ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
	expect( onSelect ).toHaveBeenCalledTimes( 1 );
} );

// -----------------------------------------------------------------------
// F1 (Codex review, issue #251 follow-up): isNumeric()/parseFloat() must
// agree on what "numeric" means — a hex/octal/binary literal string must be
// REJECTED, never silently coerced to a real coordinate. Before this fix,
// isNumeric() used `isFinite( Number( value ) )`, which ACCEPTS '0x20'
// (Number( '0x20' ) === 32), while normalizePoint()'s conversion step used
// parseFloat(), which reads the very same string as 0 — a hex coordinate
// string PASSED validation and was then silently coerced to (0, 0), "null
// island". This was a known, deliberately accepted divergence from PHP's
// is_numeric() when #201 landed (lat/lng were still REQUIRED, so the
// divergence was provably unreachable); making them OPTIONAL (#251) made it
// a live defect.
// -----------------------------------------------------------------------

test.each( [
	// Rejected: not a plain decimal numeric-string literal.
	[ '0x20', false ],
	[ '0X20', false ],
	[ '0b11', false ],
	[ '0o17', false ],
	[ '12abc', false ],
	[ '', false ],
	[ '  ', false ],
	// Accepted: exponent notation is a legitimate decimal number. Values kept inside
	// BOTH the lat ([-90,90]) and lng ([-180,180]) ranges — this table is only about
	// whether the STRING is numeric, not about the separate range check.
	[ '5e1', true ], // 50
	[ '5E1', true ], // 50
	[ '-1.5e-2', true ], // -0.015
] )( 'lat/lng string %j is numeric=%p, never silently coerced to a wrong value', ( raw, shouldAccept ) => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload.lat = raw;
	payload.lng = raw;

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	if ( shouldAccept ) {
		expect( onError ).not.toHaveBeenCalled();
		expect( onSelect ).toHaveBeenCalledTimes( 1 );
	} else {
		expect( onSelect ).not.toHaveBeenCalled();
		expect( onError ).toHaveBeenCalledTimes( 1 );
		expect( onError.mock.calls[ 0 ][ 0 ].code ).toBe( 'woodev_pickup_embed_invalid_payload' );
	}
} );

// A hex-looking string is the whole point of this fix: it must never normalize to 0,
// even though `Number( '0x20' )` is a real, non-NaN number. Pinned separately from the
// table above so a regression here reads unambiguously as "null island is back".
test( "a hex coordinate string is REJECTED, never silently normalized to 0 ('null island')", () => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload.lat = '0x20';
	payload.lng = '0x20';

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).toHaveBeenCalledTimes( 1 );
} );

test.each( [
	[ true, false ],
	[ [], false ],
	[ {}, false ],
	[ NaN, false ],
	[ Infinity, false ],
	[ -Infinity, false ],
] )( 'a non-string/non-finite-number lat/lng value %j is rejected (numeric=%p)', ( raw ) => {
	const { iframe, onSelect, onError } = initProvider();

	const payload = validPointPayload();
	payload.lat = raw;
	payload.lng = raw;

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, envelope( payload ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).toHaveBeenCalledTimes( 1 );
} );

// -----------------------------------------------------------------------
// F2 (Codex review, issue #251 follow-up): the outbound postMessage() call
// inside the initAdapter branch must be swallowed on a throw exactly like
// initAdapter() itself throwing — a DataCloneError (a value the structured-
// clone algorithm cannot handle: a function, a cyclic object) must not break
// the picker.
// -----------------------------------------------------------------------

test( 'initAdapter returning a payload postMessage() cannot clone (a function) is swallowed, not thrown', () => {
	const onReady = jest.fn().mockReturnValue( { fn: function () {} } );
	window.WoodevTestAdapter = { onReady: onReady, toPoint: jest.fn() };

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );

	expect( () => {
		dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { isMapLoad: true } );
	} ).not.toThrow();

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

test( 'initAdapter returning a cyclic object postMessage() cannot clone is swallowed, not thrown', () => {
	const cyclic = {};
	cyclic.self = cyclic;

	const onReady = jest.fn().mockReturnValue( cyclic );
	window.WoodevTestAdapter = { onReady: onReady, toPoint: jest.fn() };

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );

	expect( () => {
		dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { isMapLoad: true } );
	} ).not.toThrow();

	expect( onSelect ).not.toHaveBeenCalled();
	expect( onError ).not.toHaveBeenCalled();
} );

// -----------------------------------------------------------------------
// F3 (Codex review, issue #251 follow-up): a selectAdapter that reports a
// selection via BOTH the callback-style hook AND its own return value must
// still produce exactly ONE select/error emission for one inbound message —
// not two, and not two confirmation round trips on pickup-mount.js's side.
// -----------------------------------------------------------------------

test( 'a selectAdapter that calls the callback hook AND returns a point emits select exactly once', () => {
	const toPoint = jest.fn().mockImplementation( ( data ) => {
		window.WoodevPickupEmbedded.select( {
			id: 'CB1',
			name: 'Точка через колбэк',
			address: 'Адрес',
			type: { code: 'PVZ', label: 'ПВЗ' },
		} );

		// AND also returns a point directly — the pathological "both styles at once" case.
		return {
			id: 'RETURN1',
			name: 'Точка через return',
			address: 'Адрес',
			type: { code: 'PVZ', label: 'ПВЗ' },
		};
	} );
	window.WoodevTestAdapter = { onReady: jest.fn().mockReturnValue( null ), toPoint: toPoint };

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { some: 'carrier message' } );

	expect( onError ).not.toHaveBeenCalled();
	expect( onSelect ).toHaveBeenCalledTimes( 1 );
	// The emission that reaches the listener is the callback-route one — the return
	// value is ignored once the callback already fired synchronously.
	expect( onSelect.mock.calls[ 0 ][ 0 ].id ).toBe( 'CB1' );
} );

test( 'callback-style: selectAdapter reports via the callback hook and returns null — one emission', () => {
	const toPoint = jest.fn().mockImplementation( () => {
		window.WoodevPickupEmbedded.select( {
			id: 'CB2',
			name: 'Точка через колбэк',
			address: 'Адрес',
			type: { code: 'PVZ', label: 'ПВЗ' },
		} );

		return null;
	} );
	window.WoodevTestAdapter = { onReady: jest.fn().mockReturnValue( null ), toPoint: toPoint };

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { some: 'carrier message' } );

	expect( onError ).not.toHaveBeenCalled();
	expect( onSelect ).toHaveBeenCalledTimes( 1 );
	expect( onSelect.mock.calls[ 0 ][ 0 ].id ).toBe( 'CB2' );
} );

test( 'return-style: selectAdapter reports ONLY via its return value — still exactly one emission', () => {
	// Baseline re-confirmation with the reentry guard in place — a selectAdapter that
	// never touches the callback hook at all must be unaffected by the guard.
	const toPoint = jest.fn().mockReturnValue( {
		id: 'RETURN-ONLY',
		name: 'Точка через return',
		address: 'Адрес',
		type: { code: 'PVZ', label: 'ПВЗ' },
	} );
	window.WoodevTestAdapter = { onReady: jest.fn().mockReturnValue( null ), toPoint: toPoint };

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { some: 'carrier message' } );

	expect( onError ).not.toHaveBeenCalled();
	expect( onSelect ).toHaveBeenCalledTimes( 1 );
	expect( onSelect.mock.calls[ 0 ][ 0 ].id ).toBe( 'RETURN-ONLY' );
} );

test( 'a selectAdapter that calls the callback hook with an invalid payload then throws does not ALSO emit error', () => {
	const toPoint = jest.fn().mockImplementation( () => {
		// Invalid payload (missing required fields) — the callback route itself emits
		// `error`, then the adapter throws on its way out.
		window.WoodevPickupEmbedded.select( { garbage: true } );

		throw new Error( 'boom after callback' );
	} );
	window.WoodevTestAdapter = { onReady: jest.fn().mockReturnValue( null ), toPoint: toPoint };

	const { iframe, onSelect, onError } = initProvider( adapterConfig() );

	dispatchMessage( EXPECTED_ORIGIN, iframe.contentWindow, { some: 'carrier message' } );

	expect( onSelect ).not.toHaveBeenCalled();
	// Exactly one error — from the callback route's failed normalization, NOT a second
	// one from the adapter's own throw being caught downstream.
	expect( onError ).toHaveBeenCalledTimes( 1 );
	expect( onError.mock.calls[ 0 ][ 0 ].code ).toBe( 'woodev_pickup_embed_invalid_payload' );
} );
