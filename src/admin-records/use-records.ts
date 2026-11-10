/**
 * The table's data: definition, rows and expanded child rows, read for the query the URL describes.
 *
 * Stale-read protection. Every read takes a ticket from a {@link createGate gate}; its answer — success OR failure —
 * is applied only while that ticket is still the newest. A newer read, a changed query, a collapsed row or an
 * unmount all invalidate the ticket, so a slow old response can neither overwrite newer rows nor flip a newer read
 * to "error" or "ready". There is no `finally` that clears a loading flag for somebody else's request. Obsolete
 * reads are also aborted, which saves the server the work but is not what the guarantee rests on.
 *
 * Child reads carry two tickets: their own (per parent) and the list's, because rows from before a list reload
 * belong to rows that are no longer on screen.
 *
 * @package woodev-plugin-framework
 */

import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { errorMessage, isAbort, type RecordsApi } from './api';
import { listParams, readAccount, readQuery } from './query';
import type { AdminRecord, DefinitionEnvelope, ListEnvelope, RecordsQuery, RouteQuery } from './types';

export type LoadStatus = 'loading' | 'ready' | 'error';

interface Gate {
	/** Starts a read: returns its ticket and makes every earlier ticket stale. */
	open: () => number;
	/** Makes every ticket stale without starting a read. */
	close: () => void;
	current: () => number;
	isCurrent: ( ticket: number ) => boolean;
}

export function createGate(): Gate {
	let latest = 0;

	return {
		open: () => ++latest,
		close: () => {
			latest++;
		},
		current: () => latest,
		isCurrent: ( ticket ) => ticket === latest,
	};
}

export interface DefinitionState {
	status: LoadStatus;
	/** The last definition read; kept on screen while another account's definition loads. */
	envelope: DefinitionEnvelope | null;
	message: string;
}

export interface ListState {
	status: LoadStatus;
	/** The last list read; kept while the next one loads. */
	data: ListEnvelope | null;
	message: string;
}

export interface ChildrenState {
	status: LoadStatus;
	rows: AdminRecord[];
	total: number;
	/** The last page read successfully; 0 while a first-page read has not answered. */
	page: number;
	/** The page whose read failed, 0 when none did: the Retry button repeats exactly this request. */
	failedPage: number;
	message: string;
}

const NO_CHILDREN: ChildrenState = { status: 'loading', rows: [], total: 0, page: 0, failedPage: 0, message: '' };

function appendDistinct( known: AdminRecord[], incoming: AdminRecord[] ): AdminRecord[] {
	const knownIds = new Set( known.map( ( row ) => row.id ) );

	return [ ...known, ...incoming.filter( ( row ) => ! knownIds.has( row.id ) ) ];
}

function patchList( rows: AdminRecord[], fresh: Map< string, AdminRecord >, removed: Set< string > ): AdminRecord[] {
	return rows.filter( ( row ) => ! removed.has( row.id ) ).map( ( row ) => fresh.get( row.id ) ?? row );
}

const NO_CHILDREN_BY_ID: Record< string, ChildrenState > = {};
const NO_EXPANDED: string[] = [];

export interface Records {
	definition: DefinitionState;
	/** The query on screen; `null` until a definition has been read. */
	query: RecordsQuery | null;
	list: ListState;
	children: Record< string, ChildrenState >;
	expanded: string[];
	/** Changes with every list read, so an extension can tell that rows it asked about are gone. */
	epoch: number;
	/** Reads the definition again after it failed. */
	retryDefinition: () => void;
	/** Reads the current page again; expanded rows that are still there stay expanded. */
	reload: () => void;
	toggle: ( parentId: string ) => void;
	/** Reads the next page of a row's children; after a failed read, the page that failed. */
	readMoreChildren: ( parentId: string ) => void;
	/** Replaces rows by id and drops removed ones, in the list and in every expanded row. */
	patchRows: ( rows: AdminRecord[], removedIds?: string[] ) => void;
}

/**
 * @param api   The table's REST client.
 * @param route The route query (the page URL).
 */
