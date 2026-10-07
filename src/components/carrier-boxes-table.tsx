/** Carrier presets share a table while retaining their individual stored setting keys. */
import { __ } from '@wordpress/i18n';
import { TextControl, ToggleControl } from '@wordpress/components';
import FieldTip from './field-tip';
import { validateBoxCost } from './boxes-table';

type Preset = { id: string; name: string; length: number; width: number; height: number; max_weight: number; box_weight: number; cost_mode: 'carrier' | 'fixed' | 'merchant'; field: 'enabled' | 'charge' | 'cost' };
type Field = { value: string | boolean; disabled?: boolean; tooltip?: string; box_preset?: Preset };
type Props = { fields: Record<string, Field>; values: Record<string, string | boolean>; onFieldChange: ( id: string, value: string | boolean ) => void; serverErrors?: Record<string, string> };

export default function CarrierBoxesTable( { fields, values, onFieldChange, serverErrors = {} }: Props ) {
	const rows = new Map<string, { preset: Preset; cells: Partial<Record<Preset['field'], string>> }>();
	Object.entries( fields ).forEach( ( [ id, field ] ) => {
		const preset = field.box_preset;
		if ( ! preset ) { return; }
		if ( ! rows.has( preset.id ) ) { rows.set( preset.id, { preset, cells: {} } ); }
		rows.get( preset.id )!.cells[ preset.field ] = id;
	} );
	if ( ! rows.size ) { return null; }
	const value = ( id: string ) => values[ id ] ?? fields[ id ].value;
	const enabledLabel = __( 'Использовать', 'woodev-plugin-framework' );
	const costTips = Array.from( new Set(
		Array.from( rows.values() )
			.map( ( { cells } ) => fields[ ( cells.charge || cells.cost )! ].tooltip )
			.filter( Boolean )
	) ).join( ' ' );
	const costLabel = __( 'Стоимость', 'woodev-plugin-framework' );
	return <div className="woodev-boxes woodev-boxes--carrier">
		<div className="woodev-boxes__scroll">
			<table className="widefat woodev-boxes__table">
				<thead><tr>
					<th scope="col">{ __( 'Название', 'woodev-plugin-framework' ) }</th>
					<th scope="col">{ __( 'Размеры, см', 'woodev-plugin-framework' ) }</th>
					<th scope="col">{ __( 'Макс. вес, кг', 'woodev-plugin-framework' ) }</th>
					<th scope="col">{ __( 'Вес упаковки, кг', 'woodev-plugin-framework' ) }</th>
					<th scope="col">{ __( 'Вкл', 'woodev-plugin-framework' ) }</th>
					<th scope="col">{ costLabel }<FieldTip text={ costTips } /></th>
				</tr></thead>
				<tbody>{ Array.from( rows.values() ).map( ( { preset, cells } ) => {
					const enabledId = cells.enabled!;
					const costId = ( cells.charge || cells.cost )!;
					const error = serverErrors[ costId ] || ( preset.cost_mode === 'merchant' ? validateBoxCost( value( costId ) ) : null );
					return <tr key={ preset.id }>
						<th scope="row">{ preset.name }</th>
						<td>{ preset.length } × { preset.width } × { preset.height }</td>
						<td>{ preset.max_weight || '—' }</td><td>{ preset.box_weight }</td>
						<td><ToggleControl __nextHasNoMarginBottom label="" aria-label={ `${ enabledLabel }, ${ preset.name }` } checked={ !! value( enabledId ) } disabled={ !! fields[ enabledId ].disabled } onChange={ ( checked ) => onFieldChange( enabledId, checked ) } /></td>
						<td className={ error ? 'woodev-field--error' : undefined }>
							{ preset.cost_mode === 'carrier'
								? <ToggleControl __nextHasNoMarginBottom label="" aria-label={ `${ __( 'Учитывать стоимость', 'woodev-plugin-framework' ) }, ${ preset.name }` } checked={ !! value( costId ) } disabled={ !! fields[ costId ].disabled } onChange={ ( checked ) => onFieldChange( costId, checked ) } />
								: <TextControl __nextHasNoMarginBottom __next40pxDefaultSize hideLabelFromVision label={ `${ costLabel }, ${ preset.name }` } value={ String( value( costId ) ) } readOnly={ preset.cost_mode === 'fixed' } disabled={ !! fields[ costId ].disabled } onChange={ ( next ) => onFieldChange( costId, next ) } /> }
							{ error && <div className="woodev-field__error" role="alert">{ error }</div> }
						</td>
					</tr>;
				} ) }</tbody>
			</table>
		</div>
		<p>{ __( 'Стоимость: число или N%, например 2%.', 'woodev-plugin-framework' ) }</p>
	</div>;
}
