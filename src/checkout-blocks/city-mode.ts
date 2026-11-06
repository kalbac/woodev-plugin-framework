/**
 * City mode on Checkout Blocks (range-shipping city mode spec §4.2): the closed list of places the
 * merchant listed for the shipping country, and the questions the clients ask of it.
 *
 * Pure: no DOM, no store, no network.
 *
 * @package woodev-plugin-framework
 */

import { namesCity } from './mapping';
import type { AllowedPlace, LocationConfig, LocationRecord } from './types';

/** The places listed for `country`, or `null` when the merchant lists none there. */
export function allowedPlaces( config: LocationConfig, country: string ): AllowedPlace[] | null {
	const places = config.allowed?.[ country.toUpperCase() ];

	return Array.isArray( places ) && places.length > 0 ? places : null;
}

/**
 * The one listed place of `country`, or `null` when there are several or none. One place is not a
 * choice: the clients write it into the address instead of asking.
 */
export function singlePlace( config: LocationConfig, country: string ): AllowedPlace | null {
	const places = allowedPlaces( config, country );

	return places !== null && places.length === 1 ? places[ 0 ] : null;
}

/** The text a listed place posts as City, looked up by the record's key; `''` when it is not listed. */
export function listedPlaceValue( config: LocationConfig, record: LocationRecord ): string {
	const place = allowedPlaces( config, record.country )?.find( ( listed ) => listed.key === record.key );

	return place?.value ?? '';
}

/**
 * Whether `city` names one of `places` — by the place's own name, or by its parent's name, the way
 * the server's `Location_Record::names_locality()` accepts both.
 */
export function namesListedPlace( places: AllowedPlace[], city: string ): boolean {
	return places.some(
		( place ) =>
			namesCity( place.value, '', city ) ||
			namesCity( place.record.settlement?.name ?? '', place.record.settlement?.type ?? '', city )
	);
}
