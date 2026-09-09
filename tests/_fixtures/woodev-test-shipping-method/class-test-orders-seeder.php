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
		 * Bumped whenever {@see self::demo_orders()} changes, so an existing rig
		 * re-seeds instead of silently keeping the old, smaller set. The option
		 * stores this value rather than a bare '1'.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const SEED_VERSION = '3';

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
		 * Must match the provider's own `carrier_order_id_meta_key` (SP-10 #841) — the
		 * presence of this meta is what «Заказы доставки» reads as "this order has been
		 * exported to the carrier".
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const CARRIER_ORDER_ID_META_KEY = '_woodev_test_shipping_carrier_order_id';

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
			return $trigger_enabled && self::SEED_VERSION !== $seeded_option_value;
		}

		/**
		 * The demo orders' raw definitions.
		 *
		 * The first three are the ones that carry MEANING and are kept verbatim: a
		 * mapped in-transit status, a mapped ready-for-pickup status with no tracking
		 * number, and one carrying `LOST_IN_TRANSIT` — the provider's own deliberately
		 * unmapped raw value (see `init_test_shipping_orders_page()`'s docblock) — so
		 * the unmapped-status branch is visible on THIS carrier's rows, not only in a
		 * test.
		 *
		 * The rest are GENERATED rather than typed out, because their only job is
		 * volume: the operator needs more rows than one page holds (the REST default
		 * is 20) to exercise the table's pagination and its date filter at all. Typing
		 * thirty near-identical literals invites a copy-paste defect in the one that
		 * matters — see the framework's own rule about deriving tables instead of
		 * hand-writing them.
		 *
		 * ⚠ Dates: most land inside the CURRENT year, because the page's default
		 * period is `period=year` (D11) and anything older is hidden until the
		 * merchant widens the range. A few are deliberately pushed into LAST year, so
		 * that the date filter has something to reveal and hide.
		 *
		 * Pure — no WordPress calls beyond date arithmetic — so the seeded shape stays
		 * inspectable without a database.
		 *
		 * ⚠ `carrier_order_id` (SP-10 #841) is written to only PART of the set — never
		 * all, never none — so the «Заказы доставки» page's "new orders" filter
		 * (`is_exported`) has both sides to find on the rig, the same reason `tracking`
		 * is already split above.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array{status:string, raw_status:string, tracking:?string, carrier_order_id:?string, days_ago:int}>
		 */
		public static function demo_orders(): array {
			$orders = [
				[
					'status'            => 'processing',
					'raw_status'        => 'ON_THE_WAY',
					'tracking'          => 'TESTCARRIER-000123',
					'carrier_order_id'  => 'TESTCARRIER-EXPORT-000123',
					'days_ago'          => 0,
				],
				[
					'status'            => 'processing',
					'raw_status'        => 'ARRIVED_PVZ',
					'tracking'          => null,
					'carrier_order_id'  => 'TESTCARRIER-EXPORT-000124',
					'days_ago'          => 0,
				],
				[
					'status'            => 'processing',
					'raw_status'        => 'LOST_IN_TRANSIT',
					'tracking'          => 'TESTCARRIER-000125',
					// Deliberately NOT exported — «lost» does not imply the framework's
					// own export marker was ever written; the two are independent facts.
					'carrier_order_id'  => null,
					'days_ago'          => 0,
				],
			];

			/*
			 * Every raw status this provider declares, so the canonical column has
			 * something to map on most rows and something to fail to map on a few.
			 *
			 * ⚠ These are the provider's OWN keys and must stay in step with the
			 * `status_map` / `status_labels` it registers in
			 * `woodev-test-shipping-method.php`. A raw value that exists in neither
			 * renders as a bare key — `RawStatusVocabularyTest` pins that, because the
			 * first draft of this list was typed from memory and invented three
			 * statuses (`DELIVERED`, `RETURNING`, `CANCELED`) that this carrier has
			 * never spoken.
			 */
			$raw_statuses = [
				'CREATED',
				'PICKED_UP',
				'ON_THE_WAY',
				'ARRIVED_PVZ',
				'HANDED_TO_CLIENT',
				'RETURN_STARTED',
				'RETURNED_TO_SENDER',
				'CANCELLED_BY_CLIENT',
				'LOST_IN_TRANSIT',
			];
			$wc_statuses  = [ 'processing', 'on-hold', 'completed', 'pending' ];

			// Spread across the year: mostly recent, thinning out backwards, with the
			// last few beyond the year boundary on purpose.
			$spread = [ 1, 2, 3, 5, 8, 11, 15, 19, 24, 30, 37, 45, 54, 64, 75, 87, 100, 114, 129, 145, 162, 180, 199, 219, 240, 262, 285, 309, 334, 400, 430 ];

			foreach ( $spread as $index => $days_ago ) {
				$raw = $raw_statuses[ $index % count( $raw_statuses ) ];

				$orders[] = [
					'status'            => $wc_statuses[ $index % count( $wc_statuses ) ],
					'raw_status'        => $raw,
					// Every third row carries no tracking number, so the
					// tracking-presence filter has both sides to find.
					'tracking'          => 0 === $index % 3 ? null : sprintf( 'TESTCARRIER-%06d', 200 + $index ),
					// Every other row is "new" (never exported), on a DIFFERENT modulo
					// than `tracking` above, so `is_exported` and `has_tracking` overlap
					// in both directions rather than perfectly tracking one another.
					'carrier_order_id'  => 0 === $index % 2 ? sprintf( 'TESTCARRIER-EXPORT-%06d', 200 + $index ) : null,
					'days_ago'          => $days_ago,
				];
			}

			return $orders;
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

			update_option( self::SEEDED_OPTION, self::SEED_VERSION );
		}

		/**
		 * Creates one demo order from a {@see self::demo_orders()} definition.
		 *
		 * @since 2.0.2
		 *
		 * @param array{status:string, raw_status:string, tracking:?string, carrier_order_id:?string} $definition
		 *
		 * @return void
		 */
		private static function seed_one( array $definition ): void {
			$order = wc_create_order();
			$order->set_status( $definition['status'] );

			// Backdate, so the list has a real spread to sort and filter by. WooCommerce
			// stamps `date_created` at creation, so it has to be set explicitly here.
			$days_ago = isset( $definition['days_ago'] ) ? (int) $definition['days_ago'] : 0;

			if ( 0 < $days_ago ) {
				$order->set_date_created( time() - ( $days_ago * DAY_IN_SECONDS ) );
			}
			$order->update_meta_data( self::MARKER_META_KEY, '1' );
			$order->update_meta_data( self::STATUS_META_KEY, $definition['raw_status'] );

			if ( null !== $definition['tracking'] ) {
				$order->update_meta_data( self::TRACKING_META_KEY, $definition['tracking'] );
			}

			if ( isset( $definition['carrier_order_id'] ) && null !== $definition['carrier_order_id'] ) {
				$order->update_meta_data( self::CARRIER_ORDER_ID_META_KEY, $definition['carrier_order_id'] );
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
