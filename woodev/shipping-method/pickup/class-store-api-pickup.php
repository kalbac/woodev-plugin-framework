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
			self::require_supported_packages( $context );
			$prepared = [];
			foreach ( self::commands( $data ) as $command ) {
				[ $handler, $value ] = $command;
				// After a switch to courier, clearing the old picker is a successful no-op.
				if ( true === ( $value['clear'] ?? false ) ) {
					$prepared[] = [ $handler, null ];
					continue;
				}
				if ( ! $handler->owns_store_api_rate( $context['rate_id'] ) || ! self::rate_available( $context ) ) {
					self::refuse( self::choose_message() );
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
				return [ $moved, $fields ];
			}
			static::write_destination( $previous, $context['chosen'] );
			return [ static::context( true ), [] ];
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
					$snapshot = $handler->store_api_confirmation( $context['rate_id'], $context['address_key'] );
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
			self::reconcile( $request, $order );
			self::$echoes[ $order->get_id() ] = (array) ( $request->get_param( 'extensions' )[ self::EXTENSION_NAMESPACE ] ?? [] );
		}

		/**
		 * Applies explicit clears and invalidates changed rate/address confirmations.
		 *
		 * @since 2.0.2
		 * @param \WP_REST_Request $request Checkout request.
		 * @param \WC_Order|null   $order Existing draft, when available.
		 * @return void
		 */
		private static function reconcile( \WP_REST_Request $request, ?\WC_Order $order = null ): void {
			$context = static::context( true );
			foreach ( self::$handlers as $plugin_id => $fields ) {
				foreach ( $fields as $field_id => $handler ) {
					$value = $request->get_param( 'extensions' )[ self::EXTENSION_NAMESPACE ]['pickup'][ $plugin_id ][ $field_id ] ?? [];
					if ( is_array( $value ) && true === ( $value['clear'] ?? false ) ) {
						$handler->clear_store_api_selection( $context['rate_id'], $order );
					}
					$handler->reconcile_store_api_selection( $context['rate_id'], $context['address_key'], $order );
				}
			}
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
			$payload = self::$echoes[ $order->get_id() ];
			if ( [] !== $payload && ! is_array( $payload['pickup'] ?? null ) ) {
				$errors->add( 'woodev_pickup_validation', self::choose_message() );
				return;
			}
			foreach ( $payload['pickup'] ?? [] as $plugin_id => $fields ) {
				if ( ! is_array( $fields ) ) {
					$errors->add( 'woodev_pickup_validation', self::choose_message() );
					return;
				}
				foreach ( $fields as $field_id => $confirmation ) {
					$owner = self::$handlers[ $plugin_id ][ $field_id ] ?? null;
					if ( null === $owner || ( null !== $confirmation && ! is_array( $confirmation ) )
						|| ( ! empty( $confirmation ) && ! ( $confirmation['clear'] ?? false ) && ! $owner->owns_store_api_rate( $order_method ) ) ) {
						$errors->add( 'woodev_pickup_validation', self::choose_message() );
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
					// Completed-processing clears memory before payment: retries use installed field meta.
					if ( '' === $point_id ) {
						$point_id = (string) \Woodev_Order_Compatibility::get_order_meta( $order, $field_id );
					}
					// A valid retry may echo the original confirmation after the existing writer cleared memory.
					if ( null === $snapshot && '' !== $point_id && '' === ( $selected['point_id'] ?? '' ) ) {
						$snapshot = [
							'plugin_id' => $plugin_id,
							'field_id' => $field_id,
							'point_id' => $point_id,
							'locality' => $selected['locality'] ?? '',
							'rate_id' => $rate_id,
						];
					}
					$echo = self::$echoes[ $order->get_id() ]['pickup'][ $plugin_id ][ $field_id ] ?? [];
					if ( '' === $point_id || $order_method !== explode( ':', $rate_id )[0]
						|| $order_instance !== (int) ( explode( ':', $rate_id )[1] ?? 0 ) || ! self::rate_available( $context )
						|| self::address_key( $order->get_address( 'shipping' ) ) !== $context['address_key'] ) {
						$messages[] = self::choose_message();
					} elseif ( ! empty( $echo ) && ( null === $snapshot || ! self::echo_matches( $echo, $snapshot ) ) ) {
						$messages[] = self::choose_message();
					} else {
						$messages = $handler->store_api_point_errors( $point_id, $rate_id, (string) $order->get_payment_method() );
					}
					foreach ( $messages as $message ) {
						$errors->add( 'woodev_pickup_validation', $message );
					}
				}
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
			return __( 'Please choose a pickup point on the checkout page before paying.', 'woodev-plugin-framework' );
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
