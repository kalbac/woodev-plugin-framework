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
