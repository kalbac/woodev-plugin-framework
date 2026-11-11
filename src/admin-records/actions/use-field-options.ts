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
 */
export function useFieldOptions( table: TableHandle, action: string, field: string, rowId: string ): FieldOptions {
	const [ state, setState ] = useState< OptionsState >( LOADING );
	const [ cursor, setCursor ] = useState( '' );
	const { api, context } = table;
	const account = context.account_id;

	// A different action, field, row or account is a different list.
	useEffect( () => {
		setState( LOADING );
		setCursor( '' );
	}, [ action, field, rowId, account ] );

	useEffect( () => {
		const controller = new AbortController();

		createActionsApi( api )
			.fieldOptions( { account_id: account }, action, field, { rowId, search: '', cursor }, controller.signal )
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
	}, [ api, account, action, field, rowId, cursor ] );

	const next = state.next;
	const loadMore = useCallback( () => {
		if ( next !== null ) {
			setCursor( next );
		}
	}, [ next ] );

	return { options: state.options, status: state.status, hasMore: state.next !== null, loadMore };
}
