/**
 * Block checkout: a Place Order press that WooCommerce core swallows is carried out once the
 * checkout is ready again (#1279).
 *
 * MEASURED ON A CLEAN WOOCOMMERCE 11.2.1 (no plugin of ours active), 12 of 12: the shopper edits the
 * postcode or the city and presses Place Order straight away. The mousedown takes focus from the
 * field; core's `focusout` listener (`wc-blocks-data.js`) pushes the unsent address at once, and
 * because those fields can change the rates the push turns the checkout «calculating» — the button
 * is `disabled` ~8 ms later, before the mouseup. The browser then delivers the click to the
 * button's ancestor, not to the button: nothing happens, no message. The second press works.
 *
 * The guard repeats exactly that one lost press, and nothing else:
 *
 * - a primary-button press that STARTED on the enabled button and ENDED over it, with the button
 *   disabled in between — the click that would have placed the order never reached it;
 * - carried out with `click()` the moment the button is enabled again, which is the moment core's
 *   own recalculation is done — so the order is placed against the same address it would have
 *   been placed against had the click not been lost;
 * - dropped if the shopper does anything else first (a press, a key, a touch), or after
 *   {@link RESUBMIT_WINDOW_MS}.
 *
 * A click that reached the button, a keyboard activation and a press that started while the
 * button was already disabled are never repeated.
 *
 * @package woodev-plugin-framework
 */

/** WooCommerce's Place Order button in the Checkout block. */
export const PLACE_ORDER_SELECTOR = '.wc-block-components-checkout-place-order-button';

/**
 * How long a swallowed press waits for the button to be enabled again. A carrier rate can take
 * several seconds to recalculate; a press older than this is not the shopper's current intent.
 */
export const RESUBMIT_WINDOW_MS = 10_000;

/**
 * Starts watching Place Order presses on `doc`. Answers the function that stops it and drops a
 * press still waiting.
 */
export function guardPlaceOrderPresses( doc: Document = document ): () => void {
	const view = doc.defaultView;

	if ( ! view || typeof view.MutationObserver !== 'function' ) {
		return () => undefined;
	}

	let pressed = false;
	let observer: MutationObserver | null = null;
	let timer: ReturnType< typeof setTimeout > | null = null;

	const button = (): HTMLButtonElement | null => doc.querySelector< HTMLButtonElement >( PLACE_ORDER_SELECTOR );
	const onButton = ( target: EventTarget | null ): boolean =>
		target instanceof view.Element && target.closest( PLACE_ORDER_SELECTOR ) !== null;
	const over = ( element: Element, event: MouseEvent ): boolean => {
		const rect = element.getBoundingClientRect();

		return event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom;
	};

	const drop = (): void => {
		observer?.disconnect();
		observer = null;

		if ( timer !== null ) {
			clearTimeout( timer );
			timer = null;
		}
	};

	const resubmitWhenEnabled = (): void => {
		const ready = button();

		if ( ready && ! ready.disabled ) {
			drop();
			ready.click();
		}
	};

	const wait = (): void => {
		drop();
		observer = new view.MutationObserver( resubmitWhenEnabled );
		// The attribute flip, and a re-render that replaces the button.
		observer.observe( doc.body, { attributes: true, attributeFilter: [ 'disabled' ], childList: true, subtree: true } );
		timer = setTimeout( drop, RESUBMIT_WINDOW_MS );
	};

	const onMouseDown = ( event: MouseEvent ): void => {
		// Anything the shopper presses next is their newer word, a press on the button included.
		drop();
		pressed = event.button === 0 && onButton( event.target ) && button()?.disabled === false;
	};

	const onMouseUp = ( event: MouseEvent ): void => {
		const started = pressed;
		const target = button();

		pressed = false;

		// The browser clicks the button unless it was disabled between the press and the release.
		if ( started && event.button === 0 && target && target.disabled && over( target, event ) ) {
			wait();
		}
	};

	const onOtherInput = (): void => {
		pressed = false;
		drop();
	};

	doc.addEventListener( 'mousedown', onMouseDown, true );
	doc.addEventListener( 'mouseup', onMouseUp, true );
	doc.addEventListener( 'keydown', onOtherInput, true );
	doc.addEventListener( 'touchstart', onOtherInput, true );

	return () => {
		drop();
		doc.removeEventListener( 'mousedown', onMouseDown, true );
		doc.removeEventListener( 'mouseup', onMouseUp, true );
		doc.removeEventListener( 'keydown', onOtherInput, true );
		doc.removeEventListener( 'touchstart', onOtherInput, true );
	};
}
