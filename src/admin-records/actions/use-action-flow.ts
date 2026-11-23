/**
 * The life of one action: fields → plan → confirmation → result.
 *
 * Three rules shape it.
 *
 * 1. **A mutation starts from a click, never from an effect.** `confirm()` is called by a button; nothing in a
 *    render or an effect sends an apply, so a remount (React's strict mode, a re-render of the table) cannot send
 *    one twice.
 * 2. **One request at a time.** `busy` is a ref, set before the request leaves, so a double click in the same tick
 *    is one request, and the buttons are disabled for the length of it.
 * 3. **An answer that never came is not a failure to retry.** When an apply gets no usable answer the action may
 *    have run: the flow never sends it again by itself, reads the table again and shows PHP's recovery sentence.
 *
 * The server replans on apply, so the plan on screen is what the merchant agreed to see, not a receipt: the result
 * carries the real counts.
 *
 * @package woodev-plugin-framework
 */

import { useCallback, useRef, useState } from '@wordpress/element';
import { isAbort } from '../api';
import type { Ownership } from '../activity';
import { hasBusyRow } from '../activity/busy';
import type { TableHandle } from '../extensions';
import type { ActionDescriptor, MatchingQuery } from '../types';
import { createActionsApi } from './api';
import { describeFailure, mayHaveChangedRows, type Failure } from './failure';
import type { ActionTarget, ApplyResponse, FieldValue, Payload, PreviewResponse } from './types';

export type Stage = 'input' | 'planning' | 'previewed' | 'applying' | 'done' | 'failed';

export interface FlowState {
	action: ActionDescriptor;
	/** Targets; empty for an account-wide action, and for an «every row under the filter» one (see `matching`). */
	ids: string[];
	/** The filter and search the action runs over instead of ids, frozen when the merchant chose it; `null` for ticked rows. */
	matching: MatchingQuery | null;
	/**
	 * The dialog only shows the plan and closes: a read-only report (the account line's «Подробнее») carries its answer in
	 * the preview, and there is nothing to apply.
	 */
	previewOnly: boolean;
	/** The row of a row action, for option reads that depend on it; empty for a bulk action. */
	rowId: string;
	/** The account the flow started in. A flow never outlives a change of account. */
	account: string;
	stage: Stage;
	payload: Payload;
	fieldErrors: Record< string, string >;
	plan: PreviewResponse | null;
	result: ApplyResponse | null;
	failure: Failure | null;
	/** Which request failed: only a failed read may be offered again. */
	failedAt: 'preview' | 'apply' | null;
}

export interface BeginOptions {
	/** Runs after an apply came back (for a bulk action: clears the selection). */
	onApplied?: () => void;
	/** Runs the action over every row under this filter, with no ids. */
	matching?: MatchingQuery;
	/** Show the preview's answer and never apply (see {@link FlowState.previewOnly}). */
	previewOnly?: boolean;
	/** Values the server already chose for the fields — an account-line button's payload; the dialog never asks for them. */
	payload?: Record< string, string >;
}

export interface ActionFlow {
	state: FlowState | null;
	/** Tells the flow which table scope is on screen now; the dialog calls it after every render. */
	observe: ( table: TableHandle ) => void;
	begin: ( action: ActionDescriptor, ids: string[], rowId: string, table: TableHandle, options?: BeginOptions ) => void;
	edit: ( fieldId: string, value: FieldValue ) => void;
	preview: ( table: TableHandle ) => Promise< void >;
	confirm: ( table: TableHandle ) => Promise< void >;
	close: () => void;
}

/** The table and account a handle belongs to: a response may change the rows only of the scope it was sent for. */
function scopeOf( table: TableHandle ): string {
	return `${ table.api.path }|${ table.context.account_id }`;
}

/** Mutating actions plan first, except a row action whose PHP definition says a click is enough. */
export function needsPreview( action: ActionDescriptor ): boolean {
	return action.effect === 'mutate' && ! action.skip_preview;
}

/**
 * An action that runs on the click and has nothing to show: account-wide, no preview, no field to fill. «Обновить список» is one:
 * counts of «selected 1 / accepted 1» mean nothing for it, and its progress is the account line above the table. Its dialog stays
 * closed unless something went wrong.
 *
 * @param action The action.
 */
export function isQuiet( action: ActionDescriptor ): boolean {
	return action.scope === 'account' && action.skip_preview && visibleFields( action ).length === 0;
}

/** An unchecked box is a real `false`, not a field that was left out. */
function initialPayload( action: ActionDescriptor ): Payload {
	return Object.fromEntries(
		action.fields.filter( ( field ) => field.type === 'boolean' ).map( ( field ) => [ field.id, false ] )
	);
}

