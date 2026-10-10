<?php
/**
 * Shipment facts change event seam.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Shipment_Facts_Events' ) ) :
	/**
	 * Turns what a carrier's API now says about a shipment into once-per-real-change events — the sibling of
	 * {@see Delivery_Status_Events} for the facts that are not the delivery status: the carrier's cost, the
	 * delivery date, delivery issues and the assigned courier.
	 *
	 * A carrier hands the facts in with ONE call, {@see self::record()}, after it re-read the shipment from its
	 * API (a webhook only says «go and look», it is never the source). The framework compares every fact to its
	 * stored baseline and, for each REAL change, fires one neutral action, writes one order note and — for a
	 * delivery issue or a cost change — marks the order for the «attention» row flag ({@see Shipment_Facts_Flag}).
	 *
	 * The rules, per fact:
	 *
	 * - **Absent baseline = silent initialisation.** The first value of a fact an order ever gets is recorded and
	 *   announces nothing, otherwise the first poll after an update would flood every existing order with notes.
	 * - **Known-empty is stored explicitly.** A carrier that reports «none» ({@see Shipment_Facts}) leaves a
	 *   baseline behind, so the value that appears later IS a change.
	 * - **One event per real change.** The same value again (a replayed webhook, the next poll) announces nothing;
	 *   an empty report never overwrites a known value.
	 * - **One record at a time per order.** Two requests that carry the same news (a webhook and a poll) are
	 *   serialised by a short-lived named lock, and the order's meta is re-read once the lock is held, so the
	 *   request that comes second finds the baseline already moved and announces nothing.
	 *
	 * **WooCommerce totals and shipping lines are never changed** — the carrier's cost is the carrier's figure,
	 * and what the merchant does with it is the merchant's call (an action hook is there for exactly that).
	 *
	 * Meta written (per carrier, `{id}` is {@see Orders_Provider::get_id()}) — installed-site data contracts,
	 * keep byte-for-byte: {@see self::COST_META_PREFIX}, {@see self::DATE_META_PREFIX},
	 * {@see self::ISSUES_META_PREFIX}, {@see self::COURIER_META_PREFIX}, {@see self::ATTENTION_META_PREFIX}.
	 *
	 * @since 2.0.2
	 */
	final class Shipment_Facts_Events {
		/**
		 * Prefix of the meta that remembers the carrier's last cost: `array{amount:string,currency:string}`.
		 *
		 * @var string
		 */
		public const COST_META_PREFIX = '_woodev_shipment_fact_cost_';

		/**
		 * Prefix of the meta that remembers the last delivery date: `array{date,kind,window}`; `[]` = known to be none.
		 *
		 * @var string
		 */
		public const DATE_META_PREFIX = '_woodev_shipment_fact_date_';

		/**
		 * Prefix of the meta that remembers the delivery issues already announced: a list of `array{code,label,at}`,
		 * oldest first; `[]` = known to be none.
		 *
		 * @var string
		 */
		public const ISSUES_META_PREFIX = '_woodev_shipment_fact_issues_';

		/**
		 * Prefix of the meta that remembers the assigned courier: `array{name,phone,vehicle,plate}`; `[]` = known to be none.
		 *
		 * @var string
		 */
		public const COURIER_META_PREFIX = '_woodev_shipment_fact_courier_';

		/**
		 * Prefix of the meta that the «attention» row flag reads: `array{issue?:array,cost?:array}`, written only by a
		 * real change after the baseline.
		 *
		 * @var string
		 */
		public const ATTENTION_META_PREFIX = '_woodev_shipment_fact_attention_';

		/**
		 * The canonical delivery states that settle a delivery issue: the shipment has reached its end.
		 *
		 * @var string[]
		 */
		public const FINAL_STATES = [ Delivery_Status::DELIVERED, Delivery_Status::RETURNED, Delivery_Status::CANCELLED ];

		/**
		 * The scope of the per-order named lock ({@see Order_Lock}).
		 *
		 * @var string
		 */
		private const LOCK_SCOPE = 'facts';

		/**
		 * Seconds a record waits for another one on the same order.
		 *
		 * @var int
		 */
		private const LOCK_WAIT = 5;

		/**
		 * How many delivery issues an order remembers.
		 *
		 * @var int
		 */
		private const ISSUES_KEPT = 20;

		/**
		 * Compares the facts with the order's baselines and announces every real change.
		 *
		 * ⚠ The order's meta is re-read once the lock is held, so persist the order's own pending meta changes
		 * (`$order->save()`) BEFORE calling.
		 *
		 * Fires, once per change and after the new baseline is saved (a failed save never leaves a note that the
		 * next call would write again): `woodev_shipping_carrier_cost_changed`,
		 * `woodev_shipping_delivery_date_changed`, `woodev_shipping_delivery_issue`,
		 * `woodev_shipping_courier_assigned`.
		 *
		 * @since 2.0.2
		 * @param \WC_Order       $order    Shipment order.
		 * @param Orders_Provider $provider Carrier descriptor.
		 * @param Shipment_Facts  $facts    What the carrier's API says now.
		 * @return bool False when nothing was applied — the order cannot be locked, or has no id; true otherwise,
		 *              also when nothing changed.
		 */
		public static function record( \WC_Order $order, Orders_Provider $provider, Shipment_Facts $facts ): bool {
			$order_id = (int) $order->get_id();

			if ( $order_id <= 0 ) {
				return false;
			}

			if ( $facts->is_empty() ) {
				return true;
			}

			if ( ! Order_Lock::acquire( self::LOCK_SCOPE, $order_id, self::LOCK_WAIT ) ) {
				return false;
			}

			$staged = [
				'changed' => false,
				'events'  => [],
			];

			try {
				// The caller loaded the order before it waited for the lock; diff against what is persisted now.
				$order->read_meta_data( true );

				$staged = self::stage( $order, $provider, $facts );

				if ( $staged['changed'] ) {
					$order->save_meta_data();
				}
			} finally {
				Order_Lock::release( self::LOCK_SCOPE, $order_id );
			}

			self::announce( $order, $provider, $staged['events'] );

			return true;
		}

		/**
		 * The «attention» a real change left on the order, for the row flag.
		 *
		 * @since 2.0.2
		 * @param \WC_Order       $order    Order.
		 * @param Orders_Provider $provider Carrier descriptor.
		 * @return array{issue?:array{code:string,label:string,at:string},cost?:array{from:float,to:float,currency:string}}
		 */
		public static function get_attention( \WC_Order $order, Orders_Provider $provider ): array {
			$attention = $order->get_meta( self::meta_key( self::ATTENTION_META_PREFIX, $provider ), true );

			return is_array( $attention ) ? $attention : [];
		}

		/**
		 * A money amount as the merchant reads it: no needless zeros, non-breaking thousands space.
		 *
		 * @since 2.0.2
		 * @param float $value Amount.
		 * @return string «465», «465,5», «1 234,56».
		 */
		public static function format_money( float $value ): string {
			return rtrim( rtrim( number_format( $value, 2, ',', "\u{00A0}" ), '0' ), ',' );
		}

		/**
		 * A currency as the merchant reads it: «₽» for the rouble, the ISO code otherwise.
		 *
		 * @since 2.0.2
		 * @param string $currency ISO code.
		 * @return string
		 */
		public static function format_currency( string $currency ): string {
			return 'RUB' === $currency ? '₽' : $currency;
		}

		/**
		 * Diffs every reported fact against its baseline and stages the new baselines on the order.
		 *
		 * @param \WC_Order       $order    Order; its meta is updated, the caller saves it.
		 * @param Orders_Provider $provider Carrier descriptor.
		 * @param Shipment_Facts  $facts    The carrier's facts.
		 * @return array{changed:bool,events:array<int,array<string,mixed>>}
		 */
		private static function stage( \WC_Order $order, Orders_Provider $provider, Shipment_Facts $facts ): array {
			$events  = [];
			$changed = false;

			$cost = $facts->get_cost();
			if ( null !== $cost ) {
				$changed = self::stage_cost( $order, $provider, $cost, $events ) || $changed;
			}

			if ( $facts->reports_delivery_date() ) {
				$changed = self::stage_date( $order, $provider, $facts->get_delivery_date(), $events ) || $changed;
			}

			$issues = $facts->get_issues();
			if ( null !== $issues ) {
				$changed = self::stage_issues( $order, $provider, $issues, $events ) || $changed;
			}

			if ( $facts->reports_courier() ) {
				$changed = self::stage_courier( $order, $provider, $facts->get_courier(), $events ) || $changed;
			}

			if ( [] !== $events && self::stage_attention( $order, $provider, $events ) ) {
				$changed = true;
			}

			return [
				'changed' => $changed,
				'events'  => $events,
			];
		}

		/**
		 * The carrier's cost: a change is a different amount; a currency alone moving is recorded silently.
		 *
		 * @param \WC_Order                           $order    Order.
		 * @param Orders_Provider                     $provider Carrier.
		 * @param array{amount:float,currency:string} $cost     The reported cost.
		 * @param array<int,array<string,mixed>>      $events   Events so far; appended to.
		 * @return bool Whether the order's meta changed.
		 */
		private static function stage_cost( \WC_Order $order, Orders_Provider $provider, array $cost, array &$events ): bool {
			$key    = self::meta_key( self::COST_META_PREFIX, $provider );
			$stored = $order->meta_exists( $key ) ? $order->get_meta( $key, true ) : null;
			$write  = [
				'amount'   => number_format( $cost['amount'], 2, '.', '' ),
				'currency' => $cost['currency'],
			];

			if ( ! is_array( $stored ) || ! isset( $stored['amount'] ) || ! is_numeric( $stored['amount'] ) ) {
				// Never recorded (or unreadable): this is the initial value, not a change.
				$order->update_meta_data( $key, $write );

				return true;
			}

			$old = round( (float) $stored['amount'], 2 );

			if ( abs( $old - $cost['amount'] ) >= 0.005 ) {
				$events[] = [
					'kind'     => 'cost',
					'old'      => $old,
					'new'      => $cost['amount'],
					'currency' => $cost['currency'],
				];
			} elseif ( ( $stored['currency'] ?? '' ) === $cost['currency'] ) {
				return false;
			}

			$order->update_meta_data( $key, $write );

			return true;
		}

		/**
		 * The delivery date: a change is a different date, kind or window. «None» never overwrites a known date.
		 *
		 * @param \WC_Order                                                                    $order    Order.
		 * @param Orders_Provider                                                              $provider Carrier.
		 * @param array{date:string,kind:string,window:array{from:string,to:string}|null}|null $date     The reported date, null for «none».
		 * @param array<int,array<string,mixed>>                                               $events   Events so far; appended to.
		 * @return bool Whether the order's meta changed.
		 */
		private static function stage_date( \WC_Order $order, Orders_Provider $provider, ?array $date, array &$events ): bool {
			$key = self::meta_key( self::DATE_META_PREFIX, $provider );

			if ( ! $order->meta_exists( $key ) ) {
				$order->update_meta_data( $key, $date ?? [] );

				return true;
			}

			if ( null === $date ) {
				return false;
			}

			$stored = $order->get_meta( $key, true );
			$stored = is_array( $stored ) ? $stored : [];

			if ( $stored === $date ) {
				return false;
			}

			$events[] = [
				'kind'     => 'date',
				'date'     => $date['date'],
				'window'   => $date['window'],
				'type'     => $date['kind'],
				'previous' => isset( $stored['date'] ) && is_string( $stored['date'] ) ? $stored['date'] : '',
			];

			$order->update_meta_data( $key, $date );

			return true;
		}

		/**
		 * Delivery issues: every issue not seen before is one event; the history is capped.
		 *
		 * @param \WC_Order                                            $order    Order.
		 * @param Orders_Provider                                      $provider Carrier.
		 * @param array<int,array{code:string,label:string,at:string}> $issues   The reported issues.
		 * @param array<int,array<string,mixed>>                       $events   Events so far; appended to.
		 * @return bool Whether the order's meta changed.
		 */
		private static function stage_issues( \WC_Order $order, Orders_Provider $provider, array $issues, array &$events ): bool {
			$key    = self::meta_key( self::ISSUES_META_PREFIX, $provider );
			$known  = $order->meta_exists( $key );
			$stored = $known ? $order->get_meta( $key, true ) : [];
			$stored = is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : [];
			$seen   = array_map( [ self::class, 'issue_key' ], $stored );
			$fresh  = [];

			foreach ( $issues as $issue ) {
				if ( in_array( self::issue_key( $issue ), $seen, true ) ) {
					continue;
				}

				$seen[]  = self::issue_key( $issue );
				$fresh[] = $issue;
			}

			if ( [] === $fresh ) {
				if ( $known ) {
					return false;
				}

				$order->update_meta_data( $key, [] );

				return true;
			}

			// Oldest first by the carrier's own timestamp; an issue without one keeps its place.
			$all = array_merge( $stored, $fresh );
			usort(
				$all,
				static function ( array $a, array $b ): int {
					return (int) strtotime( (string) ( $a['at'] ?? '' ) ) <=> (int) strtotime( (string) ( $b['at'] ?? '' ) );
				}
			);

			$order->update_meta_data( $key, array_slice( $all, -self::ISSUES_KEPT ) );

			// The history of an order whose issues were never recorded is not news.
			if ( $known ) {
				foreach ( $fresh as $issue ) {
					$events[] = [
						'kind'  => 'issue',
						'code'  => $issue['code'],
						'label' => $issue['label'],
						'at'    => $issue['at'],
					];
				}
			}

			return true;
		}

		/**
		 * The courier: a change is any field differing. «None» never overwrites a known courier.
		 *
		 * @param \WC_Order                                                        $order    Order.
		 * @param Orders_Provider                                                  $provider Carrier.
		 * @param array{name:string,phone:string,vehicle:string,plate:string}|null $courier  The reported courier, null for «none».
		 * @param array<int,array<string,mixed>>                                   $events   Events so far; appended to.
		 * @return bool Whether the order's meta changed.
		 */
		private static function stage_courier( \WC_Order $order, Orders_Provider $provider, ?array $courier, array &$events ): bool {
			$key = self::meta_key( self::COURIER_META_PREFIX, $provider );

			if ( ! $order->meta_exists( $key ) ) {
				$order->update_meta_data( $key, $courier ?? [] );

				return true;
			}

			if ( null === $courier ) {
				return false;
			}

			$stored = $order->get_meta( $key, true );

			if ( $stored === $courier ) {
				return false;
			}

			$events[] = [
				'kind'    => 'courier',
				'courier' => $courier,
			];

			$order->update_meta_data( $key, $courier );

			return true;
		}

		/**
		 * Remembers the latest issue and cost change for the row flag, unless the merchant switched that off.
		 *
		 * @param \WC_Order                      $order    Order.
		 * @param Orders_Provider                $provider Carrier.
		 * @param array<int,array<string,mixed>> $events   Staged events.
		 * @return bool Whether the order's meta changed; a date or courier change asks for no attention.
		 */
		private static function stage_attention( \WC_Order $order, Orders_Provider $provider, array $events ): bool {
			$key       = self::meta_key( self::ATTENTION_META_PREFIX, $provider );
			$attention = $order->get_meta( $key, true );
			$attention = is_array( $attention ) ? $attention : [];
			$staged    = false;

			foreach ( $events as $event ) {
				if ( ! in_array( $event['kind'], [ 'issue', 'cost' ], true ) ) {
					continue;
				}

				/**
				 * Filters whether a change puts the «attention» flag on the order's row.
				 *
				 * @since 2.0.2
				 * @param bool            $flag     Whether to flag the order; true.
				 * @param string          $kind     `issue` or `cost`.
				 * @param \WC_Order       $order    Shipment order.
				 * @param Orders_Provider $provider Carrier descriptor.
				 */
				if ( ! apply_filters( 'woodev_shipping_shipment_fact_flag', true, $event['kind'], $order, $provider ) ) {
					continue;
				}

				if ( 'issue' === $event['kind'] ) {
					$attention['issue'] = [
						'code'  => $event['code'],
						'label' => $event['label'],
						'at'    => $event['at'],
					];
				} else {
					$attention['cost'] = [
						'from'     => $event['old'],
						'to'       => $event['new'],
						'currency' => $event['currency'],
					];
				}

				$staged = true;
			}

			if ( $staged ) {
				$order->update_meta_data( $key, $attention );
			}

			return $staged;
		}

		/**
		 * Writes the order note and fires the action of every staged event.
		 *
		 * @param \WC_Order                      $order    Order.
		 * @param Orders_Provider                $provider Carrier descriptor.
		 * @param array<int,array<string,mixed>> $events   Result of {@see self::stage()}.
		 * @return void
		 */
		private static function announce( \WC_Order $order, Orders_Provider $provider, array $events ): void {
			foreach ( $events as $event ) {
				self::note( $order, $provider, $event );

				switch ( $event['kind'] ) {
					case 'cost':
						/**
						 * The carrier changed the delivery cost of a shipment. WooCommerce totals are NOT changed by the framework.
						 *
						 * Fired once per real change, after the new cost is remembered.
						 *
						 * @since 2.0.2
						 * @param \WC_Order       $order    Shipment order.
						 * @param float           $old      Previous figure.
						 * @param float           $new      The carrier's current figure. Whose cost it is (contract or recipient) is the carrier's business.
						 * @param string          $currency ISO currency code.
						 * @param Orders_Provider $provider Carrier descriptor; `$provider->get_id()` tells carriers apart.
						 */
						do_action( 'woodev_shipping_carrier_cost_changed', $order, (float) $event['old'], (float) $event['new'], (string) $event['currency'], $provider );
						break;

					case 'date':
						/**
						 * The carrier set or moved the delivery date of a shipment.
						 *
						 * Fired once per real change of the date, its kind or its window, after the new value is remembered.
						 *
						 * @since 2.0.2
						 * @param \WC_Order                           $order    Shipment order.
						 * @param string                              $date     New date, `Y-m-d`.
						 * @param array{from:string,to:string}|null   $window   Time window the carrier states, or null.
						 * @param string                              $kind     `planned` or `agreed` ({@see Shipment_Facts}).
						 * @param string                              $previous Previous date, `Y-m-d`; empty when the carrier had none.
						 * @param Orders_Provider                     $provider Carrier descriptor; `$provider->get_id()` tells carriers apart.
						 */
						do_action( 'woodev_shipping_delivery_date_changed', $order, (string) $event['date'], $event['window'], (string) $event['type'], (string) $event['previous'], $provider );
						break;

					case 'issue':
						/**
						 * The carrier reported a delivery problem for a shipment (the shipment continues; it is not a status).
						 *
						 * Fired once per issue not seen before, after it is remembered.
						 *
						 * @since 2.0.2
						 * @param \WC_Order       $order    Shipment order.
						 * @param string          $code     The carrier's own issue code.
						 * @param string          $label    Merchant wording of the code; may be empty.
						 * @param string          $at       When the issue arose, in the carrier's own format; may be empty.
						 * @param Orders_Provider $provider Carrier descriptor; `$provider->get_id()` tells carriers apart.
						 */
						do_action( 'woodev_shipping_delivery_issue', $order, (string) $event['code'], (string) $event['label'], (string) $event['at'], $provider );
						break;

					case 'courier':
						/**
						 * The carrier assigned a courier to a shipment, or changed it.
						 *
						 * Fired once per real change of any courier field, after it is remembered.
						 *
						 * @since 2.0.2
						 * @param \WC_Order                                                        $order    Shipment order.
						 * @param array{name:string,phone:string,vehicle:string,plate:string}      $courier  The courier; a field the carrier does not give is empty.
						 * @param Orders_Provider                                                  $provider Carrier descriptor; `$provider->get_id()` tells carriers apart.
						 */
						do_action( 'woodev_shipping_courier_assigned', $order, $event['courier'], $provider );
						break;
				}
			}
		}

		/**
		 * Writes the order note of one event, unless the merchant or a plugin switched it off.
		 *
		 * @param \WC_Order           $order    Order.
		 * @param Orders_Provider     $provider Carrier descriptor.
		 * @param array<string,mixed> $event Staged event.
		 * @return void
		 */
		private static function note( \WC_Order $order, Orders_Provider $provider, array $event ): void {
			/**
			 * Filters the order note written for a shipment fact change.
			 *
			 * Return the text to reword it, or an empty string to write no note at all.
			 *
			 * @since 2.0.2
			 * @param string          $note     The note, worded with the carrier's name.
			 * @param string          $kind     `cost`, `date`, `issue` or `courier`.
			 * @param \WC_Order       $order    Shipment order.
			 * @param Orders_Provider $provider Carrier descriptor.
			 * @param array           $event    The change: the same values the matching action receives.
			 */
			$note = apply_filters( 'woodev_shipping_shipment_fact_note', self::note_text( $provider, $event ), $event['kind'], $order, $provider, $event );

			if ( is_string( $note ) && '' !== $note ) {
				$order->add_order_note( $note );
			}
		}

		/**
		 * The default note of an event: generic carrier wording, the carrier's name from the plugin.
		 *
		 * @param Orders_Provider     $provider Carrier descriptor.
		 * @param array<string,mixed> $event    Staged event.
		 * @return string
		 */
		private static function note_text( Orders_Provider $provider, array $event ): string {
			$carrier = $provider->get_label();

			switch ( $event['kind'] ) {
				case 'cost':
					return sprintf(
						/* translators: 1: carrier name, 2: previous delivery cost, 3: new delivery cost, 4: currency sign. */
						__( '%1$s изменил стоимость доставки: %2$s → %3$s %4$s. Это данные перевозчика: чья это стоимость — по договору или для получателя — он может не уточнять. Сумма заказа и доставка в магазине не менялись.', 'woodev-plugin-framework' ),
						$carrier,
						self::format_money( (float) $event['old'] ),
						self::format_money( (float) $event['new'] ),
						self::format_currency( (string) $event['currency'] )
					);

				case 'date':
					return self::date_note( $carrier, $event );

				case 'issue':
					return sprintf(
						/* translators: 1: carrier name, 2: the delivery problem, e.g. «Телефон неверный». */
						__( '%1$s: проблема доставки — %2$s', 'woodev-plugin-framework' ),
						$carrier,
						self::issue_label( (string) $event['code'], (string) $event['label'] )
					);

				default:
					return sprintf(
						/* translators: 1: carrier name, 2: the courier's details (name, phone, vehicle, plate). */
						__( '%1$s назначил курьера: %2$s.', 'woodev-plugin-framework' ),
						$carrier,
						self::courier_details( $event['courier'] )
					);
			}
		}

		/**
		 * The delivery date note: a planned or an agreed date, set or moved, with the window when there is one.
		 *
		 * @param string              $carrier Carrier name.
		 * @param array<string,mixed> $event   Date event.
		 * @return string
		 */
		private static function date_note( string $carrier, array $event ): string {
			$date     = self::show_date( (string) $event['date'] );
			$previous = '' === (string) $event['previous'] ? '' : self::show_date( (string) $event['previous'] );
			$window   = '';

			if ( is_array( $event['window'] ) ) {
				$window = trim( (string) $event['window']['from'] ) . ( '' !== (string) $event['window']['from'] && '' !== (string) $event['window']['to'] ? '–' : '' ) . trim( (string) $event['window']['to'] );
			}

			$shown = '' === $window ? $date : $date . ', ' . $window;

			if ( Shipment_Facts::DATE_AGREED === $event['type'] ) {
				return sprintf(
					/* translators: 1: carrier name, 2: agreed delivery date and time window. */
					__( '%1$s согласовал дату доставки: %2$s.', 'woodev-plugin-framework' ),
					$carrier,
					$shown
				);
			}

			if ( '' === $previous ) {
				return sprintf(
					/* translators: 1: carrier name, 2: planned delivery date and time window. */
					__( '%1$s назначил плановую дату доставки: %2$s.', 'woodev-plugin-framework' ),
					$carrier,
					$shown
				);
			}

			return sprintf(
				/* translators: 1: carrier name, 2: previous planned delivery date, 3: new planned delivery date and time window. */
				__( '%1$s изменил плановую дату доставки: %2$s → %3$s.', 'woodev-plugin-framework' ),
				$carrier,
				$previous,
				$shown
			);
		}

		/**
		 * What the merchant reads for an issue: the carrier's wording, or its code when it gave none.
		 *
		 * @since 2.0.2
		 * @param string $code  Issue code.
		 * @param string $label Wording; may be empty.
		 * @return string
		 */
		public static function issue_label( string $code, string $label ): string {
			if ( '' !== $label ) {
				return $label;
			}

			return sprintf(
				/* translators: %s: the carrier's delivery problem code that has no wording. */
				__( 'Проблема доставки (код %s)', 'woodev-plugin-framework' ),
				$code
			);
		}

		/**
		 * The courier as one line: name, phone, vehicle, plate — whatever the carrier gave.
		 *
		 * @param array<string,string> $courier Courier.
		 * @return string
		 */
		private static function courier_details( array $courier ): string {
			$parts = [];

			if ( '' !== $courier['name'] ) {
				$parts[] = $courier['name'];
			}

			if ( '' !== $courier['phone'] ) {
				/* translators: %s: the courier's phone number. */
				$parts[] = sprintf( __( 'тел. %s', 'woodev-plugin-framework' ), $courier['phone'] );
			}

			if ( '' !== $courier['vehicle'] ) {
				/* translators: %s: the courier's vehicle, e.g. «Lada Largus». */
				$parts[] = sprintf( __( 'транспорт: %s', 'woodev-plugin-framework' ), $courier['vehicle'] );
			}

			if ( '' !== $courier['plate'] ) {
				/* translators: %s: the courier vehicle's plate number. */
				$parts[] = sprintf( __( 'госномер: %s', 'woodev-plugin-framework' ), $courier['plate'] );
			}

			return implode( ', ', $parts );
		}

		/**
		 * DD.MM.YYYY of a `Y-m-d` date.
		 *
		 * @param string $date Date.
		 * @return string
		 */
		private static function show_date( string $date ): string {
			return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) ? $parts[3] . '.' . $parts[2] . '.' . $parts[1] : $date;
		}

		/**
		 * Identity of an issue: its code and when it arose.
		 *
		 * @param array<string,mixed> $issue Issue.
		 * @return string
		 */
		private static function issue_key( array $issue ): string {
			return (string) ( $issue['code'] ?? '' ) . '|' . (string) ( $issue['at'] ?? '' );
		}

		/**
		 * One baseline's meta key for a carrier.
		 *
		 * @param string          $prefix   One of the `*_META_PREFIX` constants.
		 * @param Orders_Provider $provider Carrier descriptor.
		 * @return string
		 */
		private static function meta_key( string $prefix, Orders_Provider $provider ): string {
			return $prefix . $provider->get_id();
		}
	}
endif;
