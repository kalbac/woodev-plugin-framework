/**
 * The records table: one PHP-described table, rendered on WooCommerce's runtime `TableCard`.
 *
 * Everything on screen comes from the table's definition and rows (`Woodev\Framework\Admin\Records\*`): columns,
 * cell types, filters, sort keys, page sizes, actions and every string. The component knows the owner and table id
 * only as two path segments of its REST routes; it has no branch on either.
 *
 * The page URL is the table's state. A control changes the URL, the URL becomes the query, the query becomes a list
 * request — so reload, bookmark, Back and Forward all show the same table ({@see ./query}, {@see ./use-records}).
 *
 * @package woodev-plugin-framework
 */

import type { MutableRefObject, ReactNode, RefObject } from 'react';
import { createPortal, useEffect, useLayoutEffect, useMemo, useRef, useState } from '@wordpress/element';
import { createRecordsApi } from './api';
import { DataStatus } from './data-status';
import type { RecordsExtensions, TableHandle } from './extensions';
import { FiltersBar, SearchBox, ViewsRow, isNarrowed } from './filters';
import { MESSAGE_KEYS, firstMessage, message } from './messages';
import { changeQuery, searchFilter, writeQuery } from './query';
import { Pager } from './pager';
import { RowActions } from './row-actions';
import { useSelection, type Selection } from './selection';
import { EmptyCard, ErrorState, LoadingState } from './states';
import './style.scss';
import { STYLES } from './styles';
import {
	SelectionBar,
	buildHeaders,
	buildLines,
	nameColumnIndex,
	visibleRowIds,
	type TableModelInput,
} from './table-model';
import type { AdminRecord, Definition, Direction, Messages, RecordsQuery, RouteQuery } from './types';
import { useRecords, type Records } from './use-records';
import { getNavigate, getTableCard, type Navigate, type TableCardProps } from './wc-runtime';

export interface RecordsTableProps {
	/** The plugin that registered the table. */
	owner: string;
	/** The table id. */
	table: string;
	/** The card title and the table's caption — the page title PHP supplied. */
	title: string;
	/** The route query wc-admin hands the page. */
	query: RouteQuery;
	/** Writes a URL patch. Defaults to wc-admin's navigation. */
	navigate?: Navigate;
	/** Strings for the time before a definition has been read (a failed first load has no other source). */
	fallbackMessages?: Messages;
	/** The action and activity parts. */
	extensions?: RecordsExtensions;
	/** A control of the page drawn first in the filter row (the marketplace of «Заказы маркетплейсов»). */
	leadingFilter?: ReactNode;
}

/**
 * The route query and the way to change it. Outside wc-admin (no navigation runtime, no `navigate` prop) the table
 * still works: it keeps its own patch on top of the query it was given.
 *
 * @param route    The route query from the page.
 * @param navigate The caller's own navigation, if any.
 */
function useRoute( route: RouteQuery, navigate?: Navigate ): [ RouteQuery, Navigate ] {
	const [ local, setLocal ] = useState< RouteQuery >( {} );
	const external = navigate ?? getNavigate();
	const merged = useMemo( () => ( { ...route, ...local } ), [ route, local ] );

	if ( external ) {
		return [ route, external ];
	}

	return [ merged, ( patch ) => setLocal( ( known ) => ( { ...known, ...patch } ) ) ];
}

/** A summary longer than this is a paragraph of explanation, not a status line. */
const LONG_SUMMARY = 160;

/**
 * The sentence above the table. A short one is shown as it is; a long one keeps its first sentence on screen and folds the rest
 * into a collapsed «details», so the table starts near the top instead of under a paragraph.
 *
 * @param text PHP's summary.
 */
function Summary( { text }: { text: string } ) {
	const split = text.search( /[.!?»]\s/ );
	const head = split < 0 ? text : text.slice( 0, split + 1 );
	const rest = split < 0 ? '' : text.slice( split + 1 ).trim();

	if ( text.length <= LONG_SUMMARY || rest === '' ) {
		return (
			<p className="woodev-records__summary" style={ STYLES.summary }>
				{ text }
			</p>
		);
	}

	return (
		<details className="woodev-records__summary" style={ STYLES.summary }>
			<summary>{ head }</summary>
			<p>{ rest }</p>
		</details>
	);
}

