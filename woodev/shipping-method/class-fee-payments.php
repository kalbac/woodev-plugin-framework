<?php
/**
 * Fee only for chosen payment methods — the shared plumbing behind
 * {@see Shipping_Method::FEATURE_FEE_PAYMENTS} (#1144).
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Fee_Payments' ) ) :

	/**
	 * Gets the customer's chosen payment method to where a shipping rate is calculated and cached.
	 *
	 * Three things have to be true for «the fee applies only to these payment methods» to hold, and
	 * this class owns all three:
	 *
	 * 1. **The method reaches the package.** WooCommerce keeps a package's rates in the session under
	 *    the package's hash and serves them while the hash is unchanged
	 *    (`WC_Shipping::calculate_shipping_for_package()`), so a payment method that is not part of the
	 *    package would leave the OLD rates in place after the customer switched. It is added on
	 *    `woocommerce_cart_shipping_packages`, which both the classic cart and the block checkout's
	 *    Store API go through.
	 * 2. **A change of method recalculates.** The classic order form does not do it on its own and the
	 *    block checkout only tells the server at the order POST; each gets a trigger — a small script
	 *    (`fee-payments-classic.js`) and the `woodev-shipping-fee-payments` Store API update callback.
	 * 3. **Only where somebody uses it.** A shop where no method restricts its fee keeps its package
	 *    hashes, its cache hits and its page weight exactly as they were: all three pieces above stay
	 *    off until a saved instance carries a non-empty list.
	 *
	 * «Somebody uses it» is a registry option, kept up to date from the option hooks, not a scan of
	 * the registered methods: on a cold request WooCommerce collects the cart's packages BEFORE
	 * `woocommerce_shipping_methods` registers the carriers' methods (gotcha
	 * `cart-and-checkout-share-one-shipping-rate-cache-entry`). Because it follows the option itself,
	 * a carrier that writes the list from its own migration is counted the same as a merchant's save.
	 *
	 * Static and fleet-wide: every shipping plugin calls {@see self::register()}, the first call wires
	 * the hooks, and N plugins still run each callback once.
	 *
	 * @since 2.0.2
	 */
	final class Fee_Payments {

		/** The instance setting that holds the gateway ids — the v1 CDEK plugin's key, so a migration carries it 1:1. */
		public const OPTION_KEY = 'fee_payments';

		/** The package key the chosen payment method travels under (the v1 CDEK plugin's name for it). */
		public const PACKAGE_KEY = 'chosen_payment_method';

		/** The `cart/extensions` namespace of the block checkout's «payment method changed» command. */
		public const EXTENSION_NAMESPACE = 'woodev-shipping-fee-payments';

		/** WooCommerce Blocks integration name; the data key WooCommerce publishes is `{name}_data`. */
		public const INTEGRATION_NAME = 'woodev-shipping-fee-payments';

		/** The registry: names of instance-settings options whose list is not empty. */
		public const REGISTRY_OPTION = 'woodev_shipping_fee_payment_instances';

		/** @var bool whether this request already wired the hooks */
		private static bool $registered = false;

		/** @var object|null a stand-in for `WC()`. Tests only: `WC` is never defined in the unit-test process. */
		private static ?object $runtime = null;

		/**
		 * Wires the hooks once per request; later plugins are no-ops.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function register(): void {

			if ( self::$registered ) {
				return;
			}

			self::$registered = true;

			add_action( 'added_option', [ self::class, 'on_option_added' ], 10, 2 );
			add_action( 'updated_option', [ self::class, 'on_option_updated' ], 10, 3 );
			add_action( 'deleted_option', [ self::class, 'on_option_deleted' ] );

			add_filter( 'woocommerce_cart_shipping_packages', [ self::class, 'add_chosen_payment_to_packages' ] );
			add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_classic_script' ] );
			add_action( 'woocommerce_blocks_loaded', [ self::class, 'register_store_api_callback' ] );
			add_action( 'woocommerce_blocks_checkout_block_registration', [ self::class, 'register_blocks_integration' ] );

			if ( did_action( 'woocommerce_blocks_loaded' ) ) {
				self::register_store_api_callback();
			}
		}

		/**
		 * Forgets the per-request wiring. Tests only.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$registered = false;
			self::$runtime    = null;
		}

		/**
		 * Answers `WC()` from `$runtime` instead. Tests only.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param object|null $runtime the stand-in, or `null` to read WooCommerce again.
		 * @return void
		 */
		public static function use_runtime_for_tests( ?object $runtime ): void {
			self::$runtime = $runtime;
		}

		/**
		 * The gateway ids in a stored list, whatever shape it was saved in.
		 *
		 * WooCommerce saves a multiselect as an array, an empty one as `''` or `[]`, and a carrier's
		 * migration may hand over anything.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value a stored `fee_payments` value.
		 * @return string[]
		 */
		public static function normalize( $value ): array {

			$ids  = [];
			$list = is_array( $value ) ? $value : [];

			foreach ( $list as $id ) {
				if ( is_string( $id ) && '' !== trim( $id ) ) {
					$ids[] = trim( $id );
				}
			}

			return array_values( array_unique( $ids ) );
		}

		/**
		 * Whether any saved shipping method instance limits its fee to payment methods.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public static function is_used(): bool {
			return [] !== self::registry();
		}

		/**
		 * The options of the `fee_payments` control: the store's enabled payment gateways, plus any
		 * id already chosen that is no longer among them.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $selected the ids currently saved.
		 * @return array<string, string> gateway id => label.
		 */
		public static function gateway_options( array $selected = [] ): array {

			$options  = [];
			$woo      = self::wc();
			$registry = null !== $woo && is_callable( [ $woo, 'payment_gateways' ] ) ? $woo->payment_gateways() : null;
			$gateways = is_object( $registry ) && is_callable( [ $registry, 'payment_gateways' ] ) ? $registry->payment_gateways() : [];

			foreach ( (array) $gateways as $id => $gateway ) {

				if ( ! is_object( $gateway ) || 'yes' !== ( $gateway->enabled ?? '' ) ) {
					continue;
				}

				$title    = trim( wp_strip_all_tags( (string) $gateway->get_title() ) );
				$method   = trim( wp_strip_all_tags( (string) $gateway->get_method_title() ) );
				$label    = '' === $title ? $method : $title;
				$options[ (string) $id ] = '' !== $method && 0 !== strcasecmp( $label, $method ) ? sprintf( '%s (%s)', $label, $method ) : $label;
			}

			foreach ( $selected as $id ) {
				if ( ! isset( $options[ $id ] ) ) {
					$options[ $id ] = $id;
				}
			}

			return $options;
		}

		/**
		 * Adds the chosen payment method to every shipping package, so WooCommerce's rate cache is keyed
		 * by it. Nothing is added while no instance uses the option.
		 *
		 * @internal Hooked on `woocommerce_cart_shipping_packages`; not for direct calls.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $packages the cart's shipping packages.
		 * @return mixed
		 */
		public static function add_chosen_payment_to_packages( $packages ) {

			if ( ! is_array( $packages ) || [] === $packages || ! self::is_used() ) {
				return $packages;
			}

			$chosen = self::session_payment_method();

			if ( '' === $chosen ) {
				return $packages;
			}

			foreach ( $packages as $key => $package ) {
				if ( is_array( $package ) ) {
					$packages[ $key ][ self::PACKAGE_KEY ] = $chosen;
				}
			}

			return $packages;
		}

		/**
		 * Classic order form: a change of payment method recalculates the shipping rates.
		 *
		 * WooCommerce's `checkout.js` only fires `payment_method_selected` on a change, and
		 * `update_totals_on_change` is not on the gateway radios — so, left alone, the form keeps the
		 * rates it had until the customer touches something else, and the order POST then prices the
		 * order differently from what was shown.
		 *
		 * @internal Hooked on `wp_enqueue_scripts`.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function enqueue_classic_script(): void {

			if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! self::is_used() ) {
				return;
			}

			$path = __DIR__ . '/assets/js/frontend/fee-payments-classic.js';
			$ver  = file_exists( $path ) ? (string) filemtime( $path ) : (string) \Woodev_Plugin::VERSION;

			wp_enqueue_script(
				'woodev-fee-payments-classic',
				plugins_url( 'assets/js/frontend/fee-payments-classic.js', __FILE__ ),
				[ 'jquery', 'wc-checkout' ],
				$ver,
				true
			);
		}

		/**
		 * Block checkout: the Store API command that tells the server which payment method is chosen.
		 *
		 * Switching the gateway in the blocks sends nothing that recalculates shipping (the checkout
		 * routes write the method to the session, never the gateway change on its own), so the server's
		 * session can hold whatever an earlier request left there. `extensionCartUpdate()` runs this
		 * callback and WooCommerce then recalculates the cart — shipping included — before it replies.
		 *
		 * @internal Hooked on `woocommerce_blocks_loaded`.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function register_store_api_callback(): void {

			if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
				return;
			}

			woocommerce_store_api_register_update_callback(
				[
					'namespace' => self::EXTENSION_NAMESPACE,
					'callback'  => [ self::class, 'update_cart' ],
				]
			);
		}

		/**
		 * Saves the payment method the block checkout reports, for the recalculation that follows.
		 *
		 * @internal Called by WooCommerce's `cart/extensions` route.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $data the command: `[ 'payment_method' => '<gateway id>' ]`.
		 * @return void
		 */
		public static function update_cart( $data ): void {

			$method = is_array( $data ) && isset( $data['payment_method'] ) && is_string( $data['payment_method'] ) ? trim( $data['payment_method'] ) : '';

			$session = self::session();

			if ( '' === $method || strlen( $method ) > 100 || null === $session ) {
				return;
			}

			$session->set( self::PACKAGE_KEY, wc_clean( $method ) );
		}

		/**
		 * Adds the Checkout block integration that loads the trigger script.
		 *
		 * @internal Hooked on `woocommerce_blocks_checkout_block_registration`.
		 *
		 * @since 2.0.2
		 *
		 * @param object $registry the WooCommerce `IntegrationRegistry`.
		 * @return void
		 */
		public static function register_blocks_integration( object $registry ): void {

			if ( ! class_exists( __NAMESPACE__ . '\Checkout\Blocks\Fee_Payments_Blocks_Integration' ) || ! is_callable( [ $registry, 'register' ] ) ) {
				return;
			}

			$registry->register( new Checkout\Blocks\Fee_Payments_Blocks_Integration() );
		}

		/**
		 * `added_option` — a new instance-settings option may carry a list.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $option the option name.
		 * @param mixed $value  the option value.
		 * @return void
		 */
		public static function on_option_added( $option, $value ): void {
			self::track( $option, $value );
		}

		/**
		 * `updated_option` — an instance-settings option may have gained or lost its list.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $option the option name.
		 * @param mixed $old    the previous value.
		 * @param mixed $value  the new value.
		 * @return void
		 */
		public static function on_option_updated( $option, $old, $value ): void {
			self::track( $option, $value );
		}

		/**
		 * `deleted_option` — WooCommerce deletes an instance's option when its method leaves the zone
		 * (`WC_Shipping_Zone_Data_Store::delete_method()`).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $option the option name.
		 * @return void
		 */
		public static function on_option_deleted( $option ): void {
			self::track( $option, null );
		}

		/**
		 * Keeps the registry in step with one instance-settings option.
		 *
		 * @param mixed $option the option name.
		 * @param mixed $value  the value it now holds, `null` once deleted.
		 * @return void
		 */
		private static function track( $option, $value ): void {

			// every option write on the site passes here: the cheapest test goes first
			if ( ! is_string( $option ) || 0 !== strncmp( $option, 'woocommerce_', 12 ) || 1 !== preg_match( '/^woocommerce_.+_\d+_settings$/', $option ) ) {
				return;
			}

			$registry = self::registry();
			$uses     = is_array( $value ) && [] !== self::normalize( $value[ self::OPTION_KEY ] ?? [] );

			if ( $uses === isset( $registry[ $option ] ) ) {
				return;
			}

			if ( $uses ) {
				$registry[ $option ] = true;
			} else {
				unset( $registry[ $option ] );
			}

			if ( [] === $registry ) {
				delete_option( self::REGISTRY_OPTION );
			} else {
				update_option( self::REGISTRY_OPTION, $registry );
			}
		}

		/**
		 * @return array<string, true>
		 */
		private static function registry(): array {

			$registry = get_option( self::REGISTRY_OPTION, [] );

			return is_array( $registry ) ? $registry : [];
		}

		/**
		 * The payment method WooCommerce's session holds, or `''` without a session.
		 *
		 * @return string
		 */
		private static function session_payment_method(): string {

			$session = self::session();
			$chosen  = null === $session ? null : $session->get( self::PACKAGE_KEY );

			return is_string( $chosen ) ? $chosen : '';
		}

		/**
		 * @return object|null the WooCommerce session, `null` in the admin or a REST call without one.
		 */
		private static function session(): ?object {

			$woo     = self::wc();
			$session = null !== $woo && isset( $woo->session ) ? $woo->session : null;

			return is_object( $session ) ? $session : null;
		}

		/**
		 * @return object|null the `WooCommerce` instance, `null` when WooCommerce is not loaded.
		 */
		private static function wc(): ?object {

			if ( null !== self::$runtime ) {
				return self::$runtime;
			}

			$woo = function_exists( 'WC' ) ? WC() : null;

			return is_object( $woo ) ? $woo : null;
		}
	}

endif;
