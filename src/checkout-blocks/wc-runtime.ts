/**
 * Typed access to the WooCommerce Blocks runtime this bundle consumes off `window.wc`.
 *
 * `@wordpress/dependency-extraction-webpack-plugin` (what `wp-scripts` ships) does not know
 * `@woocommerce/*`, and those packages are not in this repo's `node_modules` — the bundle reads them
 * from the globals WooCommerce exposes behind the `wc-blocks-checkout` and `wc-settings` handles that
 * `Locality_Blocks_Integration` declares by hand. Shapes were read from the installed WooCommerce 11.1
 * (`wc-cart-checkout-base-frontend.js`: `registerCheckoutBlock`), not recalled.
 *
 * A local cast instead of a `Window.wc` augmentation: `src/shipping-orders-page/wc-globals.d.ts`
 * already augments `Window.wc` with the ADMIN runtime, and two augmentations of one property must
 * agree on its type.
 *
 * @package woodev-plugin-framework
 */

import type { ComponentType } from 'react';

export interface RegisterCheckoutBlockOptions {
	metadata: Record< string, unknown >;
	component: ComponentType< Record< string, unknown > >;
	force?: boolean;
}

export interface WcRuntime {
	blocksCheckout?: {
		registerCheckoutBlock?: ( options: RegisterCheckoutBlockOptions ) => void;
	};
	wcSettings?: {
		getSetting?: < T >( name: string, fallback?: T ) => T;
	};
}

export function wcRuntime(): WcRuntime | undefined {
	return ( window as unknown as { wc?: WcRuntime } ).wc;
}