/** What a request names: the frozen filter, or the ticked rows. */
function targetOf( flow: FlowState ): ActionTarget {
	return flow.matching ? { matching: flow.matching } : flow.ids;
}

/** Fields the merchant is asked about; a `hidden` one carries a value the server chose and is never drawn. */
export function visibleFields( action: ActionDescriptor ): ActionDescriptor[ 'fields' ] {
	return action.fields.filter( ( field ) => field.type !== 'hidden' );
}

/** A field a switch of the same dialog has turned off: not asked, not required, not sent. */
export function isHidden( field: ActionDescriptor[ 'fields' ][ number ], payload: Payload ): boolean {
	return field.hidden_when !== undefined && field.hidden_when !== '' && payload[ field.hidden_when ] === true;
}

/** Empty text and empty lists are left out, so the server's own "required" sentence is the one that answers. A hidden field is left out too. */
function sendable( action: ActionDescriptor, payload: Payload ): Payload {
	const hidden = new Set( action.fields.filter( ( field ) => isHidden( field, payload ) ).map( ( field ) => field.id ) );

	return Object.fromEntries(
		Object.entries( payload ).filter(
			( [ id, value ] ) => ! hidden.has( id ) && ! ( value === '' || ( Array.isArray( value ) && value.length === 0 ) )
		)
	);
}

/** Whether a field the merchant must fill is still empty: the dialog's forward button waits for it. */
export function hasEmptyRequired( action: ActionDescriptor, payload: Payload ): boolean {
	return action.fields.some( ( field ) => {
		if ( ! field.required || field.type === 'hidden' || field.type === 'boolean' || isHidden( field, payload ) ) {
			return false;
		}
		const value = payload[ field.id ];

		return value === undefined || ( typeof value === 'string' ? value.trim() === '' : Array.isArray( value ) && value.length === 0 );
	} );
}

/**
 * @param ownership The activity part's row ownership, when the page has one. The rows of an apply are claimed while
 *                  it is in flight and released once its answer has landed, so a refresh that was already on its
 *                  way never overwrites what the apply wrote.
 */
