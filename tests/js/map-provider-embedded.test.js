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
 * docblock and #251), the invalid/missing `embedUrl` guard, and `destroy()`'s
 * listener detachment + callback hook clearing + idempotency. Also covers the
 * #201 field-parity fix, which is scoped to OPTIONAL-field handling only:
 * `services`/`point_short_name` now normalize at all, and `payment_methods`/
 * `photos`/`services` filter out non-string/whitespace-only elements instead
 * of `String()`-coercing them (matching `Pickup_Point::sanitize_string_list()`),
 * and `max_weight` falls back to `0`, never `NaN`, for a garbage value
 * (matching PHP's `(int)` cast).
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
	expect( point.short_address ).toBe( '' );
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
