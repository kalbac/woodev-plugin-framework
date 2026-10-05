/**
 * Checkout Blocks registration (SP-11 C-1 #1087, C-2b #1089): registers the framework's FORCED inner
 * blocks (operator decision D-2 A) — the locality chooser under the shipping address, the
 * pickup-point button under the shipping methods. The merchant does nothing: WooCommerce adds a
 * forced block to the checkout on the frontend even when the saved page does not contain it, and
 * renders it ONCE when the saved page already does.
 *
 * Feature-detected (D-5 A): without WooCommerce's `registerCheckoutBlock`, or when the server
 * published no data for a block (the location layer is inactive; no carrier has a pickup field),
 * that block is not registered and the native checkout works alone.
 *
 * `block.json` is the single source of each block's metadata — the same file the server registers,
 * whose `parent` WooCommerce reads.
 *
 * @package woodev-plugin-framework
 */

import localityMetadata from '../../woodev/shipping-method/checkout/blocks/shipping-locality/block.json';
import localityBillingMetadata from '../../woodev/shipping-method/checkout/blocks/shipping-locality-billing/block.json';
import pickupMetadata from '../../woodev/shipping-method/checkout/blocks/shipping-pickup/block.json';
import { LocalityChooser } from './locality-chooser';
import { PickupPicker } from './pickup-picker';
import type { CheckoutExtensionData } from './pickup-picker';
import { readPickupData } from './pickup-stores';
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
		metadata: localityMetadata as unknown as Record< string, unknown >,
		component: () => <LocalityChooser config={ config } addressTarget="shipping" />,
		force: true,
	} );
	const getSetting = wcRuntime()?.wcSettings?.getSetting;
	const forcedBillingAddress = typeof getSetting === 'function' && getSetting< boolean >( 'forcedBillingAddress', false ) === true;

	if ( config.billingOnly === true && forcedBillingAddress ) {
		register( {
			metadata: localityBillingMetadata as unknown as Record< string, unknown >,
			component: () => <LocalityChooser config={ config } addressTarget="billing" />,
			force: true,
		} );
	}

	return true;
}

export function registerPickupBlock(): boolean {
	const register = wcRuntime()?.blocksCheckout?.registerCheckoutBlock;
	const data = readPickupData();

	if (
		typeof register !== 'function' ||
		! data ||
		! data.enabled ||
		! data.namespace ||
		! Array.isArray( data.fields ) ||
		data.fields.length === 0
	) {
		return false;
	}

	register( {
		metadata: pickupMetadata as unknown as Record< string, unknown >,
		// WooCommerce hands every inner block `checkoutExtensionData` — the request's echo setter.
		component: ( props ) => (
			<PickupPicker data={ data } checkoutExtensionData={ props.checkoutExtensionData as CheckoutExtensionData } />
		),
		force: true,
	} );

	return true;
}