/** The card's footer, where the pager is drawn. */
const FOOTER_SLOT = '.components-card__footer';

/**
 * The footer of the card inside `host`, found again after every render (the card is made again when its columns change).
 *
 * @param host The element that holds the card.
 */
function useFooterSlot( host: RefObject< HTMLElement > ): Element | null {
	const [ slot, setSlot ] = useState< Element | null >( null );

	useLayoutEffect( () => {
		setSlot( host.current?.querySelector( FOOTER_SLOT ) ?? null );
	} );

	return slot;
}

interface TableBodyProps {
	title: string;
	/** The card header's own controls, bulk group and search; `null` when the table has neither. */
	headerActions: ReactNode;
	definition: Definition;
	messages: Messages;
	query: RecordsQuery;
	records: Records;
	selection: Selection | null;
	handle: TableHandle;
	extensions: RecordsExtensions;
	onChange: ( query: RecordsQuery ) => void;
}

function sortedColumnId( definition: Definition, query: RecordsQuery ): string | undefined {
	return definition.columns.find( ( column ) => column.sort_key !== null && column.sort_key === query.sort )?.id;
}

/** The card: WooCommerce's table, sort headers and pagination around the shell's lines. */
function TableBody( {
	title,
	headerActions,
	definition,
	messages,
	query,
	records,
	selection,
	handle,
	extensions,
	onChange,
}: TableBodyProps ) {
	const TableCard = getTableCard();
	const host = useRef< HTMLDivElement >( null );
	const footer = useFooterSlot( host );
	const data = records.list.data;
	const summaryLabel = message( messages, MESSAGE_KEYS.summaryLabel );
	// WooCommerce reports one page-size choice as two callbacks (size, then the page clamped to the new size). Both are
	// computed from the query that was on screen, so the second would write the old size back: every change starts from
	// the query the previous callback of the same event produced.
	const latest = useRef( query );
	latest.current = query;
	const dropClampedPage = useRef( false );
	const columnsMenu = message( messages, MESSAGE_KEYS.columnsMenu );

	// `TableCard` names its column menu with an English string of its own and takes no prop to change it: PHP's name is
	// written on the icon button after every render (React leaves an attribute alone while its own prop is unchanged).
	useEffect( () => {
		if ( columnsMenu === '' ) {
			return;
		}

		document.querySelectorAll( '.woodev-records .woocommerce-ellipsis-menu__toggle' ).forEach( ( button ) => {
			button.setAttribute( 'aria-label', columnsMenu );
			button.setAttribute( 'title', columnsMenu );
		} );
	} );

	if ( ! TableCard ) {
		return null;
	}

	const renderActions = ( row: AdminRecord, rowKey: string ) => (
		<RowActions
			row={ row }
			rowKey={ rowKey }
			descriptors={ definition.row_actions }
			onAction={
				extensions.onRowAction
					? ( action, target ) => extensions.onRowAction?.( action, target, handle )
					: undefined
			}
			blockedReason={
				extensions.blockedReason
					? ( action, target ) => extensions.blockedReason?.( action, target, handle ) ?? ''
					: undefined
			}
		>
			{ extensions.renderRowActivity?.( row, handle ) }
		</RowActions>
	);

	const model: TableModelInput = {
		definition,
		messages,
		rows: data?.rows ?? [],
		children: records.children,
		expanded: records.expanded,
		selection,
		onToggle: records.toggle,
		onMoreChildren: records.readMoreChildren,
		renderActions,
	};
	const headers = buildHeaders( model );
	const lines = buildLines( model );
	const leadColumns = headers.length - definition.columns.length;
	// The row header is the table's name column. A photo column in front of the name must not take that place (WooCommerce draws
	// the row-header cell in its own grey).
	const rowHeaderIndex = leadColumns + nameColumnIndex( definition );

	const go = ( change: Partial< RecordsQuery > ) => {
		const next = changeQuery( latest.current, change );

		latest.current = next;
		onChange( next );
	};

	const sortBy = ( columnId: string, direction: string ) => {
		const sortKey = definition.columns.find( ( column ) => column.id === columnId )?.sort_key;
		if ( sortKey ) {
			go( { sort: sortKey, direction: direction as Direction } );
		}
	};

	// The page-size control in the footer is the only one shown, and lists PHP's sizes. Should WooCommerce's own picker ever
	// call in (its CSS hook not matching), a size PHP did not declare is dropped together with the page clamp that
	// WooCommerce computes for it right afterwards.
	const changePerPage = ( value: string | number ) => {
		const perPage = Number( value );
		if ( definition.pagination.per_page_options.includes( perPage ) ) {
			go( { perPage } );
			return;
		}

		dropClampedPage.current = true;
		Promise.resolve().then( () => {
			dropClampedPage.current = false;
		} );
	};

	const changePage = ( page: number ) => {
		if ( ! dropClampedPage.current ) {
			go( { page } );
		}
	};

	const onQueryChange: TableCardProps[ 'onQueryChange' ] = ( param ) =>
		param === 'per_page' ? changePerPage : () => {};

	return (
		<>
			<div ref={ host }>
				<TableCard
					// Remounted when the columns change: WooCommerce reads the visible-column list once, on mount.
					key={ headers.map( ( header ) => header.key ).join( ',' ) }
					className="woodev-records__table"
					title={ title }
					headers={ headers }
					rows={ lines.map( ( line ) => line.cells ) }
					rowKey={ ( _row, index ) => lines[ index ]?.key ?? String( index ) }
					rowHeader={ rowHeaderIndex }
					rowsPerPage={ query.perPage }
					totalRows={ data?.total ?? 0 }
					query={ {
						orderby: sortedColumnId( definition, query ),
						order: query.direction,
						paged: String( query.page ),
						per_page: query.perPage,
					} }
					isLoading={ records.list.status === 'loading' }
					actions={ headerActions ? [ headerActions ] : undefined }
					hasSearch={ searchFilter( definition ) !== undefined }
					summary={ summaryLabel !== '' && data ? [ { label: summaryLabel, value: data.total } ] : undefined }
					showMenu={ definition.columns.some( ( column ) => ! column.required ) }
					onSort={ sortBy }
					onPageChange={ changePage }
					onQueryChange={ onQueryChange }
				/>
			</div>
			{ footer &&
				records.list.status !== 'loading' &&
				createPortal(
					<Pager
						page={ query.page }
						perPage={ query.perPage }
						total={ data?.total ?? 0 }
						sizes={ definition.pagination.per_page_options }
						sizeLabel={ message( messages, MESSAGE_KEYS.perPage ) || title }
						messages={ messages }
						onPage={ changePage }
						onPerPage={ changePerPage }
					/>,
					footer
				) }
		</>
	);
}

