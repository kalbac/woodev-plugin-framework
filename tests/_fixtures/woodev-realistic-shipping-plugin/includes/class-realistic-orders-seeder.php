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
		public const SEED_VERSION = '4';

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
		 * Where this carrier stores the id the carrier itself assigned — the meta
		 * whose PRESENCE is what «выгружен» means (SP-10 #841). Must match the
		 * `carrier_order_id_meta_key` this fixture's `Orders_Provider` registers in
		 * `class-realistic-shipping-plugin.php`; the filter reads the provider's
		 * declaration, so a divergence here silently makes every order look new.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const CARRIER_ORDER_ID_META_KEY = '_woodev_realistic_carrier_order_id';

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
		 * Must match the provider's own `pickup_point_meta_key` (see
		 * `class-realistic-shipping-plugin.php`'s registration) — written only for
		 * orders using {@see self::METHOD_IDS}'s pickup method (#861).
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const PICKUP_POINT_META_KEY = '_woodev_realistic_pickup_point';

		/**
		 * SKU of the single demo product seeded orders add a line item for (#861) —
		 * found-or-created once, never duplicated across requests.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const DEMO_PRODUCT_SKU = 'woodev-realistic-demo-item';

		/**
		 * Login of the one demo WordPress user some seeded orders attach as a
		 * registered customer (#861) — the rest stay guest orders (`customer_id`
		 * 0), so the «Покупатель» cell shows both a linked and a plain name.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const DEMO_CUSTOMER_LOGIN = 'woodev_realistic_demo_customer';

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
		 * The customer personas seeded orders cycle through (#861) — deliberately
		 * HETEROGENEOUS: a short name and a long double-barrelled one, a short
		 * address and one long enough to wrap in the «Доставка» cell. Its own set,
		 * separate from `Woodev_Test_Orders_Seeder::customer_pool()` — same "each
		 * fixture owns its own demo data" reasoning as this file's own docblock.
		 * Pure data, no WordPress calls, so it stays inspectable without a database.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array{first_name:string, last_name:string, email:string, phone:string, city:string, address_1:string, postcode:string}>
		 */
		public static function customer_pool(): array {
			return [
				[
					'first_name' => 'Лев',
					'last_name'  => 'Орлов',
					'email'      => 'lev.orlov@example.com',
					'phone'      => '+7 951 111-22-11',
					'city'       => 'Воронеж',
					'address_1'  => 'ул. Никитинская, д. 3',
					'postcode'   => '394000',
				],
				[
					'first_name' => 'Виктория',
					'last_name'  => 'Черемных-Богданович',
					'email'      => 'v.cheremnyh-bogdanovich@example.com',
					'phone'      => '+7 918 222-33-44',
					'city'       => 'Ростов-на-Дону',
					'address_1'  => 'б-р Комарова, д. 15, корпус 2, кв. 233, подъезд 4',
					'postcode'   => '344000',
				],
				[
					'first_name' => 'Роман',
					'last_name'  => 'Волков',
					'email'      => 'roman.volkov@example.com',
					'phone'      => '+7 961 333-44-55',
					'city'       => 'Краснодар',
					'address_1'  => 'ул. Красная, д. 55',
					'postcode'   => '350000',
				],
				[
					'first_name' => 'Анна',
					'last_name'  => 'Белова',
					'email'      => 'anna.belova@example.com',
					'phone'      => '+7 927 444-55-66',
					'city'       => 'Самара',
					'address_1'  => 'ул. Гагарина, д. 18, кв. 7',
					'postcode'   => '443001',
				],
				[
					'first_name' => 'Сергей',
					'last_name'  => 'Морозов',
					'email'      => 'sergey.morozov@example.com',
					'phone'      => '+7 908 555-66-77',
					'city'       => 'Челябинск',
					'address_1'  => 'просп. Ленина, д. 60',
					'postcode'   => '454080',
				],
				[
					'first_name' => 'Ольга',
					'last_name'  => 'Фёдорова',
					'email'      => 'olga.fedorova@example.com',
					'phone'      => '+7 902 666-77-88',
					'city'       => 'Пермь',
					'address_1'  => 'ул. Ленина, д. 33, кв. 9',
					'postcode'   => '614000',
				],
			];
		}

		/**
		 * The payment methods seeded orders cycle through (card #876) — several DIFFERENT ones,
		 * never one for all: a «Оплата» column fed by a single value is a different shape from a
		 * real shop's and hid two defects on this page before anyone noticed the column was
		 * uniform rather than merely repetitive. Its own set, separate from
		 * `Woodev_Test_Orders_Seeder::payment_method_pool()` — same "each fixture owns its own
		 * demo data" reasoning as this file's own docblock. Pure data, no WordPress calls.
		 *
		 * Deliberately sized at 5 — every other cycling field in {@see self::demo_orders()} uses
		 * a modulus of 2, 4, 6 or 8; a 5th distinct modulus keeps this fact independent of them.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array{method:string, title:string}>
		 */
		public static function payment_method_pool(): array {
			return [
				[
					'method' => 'bacs',
					'title'  => 'Банковская карта',
				],
				[
					'method' => 'cod',
					'title'  => 'Наложенный платёж',
				],
				[
					'method' => 'yookassa',
					'title'  => 'ЮKassa',
				],
				[
					'method' => 'sbp',
					'title'  => 'СБП',
				],
				[
					'method' => 'bank_transfer',
					'title'  => 'Банковский перевод',
				],
			];
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
		 * ⚠ Customer/address/line-item fields (#861): `customer_index` selects a
		 * persona from {@see self::customer_pool()}, `is_guest` decides whether the
		 * order attaches a registered `WP_User` or stays anonymous, and `qty`
		 * varies the line item so «Оплата» is not a uniform total across every
		 * row. There is no separate `has_pickup` flag here — {@see self::seed_one()}
		 * derives it from `method_id` itself: a real pickup order has a chosen
		 * pickup point, a courier order does not.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array{status:string, raw_status:string, tracking:?string, carrier_order_id:?string, days_ago:int, method_id:string, customer_index:int, is_guest:bool, qty:int, payment_method_index:int}>
		 */
		public static function demo_orders(): array {
			$raw_statuses  = [ 'NEW', 'ACCEPTED', 'IN_TRANSIT', 'READY', 'DELIVERED', 'RETURNED', 'CANCELLED', 'CUSTOMS_HOLD' ];
			$wc_statuses   = [ 'processing', 'on-hold', 'completed', 'pending' ];
			$spread        = [ 2, 4, 6, 9, 13, 17, 22, 28, 35, 42, 51, 60, 70, 82, 95, 109, 124, 140, 157, 175, 194, 214, 235, 257, 280, 305, 331, 380, 420 ];
			$persona_count = count( self::customer_pool() );

			$orders = [];

			foreach ( $spread as $index => $days_ago ) {
				$orders[] = [
					'status'           => $wc_statuses[ $index % count( $wc_statuses ) ],
					'raw_status'       => $raw_statuses[ $index % count( $raw_statuses ) ],
					// Every third row carries no tracking number, so the
					// tracking-presence filter has both sides to find.
					'tracking'         => 0 === $index % 3 ? null : sprintf( 'RL%09d', 200100000 + $index ),
					// ⚠ Only PART of the set is exported (SP-10 #841), for the same
					// reason tracking above is partial: a filter whose "true" side is
					// empty on the rig cannot be verified there at all, and an
					// all-or-nothing set hides a defect in whichever side is missing.
					// Every second row, so the split stays visible on this carrier
					// alone as well as on the two-carrier aggregate.
					'carrier_order_id' => 0 === $index % 2 ? sprintf( 'RL-EXPORT-%06d', 300 + $index ) : null,
					'days_ago'         => $days_ago,
					// Alternate the two methods, so the `type` column shows both
					// «Курьер» and «Пункт выдачи» rather than one of them.
					'method_id'        => self::METHOD_IDS[ $index % count( self::METHOD_IDS ) ],
					// Cycles through every persona so a large set exercises every
					// name/address length, not just decoration on a handful of rows.
					'customer_index'   => $index % $persona_count,
					// Mostly guest, every fourth row a registered customer — a
					// DIFFERENT modulo than `method_id`/`tracking` above, so the
					// facts overlap in both directions rather than tracking each
					// other.
					'is_guest'         => 0 !== $index % 4,
					'qty'              => 1 + ( $index % 3 ),
					// Cycles through every payment method (#876) on its OWN modulus.
					'payment_method_index' => $index % count( self::payment_method_pool() ),
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

			if ( ! function_exists( 'wc_create_order' ) || ! class_exists( '\WC_Order_Item_Shipping' ) || ! class_exists( '\WC_Product_Simple' ) ) {
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
		 * @param array{status:string, raw_status:string, tracking:?string, carrier_order_id:?string, days_ago:int, method_id:string, customer_index:int, is_guest:bool, qty:int, payment_method_index:int} $definition
		 *
		 * @return void
		 */
		private static function seed_one( array $definition ): void {
			$order = wc_create_order();
			$order->set_status( $definition['status'] );

			$payment = self::payment_method_pool()[ $definition['payment_method_index'] % count( self::payment_method_pool() ) ];
			$order->set_payment_method( $payment['method'] );
			$order->set_payment_method_title( $payment['title'] );

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

			if ( null !== $definition['carrier_order_id'] ) {
				$order->update_meta_data( self::CARRIER_ORDER_ID_META_KEY, $definition['carrier_order_id'] );
			}

			// A pickup order has a chosen pickup point, a courier order does not —
			// derived from `method_id` rather than a second independent flag.
			self::apply_customer( $order, $definition, self::METHOD_IDS[1] === $definition['method_id'] );

			$shipping_item = new \WC_Order_Item_Shipping();
			$shipping_item->set_method_title( 'Реалистичная доставка' );
			$shipping_item->set_method_id( $definition['method_id'] );
			$shipping_item->set_total( '350' );
			$order->add_item( $shipping_item );

			$product = self::demo_product();

			if ( null !== $product ) {
				$order->add_product( $product, $definition['qty'] );
			}

			// Without this, `_order_total`/`_order_shipping_total` stay at the
			// defaults the item-level setters above never touch, and both «Доставка»
			// (the 350 set above) and «Оплата» keep showing 0,00 ₽ (#861).
			$order->calculate_totals();
			$order->save();
		}

		/**
		 * Applies one {@see self::customer_pool()} persona to an order: billing +
		 * shipping fields always, a registered `WP_User` when the definition asks
		 * for one, and a pickup-point destination when `$has_pickup` is true (#861).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order                                       $order      order being built.
		 * @param array{customer_index:int, is_guest:bool}         $definition demo order definition.
		 * @param bool                                             $has_pickup whether to attach a pickup-point destination.
		 *
		 * @return void
		 */
		private static function apply_customer( \WC_Order $order, array $definition, bool $has_pickup ): void {
			$pool    = self::customer_pool();
			$persona = $pool[ $definition['customer_index'] % count( $pool ) ];

			$order->set_billing_first_name( $persona['first_name'] );
			$order->set_billing_last_name( $persona['last_name'] );
			$order->set_billing_email( $persona['email'] );
			$order->set_billing_phone( $persona['phone'] );
			$order->set_billing_city( $persona['city'] );
			$order->set_billing_address_1( $persona['address_1'] );
			$order->set_billing_postcode( $persona['postcode'] );
			$order->set_billing_country( 'RU' );

			$order->set_shipping_first_name( $persona['first_name'] );
			$order->set_shipping_last_name( $persona['last_name'] );
			$order->set_shipping_city( $persona['city'] );
			$order->set_shipping_address_1( $persona['address_1'] );
			$order->set_shipping_postcode( $persona['postcode'] );
			$order->set_shipping_country( 'RU' );

			if ( ! $definition['is_guest'] ) {
				$order->set_customer_id( self::demo_customer_id() );
			}

			if ( $has_pickup ) {
				$order->update_meta_data(
					self::PICKUP_POINT_META_KEY,
					[
						'id'      => sprintf( 'RL-PVZ-%d', $order->get_id() ),
						'name'    => 'Пункт выдачи «Реалистичный» на ' . $persona['city'],
						'address' => $persona['city'] . ', ' . $persona['address_1'],
						'lat'     => 55.7558,
						'lng'     => 37.6173,
						'type'    => [
							'code'  => 'pvz',
							'label' => 'Пункт выдачи',
						],
					]
				);
			}
		}

		/**
		 * Finds or creates the single demo product seeded orders add a line item
		 * for (#861) — idempotent, so re-seeding on a version bump never creates a
		 * duplicate.
		 *
		 * @since 2.0.2
		 *
		 * @return \WC_Product|null null only when the found/created product could
		 *                          not be loaded back — a display-only seeder must
		 *                          not fatal the whole batch over one product.
		 */
		private static function demo_product(): ?\WC_Product {
			$product_id = wc_get_product_id_by_sku( self::DEMO_PRODUCT_SKU );

			if ( 0 === $product_id ) {
				$product = new \WC_Product_Simple();
				$product->set_name( 'Демо-товар для реалистичной доставки' );
				$product->set_sku( self::DEMO_PRODUCT_SKU );
				$product->set_regular_price( '2490' );
				$product->set_price( '2490' );
				$product->set_status( 'publish' );
				$product->set_catalog_visibility( 'hidden' );
				$product_id = $product->save();
			}

			$product = wc_get_product( $product_id );

			return $product instanceof \WC_Product ? $product : null;
		}

		/**
		 * Finds or creates the single demo `WP_User` some seeded orders attach as a
		 * registered customer (#861) — idempotent by login, so re-seeding never
		 * creates a duplicate account.
		 *
		 * @since 2.0.2
		 *
		 * @return int the user id, or 0 when creation failed (the order then simply
		 *             stays a guest order rather than fataling the whole batch).
		 */
		private static function demo_customer_id(): int {
			$existing = get_user_by( 'login', self::DEMO_CUSTOMER_LOGIN );

			if ( $existing instanceof \WP_User ) {
				return $existing->ID;
			}

			$user_id = wp_insert_user(
				[
					'user_login'   => self::DEMO_CUSTOMER_LOGIN,
					'user_pass'    => wp_generate_password(),
					'user_email'   => 'demo.customer@woodev-realistic.test',
					'display_name' => 'Кирилл Абрамов',
					'first_name'   => 'Кирилл',
					'last_name'    => 'Абрамов',
					'role'         => 'customer',
				]
			);

			return is_wp_error( $user_id ) ? 0 : (int) $user_id;
		}
	}
}
