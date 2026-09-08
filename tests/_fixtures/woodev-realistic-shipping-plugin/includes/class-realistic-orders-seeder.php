<?php
/**
 * Rig-only demo-order seeder for the realistic shipping fixture.
 *
 * Mirrors `Woodev_Test_Orders_Seeder` in the other shipping fixture, deliberately:
 * each fixture owns its OWN demo data, so neither reaches across into the other's
 * marker key. Both exist for one reason — the «Заказы доставки» page cannot be
 * judged on a rig that holds a handful of rows. The REST route's own page size is
 * 20, so a smaller set never shows a second page, never exercises sorting across
 * pages, and gives the date filter nothing to hide.
 *
 * ⚠ Runs ONLY when the rig constant `WOODEV_TEST_SEED_ORDERS_DEMO` is truthy, and
 * only once per recorded version. A fixture that created orders on every page load
 * would be a trap, not a convenience.
 *
 * @package Woodev\Tests\Fixtures
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Realistic_Orders_Seeder' ) ) {

	/**
	 * Seeds demo orders carrying this carrier's marker.
	 *
	 * @since 2.0.2
	 */
	class Woodev_Realistic_Orders_Seeder {

		/**
		 * Option recording that seeding has run, and at which version.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const SEEDED_OPTION = 'woodev_realistic_shipping_demo_orders_seeded';

		/**
		 * Bumped whenever {@see self::demo_orders()} changes, so an existing rig
		 * re-seeds instead of silently keeping the older, smaller set.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const SEED_VERSION = '1';

		/**
		 * This carrier's marker meta key — the same one its `Orders_Provider`
		 * registers in `class-realistic-shipping-plugin.php`.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const MARKER_META_KEY = '_woodev_realistic_shipping_marker';

		/**
		 * Where this carrier stores its RAW delivery status.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const STATUS_META_KEY = '_woodev_realistic_status';

		/**
		 * Where this carrier stores its tracking number.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const TRACKING_META_KEY = '_woodev_realistic_tracking_number';

		/**
		 * The two shipping method ids this fixture ships — a carrier commonly has
		 * more than one, and the page's `type` column resolves through whichever the
		 * order actually carries.
		 *
		 * @since 2.0.2
		 *
		 * @var array<int, string>
		 */
		public const METHOD_IDS = [ 'woodev_realistic_shipping', 'woodev_realistic_pickup_shipping' ];

		/**
		 * Decides whether seeding should run. Pure, so the rule is testable without
		 * a database.
		 *
		 * @since 2.0.2
		 *
		 * @param bool   $trigger_enabled     the rig's WOODEV_TEST_SEED_ORDERS_DEMO value.
		 * @param string $seeded_option_value the seeded-option's stored value ('' when unset).
		 *
		 * @return bool
		 */
		public static function should_seed( bool $trigger_enabled, string $seeded_option_value ): bool {
			return $trigger_enabled && self::SEED_VERSION !== $seeded_option_value;
		}

		/**
		 * The demo orders' raw definitions, GENERATED rather than typed out — their
		 * job is volume and spread, and thirty near-identical literals invite a
		 * copy-paste defect in whichever one carries meaning.
		 *
		 * ⚠ The raw statuses below are this carrier's OWN keys and must stay in step
		 * with the `status_map` / `status_labels` its provider registers.
		 * `CUSTOMS_HOLD` is present on purpose: it is labelled but deliberately NOT
		 * mapped, so the «Неизвестно» branch stays visible on this carrier's rows.
		 *
		 * ⚠ Dates: most land inside the CURRENT year, because the page's default
		 * period is `period=year` and anything older is hidden until the merchant
		 * widens the range. The last few are pushed beyond that boundary on purpose,
		 * so the date filter has something to reveal.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array{status:string, raw_status:string, tracking:?string, days_ago:int, method_id:string}>
		 */
		public static function demo_orders(): array {
			$raw_statuses = [ 'NEW', 'ACCEPTED', 'IN_TRANSIT', 'READY', 'DELIVERED', 'RETURNED', 'CANCELLED', 'CUSTOMS_HOLD' ];
			$wc_statuses  = [ 'processing', 'on-hold', 'completed', 'pending' ];
			$spread       = [ 2, 4, 6, 9, 13, 17, 22, 28, 35, 42, 51, 60, 70, 82, 95, 109, 124, 140, 157, 175, 194, 214, 235, 257, 280, 305, 331, 380, 420 ];

			$orders = [];

			foreach ( $spread as $index => $days_ago ) {
				$orders[] = [
					'status'     => $wc_statuses[ $index % count( $wc_statuses ) ],
					'raw_status' => $raw_statuses[ $index % count( $raw_statuses ) ],
					// Every third row carries no tracking number, so the
					// tracking-presence filter has both sides to find.
					'tracking'   => 0 === $index % 3 ? null : sprintf( 'RL%09d', 200100000 + $index ),
					'days_ago'   => $days_ago,
					// Alternate the two methods, so the `type` column shows both
					// «Курьер» and «Пункт выдачи» rather than one of them.
					'method_id'  => self::METHOD_IDS[ $index % count( self::METHOD_IDS ) ],
				];
			}

			return $orders;
		}

		/**
		 * Seeds {@see self::demo_orders()} as real `WC_Order`s, then records the
		 * version. Hooked on `admin_init`, never during plugin construction.
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
		 * @param array{status:string, raw_status:string, tracking:?string, days_ago:int, method_id:string} $definition
		 *
		 * @return void
		 */
		private static function seed_one( array $definition ): void {
			$order = wc_create_order();
			$order->set_status( $definition['status'] );

			// WooCommerce stamps `date_created` at creation, so a spread has to be
			// set explicitly or every demo row shares today's date.
			if ( 0 < $definition['days_ago'] ) {
				$order->set_date_created( time() - ( $definition['days_ago'] * DAY_IN_SECONDS ) );
			}

			$order->update_meta_data( self::MARKER_META_KEY, '1' );
			$order->update_meta_data( self::STATUS_META_KEY, $definition['raw_status'] );

			if ( null !== $definition['tracking'] ) {
				$order->update_meta_data( self::TRACKING_META_KEY, $definition['tracking'] );
			}

			$shipping_item = new \WC_Order_Item_Shipping();
			$shipping_item->set_method_title( 'Реалистичная доставка' );
			$shipping_item->set_method_id( $definition['method_id'] );
			$shipping_item->set_total( '350' );
			$order->add_item( $shipping_item );

			$order->save();
		}
	}
}
