/**
 * Shipping-zone city limit bundle entry (#1176) — the cities list in the instance form of a shipping method
 * (WooCommerce's zone modal and the method's own page). The logic lives in `mount.tsx` so it can be tested
 * without executing a module side effect.
 *
 * @package woodev-plugin-framework
 */

import { start } from './mount';
import './style.scss';

start();
