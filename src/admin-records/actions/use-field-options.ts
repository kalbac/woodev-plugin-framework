/**
 * The options of an action field that PHP declared with `options_source` (a warehouse, a category…): read from the
 * server's local source, one page at a time.
 *
 * @package woodev-plugin-framework
 */

import { useCallback, useEffect, useState } from '@wordpress/element';
import { isAbort } from '../api';
import type { TableHandle } from '../extensions';
import type { Option } from '../types';
import { createActionsApi } from './api';

export interface FieldOptions {
	options: Option[];
	status: 'loading' | 'ready' | 'error';
	/** The server has another page. */
	hasMore: boolean;
	loadMore: () => void;
}

interface OptionsState {
	options: Option[];
	cursor: string;
	status: 'loading' | 'ready' | 'error';
	next: string | null;
}

const LOADING: OptionsState = { options: [], cursor: '', status: 'loading', next: null };

/**
 * @param table  The table the dialog belongs to.
 * @param action Action id.
 * @param field  Field id.
 * @param rowId  Row of a row action; empty for a bulk action.
 * @param search What the merchant typed into a searchable field, already debounced; empty lists everything.
 */
export function useFieldOptions( table: TableHandle, action: string, field: string, rowId: string, search = '' ): FieldOptions {
	const [ state, setState ] = useState< OptionsState >( LOADING );
	const [ paging, setPaging ] = useState( { search, cursor: '' } );
	const { api, context } = table;
	const account = context.account_id;
	// A cursor belongs to the search it was issued for: a new search starts on the first page.
	const cursor = paging.search === search ? paging.cursor : '';

	// A different action, field, row or account is a different list.
	useEffect( () => {
		setState( LOADING );
		setPaging( { search, cursor: '' } );
		// `search` is deliberately not a dependency: typing keeps the list on screen until the answer replaces it.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ action, field, rowId, account ] );

	// A cursor is forgotten when the search moves on, so coming back to an earlier term starts on its first page again.
	useEffect( () => {
		setPaging( ( known ) => ( known.search === search ? known : { search, cursor: '' } ) );
	}, [ search ] );

	useEffect( () => {
		const controller = new AbortController();

		if ( cursor === '' ) {
			setState( ( known ) => ( known.status === 'loading' ? known : { ...known, status: 'loading' } ) );
		}

		createActionsApi( api )
			.fieldOptions( { account_id: account }, action, field, { rowId, search, cursor }, controller.signal )
			.then( ( page ) =>
				setState( ( known ) => ( {
					options: cursor === '' ? page.options : [ ...known.options, ...page.options ],
					cursor,
					status: 'ready',
					next: page.next_cursor,
				} ) )
			)
			.catch( ( error: unknown ) => {
				if ( ! isAbort( error ) ) {
					setState( ( known ) => ( { ...known, status: 'error' } ) );
				}
			} );

		return () => controller.abort();
	}, [ api, account, action, field, rowId, search, cursor ] );

	const next = state.next;
	const loadMore = useCallback( () => {
		if ( next !== null ) {
			setPaging( { search, cursor: next } );
		}
	}, [ next, search ] );

	return { options: state.options, status: state.status, hasMore: state.next !== null, loadMore };
}
