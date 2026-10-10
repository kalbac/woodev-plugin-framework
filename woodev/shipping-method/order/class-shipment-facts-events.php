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
	 * - **Cost has components.** A carrier may report several figures (CDEK: `delivery` and `total`); each has its
	 *   own baseline and its own event, and the cost is «none» only when the carrier says so
	 *   ({@see Shipment_Facts::with_cost()}).
	 *
	 * **The lock covers only this call.** It serialises the compare-and-store of the FACTS and nothing the
	 * carrier does around it. A carrier plugin must keep its OWN per-order serialisation around the whole
	 * operation «read the carrier's API → stage the carrier-only data (status, mode, …) → record()» — CDEK keeps
	 * its synchronizer claim — because the facts lock cannot stop two overlapping reads from being applied in the
	 * wrong order, nor two requests from staging the same carrier-only change twice.
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
		 * Prefix of the meta that remembers the carrier's last cost figures, by component:
		 * `array<string,array{amount:string,currency:string}>`; a component that is `[]` is known to be none.
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
		 * The canonical delivery states that settle a delivery issue by default: the shipment has reached its end.
		 * A carrier whose other states are final too (CDEK: `failed`) says so with the
		 * `woodev_shipping_shipment_fact_final_states` filter, see {@see self::get_final_states()}.
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
		 * ⚠ Serialisation contract: this method serialises ONLY the facts it is handed (the compare-and-store of
		 * the baselines, under {@see Order_Lock}). The carrier must keep its own per-order serialisation around
		 * its API read and its carrier-only staging, and call this inside it. A `false` result means the facts were
		 * NOT applied (the lock was not granted) — propagate it, so the caller does not acknowledge that read as done
		 * and retries on its next pass.
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
		 * @return bool False when nothing was applied — the order cannot be locked, or has no id — and the caller
		 *              must retry; true otherwise, also when nothing changed.
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
		 * The canonical delivery states the CARRIER declares final for attention: once the order reaches one, its delivery
		 * issue no longer stands. Meta-free — it only answers from the provider and a filter, because the row flag
		 * calls it for every row.
		 *
		 * @since 2.0.2
		 * @param Orders_Provider $provider Carrier descriptor.
		 * @return string[] Canonical {@see Delivery_Status} states.
		 */
		public static function get_final_states( Orders_Provider $provider ): array {
			/**
			 * Filters the canonical delivery states that settle a delivery issue for a carrier.
			 *
			 * The default is delivered / returned / cancelled. A carrier whose `failed` state is terminal (its shipment
			 * will not be attempted again — CDEK's NOT_DELIVERED / INVALID) adds it here; a carrier that does not
			 * keeps the issue flagged while the shipment is failed but recoverable. Tell carriers apart with
			 * `$provider->get_id()`. Read nothing but the arguments: this runs for every row of the orders page.
			 *
			 * @since 2.0.2
			 * @param string[]        $states   Canonical states; {@see self::FINAL_STATES}.
			 * @param Orders_Provider $provider Carrier descriptor.
			 */
			$states = apply_filters( 'woodev_shipping_shipment_fact_final_states', self::FINAL_STATES, $provider );

			return is_array( $states ) ? array_values( array_filter( $states, 'is_string' ) ) : self::FINAL_STATES;
		}

		/**
		 * What the merchant reads for a cost component: «стоимость доставки», «итоговая стоимость», or a generic wording
		 * for a component the framework does not know.
		 *
		 * @since 2.0.2
		 * @param string $component Component key.
		 * @return string A feminine noun phrase in the nominative.
		 */
		public static function cost_label( string $component ): string {
			$labels = [
				Shipment_Facts::COST_DELIVERY => __( 'стоимость доставки', 'woodev-plugin-framework' ),
				'total'                       => __( 'итоговая стоимость', 'woodev-plugin-framework' ),
			];

			/**
			 * Filters the wording of the cost components in notes and the attention flag.
			 *
			 * @since 2.0.2
			 * @param array<string,string> $labels Wording by component key; a feminine noun phrase in the nominative («стоимость доставки»),
			 *                                     which the notes inflect («… изменилась», «указана …»).
			 */
			$labels = apply_filters( 'woodev_shipping_shipment_fact_cost_labels', $labels );

			if ( is_array( $labels ) && isset( $labels[ $component ] ) && is_string( $labels[ $component ] ) && '' !== $labels[ $component ] ) {
				return $labels[ $component ];
			}

			return sprintf(
				/* translators: %s: the key of a cost component the carrier reports, e.g. «insurance». */
				__( 'стоимость (%s)', 'woodev-plugin-framework' ),
				$component
			);
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

			$costs = $facts->get_costs();
			if ( [] !== $costs ) {
				$changed = self::stage_cost( $order, $provider, $costs, $events ) || $changed;
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
		 * The carrier's cost, component by component: a change is a different amount; a currency alone moving is
		 * recorded silently. Every component has its own baseline, so one moving never disturbs another.
		 *
		 * - never recorded (or unreadable) = silent initialisation, a «none» stored as `[]`;
		 * - recorded «none» then an amount = a change from null;
		 * - a «none» over a known amount never overwrites it.
		 *
		 * @param \WC_Order                                              $order    Order.
		 * @param Orders_Provider                                        $provider Carrier.
		 * @param array<string,array{amount:float,currency:string}|null> $costs    The reported components.
		 * @param array<int,array<string,mixed>>                         $events   Events so far; appended to.
		 * @return bool Whether the order's meta changed.
		 */
		private static function stage_cost( \WC_Order $order, Orders_Provider $provider, array $costs, array &$events ): bool {
			$key    = self::meta_key( self::COST_META_PREFIX, $provider );
			$stored = self::stored_costs( $order, $key );
			$next   = $stored;

			foreach ( $costs as $component => $cost ) {
				$component = (string) $component;

				if ( ! array_key_exists( $component, $stored ) ) {
					// Never recorded: this is the initial value (or the initial «none»), not a change.
					$next[ $component ] = null === $cost ? [] : self::cost_record( $cost );

					continue;
				}

				if ( null === $cost ) {
					continue;
				}

				$known = $stored[ $component ];
				$old   = isset( $known['amount'] ) && is_numeric( $known['amount'] ) ? round( (float) $known['amount'], 2 ) : null;

				if ( null === $old || abs( $old - $cost['amount'] ) >= 0.005 ) {
					$events[] = [
						'kind'      => 'cost',
						'component' => $component,
						'old'       => $old,
						'new'       => $cost['amount'],
						'currency'  => $cost['currency'],
					];
				} elseif ( ( $known['currency'] ?? '' ) === $cost['currency'] ) {
					continue;
				}

				$next[ $component ] = self::cost_record( $cost );
			}

			if ( $next === $stored ) {
				return false;
			}

			$order->update_meta_data( $key, $next );

			return true;
		}

		/**
		 * The cost baselines stored for a carrier, by component.
		 *
		 * @param \WC_Order $order Order.
		 * @param string    $key   Cost meta key.
		 * @return array<string,array<string,mixed>> Empty when the order has none or it is unreadable.
		 */
		private static function stored_costs( \WC_Order $order, string $key ): array {
			$stored = $order->meta_exists( $key ) ? $order->get_meta( $key, true ) : [];

			if ( ! is_array( $stored ) ) {
				return [];
			}

			// The single-figure shape written before components existed: it was the delivery cost.
			if ( isset( $stored['amount'] ) ) {
				return [ Shipment_Facts::COST_DELIVERY => $stored ];
			}

			return array_filter( $stored, 'is_array' );
		}

		/**
		 * A cost as it is stored.
		 *
		 * @param array{amount:float,currency:string} $cost Cost.
		 * @return array{amount:string,currency:string}
		 */
		private static function cost_record( array $cost ): array {
			return [
				'amount'   => number_format( $cost['amount'], 2, '.', '' ),
				'currency' => $cost['currency'],
			];
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
		 * Remembers the latest issue and cost change for the row flag, unless the merchant switched that off. A change of
		 * ANY cost component raises the cost attention.
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
						'from'      => $event['old'],
						'to'        => $event['new'],
						'currency'  => $event['currency'],
						'component' => $event['component'],
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
						 * @param float|null      $old       Previous figure of this component; null when the carrier had reported none.
						 * @param float           $new       The carrier's current figure. Whose cost it is (contract or recipient) is the carrier's business.
						 * @param string          $currency  ISO currency code.
						 * @param Orders_Provider $provider  Carrier descriptor; `$provider->get_id()` tells carriers apart.
						 * @param string          $component Which figure moved: `delivery`, `total`, … ({@see Shipment_Facts::with_cost()}); each is compared on its own.
						 */
						do_action( 'woodev_shipping_carrier_cost_changed', $order, null === $event['old'] ? null : (float) $event['old'], (float) $event['new'], (string) $event['currency'], $provider, (string) $event['component'] );
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
					return self::cost_note( $carrier, $event );

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
		 * The cost note of one component: its change, or its first figure after the carrier reported none.
		 *
		 * @param string              $carrier Carrier name.
		 * @param array<string,mixed> $event   Cost event.
		 * @return string
		 */
		private static function cost_note( string $carrier, array $event ): string {
			$label    = self::cost_label( (string) $event['component'] );
			$currency = self::format_currency( (string) $event['currency'] );

			if ( null === $event['old'] ) {
				return sprintf(
					/* translators: 1: carrier name, 2: the cost component («стоимость доставки»), 3: the figure, 4: currency sign. */
					__( '%1$s: указана %2$s — %3$s %4$s. Это данные перевозчика: чья это стоимость — по договору или для получателя — он может не уточнять. Сумма заказа и доставка в магазине не менялись.', 'woodev-plugin-framework' ),
					$carrier,
					$label,
					self::format_money( (float) $event['new'] ),
					$currency
				);
			}

			return sprintf(
				/* translators: 1: carrier name, 2: the cost component («стоимость доставки»), 3: previous figure, 4: new figure, 5: currency sign. */
				__( '%1$s: %2$s изменилась — %3$s → %4$s %5$s. Это данные перевозчика: чья это стоимость — по договору или для получателя — он может не уточнять. Сумма заказа и доставка в магазине не менялись.', 'woodev-plugin-framework' ),
				$carrier,
				$label,
				self::format_money( (float) $event['old'] ),
				self::format_money( (float) $event['new'] ),
				$currency
			);
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
