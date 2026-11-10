/**
 * The attribute table of a category: one row per field, with where its value comes from.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useState } from '@wordpress/element';
import { Button, SelectControl, TextControl } from '@wordpress/components';
import type { MappingsApi } from './api';
import type { AttributeRow, AttributeSource, DictionaryValue, Messages, Option } from './types';

const SEARCH_DELAY_MS = 250;
const MIN_SEARCH = 2;

interface TableProps {
	api: MappingsApi;
	messages: Messages;
	categoryKey: string;
	productFields: Option[];
	rows: AttributeRow[];
	onChange: ( rows: AttributeRow[] ) => void;
}

/** Kinds of source a row may pick; the values are the server's `Source` kinds. */
function sourceOptions( messages: Messages ): Option[] {
	return [
		{ value: '', label: messages.sourceNone },
		{ value: 'fixed', label: messages.sourceFixed },
		{ value: 'wc_attribute', label: messages.sourceAttribute },
		{ value: 'meta', label: messages.sourceMeta },
		{ value: 'field', label: messages.sourceField },
	];
}

export function AttributeTable( { api, messages, categoryKey, productFields, rows, onChange }: TableProps ) {
	const update = ( id: string, source: AttributeSource ) =>
		onChange( rows.map( ( row ) => ( row.id === id ? { ...row, source } : row ) ) );

	return (
		<table className="widefat striped">
			<thead>
				<tr>
					<th>{ messages.columnAttribute }</th>
					<th>{ messages.columnSource }</th>
					<th>{ messages.columnValue }</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ row.id }>
						<td>
							{ row.name }
							{ row.unit ? ` (${ row.unit })` : '' }
							{ row.required && <em>{ ` — ${ messages.required }` }</em> }
						</td>
						<td>
							<SelectControl __next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ `${ row.name }: ${ messages.columnSource }` }
								hideLabelFromVision
								value={ row.source.source }
								options={ sourceOptions( messages ) }
								onChange={ ( kind ) => update( row.id, { source: kind, value: '', key: '', dictionary_value_id: '' } ) }
							/>
						</td>
						<td>
							<ValueCell
								api={ api }
								messages={ messages }
								categoryKey={ categoryKey }
								productFields={ productFields }
								row={ row }
								onChange={ ( source ) => update( row.id, source ) }
							/>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

interface CellProps {
	api: MappingsApi;
	messages: Messages;
	categoryKey: string;
	productFields: Option[];
	row: AttributeRow;
	onChange: ( source: AttributeSource ) => void;
}

function ValueCell( { api, messages, categoryKey, productFields, row, onChange }: CellProps ) {
	const { source } = row;
	const label = `${ row.name }: ${ messages.columnValue }`;

	if ( source.source === 'fixed' && row.dictionary ) {
		return <DictionaryInput api={ api } messages={ messages } categoryKey={ categoryKey } row={ row } onChange={ onChange } />;
	}

	if ( source.source === 'fixed' ) {
		return (
			<TextControl __next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				hideLabelFromVision
				placeholder={ messages.fixedValue }
				value={ source.value }
				onChange={ ( value ) => onChange( { ...source, value } ) }
			/>
		);
	}

	if ( source.source === 'field' ) {
		return (
			<SelectControl __next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				hideLabelFromVision
				value={ source.key }
				options={ [ { value: '', label: messages.sourceNone }, ...productFields ] }
				onChange={ ( key ) => onChange( { ...source, key } ) }
			/>
		);
	}

	if ( source.source === 'wc_attribute' || source.source === 'meta' ) {
		return (
			<TextControl __next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				hideLabelFromVision
				placeholder={ source.source === 'meta' ? messages.metaKey : messages.attributeKey }
				value={ source.key }
				onChange={ ( key ) => onChange( { ...source, key } ) }
			/>
		);
	}

	return null;
}

interface DictionaryProps {
	api: MappingsApi;
	messages: Messages;
	categoryKey: string;
	row: AttributeRow;
	onChange: ( source: AttributeSource ) => void;
}

/** A fixed value taken from the marketplace's dictionary: search, then pick. */
function DictionaryInput( { api, messages, categoryKey, row, onChange }: DictionaryProps ) {
	const [ term, setTerm ] = useState( '' );
	const [ results, setResults ] = useState< DictionaryValue[] | null >( null );

	useEffect( () => {
		if ( term.trim().length < MIN_SEARCH ) {
			setResults( null );
			return undefined;
		}

		let current = true;
		const timer = setTimeout( () => {
			api.dictionary( categoryKey, row.id, term )
				.then( ( response ) => current && setResults( response.results ) )
				.catch( () => current && setResults( [] ) );
		}, SEARCH_DELAY_MS );

		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ api, categoryKey, row.id, term ] );

	const pick = ( value: DictionaryValue ) => {
		setTerm( '' );
		onChange( { ...row.source, value: value.name, dictionary_value_id: String( value.id ) } );
	};

	return (
		<div>
			{ row.source.value !== '' && <strong>{ row.source.value }</strong> }
			<TextControl __next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ `${ row.name }: ${ messages.columnValue }` }
				hideLabelFromVision
				placeholder={ messages.dictionarySearch }
				value={ term }
				onChange={ setTerm }
			/>
			{ results !== null && results.length === 0 && <p className="description">{ messages.noResults }</p> }
			<ul style={ { margin: 0 } }>
				{ ( results ?? [] ).map( ( value ) => (
					<li key={ value.id }>
						<Button variant="tertiary" onClick={ () => pick( value ) }>
							{ value.text }
						</Button>
					</li>
				) ) }
			</ul>
		</div>
	);
}
