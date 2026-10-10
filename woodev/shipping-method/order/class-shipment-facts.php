<?php
/**
 * What a carrier's API says about one shipment, as plain facts.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Shipment_Facts' ) ) :
	/**
	 * The carrier-neutral shipment facts a carrier plugin hands to {@see Shipment_Facts_Events::record()}.
	 *
	 * An immutable value object the carrier fills from its OWN API read — a webhook-triggered re-read, a poll, a
	 * claim lookup — and never from an event body. Every fact is optional, and the object tells apart three states
	 * of a fact, which the framework treats differently:
	 *
	 * - **not reported** (the `with_*()` call was never made): the carrier says nothing about it; the stored value
	 *   is left alone;
	 * - **reported as none** (`with_delivery_date( null )`, `with_courier( null )`, `with_issues( [] )`): the
	 *   carrier states the shipment has none. The framework stores that explicitly, so a value that appears LATER
	 *   is recognised as a change rather than as the initial value;
	 * - **reported with a value**.
	 *
	 * A carrier therefore states «none» on the very first read of an order (right after the export), otherwise the
	 * first value it ever reports is taken as the baseline and announces nothing.
	 *
	 * Values are normalised here: a malformed date, a negative amount or an issue without a code is dropped as if
	 * it was not reported, never guessed at.
	 *
	 * @since 2.0.2
	 */
	final class Shipment_Facts {
		/**
		 * The date the carrier plans to deliver on.
		 *
		 * @var string
		 */
		public const DATE_PLANNED = 'planned';

		/**
		 * The date agreed with the recipient.
		 *
		 * @var string
		 */
		public const DATE_AGREED = 'agreed';

		/**
		 * The currency of a cost a carrier hands in without naming one: this framework serves the Russian market.
		 *
		 * @var string
		 */
		public const DEFAULT_CURRENCY = 'RUB';

		/**
		 * The component {@see self::with_cost()} stands for when the carrier names none.
		 *
		 * @var string
		 */
		public const COST_DELIVERY = 'delivery';

		/**
		 * The cost components the carrier reported, by component; a null entry is «reported as none».
		 *
		 * @var array<string,array{amount:float,currency:string}|null>
		 */
		private array $costs = [];

		/**
		 * Whether the carrier reported the delivery date (a value or «none»).
		 *
		 * @var bool
		 */
		private bool $date_reported = false;

		/**
		 * The delivery date, or null for «none».
		 *
		 * @var array{date:string,kind:string,window:array{from:string,to:string}|null}|null
		 */
		private ?array $date = null;

		/**
		 * The delivery issues the carrier lists, or null when it did not report them.
		 *
		 * @var array<int,array{code:string,label:string,at:string}>|null
		 */
		private ?array $issues = null;

		/**
		 * Whether the carrier reported the courier (a value or «none»).
		 *
		 * @var bool
		 */
		private bool $courier_reported = false;

		/**
		 * The courier, or null for «none».
		 *
		 * @var array{name:string,phone:string,vehicle:string,plate:string}|null
		 */
		private ?array $courier = null;

		/**
		 * An empty set of facts: the carrier reports nothing yet.
		 *
		 * @since 2.0.2
		 * @return self
		 */
		public static function create(): self {
			return new self();
		}

		/**
		 * One cost figure of the carrier. WooCommerce totals and shipping lines are never changed by it.
		 *
		 * A carrier may report SEVERAL components, each compared with its own baseline (CDEK: `delivery` and `total`);
		 * call this once per component. A component is never derived from another one — which figure the carrier
		 * reports when only one of them moved is the carrier plugin's business.
		 *
		 * `null` states «the carrier has no figure (yet)»: it is stored explicitly, so the first amount that follows
		 * is a change (with a previous figure of null). An amount that is not a finite, non-negative number is
		 * ignored (the component stays «not reported»); `0.0` is a real value.
		 *
		 * @since 2.0.2
		 * @param float|null $amount    Amount; null = «none».
		 * @param string     $currency  ISO 4217 code; anything else falls back to {@see self::DEFAULT_CURRENCY}.
		 * @param string     $component Component key (lowercase letters, digits, `_`), {@see self::COST_DELIVERY} by default; an
		 *                              unusable key is ignored.
		 * @return self
		 */
		public function with_cost( ?float $amount, string $currency = self::DEFAULT_CURRENCY, string $component = self::COST_DELIVERY ): self {
			$component = strtolower( trim( $component ) );

			if ( 1 !== preg_match( '/^[a-z0-9_]{1,32}$/', $component ) ) {
				return $this;
			}

			$copy = clone $this;

			if ( null === $amount ) {
				$copy->costs[ $component ] = null;

				return $copy;
			}

			if ( ! is_finite( $amount ) || $amount < 0 ) {
				return $this;
			}

			$currency = strtoupper( trim( $currency ) );

			$copy->costs[ $component ] = [
				'amount'   => round( $amount, 2 ),
				'currency' => 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : self::DEFAULT_CURRENCY,
			];

			return $copy;
		}

		/**
		 * The delivery date, or `null` when the carrier states there is none (yet).
		 *
		 * @since 2.0.2
		 * @param string|null                         $date   `Y-m-d`, or a date-time starting with one; null = «none». A malformed
		 *                                                    string is ignored (the fact stays «not reported»).
		 * @param string                              $kind   {@see self::DATE_PLANNED} or {@see self::DATE_AGREED}; anything else is planned.
		 * @param array{from?:string,to?:string}|null $window Time window the carrier states, e.g. `[ 'from' => '10:00', 'to' => '14:00' ]`.
		 * @return self
		 */
		public function with_delivery_date( ?string $date, string $kind = self::DATE_PLANNED, ?array $window = null ): self {
			$copy                = clone $this;
			$copy->date_reported = true;

			if ( null === $date ) {
				$copy->date = null;

				return $copy;
			}

			if ( 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2})/', trim( $date ), $parts ) ) {
				return $this;
			}

			$from = isset( $window['from'] ) && is_scalar( $window['from'] ) ? trim( (string) $window['from'] ) : '';
			$to   = isset( $window['to'] ) && is_scalar( $window['to'] ) ? trim( (string) $window['to'] ) : '';

			$copy->date = [
				'date'   => $parts[1],
				'kind'   => self::DATE_AGREED === $kind ? self::DATE_AGREED : self::DATE_PLANNED,
				'window' => '' === $from && '' === $to ? null : [
					'from' => $from,
					'to'   => $to,
				],
			];

			return $copy;
		}

		/**
		 * The delivery issues the carrier lists for the shipment — all of them, not only the new ones: the framework
		 * remembers which it has already announced. An empty list states «no issues».
		 *
		 * @since 2.0.2
		 * @param array<int,array{code?:scalar,label?:scalar,at?:scalar}> $issues Each `code` (required, the carrier's own), `label`
		 *                                                                         (merchant wording, optional), `at` (when it arose, any
		 *                                                                         date-time string the carrier uses, optional).
		 * @return self
		 */
		public function with_issues( array $issues ): self {
			$clean = [];

			foreach ( $issues as $issue ) {
				if ( ! is_array( $issue ) || ! isset( $issue['code'] ) || ! is_scalar( $issue['code'] ) || '' === trim( (string) $issue['code'] ) ) {
					continue;
				}

				$clean[] = [
					'code'  => self::text( $issue['code'] ),
					'label' => self::text( $issue['label'] ?? '' ),
					'at'    => self::text( $issue['at'] ?? '' ),
				];
			}

			$copy         = clone $this;
			$copy->issues = $clean;

			return $copy;
		}

		/**
		 * The courier assigned to the shipment, or `null` when the carrier states there is none (yet).
		 *
		 * A courier with no field filled in counts as «none».
		 *
		 * @since 2.0.2
		 * @param array{name?:scalar,phone?:scalar,vehicle?:scalar,plate?:scalar}|null $courier Name, phone, vehicle and plate; all optional.
		 * @return self
		 */
		public function with_courier( ?array $courier ): self {
			$copy                   = clone $this;
			$copy->courier_reported = true;
			$copy->courier          = null;

			if ( null !== $courier ) {
				$clean = [
					'name'    => self::text( $courier['name'] ?? '' ),
					'phone'   => self::text( $courier['phone'] ?? '' ),
					'vehicle' => self::text( $courier['vehicle'] ?? '' ),
					'plate'   => self::text( $courier['plate'] ?? '' ),
				];

				$copy->courier = '' === implode( '', $clean ) ? null : $clean;
			}

			return $copy;
		}

		/**
		 * The reported cost components.
		 *
		 * @since 2.0.2
		 * @return array<string,array{amount:float,currency:string}|null> By component; null = reported as none.
		 */
		public function get_costs(): array {
			return $this->costs;
		}

		/**
		 * One reported cost component.
		 *
		 * @since 2.0.2
		 * @param string $component Component key.
		 * @return array{amount:float,currency:string}|null Null for «none» and for «not reported».
		 */
		public function get_cost( string $component = self::COST_DELIVERY ): ?array {
			return $this->costs[ $component ] ?? null;
		}

		/**
		 * Whether the carrier reported the delivery date, with a value or as «none».
		 *
		 * @since 2.0.2
		 * @return bool
		 */
		public function reports_delivery_date(): bool {
			return $this->date_reported;
		}

		/**
		 * The reported delivery date.
		 *
		 * @since 2.0.2
		 * @return array{date:string,kind:string,window:array{from:string,to:string}|null}|null Null for «none» and for «not reported».
		 */
		public function get_delivery_date(): ?array {
			return $this->date;
		}

		/**
		 * The reported delivery issues.
		 *
		 * @since 2.0.2
		 * @return array<int,array{code:string,label:string,at:string}>|null Null when not reported; an empty list is «no issues».
		 */
		public function get_issues(): ?array {
			return $this->issues;
		}

		/**
		 * Whether the carrier reported the courier, with a value or as «none».
		 *
		 * @since 2.0.2
		 * @return bool
		 */
		public function reports_courier(): bool {
			return $this->courier_reported;
		}

		/**
		 * The reported courier.
		 *
		 * @since 2.0.2
		 * @return array{name:string,phone:string,vehicle:string,plate:string}|null Null for «none» and for «not reported».
		 */
		public function get_courier(): ?array {
			return $this->courier;
		}

		/**
		 * Whether nothing at all was reported.
		 *
		 * @since 2.0.2
		 * @return bool
		 */
		public function is_empty(): bool {
			return [] === $this->costs && ! $this->date_reported && null === $this->issues && ! $this->courier_reported;
		}

		/**
		 * A single-line plain-text value from whatever the carrier sent.
		 *
		 * @param mixed $value Raw value.
		 * @return string
		 */
		private static function text( $value ): string {
			return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
		}
	}
endif;