export function useActionFlow( ownership?: Ownership ): ActionFlow {
	const [ state, setState ] = useState< FlowState | null >( null );
	const current = useRef< FlowState | null >( null );
	const busy = useRef( false );
	const ticket = useRef( 0 );
	const abort = useRef< AbortController | null >( null );
	const afterApply = useRef< ( () => void ) | undefined >( undefined );
	// Updated by the dialog on every render, so it is the scope on screen now, not the one a request was sent from.
	const onScreen = useRef( '' );

	const update = useCallback( ( next: FlowState | null ) => {
		current.current = next;
		setState( next );
	}, [] );

	const patch = useCallback(
		( changes: Partial< FlowState > ) => {
			if ( current.current ) {
				update( { ...current.current, ...changes } );
			}
		},
		[ update ]
	);

	const fail = useCallback(
		( error: unknown, at: 'preview' | 'apply', table: TableHandle ) => {
			const failure = describeFailure( error );

			if ( failure.kind === 'fields' ) {
				patch( { stage: 'input', plan: null, failure, fieldErrors: failure.fieldErrors, failedAt: at } );
			} else {
				patch( { stage: 'failed', failure, failedAt: at } );
			}
			if ( at === 'apply' && mayHaveChangedRows( failure ) && scopeOf( table ) === onScreen.current ) {
				table.reload();
			}
		},
		[ patch ]
	);

	/**
	 * Brings the table in line with what the server did, then lets the caller clear its selection. If the merchant
	 * has moved to another account meanwhile, the answer is not applied: its row ids may name other rows there.
	 */
	const showResult = useCallback( ( flow: FlowState, result: ApplyResponse, table: TableHandle ) => {
		if ( scopeOf( table ) !== onScreen.current ) {
			return;
		}
		if ( result.status === 'queued' || flow.action.scope === 'account' || flow.matching ) {
			// The rows that changed are not in the answer — for a filter-wide action most of them are off this page: read them again.
			table.reload();
		} else {
			table.patchRows( result.rows, result.removed_ids );
		}
		afterApply.current?.();
	}, [] );

	const preview = useCallback(
		async ( table: TableHandle ) => {
			const flow = current.current;
			if ( ! flow || busy.current ) {
				return;
			}

			busy.current = true;
			const mine = ++ticket.current;
			abort.current = new AbortController();
			patch( { stage: 'planning', failure: null, fieldErrors: {} } );

			try {
				const plan = await createActionsApi( table.api ).preview(
					table.context,
					flow.action.id,
					targetOf( flow ),
					sendable( flow.action, flow.payload ),
					abort.current.signal
				);
				if ( mine === ticket.current ) {
					patch( { stage: 'previewed', plan } );
				}
			} catch ( error ) {
				if ( mine === ticket.current && ! isAbort( error ) ) {
					fail( error, 'preview', table );
				}
			} finally {
				if ( mine === ticket.current ) {
					busy.current = false;
				}
			}
		},
		[ fail, patch ]
	);

	const confirm = useCallback(
		async ( table: TableHandle ) => {
			const flow = current.current;
			const planned = flow?.stage === 'previewed' && flow.plan?.can_apply === true;
			const direct = flow?.stage === 'input' && ! needsPreview( flow.action ) && ! flow.previewOnly;
			if ( ! flow || busy.current || flow.previewOnly || ! ( planned || direct ) ) {
				return;
			}

			// «Every row under the filter» reaches a row that went busy after the preview was drawn: the plan on screen is void,
			// and the merchant starts again from the ticked rows (see `selection.ts`).
			if ( flow.matching && hasBusyRow( [ ...table.rows, ...table.children ] ) ) {
				ticket.current++;
				abort.current?.abort();
				afterApply.current = undefined;
				update( null );

				return;
			}

			busy.current = true;
			const mine = ++ticket.current;
			const claim = ownership?.claim( flow.ids );
			patch( { stage: 'applying', failure: null, fieldErrors: {} } );

			try {
				// No abort signal: a mutation the browser gives up on may still have run.
				const result = await createActionsApi( table.api ).apply(
					table.context,
					flow.action.id,
					targetOf( flow ),
					sendable( flow.action, flow.payload )
				);
				if ( mine === ticket.current ) {
					showResult( flow, result, table );
					patch( { stage: 'done', result } );
				}
			} catch ( error ) {
				if ( mine === ticket.current ) {
					fail( error, 'apply', table );
				}
			} finally {
				if ( claim ) {
					ownership?.release( flow.ids, claim );
				}
				if ( mine === ticket.current ) {
					busy.current = false;
				}
			}
		},
		[ fail, ownership, patch, showResult, update ]
	);

	const begin = useCallback(
		( action: ActionDescriptor, ids: string[], rowId: string, table: TableHandle, options: BeginOptions = {} ) => {
			// A link is navigation; it has no route and nothing to confirm.
			if ( action.interaction === 'link' || current.current?.stage === 'applying' ) {
				return;
			}

			ticket.current++;
			abort.current?.abort();
			busy.current = false;
			onScreen.current = scopeOf( table );
			afterApply.current = options.onApplied;
			update( {
				action,
				ids,
				matching: options.matching ?? null,
				previewOnly: options.previewOnly === true,
				rowId,
				account: table.context.account_id,
				stage: 'input',
				payload: { ...initialPayload( action ), ...options.payload },
				fieldErrors: {},
				plan: null,
				result: null,
				failure: null,
				failedAt: null,
			} );

			if ( visibleFields( action ).length > 0 ) {
				return;
			}
			if ( needsPreview( action ) || options.previewOnly ) {
				void preview( table );
			} else {
				void confirm( table );
			}
		},
		[ confirm, preview, update ]
	);

	const edit = useCallback(
		( fieldId: string, value: FieldValue ) => {
			const flow = current.current;
			if ( ! flow || flow.stage === 'planning' || flow.stage === 'applying' || flow.stage === 'done' ) {
				return;
			}

			// A changed input makes the plan on screen a plan of something else: ask again.
			const errors = { ...flow.fieldErrors };
			delete errors[ fieldId ];
			const payload: Payload = { ...flow.payload, [ fieldId ]: value };
			// Fields a switch has just hidden start empty when it is turned off again: the box they come back in is blank too.
			if ( value === true ) {
				flow.action.fields.filter( ( field ) => field.hidden_when === fieldId ).forEach( ( field ) => delete payload[ field.id ] );
			}
			patch( {
				payload,
				fieldErrors: errors,
				stage: flow.stage === 'previewed' ? 'input' : flow.stage,
				plan: null,
			} );
		},
		[ patch ]
	);

	const close = useCallback( () => {
		if ( current.current?.stage === 'applying' ) {
			return;
		}

		ticket.current++;
		abort.current?.abort();
		busy.current = false;
		afterApply.current = undefined;
		update( null );
	}, [ update ] );

	const observe = useCallback( ( table: TableHandle ) => {
		onScreen.current = scopeOf( table );
	}, [] );

	return { state, observe, begin, edit, preview, confirm, close };
}