export function useRecords( api: RecordsApi, route: RouteQuery ): Records {
	const account = readAccount( route );

	const [ definition, setDefinition ] = useState< DefinitionState >( {
		status: 'loading',
		envelope: null,
		message: '',
	} );
	const [ list, setList ] = useState< ListState >( { status: 'loading', data: null, message: '' } );
	const [ children, setChildren ] = useState< Record< string, ChildrenState > >( {} );
	const [ expanded, setExpanded ] = useState< string[] >( [] );
	const [ definitionAttempt, setDefinitionAttempt ] = useState( 0 );
	const [ listAttempt, setListAttempt ] = useState( 0 );
	const [ epoch, setEpoch ] = useState( 0 );

	const definitionGate = useRef( createGate() ).current;
	const listGate = useRef( createGate() ).current;
	const childGates = useRef( new Map< string, Gate >() ).current;
	const expandedRef = useRef< string[] >( [] );
	const childrenRef = useRef< Record< string, ChildrenState > >( {} );
	/** The definition envelope the list, the expansion and the cached children were read for (account, owner, table). */
	const scopeRef = useRef< DefinitionEnvelope | null >( null );

	const envelope = definition.status === 'ready' ? definition.envelope : null;
	const shownDefinition = definition.envelope?.definition ?? null;
	const query = useMemo(
		() => ( shownDefinition ? readQuery( shownDefinition, route ) : null ),
		[ shownDefinition, route ]
	);
	const requestKey = envelope && query ? JSON.stringify( listParams( envelope.context, query ) ) : '';
	const queryRef = useRef( query );
	queryRef.current = query;

	const writeExpanded = useCallback( ( next: string[] ) => {
		expandedRef.current = next;
		setExpanded( next );
	}, [] );

	const writeChildren = useCallback(
		( update: ( known: Record< string, ChildrenState > ) => Record< string, ChildrenState > ) => {
			childrenRef.current = update( childrenRef.current );
			setChildren( childrenRef.current );
		},
		[]
	);

	const childGate = useCallback(
		( parentId: string ): Gate => {
			if ( ! childGates.has( parentId ) ) {
				childGates.set( parentId, createGate() );
			}

			return childGates.get( parentId ) as Gate;
		},
		[ childGates ]
	);

	const readChildren = useCallback(
		( parentId: string, page: number ) => {
			const perPage = envelope?.definition.children?.default_per_page;
			if ( ! envelope || perPage === undefined ) {
				return;
			}

			const listTicket = listGate.current();
			const gate = childGate( parentId );
			const ticket = gate.open();
			const isStale = () => ! listGate.isCurrent( listTicket ) || ! gate.isCurrent( ticket );
			const known = () => childrenRef.current[ parentId ] ?? NO_CHILDREN;

			// A first-page read starts the paging over: the pages loaded so far belong to the rows it replaces.
			const paging = page === 1 ? { page: 0 } : {};
			writeChildren( ( all ) => ( {
				...all,
				[ parentId ]: { ...known(), ...paging, status: 'loading', failedPage: 0, message: '' },
			} ) );

			api.children( envelope.context, parentId, { page, perPage } ).then(
				( data ) => {
					if ( isStale() ) {
						return;
					}
					const rows = page === 1 ? data.rows : appendDistinct( known().rows, data.rows );
					writeChildren( ( all ) => ( {
						...all,
						[ parentId ]: { status: 'ready', rows, total: data.total, page, failedPage: 0, message: '' },
					} ) );
				},
				( error ) => {
					if ( isStale() ) {
						return;
					}
					writeChildren( ( all ) => ( {
						...all,
						[ parentId ]: { ...known(), status: 'error', failedPage: page, message: errorMessage( error ) },
					} ) );
				}
			);
		},
		[ api, envelope, listGate, childGate, writeChildren ]
	);

	/** After a list read: rows that are gone collapse, the others read their children again. */
	const keepExpanded = useCallback(
		( rows: AdminRecord[] ) => {
			const expandable = new Set( rows.filter( ( row ) => row.has_children ).map( ( row ) => row.id ) );
			const kept = expandedRef.current.filter( ( id ) => expandable.has( id ) );

			writeExpanded( kept );
			writeChildren( ( all ) =>
				Object.fromEntries( kept.filter( ( id ) => all[ id ] ).map( ( id ) => [ id, all[ id ] ] ) )
			);
			kept.forEach( ( id ) => readChildren( id, 1 ) );
		},
		[ readChildren, writeChildren, writeExpanded ]
	);

	useEffect( () => {
		const ticket = definitionGate.open();
		const controller = new AbortController();

		setDefinition( ( known ) => ( { ...known, status: 'loading', message: '' } ) );

		api.definition( account, controller.signal ).then(
			( data ) => {
				if ( definitionGate.isCurrent( ticket ) ) {
					setDefinition( { status: 'ready', envelope: data, message: '' } );
				}
			},
			( error ) => {
				if ( definitionGate.isCurrent( ticket ) && ! isAbort( error ) ) {
					setDefinition( { status: 'error', envelope: null, message: errorMessage( error ) } );
				}
			}
		);

		return () => {
			definitionGate.close();
			controller.abort();
		};
	}, [ api, account, definitionAttempt, definitionGate ] );

	useEffect( () => {
		const request = queryRef.current;
		if ( ! envelope || ! request ) {
			return undefined;
		}

		const ticket = listGate.open();
		const controller = new AbortController();

		if ( scopeRef.current !== envelope ) {
			// Another account, owner or table: ids are only unique inside one context, so nothing read for the old
			// scope may stay on screen under the new one. Same-scope reloads keep their expansion ({@see keepExpanded}).
			scopeRef.current = envelope;
			childGates.forEach( ( gate ) => gate.close() );
			childGates.clear();
			writeExpanded( [] );
			writeChildren( () => ( {} ) );
			setList( ( known ) => ( { ...known, data: null } ) );
		}

		setEpoch( ticket );
		setList( ( known ) => ( { ...known, status: 'loading', message: '' } ) );

		api.rows( envelope.context, request, controller.signal ).then(
			( data ) => {
				if ( listGate.isCurrent( ticket ) ) {
					setList( { status: 'ready', data, message: '' } );
					keepExpanded( data.rows );
				}
			},
			( error ) => {
				if ( listGate.isCurrent( ticket ) && ! isAbort( error ) ) {
					setList( { status: 'error', data: null, message: errorMessage( error ) } );
				}
			}
		);

		return () => {
			listGate.close();
			controller.abort();
		};
		// `requestKey` stands for the query: the effect must not re-run for a new object that describes the same request.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ api, envelope, requestKey, listAttempt, listGate ] );

	// Until the effect above has switched the cache to the new scope, none of the old scope's data is exposed.
	const inScope = envelope === null || scopeRef.current === envelope;

	const toggle = useCallback(
		( parentId: string ) => {
			if ( expandedRef.current.includes( parentId ) ) {
				childGate( parentId ).close();
				writeExpanded( expandedRef.current.filter( ( id ) => id !== parentId ) );
				writeChildren( ( all ) =>
					Object.fromEntries( Object.entries( all ).filter( ( [ id ] ) => id !== parentId ) )
				);
				return;
			}

			writeExpanded( [ ...expandedRef.current, parentId ] );
			readChildren( parentId, 1 );
		},
		[ childGate, readChildren, writeChildren, writeExpanded ]
	);

	const readMoreChildren = useCallback(
		( parentId: string ) => {
			const state = childrenRef.current[ parentId ];

			readChildren( parentId, state?.failedPage ? state.failedPage : ( state?.page ?? 0 ) + 1 );
		},
		[ readChildren ]
	);

	const patchRows = useCallback(
		( rows: AdminRecord[], removedIds: string[] = [] ) => {
			const fresh = new Map( rows.map( ( row ) => [ row.id, row ] ) );
			const removed = new Set( removedIds );

			setList( ( known ) =>
				known.data
					? { ...known, data: { ...known.data, rows: patchList( known.data.rows, fresh, removed ) } }
					: known
			);
			writeChildren( ( all ) =>
				Object.fromEntries(
					Object.entries( all ).map( ( [ id, state ] ) => [
						id,
						{ ...state, rows: patchList( state.rows, fresh, removed ) },
					] )
				)
			);
		},
		[ writeChildren ]
	);

	return {
		definition,
		query,
		list: envelope
			? inScope
				? list
				: { ...list, status: 'loading', data: null }
			: { ...list, status: definition.status === 'error' ? 'error' : 'loading' },
		children: inScope ? children : NO_CHILDREN_BY_ID,
		expanded: inScope ? expanded : NO_EXPANDED,
		epoch,
		retryDefinition: useCallback( () => setDefinitionAttempt( ( attempt ) => attempt + 1 ), [] ),
		reload: useCallback( () => setListAttempt( ( attempt ) => attempt + 1 ), [] ),
		toggle,
		readMoreChildren,
		patchRows,
	};
}
