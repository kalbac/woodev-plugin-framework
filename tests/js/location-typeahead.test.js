/**
 * Tests for location-typeahead.js
 *
 * Covers Task 10 of the location-provider plan (SP-C): progressive enhancement
 * (native `<input>` kept, ARIA + listbox added on attach, fully removed on
 * detach), the 250ms debounce, stale-response discard via a generation
 * counter, XSS-safe rendering (`textContent`, never `innerHTML`), keyboard
 * navigation, click-outside dismissal, and the "no chrome for empty results"
 * rule. Plus defensive coverage this file's own author judged necessary:
 * double attach/detach, a rejecting fetch, clearing the input below
 * `minChars` while a request is in flight, and mouse selection.
 *
 * @see woodev/shipping-method/assets/js/frontend/location-typeahead.js
 * @see docs-internal/plans/2026-08-12-location-provider-plan.md — Task 10
 * @see docs-internal/specs/2026-08-12-location-provider-design.md — D6, §4.4
 */

'use strict';

const attachTypeahead = require( '../../woodev/shipping-method/assets/js/frontend/location-typeahead' );

/**
 * Builds a manually-settled promise + its resolve/reject callbacks — used to
 * control resolution ORDER across concurrent fetch() calls (mirrors
 * pickup-datasource.test.js's own `mockFetchDeferred()`).
 */
function deferred() {
	let resolveFn;
	let rejectFn;
	const promise = new Promise( ( resolve, reject ) => {
		resolveFn = resolve;
		rejectFn = reject;
	} );

	return { promise, resolve: resolveFn, reject: rejectFn };
}

/**
 * Yields several real microtask ticks — needed because this module's own
 * `Promise.resolve( fetchFn( query ) ).then( … )` chain adds ticks beyond the
 * one a test's own `await someDeferred.promise` already consumes. Unaffected
 * by fake timers (only setTimeout/setInterval are faked).
 */
async function flushMicrotasks() {
	for ( let i = 0; i < 5; i++ ) {
		await Promise.resolve();
	}
}

let input;

beforeEach( () => {
	document.body.innerHTML = '';
	input = document.createElement( 'input' );
	input.type = 'text';
	input.id = 'billing_city';
	document.body.appendChild( input );
} );

afterEach( () => {
	jest.useRealTimers();
	document.body.innerHTML = '';
} );

function listboxOf() {
	return document.querySelector( '.woodev-location-listbox' );
}

// -----------------------------------------------------------------------
// Progressive enhancement — attach/detach
// -----------------------------------------------------------------------

test( 'attach() adds combobox ARIA and an adjacent listbox WITHOUT replacing the input', () => {
	const original = input;

	const { detach } = attachTypeahead( input, { fetch: jest.fn(), onSelect: jest.fn() } );

	expect( document.getElementById( 'billing_city' ) ).toBe( original ); // same node, never replaced
	expect( input.getAttribute( 'role' ) ).toBe( 'combobox' );
	expect( input.getAttribute( 'aria-autocomplete' ) ).toBe( 'list' );
	expect( input.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	expect( input.getAttribute( 'aria-haspopup' ) ).toBe( 'listbox' );

	const listbox = listboxOf();
	expect( listbox ).not.toBeNull();
	expect( listbox.getAttribute( 'role' ) ).toBe( 'listbox' );
	expect( input.getAttribute( 'aria-controls' ) ).toBe( listbox.id );

	detach();
} );

test( 'detach() removes every attribute added on attach and the listbox, restoring the original DOM state', () => {
	const before = input.outerHTML;

	const { detach } = attachTypeahead( input, { fetch: jest.fn(), onSelect: jest.fn() } );
	detach();

	expect( input.outerHTML ).toBe( before );
	expect( listboxOf() ).toBeNull();
	expect( input.hasAttribute( 'role' ) ).toBe( false );
	expect( input.hasAttribute( 'aria-autocomplete' ) ).toBe( false );
	expect( input.hasAttribute( 'aria-expanded' ) ).toBe( false );
	expect( input.hasAttribute( 'aria-controls' ) ).toBe( false );
	expect( input.hasAttribute( 'aria-haspopup' ) ).toBe( false );
	expect( input.hasAttribute( 'aria-activedescendant' ) ).toBe( false );
} );

test( 'detach() restores a pre-existing attribute value rather than blanking it', () => {
	input.setAttribute( 'role', 'textbox' );

	const { detach } = attachTypeahead( input, { fetch: jest.fn(), onSelect: jest.fn() } );
	expect( input.getAttribute( 'role' ) ).toBe( 'combobox' ); // overwritten while attached

	detach();

	expect( input.getAttribute( 'role' ) ).toBe( 'textbox' ); // restored, not removed
} );

test( 'detach() called twice is a safe no-op the second time', () => {
	const { detach } = attachTypeahead( input, { fetch: jest.fn(), onSelect: jest.fn() } );

	detach();
	expect( () => detach() ).not.toThrow();
	expect( listboxOf() ).toBeNull();
} );

test( 'attaching twice to the same input detaches the first instance — exactly one listbox, old wiring dead', () => {
	jest.useFakeTimers();
	const firstFetch = jest.fn( () => Promise.resolve( [] ) );
	const secondFetch = jest.fn( () => Promise.resolve( [] ) );

	attachTypeahead( input, { fetch: firstFetch, onSelect: jest.fn() } );
	attachTypeahead( input, { fetch: secondFetch, onSelect: jest.fn() } );

	expect( document.querySelectorAll( '.woodev-location-listbox' ).length ).toBe( 1 );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );

	expect( firstFetch ).not.toHaveBeenCalled();
	expect( secondFetch ).toHaveBeenCalledTimes( 1 );
} );

