/**
 * Checkout Blocks bundle entry (SP-11 C-1 #1087, C-2b #1089) — runs the registrations; the logic
 * lives in `register.tsx` so it can be tested without executing a module side effect.
 *
 * @package woodev-plugin-framework
 */

import { watchPostcodeEdits } from './pickup-stores';
import { readFeePaymentsNamespace, watchPaymentMethod } from './payment-recalc';
import { registerLocalityBlock, registerPickupBlock } from './register';
import { captureWcRuntime } from './wc-runtime';
import { resolveCartFromServer } from './wc-stores';
import { watchBlockAddressSuggestions } from './address-suggestions';
import '../../woodev/shipping-method/assets/js/frontend/location-typeahead.js';

// First: every later read of a WooCommerce global answers from this capture.
captureWcRuntime();

const locality = registerLocalityBlock();
const pickup = registerPickupBlock();
watchBlockAddressSuggestions();

// Both blocks render from the cart store; a checkout neither is on is left to core as it is (#1111).
if ( locality || pickup ) {
	resolveCartFromServer();
}

// From before the form can be typed in: whose postcode it is, is asked at the next point (#1113).
if ( pickup ) {
	watchPostcodeEdits();
}

// A fee limited to chosen payment methods needs the server to know the chosen one (#1144); off unless the server says so.
const feePaymentsNamespace = readFeePaymentsNamespace();

if ( feePaymentsNamespace !== null ) {
	watchPaymentMethod( feePaymentsNamespace );
}
