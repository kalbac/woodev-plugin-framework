/**
 * #1279: the Place Order press WooCommerce core swallows — the button is disabled between the
 * mousedown and the mouseup by core's own address push — is carried out once, when the button is
 * enabled again. Measured on a clean WooCommerce 11.2.1; the browser sequence is reproduced here.
 */
import {
	guardPlaceOrderPresses,
	PLACE_ORDER_SELECTOR,
	RESUBMIT_WINDOW_MS,
} from '../../src/checkout-blocks/place-order-guard';

const RECT = { left: 100, right: 300, top: 500, bottom: 548, width: 200, height: 48, x: 100, y: 500, toJSON: () => ( {} ) };
const INSIDE = { clientX: 200, clientY: 520 };
const OUTSIDE = { clientX: 20, clientY: 20 };

let stop: () => void = () => undefined;
let clicks: number;

function placeOrderButton(): HTMLButtonElement {
	return document.querySelector( PLACE_ORDER_SELECTOR ) as HTMLButtonElement;
}

function mount(): void {
	document.body.innerHTML =
		'<div class="wc-block-checkout__actions_row">' +
		'<button type="button" class="wc-block-components-button wc-block-components-checkout-place-order-button">' +
		'<div class="wc-block-components-checkout-place-order-button__text">Place order</div></button></div>' +
		'<input id="billing-postcode">';
	placeOrderButton().getBoundingClientRect = () => RECT as DOMRect;
	placeOrderButton().addEventListener( 'click', () => clicks++ );
}

const press = ( target: Element, event: 'mousedown' | 'mouseup', at = INSIDE, button = 0 ): void => {
	target.dispatchEvent( new MouseEvent( event, { bubbles: true, cancelable: true, button, ...at } ) );
};

/** Core's focusout push turns the checkout «calculating» between the press and the release. */
function swallowedPress( at = INSIDE ): void {
	press( placeOrderButton().firstElementChild as Element, 'mousedown' );
	placeOrderButton().disabled = true;
	press( document.querySelector( '.wc-block-checkout__actions_row' ) as Element, 'mouseup', at );
}

/** …and core's recalculation answers. */
async function coreAnswers(): Promise< void > {
	placeOrderButton().disabled = false;
	await Promise.resolve(); // MutationObserver callbacks run as a microtask
}

beforeEach( () => {
	jest.useFakeTimers();
	clicks = 0;
	mount();
	stop = guardPlaceOrderPresses( document );
} );

afterEach( () => {
	stop();
	jest.useRealTimers();
	document.body.innerHTML = '';
} );

describe( 'guardPlaceOrderPresses (#1279)', () => {
	it( 'carries out the swallowed press once the button is enabled again', async () => {
		swallowedPress();

		expect( clicks ).toBe( 0 );

		await coreAnswers();

		expect( clicks ).toBe( 1 );

		// Once: later flips of the button do not place the order again.
		placeOrderButton().disabled = true;
		await coreAnswers();

		expect( clicks ).toBe( 1 );
	} );

	it( 'waits through a re-render that replaces the button', async () => {
		swallowedPress();

		const replacement = placeOrderButton().cloneNode( true ) as HTMLButtonElement;

		replacement.disabled = false;
		replacement.addEventListener( 'click', () => clicks++ );
		placeOrderButton().replaceWith( replacement );
		await Promise.resolve();

		expect( clicks ).toBe( 1 );
	} );

	it( 'never repeats a click that reached the button', async () => {
		press( placeOrderButton(), 'mousedown' );
		press( placeOrderButton(), 'mouseup' );
		placeOrderButton().click();
		placeOrderButton().disabled = true;
		await coreAnswers();

		expect( clicks ).toBe( 1 );
	} );

	it( 'ignores a press that started while the button was already disabled', async () => {
		placeOrderButton().disabled = true;
		press( placeOrderButton(), 'mousedown' );
		press( placeOrderButton(), 'mouseup' );
		await coreAnswers();

		expect( clicks ).toBe( 0 );
	} );

	it( 'ignores a release away from the button — the shopper dragged off it', async () => {
		swallowedPress( OUTSIDE );
		await coreAnswers();

		expect( clicks ).toBe( 0 );
	} );

	it( 'ignores the other mouse buttons', async () => {
		press( placeOrderButton(), 'mousedown', INSIDE, 2 );
		placeOrderButton().disabled = true;
		press( placeOrderButton(), 'mouseup', INSIDE, 2 );
		await coreAnswers();

		expect( clicks ).toBe( 0 );
	} );

	it.each( [
		[ 'a press elsewhere', () => press( document.getElementById( 'billing-postcode' ) as Element, 'mousedown', OUTSIDE ) ],
		[ 'a key', () => document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'a' } ) ) ],
		[ 'a touch', () => document.dispatchEvent( new Event( 'touchstart' ) ) ],
	] )( 'drops the press when the shopper does something else first: %s', async ( _label, act ) => {
		swallowedPress();
		act();
		await coreAnswers();

		expect( clicks ).toBe( 0 );
	} );

	it( 'drops a press the checkout did not get back to in time', async () => {
		swallowedPress();
		jest.advanceTimersByTime( RESUBMIT_WINDOW_MS + 1 );
		await coreAnswers();

		expect( clicks ).toBe( 0 );
	} );

	it( 'stops watching when asked', async () => {
		stop();
		swallowedPress();
		await coreAnswers();

		expect( clicks ).toBe( 0 );
	} );
} );
