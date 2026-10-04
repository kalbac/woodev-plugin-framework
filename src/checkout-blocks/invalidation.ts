/**
 * Manual-edit invalidation (SP-11 C-1, #1087).
 *
 * A locality the shopper chose is only worth keeping while the native address still says what it
 * wrote. The moment the shopper edits City by hand — or changes the country — the selection is
 * DROPPED (locally and on the server), never silently kept: a carrier would otherwise keep rating a
 * city the address no longer names.
 *
 * What «still says» means is ONE contract with the server's rule (c)
 * (`Location_Service::is_customer_record_stale()` → `native_address_disagrees()`), so the chooser
 * never keeps a selection the server ignores, and never forgets one the server would keep:
 *
 * - the country is the record's;
 * - the City names the record's settlement — record-aware (`namesCity()`): «г. Москва» and «рп
 *   Мостовской» name their records, a spelling alias does not;
 * - the State is not ANOTHER region: when the record's region stands for a state code and the
 *   native State holds a different, non-blank one. A blank State names no region and disagrees
 *   with none.
 *
 * @package woodev-plugin-framework
 */

import { matchState, namesCity, normalizeCity, recordCityComponent } from './mapping';
import type { CountryStates } from './mapping';
import type { LocationRecord, Selection } from './types';

/** The part of a native address a chosen locality answers for. */
export interface NativeLocality {
	city: string;
	state: string;
	country: string;
}

/**
 * The selection `record` stands for: its bare city, its own type word, and the state code its
 * region maps to among `states` (`null` when the region field is removed, the country has no state
 * list, or no single option matches — nothing to vouch for).
 */
export function selectionFor(
	record: LocationRecord,
	context: { states: CountryStates; regionFieldRemoved: boolean }
): Selection {
	const { name, type } = recordCityComponent( record );

	return {
		key: record.key,
		city: name,
		cityType: type,
		country: record.country.toUpperCase(),
		state: context.regionFieldRemoved ? null : matchState( record, context.states ),
	};
}

/**
 * Whether `address` no longer matches the locality `selection` stands for.
 *
 * Compared by VALUE — never by object identity, so a store round trip that hands back a fresh
 * object with the same text is not an edit.
 */
export function isSelectionStale( selection: Selection, address: NativeLocality ): boolean {
	if ( selection.country.toUpperCase() !== address.country.toUpperCase() ) {
		return true;
	}

	if ( selection.state !== null && address.state !== '' && selection.state !== address.state ) {
		return true;
	}

	return ! namesCity( selection.city, selection.cityType, address.city );
}

export type SavedVerdict =
	/** The address names the saved record: the chooser starts from it. */
	| { status: 'claimed'; selection: Selection }
	/** The address names ANOTHER place: the saved record is provenance for nothing, and is cleared. */
	| { status: 'orphaned' }
	/** Not decidable yet: nothing is claimed and nothing is cleared. */
	| { status: 'pending' };

/**
 * What to do with the locality the server still holds for the customer, given the native address.
 *
 * `pending` — never `orphaned` — while the address cannot be shown to disagree: before
 * WooCommerce's cart data has arrived (`authoritative` is `false`: the store still holds its empty
 * defaults), and for a blank City, which names no place. Clearing there would erase a locality the
 * customer chose on the classic checkout, the cart or My Account — the chain is one, shared — on
 * the strength of an address that simply has not been filled in. The server reads a blank city the
 * same way.
 */
export function judgeSavedRecord(
	record: LocationRecord,
	address: NativeLocality,
	context: { states: CountryStates; regionFieldRemoved: boolean; authoritative: boolean }
): SavedVerdict {
	if ( ! context.authoritative || address.city.trim() === '' ) {
		return { status: 'pending' };
	}

	const selection = selectionFor( record, context );

	// A record that carries no name cannot be shown to disagree (the server keeps it too) — and
	// there is nothing to show for it either.
	if ( selection.city === '' ) {
		return { status: 'pending' };
	}

	return isSelectionStale( selection, address ) ? { status: 'orphaned' } : { status: 'claimed', selection };
}

/**
 * Whether the shopper edited the native locality between two reads of it — what decides that a
 * `/select` reply arrived too late to be written into the address form.
 */
export function hasLocalityMoved( before: NativeLocality, after: NativeLocality ): boolean {
	return (
		before.country.toUpperCase() !== after.country.toUpperCase() ||
		before.state !== after.state ||
		normalizeCity( before.city ) !== normalizeCity( after.city )
	);
}
