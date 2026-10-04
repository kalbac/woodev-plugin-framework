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

/** A registered payment method, as far as this bundle reads it (`wc-blocks-registry.js`). */
export interface PaymentRegistration {
	/** The server gateway's id; WooCommerce defaults it to the registration's `name`. */
	paymentMethodId?: string;
}

export interface WcRuntime {
	blocksCheckout?: {
		registerCheckoutBlock?: ( options: RegisterCheckoutBlockOptions ) => void;
		/**
		 * `POST /wc/store/v1/cart/extensions` — runs the namespace's server callback and takes the
		 * recalculated cart into `wc/store/cart`. Resolves with that cart, rejects with the Store
		 * API's error object (`wc-cart-checkout-base-frontend.js`: `extensionCartUpdate`).
		 */
		extensionCartUpdate?: ( args: { namespace: string; data: unknown } ) => Promise< unknown >;
	};
	/** The public payment registry, keyed by registration `name` (the `wc-blocks-registry` handle). */
	wcBlocksRegistry?: {
		getPaymentMethods?: () => Record< string, PaymentRegistration | undefined >;
		getExpressPaymentMethods?: () => Record< string, PaymentRegistration | undefined >;
	};
	wcSettings?: {
		getSetting?: < T >( name: string, fallback?: T ) => T;
	};
}

/** The globals as they stood when the bundle was evaluated — see {@link captureWcRuntime}. */
let captured: WcRuntime | undefined;

/**
 * Takes the WooCommerce globals this bundle consumes ONCE, while the bundle's own script is being
 * evaluated; every later {@link wcRuntime} read answers from that capture.
 *
 * Why: WooCommerce (11.1, `wc-dependency-detection`, under `SCRIPT_DEBUG`) wraps `window.wc` in a
 * proxy that names the script behind every read of an exported key and checks its declared
 * dependencies. During evaluation it knows the script from `document.currentScript` and finds this
 * bundle's handle with its dependencies declared. For a LATER read — a render, a promise callback —
 * it walks an error stack instead, and the proxy re-wraps itself each time a script assigns
 * `window.wc`: measured on the rig, nine nested `get` traps fill V8's ten-frame stack, the caller
 * is never found, and the store's console reports «an inline or unknown script accessed
 * wc.wcSettings without proper dependency declaration» for a dependency that IS declared.
 *
 * The three keys are the handles `Locality_Blocks_Integration` declares, so each is in place by now.
 * Called with no `window.wc` it forgets the capture, and reads are live again.
 */
export function captureWcRuntime(): void {
	const wc = ( window as unknown as { wc?: WcRuntime } ).wc;

	captured = wc
		? { blocksCheckout: wc.blocksCheckout, wcBlocksRegistry: wc.wcBlocksRegistry, wcSettings: wc.wcSettings }
		: undefined;
}

export function wcRuntime(): WcRuntime | undefined {
	return captured ?? ( window as unknown as { wc?: WcRuntime } ).wc;
}
