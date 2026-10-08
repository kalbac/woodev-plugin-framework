/**
 * The cities list of a shipping method's city limit (#1176) — what the zone modal shows under the mode select.
 *
 * Chips for the cities picked so far, and the generalized {@see LocationPicker} to add one. The list keeps WHOLE
 * records (the server re-resolves them by name after a provider switch, so a bare key would not do) and hands the
 * JSON back to the form's hidden input; the server validates and compacts it again on save, so nothing here is
 * trusted.
 *
 * The pure helpers ({@see addCity}, {@see removeCity}, {@see serializeCities}) are exported for the unit tests.
 *
 * @package woodev-plugin-framework
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import LocationPicker, { type LocationRecord } from '../components/location-picker';

/** `ok` counts; `stale` (another provider's, not re-resolved) and `outside` (not in the zone's regions) are ignored by the server. */
export type CityState = 'ok' | 'stale' | 'outside';

export interface CityItem {
	record: LocationRecord;
	state: CityState;
}

/** What `City_Limit_Form::render()` puts into `data-config`. */
export interface CityLimitConfig {
	inputId: string;
	instanceId: number;
	country: string;
	active: boolean;
	restRoot: string;
	nonce: string;
	items: CityItem[];
}

/** A list longer than this is cut on the server (`City_Limit::MAX_CITIES`). */
export const MAX_CITIES = 200;

/** The route (under `woodev/v1`) that searches inside the method's zone. */
export const SEARCH_ENDPOINT = '/location/city-limit/suggest';

/**
 * Adds a picked city; a city already in the list is left where it is.
 *
 * @param {CityItem[]}     items  The list so far.
 * @param {LocationRecord} record The picked record.
 * @return {CityItem[]} The new list.
 */
export function addCity( items: CityItem[], record: LocationRecord ): CityItem[] {
	if ( items.some( ( item ) => item.record.key === record.key ) || items.length >= MAX_CITIES ) {
		return items;
	}

	return [ ...items, { record: withoutRaw( record ), state: 'ok' } ];
}

/**
 * Removes a city by key.
 *
 * @param {CityItem[]} items The list so far.
 * @param {string}     key   The city's locality key.
 * @return {CityItem[]} The new list.
 */
export function removeCity( items: CityItem[], key: string ): CityItem[] {
	return items.filter( ( item ) => item.record.key !== key );
}

/**
 * The JSON the form's hidden input carries.
 *
 * @param {CityItem[]} items The list.
 * @return {string} A JSON array of records.
 */
export function serializeCities( items: CityItem[] ): string {
	return JSON.stringify( items.map( ( item ) => withoutRaw( item.record ) ) );
}

/**
 * A record without the provider's opaque payload — it can be kilobytes per city and the server never reads it here.
 *
 * @param {LocationRecord} record A record.
 * @return {LocationRecord} The same record, `raw` dropped.
 */
function withoutRaw( record: LocationRecord ): LocationRecord {
	const { raw, ...rest } = record as LocationRecord & { raw?: unknown };

	void raw;

	return rest as LocationRecord;
}

/**
 * The text of a chip: the record's own label, then the settlement's name, then the key.
 *
 * @param {LocationRecord} record A record.
 * @return {string} Display text.
 */
export function cityLabel( record: LocationRecord ): string {
	return ( record.label || record.settlement?.name || record.key ).trim();
}

interface CityLimitListProps {
	config: CityLimitConfig;
	/** Called with the new JSON after every change. */
	onChange: ( value: string ) => void;
}

/**
 * @param {CityLimitListProps} props Component props.
 * @return {JSX.Element} The list.
 */
export default function CityLimitList( { config, onChange }: CityLimitListProps ) {
	const [ items, setItems ] = useState<CityItem[]>( config.items );

	const update = ( next: CityItem[] ) => {
		setItems( next );
		onChange( serializeCities( next ) );
	};

	return (
		<div className="woodev-city-limit__control">
			{ items.length > 0 && (
				<ul className="woodev-city-limit__list">
					{ items.map( ( item ) => (
						<li
							key={ item.record.key }
							className={ 'woodev-city-limit__chip is-' + item.state }
							title={
								'stale' === item.state
									? __( 'Не учитывается: город выбран для другого сервиса определения местоположения', 'woodev-plugin-framework' )
									: 'outside' === item.state
										? __( 'Не учитывается: город не входит в регионы зоны доставки', 'woodev-plugin-framework' )
										: undefined
							}
						>
							<span className="woodev-city-limit__chip-label">{ cityLabel( item.record ) }</span>
							<button
								type="button"
								className="woodev-city-limit__chip-remove"
								aria-label={ __( 'Удалить город', 'woodev-plugin-framework' ) + ': ' + cityLabel( item.record ) }
								onClick={ () => update( removeCity( items, item.record.key ) ) }
							>
								×
							</button>
						</li>
					) ) }
				</ul>
			) }
			<LocationPicker
				value={ null }
				level="settlement"
				country={ config.country }
				restRoot={ config.restRoot }
				nonce={ config.nonce }
				endpoint={ SEARCH_ENDPOINT }
				params={ { instance_id: String( config.instanceId ) } }
				disabled={ ! config.active }
				placeholder={ __( 'Добавить город…', 'woodev-plugin-framework' ) }
				onChange={ ( suggestion ) => update( addCity( items, suggestion.record ) ) }
			/>
		</div>
	);
}
