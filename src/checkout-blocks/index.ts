/**
 * Checkout Blocks bundle entry (SP-11 C-1 #1087, C-2b #1089) — runs the registrations; the logic
 * lives in `register.tsx` so it can be tested without executing a module side effect.
 *
 * @package woodev-plugin-framework
 */

import { registerLocalityBlock, registerPickupBlock } from './register';
import { captureWcRuntime } from './wc-runtime';

// First: every later read of a WooCommerce global answers from this capture.
captureWcRuntime();
registerLocalityBlock();
registerPickupBlock();
