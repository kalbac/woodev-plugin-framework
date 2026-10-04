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

/**
 * Whether `address` no longer matches the locality `selection` wrote.
 *
 * Compared by VALUE (case, ё and spacing folded) — never by object identity, so a store round trip
 * that hands back a fresh object with the same text is not an edit.
 */
export function isSelectionStale( selection: Selection, address: { city: string; country: string } ): boolean {
	if ( selection.country.toUpperCase() !== address.country.toUpperCase() ) {
		return true;
	}

	return normalizeCity( selection.city ) !== normalizeCity( address.city );
}
