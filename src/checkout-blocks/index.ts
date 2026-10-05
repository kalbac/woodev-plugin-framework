/**
 * Checkout Blocks bundle entry (SP-11 C-1 #1087, C-2b #1089) — runs the registrations; the logic
 * lives in `register.tsx` so it can be tested without executing a module side effect.
 *
 * @package woodev-plugin-framework
 */

import { watchPostcodeEdits } from './pickup-stores';
import { registerLocalityBlock, registerPickupBlock } from './register';
import { captureWcRuntime } from './wc-runtime';
import { resolveCartFromServer } from './wc-stores';

// First: every later read of a WooCommerce global answers from this capture.
captureWcRuntime();

const locality = registerLocalityBlock();
const pickup = registerPickupBlock();

// Both blocks render from the cart store; a checkout neither is on is left to core as it is (#1111).
if ( locality || pickup ) {
	resolveCartFromServer();
}

// From before the form can be typed in: whose postcode it is, is asked at the next point (#1113).
if ( pickup ) {
	watchPostcodeEdits();
}
