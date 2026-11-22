/**
 * Whether a row is busy: it has a process of its own in flight.
 *
 * One answer for everything that must stop at once — the full-width bar along the row's bottom edge, the selection
 * checkbox and the action buttons — the way «Заказы доставки» blocks a row whose action is running.
 *
 * @package woodev-plugin-framework
 */

import type { AdminRecord } from '../types';
import type { ProcessState } from './types';

/** A process in these states is still going; `failed` and `completed` are history, not work. */
const IN_FLIGHT: ProcessState[] = [ 'queued', 'running', 'waiting' ];

/**
 * @param row The row.
 */
export function isRowBusy( row: Pick< AdminRecord, 'activity' > ): boolean {
	return ( row.activity?.processes ?? [] ).some( ( process ) => IN_FLIGHT.includes( process.state ) );
}
