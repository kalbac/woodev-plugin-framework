/** Editable packing rows over the compatible semicolon-separated saved box list. */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl } from '@wordpress/components';

export type BoxRow = [ string, string, string, string, string, string ];
const emptyRow = (): BoxRow => [ '', '', '', '', '', '' ];
const number = ( text: string ): number => {
	const normalized = text.trim().replace( /,/g, '.' );
	return /^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:e[+-]?[0-9]+)?$/i.test( normalized ) ? Number( normalized ) : NaN;
};
const scale = ( text: string, factor: number ): string => '' !== text.trim() && Number.isFinite( number( text ) )
	? String( Number( ( number( text ) * factor ).toPrecision( 12 ) ) ) : text;

export function parseBoxRows( value: string, dimensionFactor = 1, weightFactor = 1 ): BoxRow[] {
	return String( value || '' ).split( /\r\n|[\n\r\u0085\u2028\u2029]/ ).filter( ( line ) => line.trim() ).map( ( line ) => {
		const cells = line.split( ';' ).map( ( cell ) => cell.trim() );
		// Retain a malformed line visibly; validation requires the merchant to repair it.
		const row = emptyRow().map( ( _, i ) => cells[ i ] || '' ) as BoxRow;
		if ( cells.length > 6 ) { row[ 0 ] = cells[ 0 ] + ';' + cells.slice( 6 ).join( ';' ); }
		return row.map( ( cell, i ) => i === 0 ? cell : scale( cell, i < 4 ? dimensionFactor : weightFactor ) ) as BoxRow;
	} );
}

export function serializeBoxRows( rows: BoxRow[], dimensionFactor = 1, weightFactor = 1 ): string {
	return rows.map( ( row ) => row.map( ( cell, i ) => i === 0 ? cell : scale( cell, 1 / ( i < 4 ? dimensionFactor : weightFactor ) ) ).join( '; ' ) ).join( '\n' );
}

export function validateBoxRow( row: BoxRow ): string | null {
	if ( ! row[ 0 ].replace( /<[^>]*>/g, '' ).trim() || /[;\r\n]/.test( row[ 0 ] ) ) {
		return __( 'Укажите название без точки с запятой и переноса строки.', 'woodev-plugin-framework' );
	}
	if ( row.slice( 1, 4 ).some( ( cell ) => ! cell.trim() || ! Number.isFinite( number( cell ) ) || number( cell ) <= 0 ) ) {
		return __( 'Размеры должны быть числами больше нуля.', 'woodev-plugin-framework' );
	}
	if ( row.slice( 4 ).some( ( cell ) => cell.trim() && ( ! Number.isFinite( number( cell ) ) || number( cell ) < 0 ) ) ) {
		return __( 'Вес должен быть числом не меньше нуля.', 'woodev-plugin-framework' );
	}
	return null;
}

export function validateBoxesText( value: unknown ): string | null {
	if ( typeof value !== 'string' ) { return __( 'Неверное значение.', 'woodev-plugin-framework' ); }
	for ( const row of parseBoxRows( value ) ) {
		const error = validateBoxRow( row );
		if ( error ) { return error; }
	}
	return null;
}

type Props = { value: string; onChange: ( value: string ) => void; disabled?: boolean; dimensionFactor?: number; weightFactor?: number };
export default function BoxesTable( { value, onChange, disabled = false, dimensionFactor = 1, weightFactor = 1 }: Props ) {
	const [ rows, setRows ] = useState( () => parseBoxRows( value, dimensionFactor, weightFactor ) );
	const emitted = useRef<string | null>( null );
	useEffect( () => {
		if ( value !== emitted.current ) { setRows( parseBoxRows( value, dimensionFactor, weightFactor ) ); }
	}, [ value, dimensionFactor, weightFactor ] );
	const change = ( next: BoxRow[] ) => {
		setRows( next );
		emitted.current = serializeBoxRows( next, dimensionFactor, weightFactor );
		onChange( emitted.current );
	};
	const headers = [
		__( 'Название', 'woodev-plugin-framework' ),
		__( 'Длина, см', 'woodev-plugin-framework' ),
		__( 'Ширина, см', 'woodev-plugin-framework' ),
		__( 'Высота, см', 'woodev-plugin-framework' ),
		__( 'Макс. вес, кг', 'woodev-plugin-framework' ),
		__( 'Вес упаковки, кг', 'woodev-plugin-framework' ),
	];
	return (
		<div className="woodev-boxes">
			<div className="woodev-boxes__scroll">
				<table className="widefat woodev-boxes__table">
					<thead><tr>{ headers.map( ( header ) => <th key={ header } scope="col">{ header }</th> ) }<th scope="col">{ __( 'Действия', 'woodev-plugin-framework' ) }</th></tr></thead>
					<tbody>{ rows.map( ( row, index ) => (
						<tr key={ index }>{ row.map( ( cell, column ) => (
							<td key={ column }>
								<TextControl __nextHasNoMarginBottom __next40pxDefaultSize hideLabelFromVision
									label={ `${ headers[ column ] }, ${ index + 1 }` } value={ cell } disabled={ disabled }
									onChange={ ( next ) => { const updated = rows.map( ( r ) => [ ...r ] as BoxRow ); updated[ index ][ column ] = next; change( updated ); } } />
								{ column === 0 && validateBoxRow( row ) && <div className="woodev-field__error" role="alert">{ validateBoxRow( row ) }</div> }
							</td>
						) ) }<td><Button variant="tertiary" isDestructive disabled={ disabled } onClick={ () => change( rows.filter( ( _, i ) => i !== index ) ) }>{ __( 'Удалить', 'woodev-plugin-framework' ) }</Button></td></tr>
					) ) }</tbody>
				</table>
			</div>
			<Button variant="secondary" disabled={ disabled } onClick={ () => change( [ ...rows, emptyRow() ] ) }>{ __( 'Добавить упаковку', 'woodev-plugin-framework' ) }</Button>
		</div>
	);
}
