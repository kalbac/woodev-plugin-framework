<?php
/**
 * Woodev_Test_Orders_Seeder — SP-10 #830's demo-orders bridge.
 *
 * `grep -rln "Orders_Provider" tests/_fixtures/` used to find exactly one file
 * (`woodev-realistic-shipping-plugin`), so the rig's «Заказы доставки» page showed
 * the degenerate single-carrier case: no carrier `FilterPicker` (`app.tsx` gates it
 * on `providers.length > 1`), the D6 counter with nothing to sum, and M2's
 * `relation => OR` aggregate never actually executing in a browser. Registering
 * THIS fixture's own `Orders_Provider` (see
 * `Woodev_Test_Shipping_Method_Plugin::init_test_shipping_orders_page()`) fixes the
 * REGISTRATION half; this class fixes the DATA half — without at least one order
 * carrying this fixture's marker, the second carrier's tab and the aggregate's
 * second summand would both be empty, which looks identical to "not implemented"
 * to an operator accepting the page with his eyes.
 *
 * EXPLICIT TRIGGER, NOT A PER-PAGE-LOAD FIXTURE (operator's own instruction on
 * #830): {@see self::maybe_seed()} only does anything when the rig wp-config
 * constant `WOODEV_TEST_SEED_ORDERS_DEMO` is truthy, hooked on `admin_init` (not
 * called directly during plugin construction, where WooCommerce's order factory
 * functions are not guaranteed to exist yet). It is also idempotent — guarded by
 * the `woodev_test_shipping_demo_orders_seeded` option — so leaving the constant
 * on does not create a fresh batch of orders on every admin request.
 *
 * Its decision (`{@see self::should_seed()}`) is split into a pure, WordPress-call
 * -free method, same "own file, pure/impure split, direct testability" pattern
 * {@see Woodev_Test_Credential_Seeder} already established in this fixture.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_Orders_Seeder' ) ) {

	/**
	 * Class Woodev_Test_Orders_Seeder
	 */
	class Woodev_Test_Orders_Seeder {

		/**
		 * Option guarding one-shot seeding — never re-seeded once set.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const SEEDED_OPTION = 'woodev_test_shipping_demo_orders_seeded';

		/**
		 * Must match the marker key {@see \Woodev_Test_Shipping_Method_Plugin::init_test_shipping_orders_page()}
		 * registers the `Orders_Provider` under — a seeded order the provider does
		 * not recognize would never appear on the rig at all.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const MARKER_META_KEY = '_woodev_test_shipping_marker';

		/**
		 * Must match the provider's own `status_meta_key`.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const STATUS_META_KEY = '_woodev_test_shipping_status';

		/**
		 * Must match the provider's own `tracking_meta_key`.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const TRACKING_META_KEY = '_woodev_test_shipping_tracking_number';

		/**
		 * The literal shipping method id this fixture ships — same literal-not-
		 * constant reasoning as {@see \Woodev_Test_Shipping_Method_Plugin::init_test_shipping_orders_page()}.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const METHOD_ID = 'woodev_test_shipping';

		/**
		 * Decides whether seeding should run.
		 *
		 * Pure — no WordPress calls — so this rule is unit-testable on its own, same
		 * shape as {@see Woodev_Test_Credential_Seeder::should_seed()}. The rule:
		 * seed only when the rig trigger is on AND seeding has never run before on
		 * this site.
		 *
		 * @since 2.0.2
		 *
		 * @param bool   $trigger_enabled      the rig's WOODEV_TEST_SEED_ORDERS_DEMO value.
		 * @param string $seeded_option_value  the seeded-option's current stored value ('' when unset).
		 *
		 * @return bool
		 */
		public static function should_seed( bool $trigger_enabled, string $seeded_option_value ): bool {
			return $trigger_enabled && '' === $seeded_option_value;
		}

		/**
		 * The demo orders' raw definitions — one mapped, in-transit-ish status; one
		 * mapped, ready-for-pickup status with no tracking number; and one carrying
		 * `LOST_IN_TRANSIT`, the provider's own deliberately unmapped raw value (see
		 * `init_test_shipping_orders_page()`'s docblock) — so the unmapped-status
		 * branch is visible on THIS carrier's tab too, not only in a test.
		 *
		 * Pure — no WordPress calls — so the seeded shape itself is unit-testable
		 * without a database.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array{status:string, raw_status:string, tracking:?string}>
		 */
		public static function demo_orders(): array {
			return [
				[
					'status'     => 'processing',
					'raw_status' => 'ON_THE_WAY',
					'tracking'   => 'TESTCARRIER-000123',
				],
				[
					'status'     => 'processing',
					'raw_status' => 'ARRIVED_PVZ',
					'tracking'   => null,
				],
				[
					'status'     => 'processing',
					'raw_status' => 'LOST_IN_TRANSIT',
					'tracking'   => 'TESTCARRIER-000125',
				],
			];
		}

		/**
		 * Seeds {@see self::demo_orders()} as real `WC_Order`s, applying
		 * {@see self::should_seed()}'s rule, then marks seeding done.
		 *
		 * Hooked on `admin_init` — see this file's own class docblock for why not
		 * during plugin construction.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function maybe_seed(): void {
			$trigger_enabled = defined( 'WOODEV_TEST_SEED_ORDERS_DEMO' ) && WOODEV_TEST_SEED_ORDERS_DEMO;

			if ( ! self::should_seed( $trigger_enabled, (string) get_option( self::SEEDED_OPTION, '' ) ) ) {
				return;
			}

			if ( ! function_exists( 'wc_create_order' ) || ! class_exists( '\WC_Order_Item_Shipping' ) ) {
				return;
			}

			foreach ( self::demo_orders() as $definition ) {
				self::seed_one( $definition );
			}

			update_option( self::SEEDED_OPTION, '1' );
		}

		/**
		 * Creates one demo order from a {@see self::demo_orders()} definition.
		 *
		 * @since 2.0.2
		 *
		 * @param array{status:string, raw_status:string, tracking:?string} $definition
		 *
		 * @return void
		 */
		private static function seed_one( array $definition ): void {
			$order = wc_create_order();
			$order->set_status( $definition['status'] );
			$order->update_meta_data( self::MARKER_META_KEY, '1' );
			$order->update_meta_data( self::STATUS_META_KEY, $definition['raw_status'] );

			if ( null !== $definition['tracking'] ) {
				$order->update_meta_data( self::TRACKING_META_KEY, $definition['tracking'] );
			}

			$shipping_item = new \WC_Order_Item_Shipping();
			$shipping_item->set_method_title( 'Тестовая доставка' );
			$shipping_item->set_method_id( self::METHOD_ID );
			$shipping_item->set_total( '0' );
			$order->add_item( $shipping_item );

			$order->save();
		}
	}
}
