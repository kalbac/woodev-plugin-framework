/**
 * The two lists of the «Сопоставление и профили» page: mappings and profiles, both a table of the records engine
 * (`src/admin-records/`) over a PHP-described table, so they look and work like «Заказы маркетплейсов» and «Товары сайта»:
 * the same header, footer and row buttons. The columns, the sentences and the section description come from PHP
 * (`Mapping_List_Definition`, `Profile_List_Definition`); this file adds what is the page's own:
 *
 * - «Добавить …» at the right end of the card's header, and «Создать первое …» in the empty table;
 * - «Редактировать» and the name open the form in the page itself (the links are real addresses, so a middle click still opens a tab);
 * - «Удалить» asks in the marketplace admin's one dialog first.
 *
 * @package woodev-plugin-framework
 */

import type { MouseEvent } from 'react';
import { useRef, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { RecordsTable, type RecordsExtensions, type TableHandle } from '../../../admin-records';
import type { AdminRecord, LinkCell, RouteQuery } from '../../../admin-records/types';
import { DeleteDialog, type DeleteTarget } from './delete-dialog';
import type { MappingsState } from './types';

/** The arguments of the page's route a link of the table points at (`tab`, `mapping`, `profile`); `undefined` removes one. */
export type RouteArguments = Record< 'tab' | 'mapping' | 'profile', string | undefined >;

/** The route a link of the table leads to, or `null` when it is not a link to a form of this page. */
function routeOf( href: string ): RouteArguments | null {
	const { searchParams } = new URL( href, window.location.href );
	const route: RouteArguments = {
		tab: searchParams.get( 'tab' ) ?? undefined,
		mapping: searchParams.get( 'mapping' ) ?? undefined,
		profile: searchParams.get( 'profile' ) ?? undefined,
	};

	return route.mapping === undefined && route.profile === undefined ? null : route;
}

/** Which of the two lists: its table, the messages that name it. */
const LISTS = {
	mapping: { table: 'mappings', title: 'tabMappings', add: 'addMapping' },
	profile: { table: 'profiles', title: 'tabProfiles', add: 'addProfile' },
} as const;

export interface RecordsListProps {
	kind: keyof typeof LISTS;
	/** The plugin that registered the tables (the records owner). */
	owner: string;
	/** The route query of the page: the table keeps its page, sort and size there. */
	query: RouteQuery;
	state: MappingsState;
	/** Opens a form from a link of the table. */
	onFollow: ( route: RouteArguments ) => void;
	/** Opens the form of a record, or of a new one (`null`). */
	onOpen: ( id: number | null ) => void;
	/** Deletes a record; resolves when the page has shown the outcome, whichever it was. */
	onDelete: ( id: number ) => Promise< void >;
}

/** A click on a link of the table that stays on this page: the form opens without loading the page again. */
function followInPage( event: MouseEvent, onFollow: ( route: RouteArguments ) => void ) {
	const link = ( event.target as Element ).closest( 'a' );
	const plain = event.button === 0 && ! ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey );
	const route = link && plain && link.target !== '_blank' ? routeOf( link.href ) : null;

	if ( route ) {
		event.preventDefault();
		onFollow( route );
	}
}

/** What the delete dialog says about a row: its name and, for a profile, the mappings that choose it. */
function deleteTarget( row: AdminRecord, kind: RecordsListProps[ 'kind' ], state: MappingsState ): DeleteTarget {
	const id = Number( row.id );
	const profile = kind === 'profile' ? state.profiles.find( ( entry ) => entry.id === id ) : undefined;

	return { id, name: ( row.cells.name as LinkCell ).label, usedIn: profile?.used_in ?? [] };
}

/** The empty table's button: the way to the first record, or to the first shop category when there is none to map. */
function EmptyButton( { kind, state, onOpen }: Pick< RecordsListProps, 'kind' | 'state' | 'onOpen' > ) {
	const { messages, shop_categories: shopCategories } = state;

	if ( kind === 'mapping' && shopCategories.length === 0 ) {
		return (
			<Button __next40pxDefaultSize variant="primary" href={ messages.shopCategoriesUrl }>
				{ messages.createShopCategory }
			</Button>
		);
	}

	return (
		<Button __next40pxDefaultSize variant="primary" onClick={ () => onOpen( null ) }>
			{ kind === 'mapping' ? messages.createFirstMapping : messages.createFirstProfile }
		</Button>
	);
}

export function RecordsList( { kind, owner, query, state, onFollow, onOpen, onDelete }: RecordsListProps ) {
	const { messages } = state;
	const list = LISTS[ kind ];
	const [ pending, setPending ] = useState< DeleteTarget | null >( null );
	const handle = useRef< TableHandle | null >( null );

	const extensions: RecordsExtensions = {
		onRowAction: ( action, row, tableHandle ) => {
			handle.current = tableHandle;
			setPending( action.id === 'delete' ? deleteTarget( row, kind, state ) : null );
		},
		renderHeaderActions: () => (
			<Button className="woodev-mappings-add" __next40pxDefaultSize variant="primary" onClick={ () => onOpen( null ) }>
				{ messages[ list.add ] }
			</Button>
		),
		renderEmptyActions: () => <EmptyButton kind={ kind } state={ state } onOpen={ onOpen } />,
	};

	// The row is gone once the page has shown the outcome: read the table again.
	const remove = async ( id: number ) => {
		await onDelete( id );
		handle.current?.reload();
	};

	return (
		// The wrapper catches the click of a link of the table before the browser follows it.
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events
		<div className="woodev-marketplace-table woodev-mappings-records" onClick={ ( event ) => followInPage( event, onFollow ) }>
			<RecordsTable owner={ owner } table={ list.table } title={ messages[ list.title ] } query={ query } extensions={ extensions } />
			<DeleteDialog target={ pending } messages={ messages } kind={ kind } onDelete={ remove } onClose={ () => setPending( null ) } />
		</div>
	);
}
