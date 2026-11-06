/**
 * City mode takes over WooCommerce's native City and State rows on Checkout Blocks (range-shipping
 * city mode spec §4.2): the place is chosen (or shown) in the framework's own control, and the
 * native rows only carry its values.
 *
 * A row is hidden by the `woodev-field--hidden` class, never removed: Blocks submits the cart
 * store's address, not the DOM, and a mounted field keeps its own validation satisfied by the value
 * the store holds. React may replace the rows at any time, so the class is re-applied on every
 * change of the page.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef, useState } from '@wordpress/element';

const HIDDEN_CLASS = 'woodev-field--hidden';

export interface NativeRowsToHide {
	city: boolean;
	state: boolean;
}

/** The row Blocks wraps a native address field in. */
function rowOf( input: HTMLElement | null, key: 'city' | 'state' ): HTMLElement | null {
	return input?.closest< HTMLElement >( `.wc-block-components-address-form__${ key }` ) ?? input?.parentElement ?? null;
}

/**
 * Hides the native City and/or State row of the delivery address while the caller says so.
 *
 * @returns The native City field's own label (the merchant's wording — «Город», «Населённый
 *          пункт»), or `''` while the row is not on the page: the caller's control reuses it.
 */
export function useHiddenNativeRows( billingOnly: boolean, hide: NativeRowsToHide ): string {
	const [ cityLabel, setCityLabel ] = useState( '' );
	const lastLabel = useRef( '' );

	useEffect( () => {
		// Nothing of ours to hide: the page is not watched at all.
		if ( ! hide.city && ! hide.state ) {
			return undefined;
		}

		const prefix = billingOnly ? 'billing' : 'shipping';
		const touched: Array< () => void > = [];

		const hideRow = ( input: HTMLElement | null, key: 'city' | 'state' ): void => {
			const row = rowOf( input, key );

			if ( row && ! row.classList.contains( HIDDEN_CLASS ) ) {
				row.classList.add( HIDDEN_CLASS );
				touched.push( () => row.classList.remove( HIDDEN_CLASS ) );
			}
		};

		const apply = (): void => {
			const city = document.getElementById( `${ prefix }-city` );

			const label = document.querySelector( `label[for="${ prefix }-city"]` )?.textContent?.trim() ?? '';

			// A state update only when the label really changed: the observer fires on every change of the page.
			if ( label !== lastLabel.current ) {
				lastLabel.current = label;
				setCityLabel( label );
			}

			if ( hide.city ) {
				hideRow( city, 'city' );
			}

			if ( hide.state ) {
				hideRow( document.getElementById( `${ prefix }-state` ), 'state' );
			}
		};

		apply();

		const observer = new MutationObserver( apply );
		observer.observe( document.body, { childList: true, subtree: true } );

		return () => {
			observer.disconnect();
			touched.forEach( ( undo ) => undo() );
		};
	}, [ billingOnly, hide.city, hide.state ] );

	return cityLabel;
}
