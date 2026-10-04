/**
 * Maps a location record onto WooCommerce's NATIVE City and State values (SP-11 C-1, #1087).
 *
 * The chooser never invents a value the core address form cannot hold:
 *
 * - City is the settlement's bare component name, never the record's display label.
 * - State is a REAL key of the country's `woocommerce_states` list (what the core State control
 *   renders). A record's own region spelling — a provider key, a name — is never written as a state.
 *   When no option matches, nothing invalid is written: the state is cleared and the shopper picks it
 *   in the native control.
 * - With the region field removed (`regionFieldRemoved`), the state is not touched at all.
 *
 * Pure: no DOM, no store, no network — so the cases below are unit-tested directly.
 *
 * @package woodev-plugin-framework
 */

import type { LocationRecord } from './types';

/** WooCommerce `countryData[ country ].states`: state code => label. */
export type CountryStates = Record< string, string >;

export interface NativeAddressPatch {
	/** The bare city name to write into the native City field. */
	city: string;
	/**
	 * The state code to write; `null` means «leave the native value alone»
	 * (region field removed, so the shopper cannot see or fix it anyway).
	 */
	state: string | null;
	/** `false` only when the country HAS a state list and no option matched the record's region. */
	stateMatched: boolean;
}

/**
 * Words that name an administrative TYPE rather than the region itself («область», «край»…). They
 * are dropped before two region names are compared, so «Московская область» (a record) meets
 * «Московская обл.» (an option). Whole-token matches only.
 */
const TYPE_WORDS = new Set( [
	'область',
	'обл',
	'край',
	'республика',
	'респ',
	'автономный',
	'автономная',
	'округ',
	'ао',
	'федеральный',
	'город',
	'г',
	'region',
	'oblast',
	'republic',
	'province',
	'state',
	'county',
	'city',
	'of',
] );

/** Case-folded, `ё`→`е`, punctuation-free form of a region name — type words KEPT. */
function foldRegionName( name: string ): string[] {
	return name
		.toLocaleLowerCase()
		.replace( /ё/g, 'е' )
		.replace( /[^\p{L}\p{N}\s]/gu, ' ' )
		.split( /\s+/ )
		.filter( ( token ) => token !== '' );
}

/** The folded form with the administrative type words dropped: «Московская обл.» → «московская». */
export function normalizeRegionName( name: string ): string {
	return foldRegionName( name )
		.filter( ( token ) => ! TYPE_WORDS.has( token ) )
		.join( ' ' );
}

/** Case-folded, whitespace-collapsed city text — the comparison used for «did the shopper edit it». */
export function normalizeCity( city: string ): string {
	return city.toLocaleLowerCase().replace( /ё/g, 'е' ).replace( /\s+/g, ' ' ).trim();
}

/**
 * The city text for a record: the settlement's bare name. A record that carries no settlement
 * component but IS its own region (a city of federal significance published without a settlement)
 * falls back to the region's name — its identity is the same.
 */
export function recordCity( record: LocationRecord ): string {
	const settlement = record.settlement?.name?.trim();

	if ( settlement ) {
		return settlement;
	}

	return record.region?.name?.trim() ?? '';
}

/**
 * The names a record's REGION can be matched by, most specific first.
 *
 * For a city of federal significance (Moscow, Saint Petersburg, Sevastopol…) the region and the
 * settlement are ONE entity with one key: the record may carry the region component, the settlement
 * component, or both. Whichever it carries is the region's name; the settlement name is only a
 * candidate when there is NO region component (otherwise a normal city, «Подольск», would be
 * offered as a region candidate and could match a homonymous region).
 */
function regionCandidates( record: LocationRecord ): string[] {
	const region = record.region?.name?.trim();

	if ( region ) {
		return [ region ];
	}

	const settlement = record.settlement?.name?.trim();

	return settlement ? [ settlement ] : [];
}

/**
 * Finds the one state option for `record`, or `null` when none — or more than one — matches.
 *
 * Both the option's CODE and its LABEL are compared: the `related-list` mode injects options whose
 * code is the upper-cased label, native lists use ISO codes with human labels.
 *
 * Two passes, strictest first. Dropping type words only in the second pass keeps «Moscow» and
 * «Moscow Oblast» apart when both exist, while still letting «Московская область» meet an option
 * spelled «Московская обл.».
 */
export function matchState( record: LocationRecord, states: CountryStates ): string | null {
	const codes = Object.keys( states );
	const passes: Array< ( value: string ) => string > = [
		( value ) => foldRegionName( value ).join( ' ' ),
		normalizeRegionName,
	];

	for ( const candidate of regionCandidates( record ) ) {
		for ( const fold of passes ) {
			const wanted = fold( candidate );

			if ( wanted === '' ) {
				continue;
			}

			const hits = codes.filter(
				( code ) => fold( String( states[ code ] ) ) === wanted || fold( code ) === wanted
			);

			if ( hits.length === 1 ) {
				return hits[ 0 ];
			}

			// Two options for one name is ambiguous — better to ask the shopper than to guess.
			if ( hits.length > 1 ) {
				return null;
			}
		}
	}

	return null;
}

export function resolveNativeAddress(
	record: LocationRecord,
	context: { states: CountryStates; regionFieldRemoved: boolean }
): NativeAddressPatch {
	const city = recordCity( record );

	if ( context.regionFieldRemoved ) {
		return { city, state: null, stateMatched: true };
	}

	// A country with no state list renders no State control: nothing to match, nothing to clear.
	if ( Object.keys( context.states ).length === 0 ) {
		return { city, state: '', stateMatched: true };
	}

	const code = matchState( record, context.states );

	return code === null ? { city, state: '', stateMatched: false } : { city, state: code, stateMatched: true };
}
