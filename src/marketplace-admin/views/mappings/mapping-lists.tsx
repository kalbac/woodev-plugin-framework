/**
 * The two lists of the «Сопоставление и профили» page: mappings and profiles, both a WooCommerce table card of the look of
 * «Заказы доставки», with the «Добавить…» button at the right end of the card's header and «Редактировать» / «Удалить» on every row.
 * Every row is on one page; the name opens the form. «Удалить» asks in the marketplace admin's one dialog first.
 *
 * The tab above a list already names it, so the list draws no heading of its own. `TableCard` still needs a `title`: it
 * becomes the table's caption (the accessible name), and the stylesheet hides the visible heading.
 *
 * An empty list is the explanation of what the thing is and a button that creates the first one (the v1 pages' empty states).
 *
 * @package woodev-plugin-framework
 */

import type { ComponentType, ReactNode } from 'react';
import { useState } from '@wordpress/element';
import { Button, Card, CardBody, Tooltip } from '@wordpress/components';
import { HeadingWithHelp } from '../../../admin-records/table-model';
import { getTableCard, type TableCardProps, type TableCell, type TableHeader } from '../../../admin-records/wc-runtime';
import { DeleteDialog, type DeleteTarget } from './delete-dialog';
import type { AttributeCounts, AttributeCountsById, MappingsState } from './types';

/** `TableCard` with the header slot (it exists in WooCommerce 7.0 and later); C1 adds the prop to the shared type. */
type ListTableProps = TableCardProps & { actions?: ReactNode[] };

type Direction = 'asc' | 'desc';

interface Sort {
	key: string;
	direction: Direction;
}

interface ListItem {
	id: number;
	name: string;
	/** What each sortable column compares by, by column key. */
	values: Record< string, string | number >;
	cells: ReactNode[];
}

interface ListProps {
	title: string;
	addLabel: string;
	headers: TableHeader[];
	items: ListItem[];
	empty: ReactNode;
	onAdd: () => void;
}

/** Text first, compared the way a Russian reader sorts; numbers by value. */
function compare( a: string | number, b: string | number ): number {
	return typeof a === 'number' && typeof b === 'number' ? a - b : String( a ).localeCompare( String( b ), 'ru' );
}

/** The lists stand in a wrapper with its own inner padding, so a table never touches the edge of the page. */
function Wrapper( { children }: { children: ReactNode } ) {
	return <div className="woodev-marketplace-table">{ children }</div>;
}

function List( { title, addLabel, headers, items, empty, onAdd }: ListProps ) {
	const [ sort, setSort ] = useState< Sort >( { key: 'name', direction: 'asc' } );
	const TableCard = getTableCard() as ComponentType< ListTableProps > | null;

	// WooCommerce's `emptyMessage` does not exist in 7.0, so an empty list is a card of its own.
	if ( items.length === 0 ) {
		return (
			<Wrapper>
				<Card className="woodev-mappings-list woodev-mappings-list--empty">
					<CardBody>{ empty }</CardBody>
				</Card>
			</Wrapper>
		);
	}

	if ( ! TableCard ) {
		return null;
	}

	const sign = sort.direction === 'asc' ? 1 : -1;
	const sorted = [ ...items ].sort( ( a, b ) => sign * compare( a.values[ sort.key ], b.values[ sort.key ] ) );
	const rows: TableCell[][] = sorted.map( ( item ) =>
		item.cells.map( ( display, column ) => ( { display, value: item.values[ headers[ column ].key ] ?? '' } ) )
	);
	const add = (
		<Button key="add" __next40pxDefaultSize variant="primary" onClick={ onAdd }>
			{ addLabel }
		</Button>
	);

	return (
		<Wrapper>
			<TableCard
				className="woodev-mappings-list"
				title={ title }
				headers={ headers }
				rows={ rows }
				rowsPerPage={ rows.length }
				totalRows={ rows.length }
				query={ { orderby: sort.key, order: sort.direction, paged: '1' } }
				isLoading={ false }
				showMenu={ false }
				rowHeader={ 0 }
				rowKey={ ( _row, index ) => String( sorted[ index ].id ) }
				actions={ [ add ] }
				onSort={ ( key, direction ) => setSort( { key, direction: direction === 'desc' ? 'desc' : 'asc' } ) }
				onPageChange={ () => undefined }
				onQueryChange={ () => () => undefined }
			/>
		</Wrapper>
	);
}

interface RowButtonsProps {
	editLabel: string;
	deleteLabel: string;
	onEdit: () => void;
	onDelete: () => void;
}

