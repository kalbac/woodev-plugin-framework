<?php
/**
 * Shipping orders — admin order wizard payload validation
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Checkout\Checkout_Config;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Order_Payload_Validator' ) ) :

	/**
	 * Checks and normalises what the admin order wizard sends (#710 spec D4, card #968).
	 *
	 * The admin path validates its OWN input — no `$_POST`, no session, no `wc_add_notice()`
	 * (spec D4): it returns the problems as DATA, a list of `{ field, code, message }`, which the
	 * REST layer hands back as the 422 body. Only the persistence core is shared with checkout
	 * ({@see Checkout_Handler::persist_values()}).
	 *
	 * **The transport contract this class defines** — the request carries everything the wizard
	 * chose; nothing is read from a cart or a session, and the rates / points routes
	 * (I2a `POST …/orders/rates`, I2b `GET …/orders/pickup/{plugin}/points`) only PRODUCE the
	 * values the client echoes back here:
	 *
	 *  - `customer`       `{ id: int (0 = guest), create_account: bool }`
	 *  - `billing`, `shipping`   `{ first_name, last_name, company, address_1, address_2, city,
	 *                     state (a WooCommerce STATE CODE), postcode, country, phone, email (billing) }`;
	 *                     an empty `shipping` means «ship to the billing address».
	 *  - `items`          `[ { item_id?: int, product_id: int, variation_id?: int, quantity: int,
	 *                     price?: number (per unit, before tax; absent keeps the product's own) } ]`
	 *  - `shipping_line`  `{ method_id, instance_id, rate_id?, label?, cost: number (the FINAL price —
	 *                     the rate's own or the one the manager overrode, spec O8), meta?: { key: scalar } }`
	 *                     — the rate exactly as `…/orders/rates` returned it.
	 *  - `pickup_point`   `{ id }` — required when the chosen method is a pickup one, ignored otherwise.
	 *  - `fields`         the carrier's managed field values by field id (sanitised by its own
	 *                     checkout handler later).
	 *  - `carrier_fields` the carrier's own export fields (spec D7, #973): `field id => value`. Only ids the
	 *                     carrier DECLARED for the chosen tariff ({@see Orders_Provider::get_order_fields()})
	 *                     are read; they are checked by the declaration ({@see Carrier_Field_Set::normalize()},
	 *                     problems under `carrier_fields.{id}`), and the result — typed, defaults applied,
	 *                     hidden fields dropped — is what {@see Order_Editor} stores and hands the marker writer.
	 *  - `payment_method` a payment gateway id ('' = none); `status` a WooCommerce order status.
	 *
	 * The O12 rule lives here: `shipping_line.method_id` must belong to a registered carrier that
	 * declared a marker writer — the framework never creates or edits an order for one that did not
	 * ({@see Orders_Provider::has_marker_writer()}).
	 *
	 * @since 2.0.2
	 */
	class Order_Payload_Validator {

		/**
		 * Most lines one order may carry — a safety floor against a runaway client, not a
		 * business limit (the same figure the rates route uses).
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		public const MAX_LINES = 200;

		/**
		 * Address keys the wizard may send; `email` exists on billing only.
		 *
		 * @since 2.0.2
		 *
		 * @var string[]
		 */
		private const ADDRESS_KEYS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];

		/**
		 * Registry the carriers (providers) are read from.
		 *
		 * @since 2.0.2
		 *
		 * @var Orders_Registry
		 */
		private $registry;

		/**
		 * Seams over WooCommerce singletons, so the class stays testable without them.
		 *
		 * @since 2.0.2
		 *
		 * @var array<string, callable>
		 */
		private $lookups;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry              $registry carriers source.
		 * @param array<string, callable>|null $lookups optional overrides — `pickup_method_ids`
		 *                                            `fn(): string[]`, `gateway_ids` `fn(): string[]`,
		 *                                            `countries` `fn(): array<string,string>`,
		 *                                            `states` `fn( string $country ): array<string,string>`,
		 *                                            `address_rules` `fn( string $country, bool $pickup ): array` — the
		 *                                            checkout's address-field rules ({@see Checkout_Field_Policy::address_rules()}).
		 */
		public function __construct( Orders_Registry $registry, ?array $lookups = null ) {
			$this->registry = $registry;
			$this->lookups  = array_merge(
				[
					'pickup_method_ids' => [ Checkout_Config::class, 'pickup_method_ids' ],
					'gateway_ids'       => [ self::class, 'registered_gateway_ids' ],
					'countries'         => [ self::class, 'wc_countries' ],
					'states'            => [ self::class, 'wc_states' ],
					'address_rules'     => [ self::class, 'checkout_address_rules' ],
				],
				$lookups ?? []
			);
		}

		/**
		 * Validates and normalises one request body.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $payload   the request body.
		 * @param bool                 $is_update whether an existing order is being edited (a final
		 *                                        status is then refused as a target, `item_id` is read,
		 *                                        and an absent `status` means «keep»).
		 * @return array{data: array<string, mixed>, errors: array<int, array{field: string, code: string, message: string}>}
		 *         `data` is meaningful only when `errors` is empty.
		 */
		public function validate( array $payload, bool $is_update ): array {
			$errors = [];

			$customer = $this->check_customer( $payload['customer'] ?? [], $errors );
			$billing  = $this->check_address( $payload['billing'] ?? [], 'billing', true, $errors );
			$shipping = $this->check_address( $payload['shipping'] ?? [], 'shipping', false, $errors );

			$shipping_filled = false;

			foreach ( self::ADDRESS_KEYS as $key ) {
				if ( '' !== $shipping[ $key ] ) {
					$shipping_filled = true;
				}
			}

			if ( ! $shipping_filled ) {
				$shipping = array_intersect_key( $billing, array_flip( self::ADDRESS_KEYS ) );
			}

			if ( '' === $shipping['country'] ) {
				self::add_error( $errors, $shipping_filled ? 'shipping.country' : 'billing.country', 'country_required', __( 'Укажите страну доставки.', 'woodev-plugin-framework' ) );
			}

			if ( $customer['create_account'] ) {
				$this->check_new_account( $billing['email'], $errors );
			}

			$items         = $this->check_items( $payload['items'] ?? null, $is_update, $errors );
			$shipping_line = $this->check_shipping_line( $payload['shipping_line'] ?? null, $errors );
			$pickup_point  = $this->check_pickup_point( $payload['pickup_point'] ?? null, $shipping_line, $errors );
			$carrier_data  = $this->check_carrier_fields( $payload['carrier_fields'] ?? [], $shipping_line, $errors );

			$address_rules = $this->apply_address_policy( $billing, $shipping, $shipping_filled ? 'shipping' : 'billing', $this->is_pickup_line( $shipping_line ), $errors );
			$state_hidden  = ! empty( $address_rules['state']['hidden'] ) || ! empty( $address_rules['state']['removed'] );

			$this->check_state( $billing, 'billing', $state_hidden, $errors );

			if ( $shipping_filled ) {
				$this->check_state( $shipping, 'shipping', $state_hidden, $errors );
			}

			$data = [
				'customer'       => $customer,
				'billing'        => $billing,
				'shipping'       => $shipping,
				'items'          => $items,
				'shipping_line'  => $shipping_line,
				'pickup_point'   => $pickup_point,
				'fields'         => self::scalar_map( $payload['fields'] ?? [] ),
				'carrier_fields' => $carrier_data,
				'payment_method' => $this->check_payment_method( $payload['payment_method'] ?? '', $errors ),
				'status'         => $this->check_status( $payload['status'] ?? null, $is_update, $errors ),
			];

			return [
				'data'   => $data,
				'errors' => $errors,
			];
		}

		/**
		 * The customer block: an existing user, a guest, or a guest with «create an account».
		 *
		 * @since 2.0.2
		 *
		 * @param mixed                            $raw    the `customer` value.
		 * @param array<int, array<string,string>> $errors collected problems.
		 * @return array{id: int, create_account: bool}
		 */
		private function check_customer( $raw, array &$errors ): array {
			$raw = is_array( $raw ) ? $raw : [];
			$id  = isset( $raw['id'] ) && is_numeric( $raw['id'] ) ? max( 0, (int) $raw['id'] ) : 0;

			if ( $id > 0 && ! get_userdata( $id ) ) {
				self::add_error( $errors, 'customer.id', 'unknown_customer', __( 'Покупатель не найден.', 'woodev-plugin-framework' ) );
			}

			return [
				'id'             => $id,
				'create_account' => 0 === $id && filter_var( $raw['create_account'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			];
		}

		/**
		 * «Создать аккаунт» needs an email nobody owns yet (spec O11).
		 *
		 * @since 2.0.2
		 *
		 * @param string                           $email  the billing email.
		 * @param array<int, array<string,string>> $errors collected problems.
		 * @return void
		 */
		private function check_new_account( string $email, array &$errors ): void {
			if ( '' === $email ) {
				self::add_error( $errors, 'billing.email', 'account_needs_email', __( 'Чтобы создать аккаунт, укажите email покупателя.', 'woodev-plugin-framework' ) );

				return;
			}

			if ( email_exists( $email ) ) {
				self::add_error( $errors, 'billing.email', 'email_exists', __( 'Покупатель с таким email уже есть — выберите его в списке.', 'woodev-plugin-framework' ) );
			}
		}

		/**
		 * One address block, sanitised the way WooCommerce sanitises a posted address.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed                            $raw     the address value.
		 * @param string                           $section `billing` or `shipping` — the error path prefix.
		 * @param bool                             $with_email whether an `email` key is part of this block.
		 * @param array<int, array<string,string>> $errors  collected problems.
		 * @return array<string, string>
		 */
		private function check_address( $raw, string $section, bool $with_email, array &$errors ): array {
			$raw     = is_array( $raw ) ? $raw : [];
			$address = [];

			foreach ( self::ADDRESS_KEYS as $key ) {
				$address[ $key ] = self::text( $raw[ $key ] ?? '' );
			}

			$address['country'] = strtoupper( $address['country'] );
			$address['phone']   = '' === $address['phone'] ? '' : wc_sanitize_phone_number( $address['phone'] );

			if ( '' !== $address['postcode'] ) {
				$address['postcode'] = wc_format_postcode( $address['postcode'], $address['country'] );
			}

			if ( '' !== $address['country'] ) {
				$countries = (array) call_user_func( $this->lookups['countries'] );

				if ( ! array_key_exists( $address['country'], $countries ) ) {
					self::add_error( $errors, $section . '.country', 'invalid_country', __( 'Такой страны нет в справочнике магазина.', 'woodev-plugin-framework' ) );
				}
			}

			if ( $with_email ) {
				$email = isset( $raw['email'] ) && is_scalar( $raw['email'] ) ? sanitize_email( (string) $raw['email'] ) : '';

				if ( '' !== $email && ! is_email( $email ) ) {
					self::add_error( $errors, $section . '.email', 'invalid_email', __( 'Проверьте email покупателя.', 'woodev-plugin-framework' ) );
					$email = '';
				}

				$address['email'] = $email;
			}

			return $address;
		}

		/**
		 * Checks a state code after the delivery address policy has removed or hidden any fields it owns.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, string>            $address      the checked address.
		 * @param string                           $section      `billing` or `shipping` — the error path prefix.
		 * @param bool                             $state_hidden whether the policy hides or removes the state control.
		 * @param array<int, array<string,string>> $errors       collected problems.
		 * @return void
		 */
		private function check_state( array $address, string $section, bool $state_hidden, array &$errors ): void {
			if ( $state_hidden || '' === $address['country'] || '' === $address['state'] ) {
				return;
			}

			$countries = (array) call_user_func( $this->lookups['countries'] );

			if ( ! array_key_exists( $address['country'], $countries ) ) {
				return;
			}

			$states = (array) call_user_func( $this->lookups['states'], $address['country'] );

			if ( [] !== $states && ! array_key_exists( $address['state'], $states ) ) {
				self::add_error( $errors, $section . '.state', 'invalid_state', __( 'Такого региона нет в справочнике магазина для выбранной страны.', 'woodev-plugin-framework' ) );
			}
		}

		/**
		 * The order lines: real products at a sane quantity and price.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed                            $raw       the `items` value.
		 * @param bool                             $is_update whether `item_id` is read.
		 * @param array<int, array<string,string>> $errors    collected problems.
		 * @return array<int, array<string, mixed>> each: `item_id` (int), `product` (`\WC_Product`),
		 *         `product_id` (int), `variation_id` (int), `quantity` (int), `price` (string|null).
		 */
		private function check_items( $raw, bool $is_update, array &$errors ): array {
			if ( ! is_array( $raw ) || [] === $raw ) {
				self::add_error( $errors, 'items', 'items_required', __( 'Добавьте в заказ хотя бы один товар.', 'woodev-plugin-framework' ) );

				return [];
			}

			if ( count( $raw ) > self::MAX_LINES ) {
				self::add_error( $errors, 'items', 'too_many_items', __( 'В заказе слишком много позиций.', 'woodev-plugin-framework' ) );

				return [];
			}

			$items = [];

			foreach ( array_values( $raw ) as $index => $line ) {
				$path = 'items.' . $index;
				$line = is_array( $line ) ? $line : [];

				$product_id   = isset( $line['product_id'] ) && is_numeric( $line['product_id'] ) ? (int) $line['product_id'] : 0;
				$variation_id = isset( $line['variation_id'] ) && is_numeric( $line['variation_id'] ) ? (int) $line['variation_id'] : 0;
				$quantity     = isset( $line['quantity'] ) && is_numeric( $line['quantity'] ) ? (int) $line['quantity'] : 0;
				$price        = null;

				if ( $quantity < 1 ) {
					self::add_error( $errors, $path . '.quantity', 'invalid_quantity', __( 'Количество должно быть не меньше единицы.', 'woodev-plugin-framework' ) );
				}

				if ( isset( $line['price'] ) && '' !== $line['price'] ) {
					if ( is_numeric( $line['price'] ) && (float) $line['price'] >= 0 ) {
						$price = wc_format_decimal( $line['price'] );
					} else {
						self::add_error( $errors, $path . '.price', 'invalid_price', __( 'Цена должна быть числом не меньше нуля.', 'woodev-plugin-framework' ) );
					}
				}

				$product = $this->resolve_product( $product_id, $variation_id );

				if ( null === $product ) {
					self::add_error( $errors, $path . '.product_id', 'unknown_product', __( 'Товар не найден — выберите его из списка ещё раз.', 'woodev-plugin-framework' ) );

					continue;
				}

				$items[] = [
					'item_id'      => $is_update && isset( $line['item_id'] ) && is_numeric( $line['item_id'] ) ? max( 0, (int) $line['item_id'] ) : 0,
					'product'      => $product,
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'quantity'     => $quantity,
					'price'        => $price,
				];
			}

			return $items;
		}

		/**
		 * Resolves a product / variation pair to the product an order line is made of.
		 *
		 * A variable PARENT alone is not orderable — WooCommerce needs the variation — and a
		 * variation must belong to the product it was sent with.
		 *
		 * @since 2.0.2
		 *
		 * @param int $product_id   the product id.
		 * @param int $variation_id the variation id, 0 for none.
		 * @return \WC_Product|null
		 */
		private function resolve_product( int $product_id, int $variation_id ): ?\WC_Product {
			if ( $product_id < 1 ) {
				return null;
			}

			$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );

			if ( ! $product instanceof \WC_Product ) {
				return null;
			}

			if ( $variation_id > 0 ) {
				return $product->is_type( 'variation' ) && (int) $product->get_parent_id() === $product_id ? $product : null;
			}

			return $product->is_type( 'variable' ) ? null : $product;
		}

		/**
		 * The chosen delivery rate: one of OUR carriers' methods (spec O12), priced.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed                            $raw    the `shipping_line` value.
		 * @param array<int, array<string,string>> $errors collected problems.
		 * @return array<string, mixed>|null `provider` (Orders_Provider), `method_id`, `instance_id`, `rate_id`,
		 *         `label`, `cost` (string), `meta` (scalar map) — null when unusable.
		 */
		private function check_shipping_line( $raw, array &$errors ): ?array {
			if ( ! is_array( $raw ) || [] === $raw ) {
				self::add_error( $errors, 'shipping_line', 'shipping_required', __( 'Выберите способ доставки.', 'woodev-plugin-framework' ) );

				return null;
			}

			$method_id   = self::text( $raw['method_id'] ?? '' );
			$instance_id = isset( $raw['instance_id'] ) && is_numeric( $raw['instance_id'] ) ? max( 0, (int) $raw['instance_id'] ) : 0;
			$cost        = null;

			if ( isset( $raw['cost'] ) && is_numeric( $raw['cost'] ) && (float) $raw['cost'] >= 0 ) {
				$cost = wc_format_decimal( $raw['cost'] );
			} else {
				self::add_error( $errors, 'shipping_line.cost', 'invalid_cost', __( 'Стоимость доставки должна быть числом не меньше нуля.', 'woodev-plugin-framework' ) );
			}

			$provider = $this->provider_for_method( $method_id );

			if ( null === $provider ) {
				self::add_error(
					$errors,
					'shipping_line.method_id',
					'foreign_method',
					__( 'Этот способ доставки не относится к перевозчикам магазина. Такие заказы оформляются штатным редактором WooCommerce.', 'woodev-plugin-framework' )
				);

				return null;
			}

			if ( ! $provider->has_marker_writer() ) {
				// Plugin bug the manager cannot fix — it is logged, and the carrier is simply not offered.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic: a carrier plugin declared no marker writer.
				error_log( sprintf( '[woodev] carrier "%s" declared no marker_writer — the admin order wizard cannot create or edit its orders.', $provider->get_id() ) );

				self::add_error( $errors, 'shipping_line.method_id', 'carrier_unsupported', __( 'Для этого перевозчика заказы из админки пока недоступны.', 'woodev-plugin-framework' ) );

				return null;
			}

			if ( null === $cost ) {
				return null;
			}

			$label = self::text( $raw['label'] ?? '' );

			return [
				'provider'    => $provider,
				'method_id'   => $method_id,
				'instance_id' => $instance_id,
				'rate_id'     => '' !== self::text( $raw['rate_id'] ?? '' ) ? self::text( $raw['rate_id'] ) : ( $instance_id > 0 ? $method_id . ':' . $instance_id : $method_id ),
				'label'       => '' !== $label ? $label : $method_id,
				'cost'        => $cost,
				'meta'        => self::scalar_map( $raw['meta'] ?? [] ),
			];
		}

		/**
		 * The carrier that owns a bare shipping method id.
		 *
		 * @since 2.0.2
		 *
		 * @param string $method_id the bare method id.
		 * @return Orders_Provider|null
		 */
		private function provider_for_method( string $method_id ): ?Orders_Provider {
			if ( '' === $method_id ) {
				return null;
			}

			foreach ( $this->registry->get_providers() as $provider ) {
				if ( in_array( $method_id, $provider->get_method_ids(), true ) ) {
					return $provider;
				}
			}

			return null;
		}

		/**
		 * The pickup point: required for a pickup tariff (checkout parity, spec A2), dropped for any
		 * other — a stale point of a method that was not chosen never reaches the order (#745).
		 *
		 * @since 2.0.2
		 *
		 * @param mixed                            $raw           the `pickup_point` value.
		 * @param array<string, mixed>|null        $shipping_line the checked shipping line.
		 * @param array<int, array<string,string>> $errors        collected problems.
		 * @return array{id: string}|null
		 */
		private function check_pickup_point( $raw, ?array $shipping_line, array &$errors ): ?array {
			if ( ! $this->is_pickup_line( $shipping_line ) ) {
				return null;
			}

			$id = is_array( $raw ) ? self::text( $raw['id'] ?? '' ) : '';

			if ( '' === $id ) {
				self::add_error( $errors, 'pickup_point.id', 'pickup_required', __( 'Для этого тарифа выберите пункт выдачи.', 'woodev-plugin-framework' ) );

				return null;
			}

			return [ 'id' => $id ];
		}

		/**
		 * Whether the checked shipping line is a pickup tariff — the one reading of «pickup» the point
		 * check and the address policy share.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed>|null $shipping_line the checked shipping line.
		 * @return bool
		 */
		private function is_pickup_line( ?array $shipping_line ): bool {
			if ( null === $shipping_line ) {
				return false;
			}

			$chosen = $shipping_line['instance_id'] > 0 ? $shipping_line['method_id'] . ':' . $shipping_line['instance_id'] : $shipping_line['method_id'];

			return Checkout_Handler::chosen_method_matches( $chosen, (array) call_user_func( $this->lookups['pickup_method_ids'] ) );
		}

		/**
		 * The checkout's address-field policy, applied to the DELIVERY address (#985): a field the policy
		 * makes required and the address leaves empty is refused, a field the checkout removes outright
		 * (the merchant's «Удалять») is emptied on both address blocks — its value never reaches a checkout
		 * order, so it must not reach this one. A field only hidden (locale, or `hide_for_pickup`) is left
		 * as sent: the checkout keeps such a value too.
		 *
		 * The rules are the checkout's own ({@see Checkout_Field_Policy::address_rules()}); the wizard's
		 * step ② reads the same object through `GET …/orders/address-policy`, so client and server judge
		 * one rule. One deliberate exception: the postcode is never required here
		 * ({@see self::wizard_address_rules()}, #999 — the admin trusts the merchant, the checkout's
		 * «required» is buyer fool-proofing); do not re-add the checkout flag «to keep them in sync». No rule for a country (WooCommerce absent) means nothing is enforced here.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, string>            $billing  the checked billing address (emptied fields are written back).
		 * @param array<string, string>            $shipping the checked delivery address (emptied fields are written back).
		 * @param string                           $section  `shipping`, or `billing` when the delivery address follows billing — the error path prefix.
		 * @param bool                             $pickup   whether the chosen tariff is a pickup one.
		 * @param array<int, array<string,string>> $errors   collected problems.
		 * @return array<string, array{required: bool, hidden: bool, removed: bool}> the delivery address rules.
		 */
		private function apply_address_policy( array &$billing, array &$shipping, string $section, bool $pickup, array &$errors ): array {
			if ( '' === $shipping['country'] ) {
				return [];
			}

			$rules = self::wizard_address_rules( (array) call_user_func( $this->lookups['address_rules'], $shipping['country'], $pickup ) );

			foreach ( self::address_field_messages() as $key => $message ) {
				$rule = $rules[ $key ] ?? null;

				if ( ! is_array( $rule ) ) {
					continue;
				}

				if ( ! empty( $rule['removed'] ) ) {
					$billing[ $key ]  = '';
					$shipping[ $key ] = '';

					continue;
				}

				if ( ! empty( $rule['required'] ) && '' === $shipping[ $key ] ) {
					self::add_error( $errors, $section . '.' . $key, 'field_required', $message );
				}
			}

			return $rules;
		}

		/**
		 * The checkout's address rules as the WIZARD reads them (#999): the postcode is never required.
		 *
		 * The checkout is a fool-proof screen for the buyer; the wizard is for the shop staff, who rarely
		 * know a customer's postcode (WooCommerce's own RU locale requires it). Only `required` is
		 * neutralised — whether the postcode is shown, hidden or removed still follows the shop's policy —
		 * and the checkout keeps its own rules untouched. The route `GET …/orders/address-policy` answers
		 * through this too, so the step ② and the save-time check stay one rule.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $rules the checkout's rules, as `Checkout_Field_Policy::address_rules()` returns them.
		 * @return array<string, mixed> the same rules, the postcode optional.
		 */
		public static function wizard_address_rules( array $rules ): array {
			if ( isset( $rules['postcode'] ) && is_array( $rules['postcode'] ) ) {
				$rules['postcode']['required'] = false;
			}

			return $rules;
		}

		/**
		 * The sentence for each address field the policy can require — the wizard's step ② says the same
		 * words (`validation.ts` → `validateAddress()`), so a problem reads alike wherever it is caught.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, string> address key → message.
		 */
		private static function address_field_messages(): array {
			return [
				'state'     => __( 'Укажите регион доставки.', 'woodev-plugin-framework' ),
				'city'      => __( 'Укажите город или населённый пункт.', 'woodev-plugin-framework' ),
				'address_1' => __( 'Укажите улицу и дом.', 'woodev-plugin-framework' ),
				'address_2' => __( 'Укажите квартиру или офис.', 'woodev-plugin-framework' ),
				'postcode'  => __( 'Укажите индекс.', 'woodev-plugin-framework' ),
			];
		}

		/**
		 * The checkout's address-field rules for a country — the default of the `address_rules` lookup.
		 *
		 * @since 2.0.2
		 *
		 * @param string $country ISO country code of the delivery address.
		 * @param bool   $pickup  whether the chosen tariff is a pickup one.
		 * @return array<string, array{required: bool, hidden: bool, removed: bool}>
		 */
		private static function checkout_address_rules( string $country, bool $pickup ): array {
			return Checkout_Field_Policy::instance()->address_rules( $country, $pickup );
		}

		/**
		 * The carrier's own order fields for the chosen tariff (spec D7): read by the carrier's
		 * declaration, anything it did not declare dropped.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed                            $raw           the `carrier_fields` value.
		 * @param array<string, mixed>|null        $shipping_line the checked shipping line.
		 * @param array<int, array<string,string>> $errors        collected problems.
		 * @return array<string, mixed> field id => the normalised value; empty without a usable line.
		 */
		private function check_carrier_fields( $raw, ?array $shipping_line, array &$errors ): array {
			if ( null === $shipping_line ) {
				return [];
			}

			$set = Carrier_Field_Set::for_rate( $shipping_line['provider'], $shipping_line['method_id'], $shipping_line['instance_id'] );

			if ( $set->is_empty() ) {
				return [];
			}

			$result = $set->normalize( is_array( $raw ) ? $raw : [] );

			foreach ( $result['errors'] as $error ) {
				self::add_error( $errors, $error['field'], $error['code'], $error['message'] );
			}

			return $result['values'];
		}

		/**
		 * The payment method — a registered gateway, or none.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed                            $raw    the `payment_method` value.
		 * @param array<int, array<string,string>> $errors collected problems.
		 * @return string the gateway id, '' for none.
		 */
		private function check_payment_method( $raw, array &$errors ): string {
			$id = self::text( $raw );

			if ( '' !== $id && ! in_array( $id, (array) call_user_func( $this->lookups['gateway_ids'] ), true ) ) {
				self::add_error( $errors, 'payment_method', 'unknown_payment_method', __( 'Такого способа оплаты нет в магазине.', 'woodev-plugin-framework' ) );
			}

			return $id;
		}

		/**
		 * The order status, without WooCommerce's `wc-` prefix.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed                            $raw       the `status` value.
		 * @param bool                             $is_update whether an existing order is being edited.
		 * @param array<int, array<string,string>> $errors    collected problems.
		 * @return string|null null = keep the current status (update only); a create defaults to `pending`.
		 */
		private function check_status( $raw, bool $is_update, array &$errors ): ?string {
			$status = self::text( $raw );

			if ( 0 === strpos( $status, 'wc-' ) ) {
				$status = substr( $status, 3 );
			}

			if ( '' === $status ) {
				return $is_update ? null : 'pending';
			}

			$known = array_map(
				static function ( string $slug ): string {
					return 0 === strpos( $slug, 'wc-' ) ? substr( $slug, 3 ) : $slug;
				},
				array_keys( wc_get_order_statuses() )
			);

			if ( ! in_array( $status, $known, true ) ) {
				self::add_error( $errors, 'status', 'unknown_status', __( 'Такого статуса заказа нет.', 'woodev-plugin-framework' ) );
			} elseif ( $is_update && in_array( $status, Order_Actions::FINAL_STATUSES, true ) ) {
				self::add_error(
					$errors,
					'status',
					'final_status',
					sprintf(
						/* translators: %s: WooCommerce order status name, e.g. "Выполнен". */
						__( 'Статус «%s» при редактировании не выставляется — отмена, возврат и завершение заказа делаются штатными средствами WooCommerce.', 'woodev-plugin-framework' ),
						wc_get_order_status_name( $status )
					)
				);
			}

			return $status;
		}

		/**
		 * A request map reduced to its scalar entries, keys and values cleaned.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $raw the received value.
		 * @return array<string, string|int|float|bool>
		 */
		private static function scalar_map( $raw ): array {
			if ( ! is_array( $raw ) ) {
				return [];
			}

			$map = [];

			foreach ( $raw as $key => $value ) {
				if ( ! is_scalar( $value ) ) {
					continue;
				}

				$key = self::text( (string) $key );

				if ( '' !== $key ) {
					$map[ $key ] = is_string( $value ) ? wc_clean( $value ) : $value;
				}
			}

			return $map;
		}

		/**
		 * A request scalar as a clean string; anything else is ''.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value the received value.
		 * @return string
		 */
		private static function text( $value ): string {
			return is_scalar( $value ) ? (string) wc_clean( (string) $value ) : '';
		}

		/**
		 * Appends one problem.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, array<string,string>> $errors  collected problems.
		 * @param string                           $field   dotted path of the offending request field.
		 * @param string                           $code    machine code.
		 * @param string                           $message sentence for the manager.
		 * @return void
		 */
		private static function add_error( array &$errors, string $field, string $code, string $message ): void {
			$errors[] = [
				'field'   => $field,
				'code'    => $code,
				'message' => $message,
			];
		}

		/**
		 * Ids of the payment gateways the shop has registered.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		private static function registered_gateway_ids(): array {
			return array_map( 'strval', array_keys( WC()->payment_gateways()->payment_gateways() ) );
		}

		/**
		 * The shop's country list (every country, not only the ones it sells to — a manager places
		 * an order for whoever phoned).
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, string>
		 */
		private static function wc_countries(): array {
			return (array) WC()->countries->get_countries();
		}

		/**
		 * A country's states, keyed by WooCommerce state code; empty when it has none.
		 *
		 * @since 2.0.2
		 *
		 * @param string $country the country code.
		 * @return array<string, string>
		 */
		private static function wc_states( string $country ): array {
			$states = WC()->countries->get_states( $country );

			return is_array( $states ) ? $states : [];
		}
	}

endif;
