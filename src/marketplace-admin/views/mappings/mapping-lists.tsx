/**
 * The two lists of the «Сопоставление» page: mappings and profiles, each a WooCommerce table card with the «Добавить…»
 * button in its header. Every row is on one page; the name opens the form.
 *
 * @package woodev-plugin-framework
 */

import type { ComponentType, ReactNode } from 'react';
import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { getTableCard, type TableCardProps, type TableCell, type TableHeader } from '../../../admin-records/wc-runtime';
import type { MappingsState } from './types';

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
	emptyText: string;
	headers: TableHeader[];
	items: ListItem[];
	onAdd: () => void;
}

/** Text first, compared the way a Russian reader sorts; numbers by value. */
function compare( a: string | number, b: string | number ): number {
	return typeof a === 'number' && typeof b === 'number' ? a - b : String( a ).localeCompare( String( b ), 'ru' );
}

function List( { title, addLabel, emptyText, headers, items, onAdd }: ListProps ) {
	const [ sort, setSort ] = useState< Sort >( { key: 'name', direction: 'asc' } );
	const TableCard = getTableCard() as ComponentType< ListTableProps > | null;
	const add = (
		<Button key="add" __next40pxDefaultSize variant="primary" onClick={ onAdd }>
			{ addLabel }
		</Button>
	);

	// WooCommerce's `emptyMessage` does not exist in 7.0, so an empty list is a card of its own.
	if ( items.length === 0 ) {
		return (
			<Card className="woodev-mappings-list woodev-mappings-list--empty">
				<CardHeader>
					<h2 className="woodev-mappings-list__title">{ title }</h2>
					{ add }
				</CardHeader>
				<CardBody>
					<p className="woodev-mappings-list__empty">{ emptyText }</p>
				</CardBody>
			</Card>
		);
	}

	if ( ! TableCard ) {
		return null;
	}

	const sign = sort.direction === 'asc' ? 1 : -1;
	const sorted = [ ...items ].sort( ( a, b ) => sign * compare( a.values[ sort.key ], b.values[ sort.key ] ) );
	const rows: TableCell[][] = sorted.map( ( item ) =>
		item.cells.map( ( display, column ) => ( { display, value: item.values[ headers[ column ].key ] } ) )
	);

	return (
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
	);
}

interface TabProps {
	state: MappingsState;
	onOpen: ( id: number | null ) => void;
}

function NameButton( { name, onClick }: { name: string; onClick: () => void } ) {
	return (
		<Button variant="link" onClick={ onClick }>
			{ name }
		</Button>
	);
}

export function MappingsList( { state, onOpen }: TabProps ) {
	const { messages, mappings, profiles } = state;
	const profileName = ( id: number | null ) => profiles.find( ( profile ) => profile.id === id )?.name ?? '—';

	const items: ListItem[] = mappings.map( ( mapping ) => {
		const category = mapping.category_label || messages.noCategory;
		const profile = profileName( mapping.profile_id );

		return {
			id: mapping.id,
			name: mapping.name,
			values: { name: mapping.name, category, profile, products: mapping.products },
			cells: [ <NameButton key="name" name={ mapping.name } onClick={ () => onOpen( mapping.id ) } />, category, profile, mapping.products ],
		};
	} );

	return (
		<List
			title={ messages.tabMappings }
			addLabel={ messages.addMapping }
			emptyText={ messages.emptyMappings }
			headers={ [
				{ key: 'name', label: messages.columnName, isSortable: true, isLeftAligned: true, required: true },
				{ key: 'category', label: messages.columnCategory, isSortable: true, isLeftAligned: true },
				{ key: 'profile', label: messages.columnProfile, isSortable: true, isLeftAligned: true },
				{ key: 'products', label: messages.columnProducts, isSortable: true, isNumeric: true },
			] }
			items={ items }
			onAdd={ () => onOpen( null ) }
		/>
	);
}

export function ProfilesList( { state, onOpen }: TabProps ) {
	const { messages, profiles } = state;

	const items: ListItem[] = profiles.map( ( profile ) => ( {
		id: profile.id,
		name: profile.name,
		values: { name: profile.name, mappings: profile.mappings },
		cells: [ <NameButton key="name" name={ profile.name } onClick={ () => onOpen( profile.id ) } />, profile.mappings ],
	} ) );

	return (
		<List
			title={ messages.tabProfiles }
			addLabel={ messages.addProfile }
			emptyText={ messages.emptyProfiles }
			headers={ [
				{ key: 'name', label: messages.columnName, isSortable: true, isLeftAligned: true, required: true },
				{ key: 'mappings', label: messages.columnMappings, isSortable: true, isNumeric: true },
			] }
			items={ items }
			onAdd={ () => onOpen( null ) }
		/>
	);
}
