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

/**
 * Canonical delivery status => badge tone, and each tone => one WooCommerce
 * order-status colour (#829). The palette itself is WooCommerce's own,
 * measured against a live WooCommerce 11.1.0 install, not chosen —
 * `.order-status.status-*` in `assets/css/admin.css` (issue #829 comment,
 * 08.09.2026):
 *
 * | WC rule                  | background | text     |
 * |---------------------------|-----------|----------|
 * | `.order-status` (base)    | `#e5e5e5` | `#454545`|
 * | `.status-processing`      | `#c6e1c6` | `#2c4700`|
 * | `.status-on-hold`         | `#f8dda7` | `#573b00`|
 * | `.status-completed`       | `#c8d7e1` | `#003d66`|
 * | `.status-failed`/`-trash` | `#eba3a3` | `#570000`/`#550202` |
 *
 * `@woocommerce/components` ships no coloured badge of its own
 * (`.woocommerce-badge`/`.woocommerce-pill` are both neutral — measured in
 * the same pass), so this table is the ONLY legitimate colour source; badge
 * *shape* is free to follow their components, colour is not.
 *
 * Five WC colours for five tones, one-to-one — `.status-failed` and
 * `.status-trash` share a background in WC itself, so `trash`'s marginally
 * darker text is dropped in favour of `failed`'s and no sixth tone is
 * needed. The tone <-> WC-status pairing below is picked by matching what
 * each WC status actually MEANS to what each tone means, not by which
 * colour "looks right":
 *
 * - `muted` (`unknown`) <-> base/neutral — both mean "no specific state".
 * - `info` (`in_transit`, `ready_for_pickup` — the shipment actively moving
 *   through the pipeline) <-> `processing` (green) — WC's processing is
 *   "the order is actively being worked on", the closest existing WC state
 *   to "currently in motion".
 * - `warn` (`pending`, `created`, `returning` — not yet resolved, wants
 *   attention) <-> `on-hold` (amber) — WC's on-hold literally means
 *   "paused, needs someone's attention".
 * - `ok` (`delivered` — the successful end state) <-> `completed` (blue) —
 *   WC's completed is the successful terminal state of an order, exactly
 *   what `delivered` is for a shipment.
 * - `error` (`returned`, `failed`, `cancelled` — terminal and negative)
 *   <-> `failed` (red).
 *
 * The actual hex values live in `style.scss` (`.woodev-orders-status--*`)
 * rather than here, since they are pure presentation — this constant only
 * carries the WHICH-STATE-GETS-WHICH-TONE decision that both the badge and
 * (previously) the dot indicator drew from.
 */
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
};;

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
