/**
 * Pure row-formatting helpers for the shipping orders table.
 *
 * DATA IN, DISPLAY STRINGS OUT — no framework/component imports here beyond
 * `@wordpress/date`, so these stay trivially unit-testable.
 *
 * @package woodev-plugin-framework
 */

import { dateI18n, humanTimeDiff } from '@wordpress/date';
import type { DeliveryStatusCanonical, OrderRowTracking } from './rest';

/** One day, in milliseconds. */
const DAY_MS = 24 * 60 * 60 * 1000;

export interface FormattedOrderDate {
	text: string;
	title: string;
}

/**
 * Formats a row's `date_created` (ISO 8601) the way the shipped plugins did:
 * relative under 24h, absolute otherwise, with the full timestamp always
 * available as a title.
 */
export function formatOrderDate( isoString?: string | null ): FormattedOrderDate {
	if ( ! isoString ) {
		return { text: '', title: '' };
	}

	const date = new Date( isoString );

	if ( Number.isNaN( date.getTime() ) ) {
		return { text: '', title: '' };
	}

	const diffMs = Date.now() - date.getTime();
	const title = dateI18n( 'j M Y H:i', isoString );
	const text =
		diffMs >= 0 && diffMs < DAY_MS
			? humanTimeDiff( isoString )
			: dateI18n( 'j M Y', isoString );

	return { text, title };
}

export type StatusTone = 'ok' | 'warn' | 'error' | 'info' | 'muted';

/** Canonical delivery status => badge tone. */
const STATUS_TONE: Record<DeliveryStatusCanonical, StatusTone> = {
	pending: 'warn',
	created: 'warn',
	in_transit: 'info',
	ready_for_pickup: 'info',
	delivered: 'ok',
	returning: 'warn',
	returned: 'error',
	failed: 'error',
	cancelled: 'error',
	unknown: 'muted',
};

/**
 * Returns the badge tone for a canonical delivery status. An unrecognized
 * value (should not happen — the server never emits anything outside the
 * enum) falls back to `muted`, the same tone `unknown` itself uses, rather
 * than a false `ok`/`error` guess. Accepts plain `string` rather than
 * {@link DeliveryStatusCanonical} on purpose — that defensive fallback is the
 * whole point of this function, so its signature must not rule the case out.
 */
export function getStatusTone( canonical: string ): StatusTone {
	return STATUS_TONE[ canonical as DeliveryStatusCanonical ] || 'muted';
}

/**
 * Whether a row's tracking number is present. A missing number renders
 * NOTHING, never a dash glyph — the dash is a display choice for the
 * component to make, not this helper.
 */
export function hasTrackingNumber( tracking?: OrderRowTracking | null ): boolean {
	return Boolean( tracking && tracking.number );
}
