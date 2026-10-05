/**
 * Whether the cart's answer — the pickup owner and its locality key — belongs to the address the
 * shopper's form holds NOW (#1110).
 *
 * The owner is the server's answer for the address it LAST received. WooCommerce writes a form edit
 * into its store at once and pushes it to the server later (a 1.5 s debounce, then
 * `/cart/update-customer`), so for that interval — and for ever, when the push fails or never goes
 * out — the owner is the PREVIOUS address's. The store offers no «address the cart answered» to
 * compare with (`getCartData()` and `getCustomerData()` read the same state), so the lifecycle is
 * followed instead:
 *
 * - `settled` — the form's country/state/city is the one the cart last answered (or nothing has
 *   been edited): the owner can be trusted;
 * - `pending` — an edit the cart has not answered, while a push is scheduled (a bounded window after
 *   the last edit — the debounce is not observable through a public selector) or in flight
 *   (`isCustomerDataUpdating`);
 * - `stale` — the edit was never answered: the push failed, was aborted or never went out. The owner
 *   is not this address's, so the locality counts as unresolved until a later reply answers. It is a
 *   state with a way out, not a wait that can outlast the page.
 *
 * «The cart answered» is a cart reply taken into the store while OUR push was in flight, with no
 * API error left in the store (`readCartReply`, `hasCartError`). The answer is credited to the
 * address the push started with, so an edit made during the flight stays unanswered.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import {
	hasCartError,
	isCustomerDataUpdating,
	isShippingAddressAuthoritative,
	readCartReply,
	readShippingAddressKey,
} from './wc-stores';

/**
 * How long after the last address edit a push may still be scheduled: core's 1.5 s debounce
 * (`wc-blocks-data.js` 11.1) and a margin for a slow render. The debounce window cannot be observed,
 * so this is the bounded fallback; a push that starts inside it is followed by `isCustomerDataUpdating`.
 */
export const ADDRESS_PUSH_WINDOW_MS = 2500;

export type AddressState = 'settled' | 'pending' | 'stale';

export function useShippingAddressState(): AddressState {
	const key: string = useSelect( ( registrySelect ) => readShippingAddressKey( registrySelect ), [] );
	const authoritative: boolean = useSelect(
		( registrySelect ) => isShippingAddressAuthoritative( registrySelect ),
		[]
	);
	const updating: boolean = useSelect( ( registrySelect ) => isCustomerDataUpdating( registrySelect ), [] );
	const reply: unknown = useSelect( ( registrySelect ) => readCartReply( registrySelect ), [] );
	const failed: boolean = useSelect( ( registrySelect ) => hasCartError( registrySelect ), [] );

	// The address key the cart last answered; `null` until the cart has loaded.
	const [ answered, setAnswered ] = useState< string | null >( null );
	// The address key whose push window has run out, or whose push ended unanswered.
	const [ expired, setExpired ] = useState< string | null >( null );
	// The address key the push in flight started with; `null` while none is.
	const sent = useRef< string | null >( null );
	const lastReply = useRef< unknown >( reply );

	useEffect( () => {
		if ( authoritative && answered === null ) {
			setAnswered( key );
		}
	}, [ authoritative, answered, key ] );

	useEffect( () => {
		const replied = reply !== lastReply.current;

		lastReply.current = reply;

		if ( updating && sent.current === null ) {
			sent.current = key;
		}

		if ( sent.current === null ) {
			return;
		}

		if ( replied && ! failed ) {
			setAnswered( sent.current );
			setExpired( null );
			sent.current = null;
		} else if ( ! updating ) {
			// Ended with no reply, or with a refusal: nothing answered it.
			setExpired( sent.current );
			sent.current = null;
		}
	}, [ updating, reply, failed, key ] );

	const diverged = answered !== null && key !== answered;

	useEffect( () => {
		if ( ! diverged ) {
			return undefined;
		}

		const timer = setTimeout( () => setExpired( key ), ADDRESS_PUSH_WINDOW_MS );

		return () => clearTimeout( timer );
	}, [ diverged, key, answered ] );

	if ( ! diverged ) {
		return 'settled';
	}

	return updating || expired !== key ? 'pending' : 'stale';
}
