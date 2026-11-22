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

/**
 * The ids a bulk action may take: a row that started a process after it was ticked is still ticked, but it is blocked now.
 *
 * @param ids  The ticked ids.
 * @param rows Every row on screen, children included.
 */
export function withoutBusyRows( ids: string[], rows: AdminRecord[] ): string[] {
	const busy = new Set( rows.filter( isRowBusy ).map( ( row ) => row.id ) );

	return ids.filter( ( id ) => ! busy.has( id ) );
}
