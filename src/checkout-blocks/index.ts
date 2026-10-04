/**
 * Checkout Blocks bundle entry (SP-11 C-1, #1087) — runs the registration; the logic lives in
 * `register.tsx` so it can be tested without executing a module side effect.
 *
 * @package woodev-plugin-framework
 */

import { registerLocalityBlock } from './register';

registerLocalityBlock();
