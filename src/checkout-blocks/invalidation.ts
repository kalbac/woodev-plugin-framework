/**
 * Manual-edit invalidation (SP-11 C-1, #1087).
 *
 * A locality the shopper chose is only worth keeping while the native address still says what it
 * wrote. The moment the shopper edits City by hand — or changes the country — the selection is
 * DROPPED (locally and on the server), never silently kept: a carrier would otherwise keep rating a
 * city the address no longer names.
 *
 * @package woodev-plugin-framework
 */

import { normalizeCity } from './mapping';
import type { Selection } from './types';

/** The part of a native address a chosen locality answers for. */
export interface NativeLocality {
	city: string;
	state: string;
	country: string;
}

/**
 * Whether `address` no longer matches the locality `selection` wrote.
 *
 * Compared by VALUE (case, ё and spacing folded) — never by object identity, so a store round trip
 * that hands back a fresh object with the same text is not an edit. The state is watched only when
 * the chooser wrote one it can vouch for: a region the shopper had to pick by hand is theirs.
 */
export function isSelectionStale( selection: Selection, address: NativeLocality ): boolean {
	if ( selection.country.toUpperCase() !== address.country.toUpperCase() ) {
		return true;
	}

	if ( selection.state !== null && selection.state !== address.state ) {
		return true;
	}

	return normalizeCity( selection.city ) !== normalizeCity( address.city );
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