/** «Редактировать» and «Удалить» of one row: two text buttons on one line. */
function RowButtons( { editLabel, deleteLabel, onEdit, onDelete }: RowButtonsProps ) {
	return (
		<span className="woodev-mappings-list__buttons">
			<Button variant="secondary" size="small" onClick={ onEdit }>
				{ editLabel }
			</Button>
			<Button variant="secondary" size="small" isDestructive onClick={ onDelete }>
				{ deleteLabel }
			</Button>
		</span>
	);
}

function LinkButton( { name, onClick }: { name: string; onClick: () => void } ) {
	return (
		<Button variant="link" onClick={ onClick }>
			{ name }
		</Button>
	);
}

/** A cell of plain text on one line; its full text is the `title`, for the cell the stylesheet clips. */
function Clipped( { text }: { text: string } ) {
	return (
		<span className="woodev-mappings-list__clipped" title={ text }>
			{ text }
		</span>
	);
}

/** «Не настроен» in the muted tone of a value that is not there. */
function Muted( { text }: { text: string } ) {
	return <span className="woodev-mappings-list__muted">{ text }</span>;
}

/** The sentence of a message with `{name}`-style places filled. */
function fill( text: string, values: Record< string, string | number > ): string {
	return Object.entries( values ).reduce( ( result, [ key, value ] ) => result.split( `{${ key }}` ).join( String( value ) ), text );
}

/** «3 из 12», and an orange «!» when a required attribute of the category is still empty; «—» until the counts have come, or when they cannot. */
function Attributes( { counts, messages }: { counts: AttributeCounts | undefined; messages: Record< string, string > } ) {
	if ( counts === undefined ) {
		return <Muted text="—" />;
	}

	const missing = counts.required_missing;
	const warning = fill( messages.attributesMissing, { count: missing } );

	return (
		<span className="woodev-mappings-list__attributes">
			{ fill( messages.attributesOf, { filled: counts.filled, total: counts.total } ) }
			{ missing > 0 && (
				<Tooltip text={ warning } className="woodev-records__tooltip" delay={ 150 }>
					<span className="woodev-mappings-list__warning" tabIndex={ 0 } role="img" aria-label={ warning }>
						!
					</span>
				</Tooltip>
			) }
		</span>
	);
}

interface TabProps {
	state: MappingsState;
	onOpen: ( id: number | null ) => void;
	/** Deletes a record; resolves when the page has shown the outcome, whichever it was. */
	onDelete: ( id: number ) => Promise< void >;
}

interface MappingsTabProps extends TabProps {
	/** Opens a profile of the «Профили» tab. */
	onOpenProfile: ( id: number ) => void;
	/** The attribute counts by mapping id; `null` until they have been read. */
	counts: AttributeCountsById | null;
}

/** The mappings tab's empty body: the v1 explanation and its button, or the way to a first shop category when there is none. */
function EmptyMappings( { state, onOpen }: Pick< TabProps, 'state' | 'onOpen' > ) {
	const { messages } = state;
	const noCategories = state.shop_categories.length === 0;

	return (
		<div className="woodev-mappings-list__empty">
			<p>{ noCategories ? messages.emptyNoShopCategories : messages.emptyMappings }</p>
			{ noCategories ? (
				<Button __next40pxDefaultSize variant="primary" href={ messages.shopCategoriesUrl }>
					{ messages.createShopCategory }
				</Button>
			) : (
				<Button __next40pxDefaultSize variant="primary" onClick={ () => onOpen( null ) }>
					{ messages.createFirstMapping }
				</Button>
			) }
		</div>
	);
}