// -----------------------------------------------------------------------
// Debounce
// -----------------------------------------------------------------------

test( 'typing >= minChars debounces 250ms then calls fetch(query) exactly once, with the FINAL query', () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => new Promise( () => {} ) ); // never resolves — not the point of this test

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	[ 'b', 'ba', 'bar' ].forEach( ( value ) => {
		input.value = value;
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );

	// Not yet — the debounce window has not elapsed.
	jest.advanceTimersByTime( 200 );
	expect( fetchMock ).not.toHaveBeenCalled();

	jest.advanceTimersByTime( 50 );
	expect( fetchMock ).toHaveBeenCalledTimes( 1 );
	expect( fetchMock ).toHaveBeenCalledWith( 'bar' );
} );

test( 'typing below minChars never calls fetch and keeps the listbox hidden', () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => Promise.resolve( [ { label: 'x' } ] ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'b';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 500 );

	expect( fetchMock ).not.toHaveBeenCalled();
	expect( listboxOf().hidden ).toBe( true );
} );

test( 'a custom minChars is honoured', () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => new Promise( () => {} ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn(), minChars: 3 } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 500 );
	expect( fetchMock ).not.toHaveBeenCalled();

	input.value = 'bar';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	expect( fetchMock ).toHaveBeenCalledTimes( 1 );
} );

// -----------------------------------------------------------------------
// Stale-response discard (generation counter)
// -----------------------------------------------------------------------

test( 'an earlier fetch resolving AFTER a later one is discarded — listbox shows the LATER query results', async () => {
	jest.useFakeTimers();
	const first = deferred();
	const second = deferred();
	const fetchMock = jest.fn()
		.mockReturnValueOnce( first.promise )
		.mockReturnValueOnce( second.promise );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );

	input.value = 'bar';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );

	expect( fetchMock ).toHaveBeenCalledTimes( 2 );

	// Out-of-order delivery: the LATER-issued request answers FIRST.
	second.resolve( [ { label: 'Barnaul' } ] );
	await flushMicrotasks();

	first.resolve( [ { label: 'Barcelona (stale)' } ] );
	await flushMicrotasks();

	const labels = Array.from( listboxOf().children ).map( ( li ) => li.textContent );
	expect( labels ).toEqual( [ 'Barnaul' ] );
} );

test( 'clearing the input below minChars while a request is in flight discards its eventual result', async () => {
	jest.useFakeTimers();
	const pending = deferred();
	const fetchMock = jest.fn( () => pending.promise );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	expect( fetchMock ).toHaveBeenCalledTimes( 1 );

	input.value = '';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

	pending.resolve( [ { label: 'Barnaul' } ] );
	await flushMicrotasks();

	expect( listboxOf().hidden ).toBe( true );
	expect( listboxOf().children.length ).toBe( 0 );
	expect( fetchMock ).toHaveBeenCalledTimes( 1 ); // never re-fetched for the cleared query
} );

// -----------------------------------------------------------------------
// XSS — textContent only
// -----------------------------------------------------------------------

test( 'suggestion labels are rendered via textContent — a markup label never becomes an element', async () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => Promise.resolve( [ { label: '<b>Барнаул</b><img src=x onerror=alert(1)>' } ] ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	const listbox = listboxOf();
	expect( listbox.querySelector( 'b' ) ).toBeNull();
	expect( listbox.querySelector( 'img' ) ).toBeNull();
	expect( listbox.children[ 0 ].textContent ).toBe( '<b>Барнаул</b><img src=x onerror=alert(1)>' );
} );

// -----------------------------------------------------------------------
// Keyboard
// -----------------------------------------------------------------------

async function openWithTwoResults( onSelect ) {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => Promise.resolve( [ { label: 'Барнаул' }, { label: 'Барселона' } ] ) );

	const handle = attachTypeahead( input, { fetch: fetchMock, onSelect: onSelect || jest.fn() } );

	input.value = 'бар';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	return handle;
}

test( 'ArrowDown/ArrowUp move the active item and set aria-activedescendant', async () => {
	await openWithTwoResults();
	const listbox = listboxOf();

	expect( input.hasAttribute( 'aria-activedescendant' ) ).toBe( false );

	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowDown', bubbles: true } ) );
	expect( input.getAttribute( 'aria-activedescendant' ) ).toBe( listbox.children[ 0 ].id );
	expect( listbox.children[ 0 ].getAttribute( 'aria-selected' ) ).toBe( 'true' );

	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowDown', bubbles: true } ) );
	expect( input.getAttribute( 'aria-activedescendant' ) ).toBe( listbox.children[ 1 ].id );

	// Clamped, does not wrap past the last item.
	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowDown', bubbles: true } ) );
	expect( input.getAttribute( 'aria-activedescendant' ) ).toBe( listbox.children[ 1 ].id );

	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowUp', bubbles: true } ) );
	expect( input.getAttribute( 'aria-activedescendant' ) ).toBe( listbox.children[ 0 ].id );
} );

test( 'Enter selects the active item, writes its label into the input, calls onSelect and closes', async () => {
	const onSelect = jest.fn();
	await openWithTwoResults( onSelect );

	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowDown', bubbles: true } ) );
	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } ) );

	expect( onSelect ).toHaveBeenCalledTimes( 1 );
	expect( onSelect ).toHaveBeenCalledWith( { label: 'Барнаул' } );
	expect( input.value ).toBe( 'Барнаул' );
	expect( listboxOf().hidden ).toBe( true );
	expect( input.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
} );

