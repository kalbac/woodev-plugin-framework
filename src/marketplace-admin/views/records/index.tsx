/**
 * Records view: every manifest page of `kind: records` renders the generic records shell (`src/admin-records/`) over
 * one of the page's tables, owned by the page's marketplace plugin.
 *
 * A page that declares several tables (the products page: the shop's products and the marketplace's cards) shows a
 * switch between them. The labels come from PHP (`tableLabels`); the active table is a URL argument, accepted only when
 * it names one of the page's own tables; and a switch starts the other table from scratch — its own filters, page and
 * sort, no expanded row, no selection, no open dialog — so nothing of the table that was left leaks into the new one.
 *
 * @package woodev-plugin-framework
 */

import { Button } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { RecordsTable, useActionExtensions } from '../../../admin-records';
import { createActivityExtensions } from '../../../admin-records/activity';
import { getNavigate } from '../../../admin-records/wc-runtime';
import { PAGE_VIEW_FILTER } from '../../page-view';
import type { ManifestPage, PageView, PageViewProps } from '../../types';

/** The URL argument that names the active table; absent means the page's first table. */
export const TABLE_ARGUMENT = 'table';

/**
 * The route arguments that outlive a switch: wc-admin's own (`page`, `path`), the account both tables are read for, and
 * the table argument itself. Every other argument is the left table's (its filters, page, sort, search).
 */
const SURVIVING_ARGUMENTS = [ 'page', 'path', 'account', TABLE_ARGUMENT ];

const STYLES = {
	switcher: { display: 'flex', flexWrap: 'wrap', gap: '8px', margin: '0 0 16px' },
} as const;

/**
 * The table the URL asks for, when the page has it; the page's first table for anything else (an argument that names no
 * table of this page is a mistake, never a route to another table).
 *
 * @param page  The page being shown.
 * @param query The route's query.
 */
export function activeTable( page: ManifestPage, query: Record< string, string > ): string {
	const asked = query[ TABLE_ARGUMENT ];

	return asked !== undefined && page.tables.includes( asked ) ? asked : page.tables[ 0 ];
}

/**
 * The URL change that makes a table the active one: the argument itself (left out for the first table, which is what
 * a page without the argument shows) and the removal of every argument that belonged to the table being left.
 *
 * @param page  The page being shown.
 * @param query The route's query.
 * @param table The table to show.
 */
export function switchPatch( page: ManifestPage, query: Record< string, string >, table: string ): Record< string, string | undefined > {
	const patch: Record< string, string | undefined > = { [ TABLE_ARGUMENT ]: table === page.tables[ 0 ] ? undefined : table };

	Object.keys( query )
		.filter( ( key ) => ! SURVIVING_ARGUMENTS.includes( key ) )
		.forEach( ( key ) => {
			patch[ key ] = undefined;
		} );

	return patch;
}

/**
 * The page mounts both parts the shell leaves open: the activity part (locks, processes, the refresh loop, the account
 * line) and the action part (the selection column, the bulk toolbar, the dialog a row or bulk action opens). A table whose
 * definition declares no actions and no activity renders as before: neither part adds anything PHP did not declare.
 *
 * The parts keep their state here, so the view is mounted once per table (`key`): leaving a table drops it all.
 */
function TableView( { page, menu, query, table }: PageViewProps & { table: string } ) {
	const activity = useMemo( () => createActivityExtensions(), [] );
	const actions = useActionExtensions( activity );

	return (
		<RecordsTable
			owner={ menu.pluginId }
			table={ table }
			title={ page.tableLabels?.[ table ] ?? page.title }
			query={ query }
			extensions={ { ...actions, ...activity.extensions } }
		/>
	);
}

function TableSwitcher( { page, query, active }: { page: ManifestPage; query: Record< string, string >; active: string } ) {
	return (
		<nav className="woodev-marketplace-tables" aria-label={ page.title } style={ STYLES.switcher }>
			{ page.tables.map( ( table ) => (
				<Button
					key={ table }
					variant={ table === active ? 'primary' : 'secondary' }
					aria-current={ table === active ? 'page' : undefined }
					data-table={ table }
					onClick={ () => {
						if ( table !== active ) {
							getNavigate()?.( switchPatch( page, query, table ) );
						}
					} }
				>
					{ page.tableLabels?.[ table ] ?? table }
				</Button>
			) ) }
		</nav>
	);
}

function RecordsView( props: PageViewProps ) {
	const { page, query } = props;
	const table = activeTable( page, query );

	return (
		<>
			{ page.tables.length > 1 && <TableSwitcher page={ page } query={ query } active={ table } /> }
			<TableView key={ table } { ...props } table={ table } />
		</>
	);
}

addFilter( PAGE_VIEW_FILTER, 'woodev/marketplace-records-view', ( view: PageView | null, page: ManifestPage ) =>
	page.kind === 'records' && page.tables.length > 0 ? RecordsView : view
);
