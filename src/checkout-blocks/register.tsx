/**
 * Checkout Blocks registration (SP-11 C-1, #1087): registers the locality chooser as a FORCED inner block of
 * the shipping address block (operator decision D-2 A). The merchant does nothing: WooCommerce adds
 * the block to the checkout on the frontend even when the saved page does not contain it, and renders
 * it ONCE when the saved page already does.
 *
 * Feature-detected (D-5 A): without WooCommerce's `registerCheckoutBlock`, or when the server
 * published no data (the location layer is inactive), nothing is registered and the native address
 * form works alone.
 *
 * `block.json` is the single source of the block's metadata — the same file `Locality_Blocks`
 * registers on the server, whose `parent` WooCommerce reads.
 *
 * @package woodev-plugin-framework
 */

import metadata from '../../woodev/shipping-method/checkout/blocks/shipping-locality/block.json';
import { LocalityChooser } from './locality-chooser';
import { readLocalityData } from './wc-stores';
import { wcRuntime } from './wc-runtime';
import './style.scss';

export function registerLocalityBlock(): boolean {
	const register = wcRuntime()?.blocksCheckout?.registerCheckoutBlock;
	const data = readLocalityData();

	if ( typeof register !== 'function' || ! data || ! data.enabled || ! data.location ) {
		return false;
	}

	const config = data.location;

	register( {
		metadata: metadata as unknown as Record< string, unknown >,
		component: () => <LocalityChooser config={ config } />,
		force: true,
	} );

	return true;
}