export function MappingsList( { state, counts, onOpen, onOpenProfile, onDelete }: MappingsTabProps ) {
	const { messages, mappings, profiles, shop_categories: shopCategories } = state;
	const [ pending, setPending ] = useState< DeleteTarget | null >( null );
	const profileOf = ( id: number | null ) => profiles.find( ( profile ) => profile.id === id );

	const items: ListItem[] = mappings.map( ( mapping ) => {
		const category = mapping.category_label || messages.noCategory;
		const profile = profileOf( mapping.profile_id );
		const shop = mapping.terms
			.map( ( term ) => shopCategories.find( ( entry ) => entry.id === term )?.label )
			.filter( ( label ): label is string => label !== undefined )
			.join( ', ' );

		return {
			id: mapping.id,
			name: mapping.name,
			values: {
				name: mapping.name,
				category,
				shop,
				profile: profile?.name ?? '',
				attributes: counts?.[ mapping.id ]?.filled ?? 0,
				products: mapping.products,
			},
			cells: [
				<LinkButton key="name" name={ mapping.name } onClick={ () => onOpen( mapping.id ) } />,
				<Clipped key="category" text={ category } />,
				shop === '' ? <Muted key="shop" text="—" /> : <Clipped key="shop" text={ shop } />,
				profile ? <LinkButton key="profile" name={ profile.name } onClick={ () => onOpenProfile( profile.id ) } /> : <Muted key="profile" text="—" />,
				<Attributes key="attributes" counts={ counts?.[ mapping.id ] } messages={ messages } />,
				String( mapping.products ),
				<RowButtons
					key="buttons"
					editLabel={ messages.edit }
					deleteLabel={ messages.delete }
					onEdit={ () => onOpen( mapping.id ) }
					onDelete={ () => setPending( { id: mapping.id, name: mapping.name, usedIn: [] } ) }
				/>,
			],
		};
	} );

	return (
		<>
			<List
				title={ messages.tabMappings }
				addLabel={ messages.addMapping }
				headers={ [
					{ key: 'name', label: messages.columnName, isSortable: true, isLeftAligned: true, required: true },
					{ key: 'category', label: messages.columnCategory, isSortable: true, isLeftAligned: true },
					{ key: 'shop', label: messages.columnShopCategories, isSortable: true, isLeftAligned: true },
					{ key: 'profile', label: messages.columnProfile, isSortable: true, isLeftAligned: true },
					{
						key: 'attributes',
						label: <HeadingWithHelp label={ messages.columnAttributes } help={ messages.attributesHelp } />,
						isSortable: true,
						isLeftAligned: true,
					},
					{ key: 'products', label: messages.columnProducts, isSortable: true, isNumeric: true },
					{ key: 'actions', label: '', screenReaderLabel: messages.columnActions, isLeftAligned: true },
				] }
				items={ items }
				empty={ <EmptyMappings state={ state } onOpen={ onOpen } /> }
				onAdd={ () => onOpen( null ) }
			/>
			<DeleteDialog
				target={ pending }
				messages={ messages }
				kind="mapping"
				onDelete={ onDelete }
				onClose={ () => setPending( null ) }
			/>
		</>
	);
}

export function ProfilesList( { state, onOpen, onDelete }: TabProps ) {
	const { messages, profiles } = state;
	const [ pending, setPending ] = useState< DeleteTarget | null >( null );

	const items: ListItem[] = profiles.map( ( profile ) => {
		const properties = profile.properties.join( ', ' );
		const usedIn = profile.used_in.join( ', ' );

		return {
			id: profile.id,
			name: profile.name,
			values: { name: profile.name, properties, usedIn },
			cells: [
				<LinkButton key="name" name={ profile.name } onClick={ () => onOpen( profile.id ) } />,
				properties === '' ? <Muted key="properties" text={ messages.notConfigured } /> : <Clipped key="properties" text={ properties } />,
				usedIn === '' ? <Muted key="used" text="—" /> : <Clipped key="used" text={ usedIn } />,
				<RowButtons
					key="buttons"
					editLabel={ messages.edit }
					deleteLabel={ messages.delete }
					onEdit={ () => onOpen( profile.id ) }
					onDelete={ () => setPending( { id: profile.id, name: profile.name, usedIn: profile.used_in } ) }
				/>,
			],
		};
	} );

	return (
		<>
			<List
				title={ messages.tabProfiles }
				addLabel={ messages.addProfile }
				headers={ [
					{ key: 'name', label: messages.columnName, isSortable: true, isLeftAligned: true, required: true },
					{ key: 'properties', label: messages.columnProperties, isSortable: true, isLeftAligned: true },
					{ key: 'usedIn', label: messages.columnUsedIn, isSortable: true, isLeftAligned: true },
					{ key: 'actions', label: '', screenReaderLabel: messages.columnActions, isLeftAligned: true },
				] }
				items={ items }
				empty={
					<div className="woodev-mappings-list__empty">
						<p>{ messages.emptyProfiles }</p>
						<Button __next40pxDefaultSize variant="primary" onClick={ () => onOpen( null ) }>
							{ messages.createFirstProfile }
						</Button>
					</div>
				}
				onAdd={ () => onOpen( null ) }
			/>
			<DeleteDialog
				target={ pending }
				messages={ messages }
				kind="profile"
				onDelete={ onDelete }
				onClose={ () => setPending( null ) }
			/>
		</>
	);
}