test( 'Enter with no active item does nothing (no onSelect, listbox stays open)', async () => {
	const onSelect = jest.fn();
	await openWithTwoResults( onSelect );

	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( listboxOf().hidden ).toBe( false );
} );

test( 'Escape closes the listbox without changing the input value', async () => {
	await openWithTwoResults();
	input.value = 'бар';

	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );

	expect( listboxOf().hidden ).toBe( true );
	expect( listboxOf().children.length ).toBe( 0 );
	expect( input.value ).toBe( 'бар' );
} );

// -----------------------------------------------------------------------
// No reopen after a completed selection (Finding 3, PR-C review)
// -----------------------------------------------------------------------
//
// selectItem() writes the picked label into the input and dispatches a SYNTHETIC native
// `input` event by design (so the cascade layer's own delegated listener sees it) — that
// event schedules this module's OWN debounced fetch for the just-picked label, exactly like
// a real keystroke would. closeListbox() must invalidate that scheduled work (clear the
// timer AND bump the generation) so the listbox cannot reopen ~250ms later showing
// suggestions for what the customer already picked. Same shape whenever the listbox closes
// with a request in flight (Escape, blur, outside click) — a late response must never repaint.

test( 'selecting an item (Enter) does not reopen the listbox ~250ms later from its own synthetic input event', async () => {
	const onSelect = jest.fn();
	await openWithTwoResults( onSelect );

	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowDown', bubbles: true } ) );
	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } ) );

	expect( listboxOf().hidden ).toBe( true );

	// The synthetic `input` event selectItem() dispatches schedules a debounced fetch for the
	// label just written — advancing past the debounce window is exactly what would reopen
	// the listbox if closeListbox() failed to invalidate it.
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	expect( listboxOf().hidden ).toBe( true );
	expect( listboxOf().children.length ).toBe( 0 );
} );

test( 'selecting an item (mouse click) does not reopen the listbox ~250ms later', async () => {
	const onSelect = jest.fn();
	await openWithTwoResults( onSelect );

	const secondOption = listboxOf().children[ 1 ];
	secondOption.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );

	expect( listboxOf().hidden ).toBe( true );

	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	expect( listboxOf().hidden ).toBe( true );
	expect( listboxOf().children.length ).toBe( 0 );
} );

test( 'blurring while a request is in flight discards its late response — no reopen', async () => {
	jest.useFakeTimers();
	const pending = deferred();
	const fetchMock = jest.fn( () => pending.promise );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	expect( fetchMock ).toHaveBeenCalledTimes( 1 );

	input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );

	pending.resolve( [ { label: 'Barnaul' } ] );
	await flushMicrotasks();

	expect( listboxOf().hidden ).toBe( true );
	expect( listboxOf().children.length ).toBe( 0 );
} );

test( 'Escape invalidates pending work, but typing again still fetches fresh suggestions', () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => new Promise( () => {} ) ); // never resolves — not the point here

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	expect( fetchMock ).toHaveBeenCalledTimes( 1 );

	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );

	input.value = 'bar';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );

	expect( fetchMock ).toHaveBeenCalledTimes( 2 );
	expect( fetchMock ).toHaveBeenLastCalledWith( 'bar' );
} );

// -----------------------------------------------------------------------
// Mouse
// -----------------------------------------------------------------------

test( 'clicking a suggestion selects it, keeps focus on the input, and closes the listbox', async () => {
	const onSelect = jest.fn();
	await openWithTwoResults( onSelect );

	input.focus();
	const secondOption = listboxOf().children[ 1 ];
	secondOption.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );

	expect( onSelect ).toHaveBeenCalledWith( { label: 'Барселона' } );
	expect( input.value ).toBe( 'Барселона' );
	expect( listboxOf().hidden ).toBe( true );
	expect( document.activeElement ).toBe( input ); // preventDefault kept focus on the input
} );

test( 'clicking outside the widget closes the listbox', async () => {
	await openWithTwoResults();

	const elsewhere = document.createElement( 'button' );
	document.body.appendChild( elsewhere );
	elsewhere.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );

	expect( listboxOf().hidden ).toBe( true );
} );

test( 'blurring the input closes the listbox', async () => {
	await openWithTwoResults();

	input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );

	expect( listboxOf().hidden ).toBe( true );
} );

// -----------------------------------------------------------------------
// Empty results — no "no results" chrome
// -----------------------------------------------------------------------

test( 'an empty result set hides the listbox with no placeholder content', async () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => Promise.resolve( [] ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'zz';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	const listbox = listboxOf();
	expect( listbox.hidden ).toBe( true );
	expect( listbox.children.length ).toBe( 0 );
	expect( input.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
} );

// -----------------------------------------------------------------------
// Rejection
// -----------------------------------------------------------------------

test( 'a rejecting fetch never throws and leaves the listbox hidden', async () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => Promise.reject( new Error( 'network down' ) ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	expect( () => {
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		jest.advanceTimersByTime( 250 );
	} ).not.toThrow();

	await flushMicrotasks();

	expect( listboxOf().hidden ).toBe( true );
} );

test( 'a fetch that throws synchronously never throws out of the input handler', () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => {
		throw new Error( 'boom' );
	} );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	expect( () => {
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		jest.advanceTimersByTime( 250 );
	} ).not.toThrow();
} );

// -----------------------------------------------------------------------
// The value/label split — what a pick WRITES vs what the list SHOWS
// (operator, s70 rig pass: picking "Московская обл., г Жуковский" must leave
// "Жуковский" in a locality field, not the whole provider label)
// -----------------------------------------------------------------------

