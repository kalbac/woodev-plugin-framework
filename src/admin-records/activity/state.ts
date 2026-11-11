/**
 * What the refresh loop knows beyond the rows: the account line and how healthy the reads are.
 *
 * Rows carry their own activity (it is part of the row); this is the table-level part, shared between the loop
 * that writes it and the views — and an action part that wants to disable an account action — that read it.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useState } from '@wordpress/element';
import type { AccountActivity } from './types';

export interface ActivityHealth {
	/** The account-wide state, `null` before the first list read has answered. */
	account: AccountActivity | null;
	/** The last good read is older than the table's `stale_after_ms`. Controls that were disabled stay disabled. */
	stale: boolean;
	/** The last read failed and the loop is backing off. */
	failing: boolean;
}

export interface ActivityState {
	get: () => ActivityHealth;
	set: ( patch: Partial< ActivityHealth > ) => void;
	subscribe: ( listener: () => void ) => () => void;
}

export function createActivityState(): ActivityState {
	let current: ActivityHealth = { account: null, stale: false, failing: false };
	const listeners = new Set< () => void >();

	return {
		get: () => current,
		set: ( patch ) => {
			const next = { ...current, ...patch };
			const changed = ( Object.keys( next ) as Array< keyof ActivityHealth > ).some(
				( key ) => next[ key ] !== current[ key ]
			);

			if ( changed ) {
				current = next;
				listeners.forEach( ( listener ) => listener() );
			}
		},
		subscribe: ( listener ) => {
			listeners.add( listener );

			return () => {
				listeners.delete( listener );
			};
		},
	};
}

/** Re-renders the caller whenever the state changes. */
export function useActivityState( state: ActivityState ): ActivityHealth {
	const [ snapshot, setSnapshot ] = useState( state.get() );

	useEffect( () => {
		setSnapshot( state.get() );

		return state.subscribe( () => setSnapshot( state.get() ) );
	}, [ state ] );

	return snapshot;
}

/**
 * Why an account action cannot run right now — the account line's sentence — or an empty string. For an action
 * part to call from its bulk toolbar.
 *
 * @param health   The state from {@link useActivityState}.
 * @param actionId The action's id.
 */
export function accountBlockedReason( health: ActivityHealth, actionId: string ): string {
	return health.account?.blocked_actions.includes( actionId ) ? health.account.text : '';
}