interface ResultProps extends TableBodyProps {
	onRetry: () => void;
	/** The account action the empty table offers in its own body («Обновить список»); `null` for none. */
	emptyButton: ReactNode;
}

/** Below the toolbar: the failed read, the empty result, or the table. */
function Result( props: ResultProps ) {
	const { definition, messages, query, records, onRetry } = props;
	const { list } = records;

	if ( list.status === 'error' ) {
		return (
			<ErrorState
				text={ list.message || message( messages, MESSAGE_KEYS.error ) }
				retryLabel={ message( messages, MESSAGE_KEYS.retry ) }
				onRetry={ onRetry }
			/>
		);
	}

	if ( list.status === 'ready' && list.data?.total === 0 ) {
		const keys = isNarrowed( definition, query )
			? [ MESSAGE_KEYS.emptyFiltered, MESSAGE_KEYS.empty ]
			: [ MESSAGE_KEYS.empty ];

		return (
			<EmptyCard
				title={ props.title }
				actions={ props.headerActions }
				text={ firstMessage( messages, keys ) }
				button={ props.emptyButton }
			/>
		);
	}

	return <TableBody { ...props } />;
}

interface HeaderActionsProps {
	definition: Definition;
	query: RecordsQuery;
	selection: Selection;
	handle: TableHandle;
	extensions: RecordsExtensions;
	hasBulk: boolean;
	restoreFocus: MutableRefObject< boolean >;
	onChange: ( query: RecordsQuery ) => void;
}

