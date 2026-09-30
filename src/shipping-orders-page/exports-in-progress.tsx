/**
 * «Сейчас выгружаются N заказов перевозчику» (#1007): an info notice above the orders table
 * while the background export queue still holds orders.
 *
 * The figure is inlined at page load and kept current by the WordPress heartbeat: each beat
 * sends `data[ heartbeatKey ] = true`, the server answers with `{ count, text }`. `text` is the
 * FINISHED sentence, plural included — JS translations are not loaded in this project, so
 * nothing here words or pluralises anything.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import type { HeartbeatData, HeartbeatJqueryFactory } from './order-wizard/order-wizard';
import { getExportsInProgress } from './rest';
import type { ExportsInProgress } from './rest';

/** The heartbeat handlers' jQuery namespace — `.off()` by it never touches the page's own listeners. */
const NAMESPACE = 'woodevExportsInProgress';

/** What the server answers with on a beat; anything else is ignored. */
interface ExportsTick {
	count: number;
	text: string;
}

function isTick( value: unknown ): value is ExportsTick {
	const tick = value as Partial<ExportsTick> | null | undefined;

	return (
		!! tick &&
		'object' === typeof tick &&
		'number' === typeof tick.count &&
		Number.isFinite( tick.count ) &&
		tick.count >= 0 &&
		'string' === typeof tick.text
	);
}

/**
 * The current background-export figure, or `null` when the page carries none (no bootstrap
 * object) — then no heartbeat handler is registered either.
 */
export function useExportsInProgress(): ExportsInProgress | null {
	const [ state, setState ] = useState< ExportsInProgress | null >( getExportsInProgress );
	const heartbeatKey = state ? state.heartbeatKey : null;

	useEffect( () => {
		if ( ! heartbeatKey ) {
			return undefined;
		}

		const jquery = ( window as Window & { jQuery?: HeartbeatJqueryFactory } ).jQuery;

		if ( ! jquery ) {
			return undefined;
		}

		const documentHeartbeat = jquery( document );
		const send = ( event: unknown, data: HeartbeatData ) => {
			data[ heartbeatKey ] = true;
		};
		const tick = ( event: unknown, data: HeartbeatData ) => {
			const answer = data ? data[ heartbeatKey ] : undefined;

			if ( ! isTick( answer ) ) {
				return;
			}

			setState( ( current ) => ( current ? { ...current, count: answer.count, text: answer.text } : current ) );

			if ( answer.count > 0 ) {
				// Follow the queue closely while it drains; WordPress falls back on its own when the tab idles.
				( window as Window & { wp?: { heartbeat?: { interval?: ( speed: string ) => unknown } } } ).wp?.heartbeat?.interval?.( 'fast' );
			}
		};

		documentHeartbeat.on( `heartbeat-send.${ NAMESPACE }`, send );
		documentHeartbeat.on( `heartbeat-tick.${ NAMESPACE }`, tick );

		return () => {
			documentHeartbeat.off( `heartbeat-send.${ NAMESPACE }` );
			documentHeartbeat.off( `heartbeat-tick.${ NAMESPACE }` );
		};
	}, [ heartbeatKey ] );

	return state;
}

/** The notice itself — the server's sentence verbatim, no link, no button. */
export function ExportsInProgressNotice() {
	const exportsInProgress = useExportsInProgress();

	if ( ! exportsInProgress || exportsInProgress.count <= 0 ) {
		return null;
	}

	return (
		<Notice status="info" isDismissible={ false } className="woodev-orders-exports-notice">
			{ exportsInProgress.text }
		</Notice>
	);
}
