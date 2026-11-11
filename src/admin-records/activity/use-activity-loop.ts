/**
 * The refresh loop: ONE per mounted table, covering the rows on screen — expanded children included — at the
 * cadence PHP declared.
 *
 * - It reads local state only (`POST R/activity`), so a read can be repeated, delayed or thrown away freely.
 * - It stops while the page is hidden and reads at once when the page is shown again.
 * - A new list read (another page, query, account or an explicit reload) starts a new generation: the read in
 *   flight is aborted, and if it answers anyway it is dropped. The list rows already carry their activity, so the
 *   loop waits a full interval after the list read instead of asking again at once.
 * - A failed read backs off (cadence × 2ⁿ up to `max_refresh_ms`); after `stale_after_ms` without a good read the
 *   table is marked stale. Staleness only adds PHP's sentence — nothing is enabled because data got old.
 * - Rows are written through `patchRows`, and only the rows nobody else wrote since the read was sent
 *   ({@link Ownership}).
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef } from '@wordpress/element';
import { isAbort } from '../api';
import type { TableHandle } from '../extensions';
import { readActivity } from './api';
import type { Ownership } from './ownership';
import { chunk, delayAfterFailure, delayAfterSuccess, type ActivityLimits } from './schedule';
import type { ActivityState } from './state';
import type { AccountActivity, ActivityResponse } from './types';

function idleAccount(): AccountActivity {
	return { active: false, text: '', blocked_actions: [], observed_at: '' };
}

function isHidden(): boolean {
	return typeof document !== 'undefined' && document.visibilityState === 'hidden';
}

/** Answers of one tick, merged: rows and removals concatenated, the last account line and hint win. */
function merge( answers: ActivityResponse[] ): ActivityResponse {
	const last = answers[ answers.length - 1 ];

	return {
		...last,
		rows: answers.flatMap( ( answer ) => answer.rows ),
		removed_ids: answers.flatMap( ( answer ) => answer.removed_ids ),
	};
}

/**
 * @param table     The table as it is on screen.
 * @param state     Where the account line and the health go.
 * @param ownership Who may write which row.
 */
export function useActivityLoop( table: TableHandle, state: ActivityState, ownership: Ownership ): void {
	const tableRef = useRef( table );
	tableRef.current = table;

	const limits: ActivityLimits | null = table.definition.activity;
	const enabled = limits !== null;
	const refreshMs = limits?.refresh_ms ?? 0;
	const maxRefreshMs = limits?.max_refresh_ms ?? 0;
	const staleAfterMs = limits?.stale_after_ms ?? 0;
	const idLimit = limits?.id_limit ?? 0;
	const accountId = table.context.account_id;
	const apiPath = table.api.path;
	const { epoch } = table;
	// The account line the last list read carried: it is the first one shown, and a newer list read replaces it.
	const listAccount = table.accountActivity;

	useEffect( () => {
		if ( enabled ) {
			state.set( { account: listAccount ?? idleAccount() } );
		}
	}, [ enabled, listAccount, state ] );

	useEffect( () => {
		if ( ! enabled ) {
			return undefined;
		}

		const declared: ActivityLimits = {
			refresh_ms: refreshMs,
			max_refresh_ms: maxRefreshMs,
			stale_after_ms: staleAfterMs,
			id_limit: idLimit,
		};
		let generation = 0;
		let failures = 0;
		let reading = false;
		let timer: ReturnType< typeof setTimeout > | undefined;
		let staleTimer: ReturnType< typeof setTimeout > | undefined;
		let controller: AbortController | undefined;

		const clearTimers = () => {
			clearTimeout( timer );
			timer = undefined;
		};

		const armStaleTimer = () => {
			clearTimeout( staleTimer );
			staleTimer = setTimeout( () => state.set( { stale: true } ), declared.stale_after_ms );
		};

		const stopReading = () => {
			generation++;
			controller?.abort();
			controller = undefined;
			reading = false;
		};

		const apply = ( answer: ActivityResponse, ids: string[], versions: Map< string, number > ) => {
			const onScreen = new Set( ids );
			const writable = ( id: string ) => onScreen.has( id ) && ownership.mayWrite( id, versions );

			state.set( { account: answer.account_activity } );
			tableRef.current.patchRows(
				answer.rows.filter( ( row ) => writable( row.id ) ),
				answer.removed_ids.filter( writable )
			);
		};

		const schedule = ( delay: number ) => {
			clearTimers();
			if ( ! isHidden() ) {
				timer = setTimeout( tick, delay );
			}
		};

		async function tick(): Promise< void > {
			if ( reading || isHidden() ) {
				return;
			}

			const ticket = ++generation;
			const current = () => ticket === generation && tableRef.current.epoch === epoch;
			const ids = tableRef.current.visibleIds;
			const versions = ownership.snapshot( ids );
			const abort = new AbortController();

			controller = abort;
			reading = true;

			try {
				const answers: ActivityResponse[] = [];
				for ( const part of chunk( ids, declared.id_limit ) ) {
					answers.push(
						await readActivity( tableRef.current.api, tableRef.current.context, part, abort.signal )
					);
					if ( ! current() ) {
						return;
					}
				}

				const answer = merge( answers );

				reading = false;
				apply( answer, ids, versions );
				failures = 0;
				state.set( { stale: false, failing: false } );
				armStaleTimer();
				schedule( delayAfterSuccess( declared, answer.refresh_after_ms ) );
			} catch ( error ) {
				if ( ! current() || isAbort( error ) ) {
					return;
				}

				reading = false;
				failures++;
				state.set( { failing: true } );
				schedule( delayAfterFailure( declared, failures ) );
			}
		}

		const onVisibility = () => {
			if ( isHidden() ) {
				clearTimers();
				stopReading();
				return;
			}

			// Back on screen: do not wait out the rest of the interval.
			clearTimers();
			void tick();
		};

		// A new list read: the rows came with their activity, so the clock starts from now.
		state.set( { stale: false, failing: false } );
		armStaleTimer();
		schedule( declared.refresh_ms );
		document.addEventListener( 'visibilitychange', onVisibility );

		return () => {
			document.removeEventListener( 'visibilitychange', onVisibility );
			clearTimers();
			clearTimeout( staleTimer );
			stopReading();
		};
		// The loop restarts for a new list read, another account or table — not for the ids on screen, which are
		// read fresh at every tick, and not for new objects that describe the same limits.
	}, [ enabled, epoch, accountId, apiPath, refreshMs, maxRefreshMs, staleAfterMs, idLimit, state, ownership ] );
}