function spinnerOf() {
	return document.querySelector( '.woodev-location-spinner' );
}

async function pickFirst( items ) {
	const fetchMock = jest.fn( () => Promise.resolve( items ) );
	const onSelect = jest.fn();

	attachTypeahead( input, { fetch: fetchMock, onSelect } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	listboxOf().children[ 0 ].dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );

	return onSelect;
}

test( 'a selection writes item.value into the input, not item.label', async () => {
	jest.useFakeTimers();

	const item = { label: 'Московская обл., г Жуковский', value: 'Жуковский', record: { key: 'dadata:zh' } };
	const onSelect = await pickFirst( [ item ] );

	expect( input.value ).toBe( 'Жуковский' );
	// The raw item still reaches the caller untouched — the widget narrows the FIELD, not the data.
	expect( onSelect ).toHaveBeenCalledWith( item );
} );

test( 'a selection falls back to item.label when no value is supplied', async () => {
	jest.useFakeTimers();

	await pickFirst( [ { label: 'г Москва' } ] );

	expect( input.value ).toBe( 'г Москва' );
} );

test( 'a non-string value falls back to the label rather than stringifying it', async () => {
	jest.useFakeTimers();

	await pickFirst( [ { label: 'г Москва', value: { name: 'Москва' } } ] );

	expect( input.value ).toBe( 'г Москва' );
} );

test( 'the rendered option text stays the LABEL even when a narrower value is supplied', async () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => Promise.resolve( [ { label: 'Московская обл., г Жуковский', value: 'Жуковский' } ] ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	expect( listboxOf().children[ 0 ].textContent ).toBe( 'Московская обл., г Жуковский' );
} );

// -----------------------------------------------------------------------
// Busy state — the spinner and aria-busy
// -----------------------------------------------------------------------

test( 'attach() inserts a hidden spinner; detach() removes it', () => {
	const { detach } = attachTypeahead( input, { fetch: jest.fn(), onSelect: jest.fn() } );

	expect( spinnerOf() ).not.toBeNull();
	expect( spinnerOf().hidden ).toBe( true );
	expect( input.hasAttribute( 'aria-busy' ) ).toBe( false );

	detach();

	expect( spinnerOf() ).toBeNull();
} );

test( 'the spinner shows as soon as an eligible query is SCHEDULED, before the debounce fires', () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => Promise.resolve( [] ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

	// The 250ms the customer waits before anything is even requested is exactly the
	// stretch that reads as "nothing is happening" — the indicator must cover it.
	expect( fetchMock ).not.toHaveBeenCalled();
	expect( spinnerOf().hidden ).toBe( false );
	expect( input.getAttribute( 'aria-busy' ) ).toBe( 'true' );
} );

test( 'the spinner hides once results render', async () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => Promise.resolve( [ { label: 'г Москва' } ] ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	expect( listboxOf().hidden ).toBe( false );
	expect( spinnerOf().hidden ).toBe( true );
	expect( input.hasAttribute( 'aria-busy' ) ).toBe( false );
} );

test( 'the spinner hides when the query drops below minChars, with no request ever issued', () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => Promise.resolve( [] ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	expect( spinnerOf().hidden ).toBe( false );

	input.value = 'b';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

	expect( spinnerOf().hidden ).toBe( true );
	expect( fetchMock ).not.toHaveBeenCalled();
} );

test( 'the spinner hides on a rejected fetch — it must not spin forever on a network failure', async () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => Promise.reject( new Error( 'network down' ) ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	expect( spinnerOf().hidden ).toBe( true );
} );

test( 'the spinner hides when a fetch throws synchronously', () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => {
		throw new Error( 'boom' );
	} );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );

	expect( spinnerOf().hidden ).toBe( true );
} );

test( 'the spinner hides after a selection', async () => {
	jest.useFakeTimers();

	await pickFirst( [ { label: 'г Москва', value: 'Москва' } ] );

	expect( spinnerOf().hidden ).toBe( true );
	expect( input.hasAttribute( 'aria-busy' ) ).toBe( false );
} );

test( 'a STALE response does not clear the busy state a newer search owns', async () => {
	jest.useFakeTimers();
	const first = deferred();
	const second = deferred();
	const fetchMock = jest.fn()
		.mockImplementationOnce( () => first.promise )
		.mockImplementationOnce( () => second.promise );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn() } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );

	input.value = 'bar';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );

	expect( fetchMock ).toHaveBeenCalledTimes( 2 );

	// The FIRST request answers last — it is stale, and the second is still outstanding.
	first.resolve( [ { label: 'stale' } ] );
	await flushMicrotasks();

	expect( spinnerOf().hidden ).toBe( false );
	expect( listboxOf().hidden ).toBe( true ); // and it paints nothing, either

	second.resolve( [ { label: 'fresh' } ] );
	await flushMicrotasks();

	expect( spinnerOf().hidden ).toBe( true );
	expect( listboxOf().children[ 0 ].textContent ).toBe( 'fresh' );
} );

// -----------------------------------------------------------------------
// Empty results — a message, not silence (operator, s70)
// -----------------------------------------------------------------------

async function searchReturning( results, options ) {
	const fetchMock = jest.fn( () => Promise.resolve( results ) );

	attachTypeahead( input, Object.assign( { fetch: fetchMock, onSelect: jest.fn() }, options || {} ) );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();
}

function emptyRowOf() {
	return document.querySelector( '.woodev-location-empty' );
}

