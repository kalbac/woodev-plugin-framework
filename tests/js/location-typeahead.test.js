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
