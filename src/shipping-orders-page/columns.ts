/**
 * Pure row-formatting helpers for the shipping orders table.
 *
 * DATA IN, DISPLAY STRINGS OUT — no framework/component imports here beyond
 * `@wordpress/date`, so these stay trivially unit-testable.
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';
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

/**
 * Formats a unix timestamp (seconds) from `GET /shipping/orders/sync-status` (#828) the same
 * way {@link formatOrderDate} formats `date_created`: relative under 24h, absolute otherwise,
 * full timestamp always in `title`. A separate function rather than a reuse of
 * `formatOrderDate` itself — `next_update` is always AHEAD of now, so the diff can be
 * negative here, unlike a row's `date_created`, and the 24h window has to apply to both
 * directions instead of only the past one.
 */
export function formatSyncTimestamp( unixSeconds?: number | null ): FormattedOrderDate {
	if ( 'number' !== typeof unixSeconds ) {
		return { text: '', title: '' };
	}

	const date = new Date( unixSeconds * 1000 );

	if ( Number.isNaN( date.getTime() ) ) {
		return { text: '', title: '' };
	}

	const diffMs = Date.now() - date.getTime();
	const title = dateI18n( 'j M Y H:i', date );
	const text =
		Math.abs( diffMs ) < DAY_MS ? humanTimeDiff( date ) : dateI18n( 'j M Y', date );

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
 * Every canonical delivery status's Russian label, `unknown` included — the
 * options the delivery-status `AdvancedFilters` entry offers (SP-10 spec D10,
 * increment 7). Mirrors `Delivery_Status::labels()`
 * (`woodev/shipping-method/order/class-delivery-status.php`) byte-for-byte, the
 * same PHP class every row's own `canonical_label` already comes from — read
 * with Serena, not retyped from memory. Duplicated rather than fetched because
 * it is a small, closed, framework-owned enum, the same precedent `app.tsx`'s
 * own `TYPE_LABELS` already sets for the three delivery types.
 */
export const DELIVERY_STATUS_LABELS: Record<DeliveryStatusCanonical, string> = {
	pending: __( 'Ожидает отправки', 'woodev-plugin-framework' ),
	created: __( 'Создано у перевозчика', 'woodev-plugin-framework' ),
	in_transit: __( 'В пути', 'woodev-plugin-framework' ),
	ready_for_pickup: __( 'Готово к выдаче', 'woodev-plugin-framework' ),
	delivered: __( 'Доставлено', 'woodev-plugin-framework' ),
	returning: __( 'Возврат в пути', 'woodev-plugin-framework' ),
	returned: __( 'Возвращено отправителю', 'woodev-plugin-framework' ),
	failed: __( 'Не удалось доставить', 'woodev-plugin-framework' ),
	cancelled: __( 'Отменено', 'woodev-plugin-framework' ),
	unknown: __( 'Неизвестно', 'woodev-plugin-framework' ),
};

/**
 * Whether a row's tracking number is present. A missing number renders
 * NOTHING, never a dash glyph — the dash is a display choice for the
 * component to make, not this helper.
 */
export function hasTrackingNumber( tracking?: OrderRowTracking | null ): boolean {
	return Boolean( tracking && tracking.number );
}
