<?php
/**
 * Server pickup adapter for the WooCommerce Store API (9.9+).
 *
 * @package Woodev\Framework\Shipping\Pickup
 * @since 2.0.2
 */
namespace Woodev\Framework\Shipping\Pickup;

use Woodev\Framework\Http\Rest_Rate_Limit_Trait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Store_Api_Pickup' ) ) :

	/**
	 * One transport namespace shared by all active carrier handlers.
	 *
	 * Request/echo shape: pickup[plugin_id][field_id]. Server registration is independent
	 * of block rendering. Existing carrier session keys and order writers stay authoritative.
	 *
	 * @since 2.0.2
	 */
	class Store_Api_Pickup {
		use Rest_Rate_Limit_Trait;

		/** @var string Store API extension namespace. */
		public const EXTENSION_NAMESPACE = 'woodev-shipping';
		/** @var string Session key of the destination postcode's writer record ({@see self::adopted_postcode()}). */
		private const ADOPTED_POSTCODE_KEY = 'woodev_store_api_adopted_postcode';
		/**
		 * @var string[] Where the writer record ends ({@see self::forget_adopted_postcode()}): the
		 *      order placed on either checkout, the cart emptied, and the three address forms no
		 *      watcher of ours stands on — classic order review, cart calculator, My Account.
		 */
		private const ADOPTION_END_HOOKS = [
			'woocommerce_store_api_checkout_order_processed',
			'woocommerce_checkout_order_processed',
			'woocommerce_cart_emptied',
			'woocommerce_checkout_update_order_review',
			'woocommerce_calculated_shipping',
			'woocommerce_customer_save_address',
		];
		/** @var array<string, array<string, Pickup_Handler>> Active carrier field owners. */
		private static array $handlers = [];
		/** @var bool Whether initialization hooks were attached. */
		private static bool $booted = false;
		/** @var bool Whether Store API callbacks were registered. */
		private static bool $registered = false;
		/** @var array<int, array<string, mixed>> Request echoes, scoped to this PHP request. */
		private static array $echoes = [];

		/**
		 * Adds a carrier without registering a competing namespace callback.
		 *
		 * @since 2.0.2
		 * @param Pickup_Handler $handler Carrier handler.
		 * @param string         $plugin_id Owning plugin identity.
		 * @param string         $field_id Installed checkout field identity.
		 * @return void
		 */
		public static function add_handler( Pickup_Handler $handler, string $plugin_id, string $field_id ): void {
			self::$handlers[ $plugin_id ][ $field_id ] = $handler;
			if ( ! self::$booted ) {
				self::$booted = true;
				add_action( 'woocommerce_blocks_loaded', [ self::class, 'register' ] );
				add_action( 'woocommerce_init', [ self::class, 'register' ] );
			}
			if ( did_action( 'woocommerce_blocks_loaded' ) ) {
				self::register();
			}
		}

		/**
		 * Feature-detects the WC 9.9 payment gate and extension functions.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public static function register(): void {
			$controller = '\\Automattic\\WooCommerce\\StoreApi\\Utilities\\OrderController';
			if ( self::$registered || ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.9', '<' ) )
				|| ! method_exists( $controller, 'perform_custom_order_validation' )
				|| ! function_exists( 'woocommerce_store_api_register_update_callback' )
				|| ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
				return;
			}
			self::$registered = true;
			woocommerce_store_api_register_update_callback(
				[
					'namespace' => self::EXTENSION_NAMESPACE,
					'callback' => [ self::class, 'update' ],
				]
			);
			woocommerce_store_api_register_endpoint_data(
				[
					'endpoint' => 'cart',
					'namespace' => self::EXTENSION_NAMESPACE,
					'schema_callback' => [ self::class, 'schema' ],
					'data_callback' => [ self::class, 'cart_data' ],
					'schema_type' => ARRAY_A,
				]
			);
			woocommerce_store_api_register_endpoint_data(
				[
					'endpoint' => 'checkout',
					'namespace' => self::EXTENSION_NAMESPACE,
					'schema_callback' => [ self::class, 'checkout_schema' ],
					'schema_type' => ARRAY_A,
				]
			);
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ self::class, 'update_order' ], 20, 2 );
			add_action( 'woocommerce_checkout_validate_order_before_payment', [ self::class, 'validate_order' ], 20, 2 );
			// The server's own sight of a postcode edit: the route the address form is pushed through (#1113).
			add_action( 'woocommerce_store_api_cart_update_customer_from_request', [ self::class, 'observe_customer' ] );
			// The record is one checkout's and no longer: it must not authorize clearing a later one's postcode.
			foreach ( self::ADOPTION_END_HOOKS as $hook ) {
				add_action( $hook, [ self::class, 'forget_adopted_postcode' ], 10, 0 );
			}
			// This method accompanies the deferred-draft hook in WC 10.8; older WC needs no no-order reconciliation.
			if ( method_exists( '\\Automattic\\WooCommerce\\StoreApi\\Routes\\V1\\Checkout', 'build_draft_route_response' ) ) {
				add_action( 'woocommerce_store_api_checkout_update_draft', [ self::class, 'update_draft' ] );
			}
		}

		/**
		 * Cart extension schema, including each carrier's compact confirmation.
		 *
		 * @since 2.0.2
		 * @return array<string, mixed>
		 */
		public static function schema(): array {
			return [
				'pickup' => [
					'type' => 'object',
					'readonly' => true,
					'additionalProperties' => true,
				],
				'owner' => [
					'type' => [ 'object', 'null' ],
					'readonly' => true,
					'additionalProperties' => true,
				],
			];
		}

		/**
		 * Accepts checkout echoes and explicit clears through WC's recursive sanitizer.
		 *
		 * @since 2.0.2
		 * @return array<string, mixed>
		 */
		public static function checkout_schema(): array {
			$schema = self::schema();
			$schema['pickup']['readonly'] = false;
			// The owner is the server's own answer about the cart; a client never sends it.
			unset( $schema['owner'] );
			return $schema;
		}

		/**
		 * Reads the session's reusable Store API draft identity.
		 *
		 * @since 2.0.2
		 * @return int
		 */
		protected static function draft_order_id(): int {
			return function_exists( 'WC' ) && WC()->session ? (int) WC()->session->get( 'store_api_draft_order', 0 ) : 0;
		}

		/**
		 * Whether this adapter will validate a registered pickup field for this order.
		 *
		 * @since 2.0.2
		 * @param \WC_Order $order Order being validated.
		 * @param string    $plugin_id Carrier identity.
		 * @param string    $field_id Checkout field identity.
		 * @return bool
		 */
		public static function validates_field( \WC_Order $order, string $plugin_id, string $field_id ): bool {
			return array_key_exists( $order->get_id(), self::$echoes )
				&& (int) $order->get_id() === static::draft_order_id()
				&& isset( self::$handlers[ $plugin_id ][ $field_id ] );
		}

		/**
		 * Reads current rates, refreshing them only on mutation and payment paths.
		 *
		 * @since 2.0.2
		 * @param bool $refresh Whether to refresh destination-dependent shipping rates.
		 * @return array<string, mixed>
		 */
		protected static function context( bool $refresh = false ): array {
			if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
				return [
					'rate_id' => '',
					'address_key' => '',
					'chosen' => [],
					'packages' => [],
				];
			}
			if ( $refresh ) {
				WC()->cart->calculate_shipping();
			}
			$packages = WC()->shipping()->get_packages();
			$chosen = (array) WC()->session->get( 'chosen_shipping_methods', [] );
			return [
				'rate_id' => (string) ( $chosen[0] ?? '' ),
				'address_key' => self::address_key( $packages[0]['destination'] ?? [] ),
				'chosen' => $chosen,
				'packages' => $packages,
			];
		}

		/**
		 * Reads the declared payment choice from the server session.
		 *
		 * @since 2.0.2
		 * @return string
		 */
		protected static function chosen_payment_method(): string {
			return function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '';
		}

		/**
		 * Checks a declared gateway against the live available server gateway registry.
		 *
		 * @since 2.0.2
		 * @param string $payment Gateway id.
		 * @return bool
		 */
		protected static function payment_available( string $payment ): bool {
			return function_exists( 'WC' ) && isset( WC()->payment_gateways()->get_available_payment_gateways()[ $payment ] );
		}

		/**
		 * Stable destination identity; names and contact details do not select a point.
		 *
		 * @since 2.0.2
		 * @param array<string, mixed> $address Server address.
		 * @return string
		 */
		public static function address_key( array $address ): string {
			$values = [];
			foreach ( [ 'country', 'state', 'city', 'postcode', 'address_1', 'address_2' ] as $key ) {
				$values[] = (string) ( $address[ $key ] ?? '' );
			}
			return hash( 'sha256', (string) wp_json_encode( $values ) );
		}

		/**
		 * Confirms only an owned point on a currently available server rate.
		 * WC returns recalculated cart state after this callback; no custom REST response.
		 *
		 * @since 2.0.2
		 * @param array<string, mixed> $data Client identity/clear commands keyed by plugin and field.
		 * @return void
		 */
		public static function update( array $data ): void {
			if ( static::selection_rate_limited() ) {
				self::refuse( __( 'Too many requests. Please wait a moment and try again.', 'woodev-plugin-framework' ), 429 );
			}
			$context = static::context( true );
			self::observe_destination( $context );
			self::require_supported_packages( $context );
			$prepared = [];
			$edited   = false;
			foreach ( self::commands( $data ) as $command ) {
				[ $handler, $value ] = $command;
				// The browser's report of a postcode edit no request carried (see adopted_postcode()).
				$edited = $edited || true === ( $value['postcode_edited'] ?? false );
				// After a switch to courier, clearing the old picker is a successful no-op.
				if ( true === ( $value['clear'] ?? false ) ) {
					$prepared[] = [ $handler, null ];
					continue;
				}
				if ( ! $handler->owns_store_api_rate( $context['rate_id'] ) || ! self::rate_available( $context ) ) {
					self::refuse( self::choose_message() );
				}
				// No resolved locality refuses EVERY point; say what to do about it (#1110).
				if ( ! $handler->has_store_api_locality( $context['rate_id'] ) ) {
					self::refuse( self::locality_message() );
				}
				$point_id = self::clean_id( $value, 'point_id' );
				if ( '' === $point_id ) {
					self::refuse( self::choose_message() );
				}
				$payment = self::clean_id( $value, 'payment_method', static::chosen_payment_method() );
				if ( '' !== $payment && ! static::payment_available( $payment ) ) {
					self::refuse( self::choose_message() );
				}
				try {
					$selection = $handler->prepare_store_api_selection( $point_id, $context['rate_id'], $payment, $context['address_key'] );
				} catch ( \Throwable $exception ) {
					// Foreign carrier exception text must never cross the storefront boundary.
					self::refuse( self::choose_message() );
					return;
				}
				if ( ! $selection['result']['allowed'] ) {
					self::refuse( $selection['result']['reason'] ?? self::choose_message() );
				}
				$prepared[] = [ $handler, $selection ];
			}
			// All commands, including carrier verdicts, must pass before any persistence action.
			if ( $edited ) {
				static::remember_adopted_postcode( '' );
			}
			foreach ( $prepared as $index => [ $handler, $selection ] ) {
				if ( null !== $selection ) {
					// The store's address-replacement policy moves the destination with the confirmation.
					[ $context, $destination ] = self::replace_destination( $handler, $selection, $context );
					$prepared[ $index ][1]['address_key'] = $context['address_key'];
					$prepared[ $index ][1]['destination'] = $destination;
				}
			}
			foreach ( self::$handlers as $fields ) {
				foreach ( $fields as $handler ) {
					$handler->reconcile_store_api_selection( $context['rate_id'], $context['address_key'] );
				}
			}
			foreach ( $prepared as [ $handler, $selection ] ) {
				if ( null === $selection ) {
					$handler->clear_store_api_selection( $context['rate_id'] );
				} else {
					$handler->persist_store_api_selection( $selection );
				}
			}
		}

		/**
		 * Moves the destination to the confirmed point's own address when the store asks for it
		 * (`pickup_replace_address`), and answers the context the confirmation is remembered under.
		 *
		 * Destination and confirmation are reconciled in ONE request, here, because they cannot be
		 * reconciled in two: a confirmation is bound to the destination it was made for, so an
		 * address the browser rewrote afterwards would drop the confirmation it had just received.
		 * The point's address comes from the point the server fetched itself — never from the
		 * client — and the confirmation names the fields it moved, so the address form can follow.
		 *
		 * A replacement the chosen rate does not survive is undone: the point stays confirmed for
		 * the customer's own address rather than the customer losing the rate they chose.
		 *
		 * @since 2.0.2
		 * @param Pickup_Handler       $handler Owning carrier handler.
		 * @param array<string, mixed> $selection Allowed, server-prepared selection.
		 * @param array<string, mixed> $context Server cart context the selection was prepared in.
		 * @return array{0: array<string, mixed>, 1: array<string, string>} The context to remember the
		 *         confirmation under, and the destination fields that now hold the point's address.
		 */
		private static function replace_destination( Pickup_Handler $handler, array $selection, array $context ): array {
			$fields = $handler->store_api_replacement_address( $selection );
			if ( [] === $fields ) {
				return [ $context, [] ];
			}
			// A point without a postcode leaves the customer's own alone — but not the one a
			// previous point wrote there, which would stay beside this point's street (#1113).
			if ( ! isset( $fields['postcode'] ) && self::holds_adopted_postcode( $context ) ) {
				$fields['postcode'] = '';
			}
			$previous = static::write_destination( $fields );
			if ( $previous === $fields ) {
				return [ $context, $fields ];
			}
			$moved = static::context( true );
			if ( $moved['rate_id'] === $context['rate_id'] && self::rate_available( $moved ) ) {
				// As the destination now holds them (WooCommerce formats the postcode it is given).
				$destination = (array) ( $moved['packages'][0]['destination'] ?? [] );
				foreach ( $fields as $key => $value ) {
					$fields[ $key ] = (string) ( $destination[ $key ] ?? $value );
				}
				// A point is the postcode's writer only where it CHANGED it: one the customer had
				// typed already stays theirs. A cleared postcode has no writer.
				if ( isset( $fields['postcode'] ) && $fields['postcode'] !== ( $previous['postcode'] ?? '' ) ) {
					static::remember_adopted_postcode( $fields['postcode'] );
				}
				return [ $moved, $fields ];
			}
			static::write_destination( $previous, $context['chosen'] );
			return [ static::context( true ), [] ];
		}

		/**
		 * Whether the destination's postcode is one a pickup point wrote there — any carrier's:
		 * the customer may have moved from another carrier's point to this one — and nobody has
		 * been seen to edit since ({@see self::adopted_postcode()}).
		 *
		 * @since 2.0.2
		 * @param array<string, mixed> $context Server cart context, before the new point moves it.
		 * @return bool
		 */
		private static function holds_adopted_postcode( array $context ): bool {
			$adopted = static::adopted_postcode();
			return '' !== $adopted && isset( $context['packages'][0]['destination'] )
				&& (string) ( $context['packages'][0]['destination']['postcode'] ?? '' ) === $adopted;
		}

		/**
		 * The postcode a pickup point WROTE into the destination, while the postcode there is
		 * still that point's — or `''` (#1113). The record of the WRITER, kept apart from the
		 * destination's value: a postcode equal to a point's proves nothing about who typed it.
		 *
		 * WRITTEN only by this adapter, from a point the server fetched itself, and only when the
		 * confirmation changed the postcode ({@see self::replace_destination()}).
		 *
		 * VOIDED by any sign of another writer, for good — typing the same digits back does not
		 * restore it:
		 *
		 * - the server sees the destination hold another postcode: in the customer route the
		 *   address form is pushed through ({@see self::observe_customer()}), and on every
		 *   mutation path of this adapter ({@see self::observe_destination()});
		 * - the browser reports an edit with its selection command (`postcode_edited`). That is
		 *   the one thing the server cannot see for itself: a postcode retyped to the SAME value,
		 *   or changed and changed back before the form was pushed, reaches it as no change at all.
		 *
		 * TRUST. The browser's word can only KEEP a postcode. Nothing a client sends makes the
		 * server clear one: clearing stands on this record alone, and the record is the server's
		 * own. A client that reports nothing — a reloaded page, which has seen no edit — leaves
		 * the decision to what the server saw.
		 *
		 * ENDS with the checkout it was written in ({@see self::forget_adopted_postcode()}). The
		 * destination outlives a checkout — the next cart starts from the same address — and a
		 * record that outlived it too would let an old adoption clear a postcode the customer has
		 * since made their own.
		 *
		 * One session key for the whole checkout, not one per carrier: there is one destination.
		 *
		 * `protected` as a test seam, as {@see self::draft_order_id()}.
		 *
		 * @since 2.0.2
		 * @return string
		 */
		protected static function adopted_postcode(): string {
			return function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get( self::ADOPTED_POSTCODE_KEY, '' ) : '';
		}

		/**
		 * Records `$postcode` as written by a pickup point; `''` voids the record.
		 *
		 * @since 2.0.2
		 * @param string $postcode Postcode as the destination holds it, or `''`.
		 * @return void
		 */
		protected static function remember_adopted_postcode( string $postcode ): void {
			if ( function_exists( 'WC' ) && WC()->session && static::adopted_postcode() !== $postcode ) {
				WC()->session->set( self::ADOPTED_POSTCODE_KEY, $postcode );
			}
		}

		/**
		 * Voids the writer record once the destination is seen to hold another postcode.
		 *
		 * @since 2.0.2
		 * @param array<string, mixed> $context Refreshed server cart context.
		 * @return void
		 */
		private static function observe_destination( array $context ): void {
			// A cart with no package says nothing about the destination.
			if ( isset( $context['packages'][0]['destination'] ) ) {
				self::observe_postcode( (string) ( $context['packages'][0]['destination']['postcode'] ?? '' ) );
			}
		}

		/**
		 * Voids the writer record when the customer route leaves another shipping postcode — the
		 * address form's own push, so the customer's edit ({@see self::adopted_postcode()}).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 * @param \WC_Customer|mixed $customer Customer as the request left it.
		 * @return void
		 */
		public static function observe_customer( $customer ): void {
			if ( is_object( $customer ) && is_callable( [ $customer, 'get_shipping_postcode' ] ) ) {
				self::observe_postcode( (string) $customer->get_shipping_postcode() );
			}
		}

		/**
		 * Voids the writer record unless `$postcode` is the one it names.
		 *
		 * @since 2.0.2
		 * @param string $postcode The destination's postcode, as just seen.
		 * @return void
		 */
		private static function observe_postcode( string $postcode ): void {
			$adopted = static::adopted_postcode();
			if ( '' !== $adopted && $adopted !== $postcode ) {
				static::remember_adopted_postcode( '' );
			}
		}

		/**
		 * Ends the writer record ({@see self::adopted_postcode()}) where the checkout it was written
		 * in ends, or where the customer met the address on a page this adapter cannot watch.
		 *
		 * The record is bound to these EVENTS and to nothing in the cart, because nothing in the
		 * cart tells two checkouts apart: the cart hash is the same for the same product bought
		 * again, and the Store API draft order does not exist yet when a point is confirmed
		 * (WooCommerce defers it) and never exists on the classic checkout.
		 *
		 * - An order placed, on either checkout: the customer put their name to that address, so
		 *   the postcode in it is theirs from then on — also when the payment fails and the same
		 *   cart is retried.
		 * - The cart emptied. A destroyed session empties the cart first and then drops this key
		 *   with the rest of its data (`WC_Session_Handler::forget_session()`).
		 * - The classic checkout's order-review refresh, the cart's shipping calculator and My
		 *   Account's address form. Ended outright, never compared with the posted postcode: the
		 *   classic checkout posts its address on page load and one second after ANY keystroke,
		 *   so a postcode typed away and back arrives as the value that was there all along, and
		 *   no browser of ours stands on those pages to say otherwise.
		 *
		 * Every one of these errs the same way as the rest of the model: a record ended too early
		 * leaves a previous point's postcode in the form, where the customer sees it.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public static function forget_adopted_postcode(): void {
			static::remember_adopted_postcode( '' );
		}

		/**
		 * Writes street/postcode into the customer's shipping address, as WC's own customer route does.
		 *
		 * Billing follows only where the store ships to the billing address and the two are one
		 * address; a separate billing address is never touched.
		 *
		 * @since 2.0.2
		 * @param array<string, string>   $fields `address_1` and/or `postcode`.
		 * @param array<int, string>|null $chosen Chosen rates to put back with an undone write.
		 * @return array<string, string> The values the written fields held before.
		 */
		protected static function write_destination( array $fields, ?array $chosen = null ): array {
			if ( ! function_exists( 'WC' ) || ! WC()->customer || ! WC()->session ) {
				return $fields;
			}
			$customer = WC()->customer;
			$billing = wc_ship_to_billing_address_only();
			$previous = [];
			if ( isset( $fields['address_1'] ) ) {
				$previous['address_1'] = (string) $customer->get_shipping_address_1();
				$customer->set_shipping_address_1( $fields['address_1'] );
				if ( $billing ) {
					$customer->set_billing_address_1( $fields['address_1'] );
				}
			}
			if ( isset( $fields['postcode'] ) ) {
				$previous['postcode'] = (string) $customer->get_shipping_postcode();
				$postcode = wc_format_postcode( $fields['postcode'], (string) $customer->get_shipping_country() );
				$customer->set_shipping_postcode( $postcode );
				if ( $billing ) {
					$customer->set_billing_postcode( $postcode );
				}
			}
			if ( null !== $chosen ) {
				WC()->session->set( 'chosen_shipping_methods', $chosen );
			}
			$customer->save();
			return $previous;
		}

		/**
		 * Shares classic confirmation's existing 15/minute quota across both transports.
		 *
		 * @since 2.0.2
		 * @return bool
		 */
		protected static function selection_rate_limited(): bool {
			return ( new self() )->is_rate_limited( 'woodev_pickup_sel_rl_', 15 );
		}

		/**
		 * Resolves each plugin/field identity to its server handler.
		 *
		 * @since 2.0.2
		 * @param array<string, mixed> $data Extension data.
		 * @return array<int, array{0: Pickup_Handler, 1: array<string, mixed>}>
		 */
		private static function commands( array $data ): array {
			$commands = [];
			if ( ! is_array( $data['pickup'] ?? null ) || [] === $data['pickup'] ) {
				self::refuse( self::choose_message() );
			}
			foreach ( $data['pickup'] as $plugin_id => $fields ) {
				if ( ! is_array( $fields ) ) {
					self::refuse( self::choose_message() );
				}
				foreach ( $fields as $field_id => $value ) {
					$handler = self::$handlers[ $plugin_id ][ $field_id ] ?? null;
					if ( null === $handler || ! is_array( $value ) ) {
						self::refuse( self::choose_message() );
					}
					$commands[] = [ $handler, $value ];
				}
			}
			return $commands;
		}

		/**
		 * Exposes confirmation and its original close/refresh/corrected-point advice.
		 *
		 * @since 2.0.2
		 * @return array<string, mixed>
		 */
		public static function cart_data(): array {
			$context = static::context();
			$pickup = [];
			foreach ( self::$handlers as $plugin_id => $fields ) {
				foreach ( $fields as $field_id => $handler ) {
					$snapshot = $handler->store_api_cart_confirmation(
						$context['rate_id'],
						$context['address_key'],
						self::rate_available( $context ) && ! self::has_unsupported_packages( $context )
					);
					if ( null !== $snapshot ) {
						unset( $snapshot['address_key'] );
					}
					$pickup[ $plugin_id ][ $field_id ] = $snapshot;
				}
			}
			return [
				'pickup' => $pickup,
				'owner' => self::owner( $context ),
			];
		}

		/**
		 * Names the pickup field that owns the cart's chosen rate, or null for any other rate.
		 *
		 * The block checkout shows its «choose a pickup point» button from this answer alone
		 * (SP-11 C-2b, #1089): ownership is decided here, against the full server rate id, never
		 * inferred in the browser from a label or a method-id list. `locality` is the key the
		 * owner's points are addressed by — the same one a confirmation is remembered under —
		 * and `''` when the customer has not chosen a settlement.
		 *
		 * @since 2.0.2
		 * @param array<string, mixed> $context Server cart context.
		 * @return array{plugin_id: string, field_id: string, rate_id: string, locality: string}|null
		 */
		private static function owner( array $context ): ?array {
			if ( '' === $context['rate_id'] ) {
				return null;
			}
			foreach ( self::$handlers as $plugin_id => $fields ) {
				foreach ( $fields as $field_id => $handler ) {
					if ( ! $handler->owns_store_api_rate( $context['rate_id'] ) ) {
						continue;
					}
					$selected = $handler->get_selected_point_for_method( explode( ':', $context['rate_id'] )[0] );
					return [
						'plugin_id' => (string) $plugin_id,
						'field_id' => (string) $field_id,
						'rate_id' => $context['rate_id'],
						'locality' => (string) ( $selected['locality'] ?? '' ),
					];
				}
			}
			return null;
		}

		/**
		 * Reconciles the live no-order draft without running placement validation.
		 *
		 * @since 2.0.2
		 * @param \WP_REST_Request $request Live checkout request.
		 * @return void
		 */
		public static function update_draft( \WP_REST_Request $request ): void {
			self::reconcile( $request );
		}

		/**
		 * Remembers the echo for final validation and reconciles order-backed PATCH/POST.
		 *
		 * @since 2.0.2
		 * @param \WC_Order        $order Checkout or retry order.
		 * @param \WP_REST_Request $request Checkout request.
		 * @return void
		 */
		public static function update_order( \WC_Order $order, \WP_REST_Request $request ): void {
			// CheckoutOrder fires this hook too; its order must never consult the live cart. The route id is
			// read from the URL only: get_param() prefers the body, so a client could post an `id` to skip the gate.
			$url_params = $request->get_url_params();
			if ( (int) $order->get_id() !== static::draft_order_id() || isset( $url_params['id'] ) ) {
				unset( self::$echoes[ $order->get_id() ] );
				return;
			}
			self::drop_unowned_points( $order, self::reconcile( $request, $order ) );
			self::$echoes[ $order->get_id() ] = (array) ( $request->get_param( 'extensions' )[ self::EXTENSION_NAMESPACE ] ?? [] );
		}

		/**
		 * Lets every field drop the previous attempt's point once the order no longer stands on
		 * the rate and destination it was confirmed for —
		 * {@see Pickup_Handler::drop_unowned_store_api_point()}.
		 *
		 * @since 2.0.2
		 * @param \WC_Order            $order The session's draft or retry order, already synced from the cart.
		 * @param array<string, mixed> $context Server cart context the order was synced from.
		 * @return void
		 */
		private static function drop_unowned_points( \WC_Order $order, array $context ): void {
			foreach ( self::$handlers as $fields ) {
				foreach ( $fields as $handler ) {
					$handler->drop_unowned_store_api_point( $order, $context['rate_id'], $context['address_key'] );
				}
			}
		}

		/**
		 * Applies explicit clears and invalidates changed rate/address confirmations.
		 *
		 * @since 2.0.2
		 * @param \WP_REST_Request $request Checkout request.
		 * @param \WC_Order|null   $order Existing draft, when available.
		 * @return array<string, mixed> The refreshed server cart context it reconciled against.
		 */
		private static function reconcile( \WP_REST_Request $request, ?\WC_Order $order = null ): array {
			$context = static::context( true );
			self::observe_destination( $context );
			foreach ( self::$handlers as $plugin_id => $fields ) {
				foreach ( $fields as $field_id => $handler ) {
					$value = $request->get_param( 'extensions' )[ self::EXTENSION_NAMESPACE ]['pickup'][ $plugin_id ][ $field_id ] ?? [];
					if ( is_array( $value ) && true === ( $value['clear'] ?? false ) ) {
						$handler->clear_store_api_selection( $context['rate_id'], $order );
					}
					$handler->reconcile_store_api_selection( $context['rate_id'], $context['address_key'], $order );
				}
			}
			return $context;
		}

		/**
		 * Final payment gate, including pending/failed retries and express clients.
		 *
		 * @since 2.0.2
		 * @param \WC_Order $order Order about to be paid.
		 * @param \WP_Error $errors Shared validation errors.
		 * @return void
		 */
		public static function validate_order( \WC_Order $order, \WP_Error $errors ): void {
			// The shared payment hook also serves pay-for-order; this adapter owns checkout requests only.
			if ( ! array_key_exists( $order->get_id(), self::$echoes ) || (int) $order->get_id() !== static::draft_order_id() ) {
				return;
			}
			$context = static::context( true );
			if ( self::has_unsupported_packages( $context ) ) {
				$errors->add( 'woodev_pickup_packages', self::packages_message() );
				return;
			}
			/** @var \WC_Order_Item_Shipping[] $lines */
			$lines = array_values( $order->get_items( 'shipping' ) );
			$line = $lines[0] ?? null;
			$rate_id = $context['rate_id'];
			$order_method = null !== $line ? (string) $line->get_method_id() : '';
			$order_instance = null !== $line ? (int) $line->get_instance_id() : 0;
			if ( self::is_unserved_pickup_method( $order_method ) ) {
				$errors->add( 'woodev_pickup_unavailable', self::unserved_message() );
				return;
			}
			$payload = self::$echoes[ $order->get_id() ];
			if ( [] !== $payload && ! is_array( $payload['pickup'] ?? null ) ) {
				self::add_validation_error( $errors, self::choose_message() );
				return;
			}
			foreach ( $payload['pickup'] ?? [] as $plugin_id => $fields ) {
				if ( ! is_array( $fields ) ) {
					self::add_validation_error( $errors, self::choose_message() );
					return;
				}
				foreach ( $fields as $field_id => $confirmation ) {
					$owner = self::$handlers[ $plugin_id ][ $field_id ] ?? null;
					if ( null === $owner || ( null !== $confirmation && ! is_array( $confirmation ) )
						|| ( ! empty( $confirmation ) && ! ( $confirmation['clear'] ?? false ) && ! $owner->owns_store_api_rate( $order_method ) ) ) {
						self::add_validation_error( $errors, self::choose_message() );
						return;
					}
				}
			}

			foreach ( self::$handlers as $plugin_id => $fields ) {
				foreach ( $fields as $field_id => $handler ) {
					foreach ( array_slice( $lines, 1 ) as $extra ) {
						if ( $handler->owns_store_api_rate( (string) $extra->get_method_id() ) ) {
							$errors->add( 'woodev_pickup_packages', self::packages_message() );
							return;
						}
					}
					if ( ! $handler->owns_store_api_rate( $order_method ) ) {
						continue;
					}
					$messages = [];
					$snapshot = $handler->store_api_confirmation( $context['rate_id'], $context['address_key'] );
					$selected = $handler->get_selected_point_for_method( explode( ':', $rate_id )[0] );
					$point_id = $selected['point_id'] ?? '';
					// Completed processing clears the memory before payment. A retry stands on the
					// confirmation the order was placed with — never on the bare id in the order's meta,
					// which survives a street edit and a switch to another instance of the same method
					// (SP-11 C-3, #1090). The echo of a valid retry is that same confirmation.
					if ( '' === $point_id ) {
						$snapshot = $handler->store_api_placed_confirmation( $order, $context['rate_id'], $context['address_key'] );
						$point_id = (string) ( $snapshot['point_id'] ?? '' );
					}
					$echo = self::$echoes[ $order->get_id() ]['pickup'][ $plugin_id ][ $field_id ] ?? [];
					if ( '' === $point_id || $order_method !== explode( ':', $rate_id )[0]
						|| $order_instance !== (int) ( explode( ':', $rate_id )[1] ?? 0 ) || ! self::rate_available( $context )
						|| self::address_key( $order->get_address( 'shipping' ) ) !== $context['address_key'] ) {
						// A cart with no resolved locality cannot have a point: tell the customer the way out (#1110).
						$messages[] = ( '' === $point_id && ! $handler->has_store_api_locality( $rate_id ) ) ? self::locality_message() : self::choose_message();
					} elseif ( ! empty( $echo ) && ( null === $snapshot || ! self::echo_matches( $echo, $snapshot ) ) ) {
						$messages[] = self::choose_message();
					} else {
						$messages = $handler->store_api_point_errors( $point_id, $rate_id, (string) $order->get_payment_method() );
					}
					foreach ( $messages as $message ) {
						self::add_validation_error( $errors, $message );
					}
				}
			}
		}

		/**
		 * Adds a pickup refusal only when the shared Store API errors do not already contain it.
		 *
		 * @since 2.0.2
		 * @param \WP_Error $errors Shared validation errors.
		 * @param string    $message Refusal message.
		 * @return void
		 */
		private static function add_validation_error( \WP_Error $errors, string $message ): void {
			if ( ! in_array( $message, $errors->get_error_messages(), true ) ) {
				$errors->add( 'woodev_pickup_validation', $message );
			}
		}

		/**
		 * Compares only confirmation identity; client advice/full point data has no authority.
		 *
		 * @since 2.0.2
		 * @param array<string, mixed> $confirmation Client confirmation.
		 * @param array<string, mixed> $snapshot Server confirmation.
		 * @return bool
		 */
		private static function echo_matches( array $confirmation, array $snapshot ): bool {
			foreach ( [ 'plugin_id', 'field_id', 'point_id', 'locality', 'rate_id' ] as $key ) {
				if ( ( $confirmation[ $key ] ?? null ) !== ( $snapshot[ $key ] ?? null ) ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * Checks the chosen full rate still exists in the recalculated primary package.
		 *
		 * @since 2.0.2
		 * @param array<string, mixed> $context Server cart context.
		 * @return bool
		 */
		private static function rate_available( array $context ): bool {
			return isset( $context['packages'][0]['rates'][ $context['rate_id'] ] );
		}

		/**
		 * Detects another package requiring any framework handler's own point.
		 *
		 * @since 2.0.2
		 * @param array<string, mixed> $context Server cart context.
		 * @return bool
		 */
		private static function has_unsupported_packages( array $context ): bool {
			foreach ( $context['chosen'] as $package_id => $rate_id ) {
				if ( 0 === (int) $package_id ) {
					continue;
				}
				foreach ( self::$handlers as $fields ) {
					foreach ( $fields as $handler ) {
						if ( $handler->owns_store_api_rate( (string) $rate_id ) ) {
							return true;
						}
					}
				}
			}
			return false;
		}

		/**
		 * Rejects unsupported package scope before spending carrier quota.
		 *
		 * @since 2.0.2
		 * @param array<string, mixed> $context Server cart context.
		 * @return void
		 */
		private static function require_supported_packages( array $context ): void {
			if ( self::has_unsupported_packages( $context ) ) {
				self::refuse( self::packages_message() );
			}
		}

		/**
		 * Sanitizes bounded identity inputs.
		 *
		 * @since 2.0.2
		 * @param array<string, mixed> $data Request command.
		 * @param string               $key Identity key.
		 * @param string               $default Server fallback.
		 * @return string
		 */
		private static function clean_id( array $data, string $key, string $default = '' ): string {
			$value = $data[ $key ] ?? $default;
			return is_scalar( $value ) ? substr( trim( (string) wc_clean( (string) $value ) ), 0, 128 ) : '';
		}

		/**
		 * Actionable express-payment and missing-selection message.
		 *
		 * @since 2.0.2
		 * @return string
		 */
		private static function choose_message(): string {
			return __( 'You have not chosen a pickup point.', 'woodev-plugin-framework' );
		}

		/**
		 * Actionable refusal for a cart with no resolved locality (issue #1110): a hand-typed city the
		 * customer never picked from the chooser's suggestions confirms no point. Also the block
		 * checkout's hint under the pickup button ({@see \Woodev\Framework\Shipping\Checkout\Blocks\Pickup_Blocks::i18n_strings()}).
		 *
		 * @since 2.0.2
		 * @return string
		 */
		private static function locality_message(): string {
			return __( 'Choose your locality from the suggestions to see pickup points.', 'woodev-plugin-framework' );
		}

		/**
		 * Explains the supported one-delivery-chain scope.
		 *
		 * @since 2.0.2
		 * @return string
		 */
		private static function packages_message(): string {
			return __( 'Pickup points for multiple shipping packages are not supported. Please choose another shipping method.', 'woodev-plugin-framework' );
		}

		/**
		 * Customer-facing refusal for a pickup method no handler can serve (issue #1100).
		 *
		 * @since 2.0.2
		 * @return string
		 */
		private static function unserved_message(): string {
			return __( 'This pickup method is not available at checkout right now. Please choose another delivery method or contact the store.', 'woodev-plugin-framework' );
		}

		/**
		 * Whether the order's shipping method is a framework pickup method that no registered handler
		 * owns while at least one handler was built without a {@see Selection_Scope} (issue #1100).
		 *
		 * That combination is the silent dead end: the rate still requires a point, the handler that
		 * should offer it cannot own any rate, so the block checkout has no button. Foreign pickup
		 * methods, and an unowned method when every handler is scoped, are not this adapter's concern.
		 *
		 * @since 2.0.2
		 * @param string $method_id Bare shipping-method id of the order's shipping line.
		 * @return bool
		 */
		private static function is_unserved_pickup_method( string $method_id ): bool {
			if ( '' === $method_id || ! in_array( $method_id, static::pickup_method_ids(), true ) ) {
				return false;
			}
			$unscoped = false;
			foreach ( self::$handlers as $fields ) {
				foreach ( $fields as $handler ) {
					if ( $handler->owns_store_api_rate( $method_id ) ) {
						return false;
					}
					$unscoped = $unscoped || ! $handler->has_selection_scope();
				}
			}
			return $unscoped;
		}

		/**
		 * The framework's pickup shipping method ids; a seam so tests need no WooCommerce shipping.
		 *
		 * @since 2.0.2
		 * @return string[]
		 */
		protected static function pickup_method_ids(): array {
			return \Woodev\Framework\Shipping\Checkout\Checkout_Config::pickup_method_ids();
		}

		/**
		 * Throws the Store API's customer-safe 400 error.
		 *
		 * @since 2.0.2
		 * @param string $message Customer-facing error.
		 * @param int    $status HTTP error status.
		 * @return void
		 * @throws \Exception Store API route error.
		 */
		private static function refuse( string $message, int $status = 400 ): void {
			$exception = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
			throw new $exception( 'woodev_pickup_validation', $message, $status );
		}
	}

endif;