/**
 * The card header's controls in one element: the card gives each entry of `actions` its own full-width slot, so two
 * entries would be two stacked controls. The bulk group first, the search after it and filling the rest of the row —
 * [title] [action | Apply] [search] [⋮], one row like «Заказы доставки». The account-wide buttons are not here: they sit at
 * the top of the page ({@link RecordsTable}).
 */
function HeaderActions( { definition, query, selection, handle, extensions, hasBulk, restoreFocus, onChange }: HeaderActionsProps ) {
	const search = searchFilter( definition );
	const chosen = { selectedIds: selection.selectedIds, matching: selection.matchingQuery, clear: selection.clear };

	return (
		<div className="woodev-table-toolbar">
			{ hasBulk && extensions.renderBulkActions?.( chosen, handle ) }
			{ search && (
				<SearchBox
					filter={ search }
					value={ query.search }
					restoreFocus={ restoreFocus }
					onSearch={ ( text ) => onChange( changeQuery( query, { search: text } ) ) }
				/>
			) }
			{ extensions.renderHeaderActions?.( handle ) }
		</div>
	);
}

/** The shell's class: `has-expand` and `has-image` tell the stylesheet which pinned columns come before the name column. */
function rootClassName( definition: Definition ): string {
	const hasImage = definition.columns.some( ( column ) => column.id === 'image' );

	return `woodev-records${ definition.children ? ' has-expand' : '' }${ hasImage ? ' has-image' : '' }`;
}

/** Whether the keyboard focus is inside the table header's search box right now. */
function searchHasFocus(): boolean {
	const active = document.activeElement;

	return active instanceof Element && active.closest( '.woodev-records__search' ) !== null;
}

/**
 * Puts the page right when a list read leaves fewer pages than the one on screen: rows removed by a refresh or an account action, or a
 * URL that names a page past the end. The last page replaces the page in the URL, through the same navigation as any other control, so the
 * pager never says «Страница 6 из 5» and the query stays inside the result.
 *
 * @param records    The table's reads.
 * @param definition The table's definition, once it is known.
 * @param go         Writes a URL patch.
 */
function useLastPageClamp( records: Records, definition: Definition | null, go: Navigate ): void {
	const latestGo = useRef( go );
	latestGo.current = go;
	const { query } = records;
	const total = records.list.data?.total;
	const ready = records.list.status === 'ready';

	useEffect( () => {
		if ( ! definition || ! query || ! ready || total === undefined ) {
			return;
		}

		const lastPage = Math.max( 1, Math.ceil( total / query.perPage ) );

		if ( query.page > lastPage ) {
			latestGo.current( writeQuery( definition, changeQuery( query, { page: lastPage } ) ) );
		}
	}, [ definition, query, ready, total ] );
}

