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

import { SelectControl } from '@wordpress/components';
import { useMemo, useRef, useState } from '@wordpress/element';
import { createRecordsApi } from './api';
import type { RecordsExtensions, TableHandle } from './extensions';
import { FiltersBar, isNarrowed } from './filters';
import { MESSAGE_KEYS, firstMessage, message } from './messages';
import { changeQuery, writeQuery } from './query';
import { RowActions } from './row-actions';
import { useSelection, type Selection } from './selection';
import { EmptyState, ErrorState, LoadingState } from './states';
import { HIDE_VENDOR_PAGE_SIZE, STYLES } from './styles';
import { buildHeaders, buildLines, visibleRowIds, type TableModelInput } from './table-model';
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

interface TableBodyProps {
	title: string;
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
	const data = records.list.data;
	// WooCommerce reports one page-size choice as two callbacks (size, then the page clamped to the new size). Both are
	// computed from the query that was on screen, so the second would write the old size back: every change starts from
	// the query the previous callback of the same event produced.
	const latest = useRef( query );
	latest.current = query;
	const dropClampedPage = useRef( false );

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

	// The page-size control below is the only one shown, and lists PHP's sizes. Should WooCommerce's own picker ever
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

	const sizes = definition.pagination.per_page_options;
	const sizeOptions = ( sizes.includes( query.perPage ) ? sizes : [ ...sizes, query.perPage ] ).map( ( size ) => ( {
		value: String( size ),
		label: String( size ),
	} ) );

	return (
		<>
			<style>{ HIDE_VENDOR_PAGE_SIZE }</style>
			<TableCard
				// Remounted when the columns change: WooCommerce reads the visible-column list once, on mount.
				key={ headers.map( ( header ) => header.key ).join( ',' ) }
				className="woodev-records__table"
				title={ title }
				headers={ headers }
				rows={ lines.map( ( line ) => line.cells ) }
				rowKey={ ( _row, index ) => lines[ index ]?.key ?? String( index ) }
				rowHeader={ leadColumns }
				rowsPerPage={ query.perPage }
				totalRows={ data?.total ?? 0 }
				query={ {
					orderby: sortedColumnId( definition, query ),
					order: query.direction,
					paged: String( query.page ),
					per_page: query.perPage,
				} }
				isLoading={ records.list.status === 'loading' }
				showMenu={ definition.columns.some( ( column ) => ! column.required ) }
				onSort={ sortBy }
				onPageChange={ changePage }
				onQueryChange={ onQueryChange }
			/>
			{ sizes.length > 1 && (
				<div className="woodev-records__page-size" style={ STYLES.pageSize }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ message( messages, MESSAGE_KEYS.perPage ) || title }
						labelPosition="side"
						value={ String( query.perPage ) }
						options={ sizeOptions }
						onChange={ ( next: string ) => changePerPage( next ) }
					/>
				</div>
			) }
		</>
	);
}

interface ResultProps extends TableBodyProps {
	onRetry: () => void;
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

		return <EmptyState text={ firstMessage( messages, keys ) } />;
	}

	return <TableBody { ...props } />;
}

export function RecordsTable( {
	owner,
	table,
	title,
	query: pageQuery,
	navigate,
	fallbackMessages = {},
	extensions = {},
}: RecordsTableProps ) {
	const api = useMemo( () => createRecordsApi( owner, table ), [ owner, table ] );
	const [ route, go ] = useRoute( pageQuery, navigate );
	const records = useRecords( api, route );
	const envelope = records.definition.envelope;
	const definition = envelope?.definition ?? null;
	const hasBulk =
		definition !== null && definition.bulk_actions.length > 0 && extensions.renderBulkActions !== undefined;
	const selection = useSelection( definition?.pagination.max_selection ?? 0, records.epoch );

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
		visibleIds: visibleRowIds( rows, records.children ),
		epoch: records.epoch,
		accountActivity: records.list.data?.account_activity ?? null,
		reload: records.reload,
		patchRows: records.patchRows,
	};
	const summary = message( messages, MESSAGE_KEYS.summary );
	const limitNote = selection.limitReached ? message( messages, MESSAGE_KEYS.selectionLimit ) : '';

	return (
		<div className="woodev-records" data-owner={ owner } data-table={ table }>
			<FiltersBar
				definition={ definition }
				account={ envelope.context.account_id }
				query={ query }
				facets={ records.list.data?.facets ?? {} }
				messages={ messages }
				onChange={ change }
			/>
			{ extensions.renderActivity?.( handle ) }
			{ hasBulk &&
				extensions.renderBulkActions?.(
					{
						selectedIds: selection.selectedIds,
						clear: selection.clear,
					},
					handle
				) }
			{ limitNote !== '' && <p role="status">{ limitNote }</p> }
			{ summary !== '' && records.list.status === 'ready' && (
				<p className="woodev-records__summary" style={ STYLES.summary }>
					{ summary }
				</p>
			) }
			<Result
				title={ title }
				definition={ definition }
				messages={ messages }
				query={ query }
				records={ records }
				selection={ hasBulk ? selection : null }
				handle={ handle }
				extensions={ extensions }
				onChange={ change }
				onRetry={ records.reload }
			/>
		</div>
	);
}