test( 'an empty result set shows the emptyText message inside an OPEN listbox', async () => {
	jest.useFakeTimers();

	await searchReturning( [], { emptyText: 'Поиск не дал результатов. Попробуйте изменить запрос.' } );

	expect( listboxOf().hidden ).toBe( false );
	expect( emptyRowOf() ).not.toBeNull();
	expect( emptyRowOf().textContent ).toBe( 'Поиск не дал результатов. Попробуйте изменить запрос.' );
	expect( input.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
} );

test( 'the empty message is NOT an option — no role=option, and Enter selects nothing', async () => {
	jest.useFakeTimers();
	const onSelect = jest.fn();
	const fetchMock = jest.fn( () => Promise.resolve( [] ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect, emptyText: 'ничего' } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	expect( emptyRowOf().getAttribute( 'role' ) ).toBe( 'presentation' );

	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowDown', bubbles: true } ) );
	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( input.value ).toBe( 'ba' ); // the message never becomes the field's value
	expect( input.hasAttribute( 'aria-activedescendant' ) ).toBe( false );
} );

test( 'clicking the empty message selects nothing', async () => {
	jest.useFakeTimers();
	const onSelect = jest.fn();
	const fetchMock = jest.fn( () => Promise.resolve( [] ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect, emptyText: 'ничего' } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	emptyRowOf().dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( input.value ).toBe( 'ba' );
} );

test( 'the empty message is replaced by real suggestions on the next search', async () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn()
		.mockImplementationOnce( () => Promise.resolve( [] ) )
		.mockImplementationOnce( () => Promise.resolve( [ { label: 'г Москва' } ] ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn(), emptyText: 'ничего' } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	expect( emptyRowOf() ).not.toBeNull();

	input.value = 'bar';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	expect( emptyRowOf() ).toBeNull();
	expect( listboxOf().children.length ).toBe( 1 );
	expect( listboxOf().children[ 0 ].getAttribute( 'role' ) ).toBe( 'option' );
} );

test( 'closing the listbox clears the empty message too', async () => {
	jest.useFakeTimers();

	await searchReturning( [], { emptyText: 'ничего' } );

	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );

	expect( listboxOf().hidden ).toBe( true );
	expect( emptyRowOf() ).toBeNull();
} );

test( 'without emptyText the listbox still hides silently — the old contract is the default', async () => {
	jest.useFakeTimers();

	await searchReturning( [] );

	expect( listboxOf().hidden ).toBe( true );
	expect( emptyRowOf() ).toBeNull();
	expect( input.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
} );

// -----------------------------------------------------------------------
// errorText (issue #405) — a REJECTED/thrown fetch(), the "request could not
// be completed" state, must read as a DIFFERENT sentence from emptyText's
// "searched, found nothing" above — never the same message, never silence
// when the caller opted in. Mirrors the emptyText suite above exactly.
// -----------------------------------------------------------------------

function errorRowOf() {
	return document.querySelector( '.woodev-location-error' );
}

async function searchRejecting( options ) {
	const fetchMock = jest.fn( () => Promise.reject( new Error( 'upstream unavailable' ) ) );

	attachTypeahead( input, Object.assign( { fetch: fetchMock, onSelect: jest.fn() }, options || {} ) );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();
}

test( 'a rejected fetch shows the errorText message inside an OPEN listbox, distinct from emptyText', async () => {
	jest.useFakeTimers();

	await searchRejecting( {
		errorText: 'Источник подсказок недоступен. Попробуйте ещё раз позже или введите вручную.',
		emptyText: 'Поиск не дал результатов. Попробуйте изменить запрос.',
	} );

	expect( listboxOf().hidden ).toBe( false );
	expect( input.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
	expect( errorRowOf() ).not.toBeNull();
	expect( errorRowOf().textContent ).toBe( 'Источник подсказок недоступен. Попробуйте ещё раз позже или введите вручную.' );
	// Never the emptyText row — the two states must never collapse into the same message.
	expect( emptyRowOf() ).toBeNull();
} );

test( 'the error message is NOT an option — no role=option, and Enter selects nothing', async () => {
	jest.useFakeTimers();
	const onSelect = jest.fn();
	const fetchMock = jest.fn( () => Promise.reject( new Error( 'boom' ) ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect, errorText: 'недоступно' } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	expect( errorRowOf().getAttribute( 'role' ) ).toBe( 'presentation' );

	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowDown', bubbles: true } ) );
	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( input.value ).toBe( 'ba' );
	expect( input.hasAttribute( 'aria-activedescendant' ) ).toBe( false );
} );

test( 'clicking the error message selects nothing', async () => {
	jest.useFakeTimers();
	const onSelect = jest.fn();
	const fetchMock = jest.fn( () => Promise.reject( new Error( 'boom' ) ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect, errorText: 'недоступно' } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	errorRowOf().dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );

	expect( onSelect ).not.toHaveBeenCalled();
	expect( input.value ).toBe( 'ba' );
} );

test( 'the error message is replaced by real suggestions on the next search', async () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn()
		.mockImplementationOnce( () => Promise.reject( new Error( 'boom' ) ) )
		.mockImplementationOnce( () => Promise.resolve( [ { label: 'г Москва' } ] ) );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn(), errorText: 'недоступно' } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	expect( errorRowOf() ).not.toBeNull();

	input.value = 'bar';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );
	await flushMicrotasks();

	expect( errorRowOf() ).toBeNull();
	expect( listboxOf().children.length ).toBe( 1 );
	expect( listboxOf().children[ 0 ].getAttribute( 'role' ) ).toBe( 'option' );
} );

test( 'closing the listbox clears the error message too', async () => {
	jest.useFakeTimers();

	await searchRejecting( { errorText: 'недоступно' } );

	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );

	expect( listboxOf().hidden ).toBe( true );
	expect( errorRowOf() ).toBeNull();
} );

test( 'without errorText a rejected fetch still hides the listbox silently — the old contract is the default', async () => {
	jest.useFakeTimers();

	await searchRejecting();

	expect( listboxOf().hidden ).toBe( true );
	expect( errorRowOf() ).toBeNull();
	expect( input.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
} );

test( 'a fetch that throws synchronously shows the errorText message when supplied', () => {
	jest.useFakeTimers();
	const fetchMock = jest.fn( () => {
		throw new Error( 'boom' );
	} );

	attachTypeahead( input, { fetch: fetchMock, onSelect: jest.fn(), errorText: 'недоступно' } );

	input.value = 'ba';
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	jest.advanceTimersByTime( 250 );

	expect( errorRowOf() ).not.toBeNull();
	expect( errorRowOf().textContent ).toBe( 'недоступно' );
} );

// -----------------------------------------------------------------------
// ABANDON — adopt or report on blur (issue #350)
// -----------------------------------------------------------------------
//
// A customer who types a locality and tabs away without ever clicking a suggestion used to
// leave `location-cascade.js` with typed TEXT but no confirmed RECORD — harmless for most
// fields, but a dead end for the settlement level (issue #337's address lock has no exit for a
// town the provider genuinely does not carry). `options.onAbandon` is the fix: adopt the first
// suggestion when the query actually resolved to one or more, report zero via
// `onAbandon({ resolved: true })` when a completed search resolved to none, report
// `onAbandon({ resolved: false })` (FIX 1, 17.08.2026) when the text never reached `minChars`
// at all — never adopting either way — and report NOTHING at all for a blank/escaped/
// already-committed blur. See the file's own ABANDON docblock section for the full contract.

describe( 'ABANDON — adopt or report on blur (issue #350)', () => {
	afterEach( () => {
		jest.useRealTimers();
	} );

	test( 'blur with >= 1 completed result adopts item 0', async () => {
		jest.useFakeTimers();
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const fetchMock = jest.fn( () => Promise.resolve( [ { label: 'Жуковский' }, { label: 'Жуков' } ] ) );

		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon } );

		input.value = 'жук';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		jest.advanceTimersByTime( 250 );
		await flushMicrotasks();

		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await flushMicrotasks();

		expect( input.value ).toBe( 'Жуковский' );
		expect( onSelect ).toHaveBeenCalledTimes( 1 );
		expect( onSelect ).toHaveBeenCalledWith( { label: 'Жуковский' } );
		expect( onAbandon ).not.toHaveBeenCalled();
		expect( listboxOf().hidden ).toBe( true );
	} );

	test( 'blur with 0 results calls onAbandon, never onSelect, and leaves the typed text alone', async () => {
		jest.useFakeTimers();
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const fetchMock = jest.fn( () => Promise.resolve( [] ) );

		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon } );

		input.value = 'Тьмутаракань';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		jest.advanceTimersByTime( 250 );
		await flushMicrotasks();

		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await flushMicrotasks();

		expect( onSelect ).not.toHaveBeenCalled();
		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).toHaveBeenCalledWith( { query: 'Тьмутаракань', resolved: true } );
		expect( input.value ).toBe( 'Тьмутаракань' ); // never overwritten — nothing to adopt.
	} );

	test( 'Escape then blur adopts nothing', async () => {
		jest.useFakeTimers();
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const fetchMock = jest.fn( () => Promise.resolve( [ { label: 'Жуковский' } ] ) );

		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon } );

		input.value = 'жук';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		jest.advanceTimersByTime( 250 );
		await flushMicrotasks(); // a real completed result set now exists for 'жук'.

		input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await flushMicrotasks();

		expect( onSelect ).not.toHaveBeenCalled();
		expect( onAbandon ).not.toHaveBeenCalled();
		expect( input.value ).toBe( 'жук' ); // an explicit cancel, never silently overridden.
	} );

	test( 'blur immediately after a real pick adopts nothing', async () => {
		jest.useFakeTimers();
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const fetchMock = jest.fn( () => Promise.resolve( [ { label: 'Жуковский' }, { label: 'Жуков' } ] ) );

		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon } );

		input.value = 'жук';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		jest.advanceTimersByTime( 250 );
		await flushMicrotasks();

		input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowDown', bubbles: true } ) );
		input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } ) ); // a real pick.

		expect( onSelect ).toHaveBeenCalledTimes( 1 );

		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await flushMicrotasks();

		expect( onSelect ).toHaveBeenCalledTimes( 1 ); // still one — blur decided there was nothing left to do.
		expect( onAbandon ).not.toHaveBeenCalled();
		expect( input.value ).toBe( 'Жуковский' );
	} );

	test( 'blur while the debounce is still pending flushes it and then adopts', async () => {
		jest.useFakeTimers();
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const fetchMock = jest.fn( () => Promise.resolve( [ { label: 'Жуковский' } ] ) );

		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon } );

		input.value = 'жук';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		// Tabs away inside the 250ms debounce window — the commonest real trigger for this bug
		// (operator's own framing): nothing has fired yet, so a strict "already completed" check
		// with no flush would adopt nothing and the bug would survive unfixed.
		expect( fetchMock ).not.toHaveBeenCalled();

		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await flushMicrotasks();

		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
		expect( fetchMock ).toHaveBeenCalledWith( 'жук' );
		expect( onSelect ).toHaveBeenCalledWith( { label: 'Жуковский' } );
		expect( input.value ).toBe( 'Жуковский' );

		// The debounce timer must actually have been cancelled, not merely raced — advancing
		// past its original window fires nothing a second time.
		jest.advanceTimersByTime( 250 );
		await flushMicrotasks();
		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'blur while a fetch is already in flight (debounce already fired) chains onto it', async () => {
		jest.useFakeTimers();
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const pending = deferred();
		const fetchMock = jest.fn( () => pending.promise );

		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon } );

		input.value = 'жук';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		jest.advanceTimersByTime( 250 ); // the debounce fires — fetch is now in flight, unresolved.

		expect( fetchMock ).toHaveBeenCalledTimes( 1 );

		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );

		pending.resolve( [ { label: 'Жуковский' } ] );
		await flushMicrotasks();

		// Chained onto the SAME in-flight fetch — never a redundant second call.
		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
		expect( onSelect ).toHaveBeenCalledWith( { label: 'Жуковский' } );
		expect( input.value ).toBe( 'Жуковский' );
	} );

	test( 'a blank input never adopts or abandons', async () => {
		jest.useFakeTimers();
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const fetchMock = jest.fn( () => Promise.resolve( [] ) );

		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon } );

		input.value = '';
		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await flushMicrotasks();

		expect( onSelect ).not.toHaveBeenCalled();
		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	test( 'text below minChars never ADOPTS, even if it exactly matches a previously completed query — but it DOES report (FIX 1)', async () => {
		// Superseded 17.08.2026 (FIX 1): a below-minChars blur used to report nothing at all,
		// which was itself the dead end — a customer whose real settlement name is shorter than
		// minChars had no fetch to ever complete, so no way to ever clear #337's address lock.
		// It still never ADOPTS (nothing was ever asked), but it now DOES report resolved: false.
		jest.useFakeTimers();
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const fetchMock = jest.fn( () => Promise.resolve( [] ) );

		// minChars defaults to 2 — a single character is never eligible for a search at all.
		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon, minChars: 2 } );

		input.value = 'ж';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await flushMicrotasks();

		expect( fetchMock ).not.toHaveBeenCalled();
		expect( onSelect ).not.toHaveBeenCalled();
		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).toHaveBeenCalledWith( { query: 'ж', resolved: false } );
	} );

	test( 'no onAbandon option supplied — behaviour is byte-identical to today: blur just closes', async () => {
		jest.useFakeTimers();
		const onSelect = jest.fn();
		// Note: NO onAbandon in the options object below.
		const fetchMock = jest.fn( () => Promise.resolve( [] ) );

		attachTypeahead( input, { fetch: fetchMock, onSelect } );

		input.value = 'Тьмутаракань';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		jest.advanceTimersByTime( 250 );
		await flushMicrotasks();

		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await flushMicrotasks();

		expect( listboxOf().hidden ).toBe( true );
		expect( onSelect ).not.toHaveBeenCalled();
		expect( input.value ).toBe( 'Тьмутаракань' ); // untouched — no auto-adopt logic ever ran.
	} );

	// -----------------------------------------------------------------------
	// FIX 1 (P0, #350 follow-up): below-minChars is no longer a dead end
	// -----------------------------------------------------------------------
	//
	// A below-minChars blur used to just closeListbox() and stop — no fetch ever ran, so
	// onAbandon() never fired and a customer whose real settlement name is shorter than
	// minChars had no way to ever clear #337's address lock. It must now still REPORT
	// (resolved: false — nothing was ever asked), while still never ADOPTING (there is
	// nothing to adopt).

	test( 'a below-minChars, non-blank blur reports onAbandon with resolved: false, never onSelect', async () => {
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const fetchMock = jest.fn( () => Promise.resolve( [ { label: 'should never be called' } ] ) );

		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon, minChars: 3 } );

		input.value = 'жк'; // 2 chars, below minChars: 3
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await flushMicrotasks();

		expect( fetchMock ).not.toHaveBeenCalled();
		expect( onSelect ).not.toHaveBeenCalled();
		expect( onAbandon ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).toHaveBeenCalledWith( { query: 'жк', resolved: false } );
		expect( input.value ).toBe( 'жк' ); // never overwritten
		expect( listboxOf().hidden ).toBe( true );
	} );

	test( 'a blank blur still reports nothing at all, even below minChars', async () => {
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const fetchMock = jest.fn( () => Promise.resolve( [] ) );

		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon, minChars: 3 } );

		input.value = '';
		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await flushMicrotasks();

		expect( onSelect ).not.toHaveBeenCalled();
		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	// -----------------------------------------------------------------------
	// FIX 2 (P1): ensureCompletedResults() must not chain onto the WRONG in-flight fetch
	// -----------------------------------------------------------------------
	//
	// `inFlight` names the most RECENTLY ISSUED fetch, which is not necessarily one for the
	// CURRENT text — the DOM value can change with no `input` event of this module's own (a
	// silent programmatic write). Chaining onto a stale in-flight fetch there used to make
	// the widget go silent (no adopt, no onAbandon) for the query the customer actually blurs
	// on. A blur must resolve the CURRENT text, never fall silent.

	test( 'blur resolves the CURRENT text when an OLDER query is still in flight and no debounce is live', async () => {
		jest.useFakeTimers();
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const older = deferred();
		const newer = deferred();
		const fetchMock = jest.fn()
			.mockImplementationOnce( () => older.promise )
			.mockImplementationOnce( () => newer.promise );

		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon } );

		// The older query's debounce fires — its fetch is now in flight, unresolved.
		input.value = 'жук';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		jest.advanceTimersByTime( 250 );
		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
		expect( fetchMock ).toHaveBeenNthCalledWith( 1, 'жук' );

		// The DOM value now holds newer text, but WITHOUT dispatching this module's own
		// `input` event — mirrors location-cascade.js's writeSilently() (backwards fill /
		// the pickup-address-replacing announcement), which is exactly how this state arises
		// for real. No debounce is live for this newer text at all.
		input.value = 'жуковский';

		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await flushMicrotasks();

		// A SECOND, explicit fetch for the CURRENT text — never chained onto the stale one.
		expect( fetchMock ).toHaveBeenCalledTimes( 2 );
		expect( fetchMock ).toHaveBeenNthCalledWith( 2, 'жуковский' );

		newer.resolve( [ { label: 'Жуковский' } ] );
		await flushMicrotasks();

		expect( onSelect ).toHaveBeenCalledWith( { label: 'Жуковский' } );
		expect( input.value ).toBe( 'Жуковский' );

		// The stale older fetch finishing afterwards changes nothing further.
		older.resolve( [ { label: 'stale, never adopted' } ] );
		await flushMicrotasks();

		expect( onSelect ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).not.toHaveBeenCalled();
	} );

	// Note: the matching-in-flight-query case (`inFlight.query === query`, still chained onto
	// rather than re-fetched) is already covered by the pre-existing 'blur while a fetch is
	// already in flight (debounce already fired) chains onto it' test above — FIX 2 narrows
	// that same branch's condition without changing its outcome, so no separate case is added
	// here for it.

	// -----------------------------------------------------------------------
	// FIX 3 (P2): a guard re-runs AFTER the blur continuation's async gap
	// -----------------------------------------------------------------------
	//
	// ensureCompletedResults() deliberately does not closeListbox() before chaining/flushing
	// (that would bump generation and orphan the very fetch it wants to chain onto), so the
	// listbox can stay visibly open through the whole await. If something else — a real pick
	// through that still-open listbox — resolves DURING the gap, the continuation must not
	// then double-act on top of it.

	test( 'a real pick that lands before the (still-pending) blur continuation resolves is never overwritten', async () => {
		jest.useFakeTimers();
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const pending = deferred();
		const fetchMock = jest.fn( () => pending.promise );

		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon } );

		input.value = 'жук';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		jest.advanceTimersByTime( 250 ); // fetch in flight, unresolved — ensureCompletedResults() will chain onto it.

		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );

		// Resolve the in-flight fetch. This settles in TWO microtask steps: (1) runFetch()'s
		// own success handler renders the listbox and records lastCompletedQuery/Items, which
		// (2) then resolves ensureCompletedResults()'s own promise, only AFTER which the blur
		// continuation's `.then()` is even scheduled. One `await` tick lands exactly between
		// (1) and (2) — the listbox is populated, but the blur continuation has not run yet.
		pending.resolve( [ { label: 'Жуковский' }, { label: 'Жуков' } ] );
		await Promise.resolve();

		expect( listboxOf().children.length ).toBe( 2 ); // renderItems() already ran.

		// A real mouse pick on the SECOND option happens NOW — strictly before the blur
		// continuation (still queued, not yet run) gets to auto-adopt item 0.
		listboxOf().children[ 1 ].dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );

		expect( onSelect ).toHaveBeenCalledTimes( 1 );
		expect( onSelect ).toHaveBeenCalledWith( { label: 'Жуков' } );
		expect( input.value ).toBe( 'Жуков' );

		await flushMicrotasks(); // now let the blur continuation itself run.

		// The blur continuation must find `input.value !== query` ('Жуков' !== 'жук') and do
		// nothing at all — never overwriting the real pick with its own auto-adopt of item 0.
		expect( onSelect ).toHaveBeenCalledTimes( 1 );
		expect( onAbandon ).not.toHaveBeenCalled();
		expect( input.value ).toBe( 'Жуков' );
	} );

	test( 'an async adopt landing after a consumer already reacted to the abandoned text supersedes it harmlessly', async () => {
		// Documents the ordering the file docblock relies on: a real browser fires a native
		// `change` on blur BEFORE this module's own `blur` listener runs, so an outside consumer
		// (location-cascade.js's handleFieldChanged()) already sees the raw, unresolved text by
		// the time this module's async adopt decision even starts. The LATER selectItem() this
		// module runs on adopt supersedes that first, premature read exactly like a slightly
		// slower human click would — same write, same input/change dispatch. If this stops being
		// true (e.g. a future change makes the adopt run BEFORE the consumer's own `change`
		// handling), this test starts failing and should be looked at.
		jest.useFakeTimers();
		const onSelect = jest.fn();
		const onAbandon = jest.fn();
		const fetchMock = jest.fn( () => Promise.resolve( [ { label: 'Жуковский' } ] ) );
		const seenAtChange = [];

		input.addEventListener( 'change', () => seenAtChange.push( input.value ) );

		attachTypeahead( input, { fetch: fetchMock, onSelect, onAbandon } );

		input.value = 'жук';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		jest.advanceTimersByTime( 250 );
		await flushMicrotasks();

		// Mirrors what a real browser (and this module's own blur handler) does: the native
		// `change` a text edit produces fires BEFORE `blur`.
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
		await flushMicrotasks();

		// First `change` seen was the customer's own raw, unresolved text — exactly what a
		// consumer's own "typed but not picked" handling reacts to. The second is this module's
		// OWN synthetic dispatch from selectItem(), carrying the adopted value — the correction.
		expect( seenAtChange ).toEqual( [ 'жук', 'Жуковский' ] );
		expect( input.value ).toBe( 'Жуковский' );
	} );
} );