export function RecordsTable( {
	owner,
	table,
	title,
	query: pageQuery,
	navigate,
	fallbackMessages = {},
	extensions = {},
	leadingFilter,
}: RecordsTableProps ) {
	const api = useMemo( () => createRecordsApi( owner, table ), [ owner, table ] );
	const [ route, go ] = useRoute( pageQuery, navigate );
	const records = useRecords( api, route );
	const envelope = records.definition.envelope;
	const definition = envelope?.definition ?? null;
	const hasBulk =
		definition !== null && definition.bulk_actions.length > 0 && extensions.renderBulkActions !== undefined;
	const selection = useSelection( definition?.pagination.max_selection ?? 0, records.selectionEpoch );
	useLastPageClamp( records, definition, go );
	// The table and its empty card are two elements with a header each, so the search box is made again when a search
	// finds nothing — and the merchant who is still typing must keep the keyboard. Read before the swap reaches the DOM.
	const isEmpty = records.list.status === 'ready' && records.list.data?.total === 0;
	const wasEmpty = useRef( isEmpty );
	const restoreFocus = useRef( false );
	if ( wasEmpty.current !== isEmpty ) {
		restoreFocus.current = searchHasFocus();
		wasEmpty.current = isEmpty;
	}

	if ( ! envelope || ! definition || ! records.query ) {
		if ( records.definition.status !== 'error' ) {
			return <LoadingState />;
		}

		return (
			<ErrorState
				text={ records.definition.message || message( fallbackMessages, MESSAGE_KEYS.error ) }
				retryLabel={ message( fallbackMessages, MESSAGE_KEYS.retry ) }
				onRetry={ records.retryDefinition }
			/>
		);
	}

	const query = records.query;
	const messages: Messages = {
		...fallbackMessages,
		...definition.messages,
		...( records.list.data?.messages ?? {} ),
	};
	const rows = records.list.data?.rows ?? [];
	const change = ( next: RecordsQuery ) => go( writeQuery( definition, next ) );
	const handle: TableHandle = {
		api,
		definition,
		context: envelope.context,
		messages,
		query,
		rows,
		children: rows.flatMap( ( row ) => records.children[ row.id ]?.rows ?? [] ),
		visibleIds: visibleRowIds( rows, records.children ),
		epoch: records.epoch,
		loading: records.list.status === 'loading' || records.silentRead,
		accountActivity: records.list.data?.account_activity ?? null,
		reload: records.reload,
		patchRows: records.patchRows,
	};
	const onAccountAction = extensions.onAccountAction;
	if ( onAccountAction ) {
		handle.runAccountAction = ( actionId, payload ) => {
			const action = definition.bulk_actions.find( ( entry ) => entry.id === actionId && entry.scope === 'account' );
			if ( action ) {
				onAccountAction( action, payload, handle );
			}
		};
	}
	const chosen = { selectedIds: selection.selectedIds, matching: selection.matchingQuery, clear: selection.clear };
	const emptyAction = message( messages, MESSAGE_KEYS.emptyAction );
	const emptyButton =
		hasBulk && emptyAction !== ''
			? extensions.renderAccountActions?.( chosen, handle, [ emptyAction ] ) ?? null
			: extensions.renderEmptyActions?.( handle ) ?? null;
	const summary = message( messages, MESSAGE_KEYS.summary );
	const description = message( messages, MESSAGE_KEYS.description );
	const limitNote = selection.limitReached ? message( messages, MESSAGE_KEYS.selectionLimit ) : '';
	const headerActions = (
		<HeaderActions
			key="toolbar"
			definition={ definition }
			query={ query }
			selection={ selection }
			handle={ handle }
			extensions={ extensions }
			hasBulk={ hasBulk }
			restoreFocus={ restoreFocus }
			onChange={ change }
		/>
	);

	return (
		<div
			className={ rootClassName( definition ) }
			data-owner={ owner }
			data-table={ table }
		>
			{ description !== '' && <p className="woodev-records__description">{ description }</p> }
			<FiltersBar
				definition={ definition }
				account={ envelope.context.account_id }
				query={ query }
				facets={ records.list.data?.facets ?? {} }
				messages={ messages }
				onChange={ change }
				leading={ leadingFilter }
				trailing={ hasBulk ? extensions.renderAccountActions?.( chosen, handle ) : null }
				aside={
					<DataStatus
						status={ records.list.data?.data_status }
						label={ message( messages, MESSAGE_KEYS.dataStatus ) }
					/>
				}
			/>
			{ extensions.renderActivity?.( handle ) }
			{ extensions.renderActions?.( handle ) }
			<ViewsRow
				definition={ definition }
				query={ query }
				facets={ records.list.data?.facets ?? {} }
				messages={ messages }
				total={ records.list.data?.total }
				onChange={ change }
			/>
			{ limitNote !== '' && <p role="status">{ limitNote }</p> }
			{ hasBulk && records.list.status === 'ready' && records.list.data && (
				<SelectionBar
					definition={ definition }
					messages={ messages }
					rows={ records.list.data.rows }
					childrenByParent={ records.children }
					selection={ selection }
					total={ records.list.data.total }
					query={ query }
					facets={ records.list.data.facets }
				/>
			) }
			{ summary !== '' && records.list.status === 'ready' && <Summary text={ summary } /> }
			<Result
				title={ title }
				headerActions={ headerActions }
				definition={ definition }
				messages={ messages }
				query={ query }
				records={ records }
				selection={ hasBulk ? selection : null }
				handle={ handle }
				extensions={ extensions }
				onChange={ change }
				onRetry={ () => records.reload() }
				emptyButton={ emptyButton }
			/>
		</div>
	);
}
