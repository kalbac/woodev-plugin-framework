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
		public const SEED_VERSION = '6';

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
		 * Must match the provider's own `pickup_point_meta_key` (see
		 * `woodev-test-shipping-method.php`'s registration) — this is what
		 * `Order_Row_Builder::resolve_destination()` reads to show a pickup point
		 * instead of a plain address (#861).
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const PICKUP_POINT_META_KEY = '_woodev_test_shipping_pickup_point';

		/**
		 * SKU of the single demo product seeded orders add a line item for (#861) —
		 * found-or-created once, never duplicated across requests.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const DEMO_PRODUCT_SKU = 'woodev-test-demo-item';

		/**
		 * Login of the one demo WordPress user some seeded orders attach as a
		 * registered customer (#861) — the rest stay guest orders (`customer_id`
		 * 0), so the «Покупатель» cell shows both a linked and a plain name.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const DEMO_CUSTOMER_LOGIN = 'woodev_test_demo_customer';

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
		 * The customer personas seeded orders cycle through (#861) — deliberately
		 * HETEROGENEOUS: a short name and a long double-barrelled one, a short
		 * address and one long enough to wrap in the «Доставка» cell. Pure data, no
		 * WordPress calls, so it stays inspectable without a database.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array{first_name:string, last_name:string, email:string, phone:string, city:string, address_1:string, postcode:string}>
		 */
		public static function customer_pool(): array {
			return [
				[
					'first_name' => 'Ева',
					'last_name'  => 'Ким',
					'email'      => 'eva.kim@example.com',
					'phone'      => '+7 903 111-22-33',
					'city'       => 'Тверь',
					'address_1'  => 'ул. Мира, д. 5',
					'postcode'   => '170100',
				],
				[
					'first_name' => 'Александра',
					'last_name'  => 'Константинова-Виноградова',
					'email'      => 'a.konstantinova-vinogradova@example.com',
					'phone'      => '+7 916 555-77-99',
					'city'       => 'Санкт-Петербург',
					'address_1'  => 'Гражданский проспект, д. 47, корпус 3, кв. 158, подъезд 2',
					'postcode'   => '195279',
				],
				[
					'first_name' => 'Пётр',
					'last_name'  => 'Носов',
					'email'      => 'p.nosov@example.com',
					'phone'      => '+7 927 222-33-44',
					'city'       => 'Казань',
					'address_1'  => 'ул. Баумана, д. 9, кв. 12',
					'postcode'   => '420111',
				],
				[
					'first_name' => 'Юлия',
					'last_name'  => 'Смирнова',
					'email'      => 'yulia.smirnova@example.com',
					'phone'      => '+7 912 345-67-89',
					'city'       => 'Екатеринбург',
					'address_1'  => 'просп. Ленина, д. 24',
					'postcode'   => '620014',
				],
				[
					'first_name' => 'Дмитрий',
					'last_name'  => 'Соколов',
					'email'      => 'd.sokolov@example.com',
					'phone'      => '+7 923 456-78-90',
					'city'       => 'Новосибирск',
					'address_1'  => 'Красный проспект, д. 82, офис 301',
					'postcode'   => '630099',
				],
				[
					'first_name' => 'Мария',
					'last_name'  => 'Кузнецова',
					'email'      => 'maria.kuznecova@example.com',
					'phone'      => '+7 926 111-22-33',
					'city'       => 'Москва',
					'address_1'  => 'ул. Тверская, д. 12, кв. 45',
					'postcode'   => '125009',
				],
			];
		}

		/**
		 * The payment methods seeded orders cycle through (card #876) — several DIFFERENT ones,
		 * never one for all: a «Оплата» column fed by a single value is a different shape from a
		 * real shop's and hid two defects on this page before anyone noticed the column was
		 * uniform rather than merely repetitive. Pure data, no WordPress calls.
		 *
		 * Deliberately sized at 5 — every OTHER cycling field in this file already uses a
		 * modulus of 2, 3, 4 or 6 (persona count); a 5th distinct modulus keeps this fact
		 * independent of all of them, the same reasoning {@see self::demo_orders()} already
		 * gives for `tracking`/`carrier_order_id`/`is_guest`/`has_pickup`.
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
		 * ⚠ Customer/address/line-item fields (#861): `customer_index` selects a
		 * persona from {@see self::customer_pool()}, `is_guest` decides whether the
		 * order attaches a registered `WP_User` or stays anonymous, `has_pickup`
		 * decides whether the destination is a pickup point instead of a plain
		 * address, and `qty` varies the line item so «Оплата» is not a uniform
		 * total across every row.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array{status:string, raw_status:string, tracking:?string, carrier_order_id:?string, days_ago:int, customer_index:int, is_guest:bool, has_pickup:bool, qty:int, payment_method_index:int}>
		 */
		public static function demo_orders(): array {
			$orders = [
				[
					'status'               => 'processing',
					'raw_status'           => 'ON_THE_WAY',
					'tracking'              => 'TESTCARRIER-000123',
					'carrier_order_id'      => 'TESTCARRIER-EXPORT-000123',
					'days_ago'              => 0,
					'customer_index'        => 0,
					'is_guest'              => true,
					'has_pickup'            => false,
					'qty'                   => 1,
					'payment_method_index'  => 0,
				],
				[
					'status'               => 'processing',
					'raw_status'           => 'ARRIVED_PVZ',
					'tracking'              => null,
					'carrier_order_id'      => 'TESTCARRIER-EXPORT-000124',
					'days_ago'              => 0,
					// A registered customer whose parcel arrived at the pickup point —
					// «ARRIVED_PVZ» and `has_pickup` agree on purpose.
					'customer_index'        => 1,
					'is_guest'              => false,
					'has_pickup'            => true,
					'qty'                   => 2,
					'payment_method_index'  => 1,
				],
				[
					'status'               => 'processing',
					'raw_status'           => 'LOST_IN_TRANSIT',
					'tracking'              => 'TESTCARRIER-000125',
					// Deliberately NOT exported — «lost» does not imply the framework's
					// own export marker was ever written; the two are independent facts.
					'carrier_order_id'      => null,
					'days_ago'              => 0,
					'customer_index'        => 2,
					'is_guest'              => true,
					'has_pickup'            => false,
					'qty'                   => 1,
					'payment_method_index'  => 2,
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

			$persona_count = count( self::customer_pool() );

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
					// Cycles through every persona so a large set exercises every
					// name/address length, not just the three hand-typed rows above.
					'customer_index'    => $index % $persona_count,
					// Mostly guest, every fourth row a registered customer — a DIFFERENT
					// modulo than the two above, same reasoning: independent facts
					// should not collapse onto the same divisor.
					'is_guest'          => 0 !== $index % 4,
					// Every fifth row shows a pickup point instead of a plain address.
					'has_pickup'        => 0 === $index % 5,
					'qty'               => 1 + ( $index % 3 ),
					// Cycles through every payment method (#876) on its OWN modulus — 5,
					// distinct from every other cycling field above.
					'payment_method_index' => $index % count( self::payment_method_pool() ),
				];
			}

			return $orders;
		}

		/**
		 * Seeds {@see self::demo_orders()} as real `WC_Order`s, applying
		 * {@see self::should_seed()}'s rule, then marks seeding done.
		 *
		 * A version bump also backfills the seeder's OWN older orders first
		 * ({@see self::backfill_existing_orders()}, #868) — creating a fresh batch
		 * alone would leave every order an earlier version made without whatever
		 * field the newer version learned to set.
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

			if ( ! function_exists( 'wc_create_order' ) || ! class_exists( '\WC_Order_Item_Shipping' ) || ! class_exists( '\WC_Product_Simple' ) ) {
				return;
			}

			self::backfill_existing_orders();

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
		 * @param array{status:string, raw_status:string, tracking:?string, carrier_order_id:?string, customer_index:int, is_guest:bool, has_pickup:bool, qty:int, payment_method_index:int} $definition
		 *
		 * @return void
		 */
		private static function seed_one( array $definition ): void {
			$order = wc_create_order();
			$order->set_status( $definition['status'] );

			$payment = self::payment_method_pool()[ $definition['payment_method_index'] % count( self::payment_method_pool() ) ];
			$order->set_payment_method( $payment['method'] );
			$order->set_payment_method_title( $payment['title'] );

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

			self::apply_customer( $order, $definition );

			$shipping_item = new \WC_Order_Item_Shipping();
			$shipping_item->set_method_title( 'Тестовая доставка' );
			$shipping_item->set_method_id( self::METHOD_ID );
			$shipping_item->set_total( '0' );
			$order->add_item( $shipping_item );

			$product = self::demo_product();

			if ( null !== $product ) {
				$order->add_product( $product, $definition['qty'] );
			}

			// Without this, `_order_total`/`_order_shipping_total` stay at the
			// defaults the item-level setters above never touch, and «Оплата»
			// keeps showing 0,00 ₽ even though a real line item now exists (#861).
			$order->calculate_totals();
			$order->save();
		}

		/**
		 * Backfills every order THIS seeder made earlier (#868), filling EMPTY fields only.
		 *
		 * Raising {@see self::SEED_VERSION} only ever created a NEW batch; the orders an older
		 * version made kept whatever that version knew how to set — customer and address (#861),
		 * payment method (#876) were each found missing on the rig one card at a time. This walks
		 * the orders carrying {@see self::MARKER_META_KEY} (never a foreign order), and per order
		 * writes only the fields still empty: it never deletes and never overwrites a non-empty
		 * value, so a manual rig edit survives. Idempotent — a second run finds nothing empty.
		 *
		 * Goes through the WooCommerce CRUD (`wc_get_orders()` + `WC_Order`), not raw meta, so it
		 * behaves the same on HPOS and on the legacy CPT datastore.
		 *
		 * `customer_id` is deliberately NOT backfilled: `0` is the legitimate "guest" value, so an
		 * empty one cannot be told from a deliberate one.
		 *
		 * @since 2.0.2
		 *
		 * @return int how many orders were changed.
		 */
		public static function backfill_existing_orders(): int {
			$order_ids = wc_get_orders(
				[
					'limit'        => -1,
					'return'       => 'ids',
					// `meta_key` + `meta_compare`, not `meta_query`: the legacy CPT datastore drops
					// `meta_query` (an unsupported-arg notice), which would walk EVERY order — foreign ones included.
					'meta_key'     => self::MARKER_META_KEY,
					'meta_compare' => 'EXISTS',
				]
			);

			$changed = 0;

			foreach ( (array) $order_ids as $order_id ) {
				$order = wc_get_order( (int) $order_id );

				if ( $order instanceof \WC_Order && self::backfill_one( $order ) ) {
					++$changed;
				}
			}

			return $changed;
		}

		/**
		 * Which of an order's fields the backfill should write — pure, so the fill-empty /
		 * keep-non-empty / idempotent contract is unit-testable without WordPress.
		 *
		 * The values are derived from the order id alone (persona and payment method by modulus,
		 * same pools fresh seeding cycles through), so a re-run derives the same values.
		 *
		 * @since 2.0.2
		 *
		 * @param int                  $order_id the order's id.
		 * @param array<string,string> $current  the order's current values, keyed like the result
		 *                                       (`billing_city`, `payment_method`, …); a missing key counts as empty.
		 *
		 * @return array<string,string> only the fields that are empty in `$current`, with their derived value.
		 */
		public static function backfill_plan( int $order_id, array $current ): array {
			$pool    = self::customer_pool();
			$persona = $pool[ $order_id % count( $pool ) ];
			$payment = self::payment_method_pool()[ $order_id % count( self::payment_method_pool() ) ];

			$desired = [
				'billing_first_name'   => $persona['first_name'],
				'billing_last_name'    => $persona['last_name'],
				'billing_email'        => $persona['email'],
				'billing_phone'        => $persona['phone'],
				'billing_city'         => $persona['city'],
				'billing_address_1'    => $persona['address_1'],
				'billing_postcode'     => $persona['postcode'],
				'billing_country'      => 'RU',
				'shipping_first_name'  => $persona['first_name'],
				'shipping_last_name'   => $persona['last_name'],
				'shipping_city'        => $persona['city'],
				'shipping_address_1'   => $persona['address_1'],
				'shipping_postcode'    => $persona['postcode'],
				'shipping_country'     => 'RU',
				'payment_method'       => $payment['method'],
				'payment_method_title' => $payment['title'],
			];

			// A method kept from a manual edit must not get another method's title.
			$kept_method = (string) ( $current['payment_method'] ?? '' );

			if ( '' !== $kept_method ) {
				$desired['payment_method_title'] = '';

				foreach ( self::payment_method_pool() as $entry ) {
					if ( $entry['method'] === $kept_method ) {
						$desired['payment_method_title'] = $entry['title'];
					}
				}
			}

			$plan = [];

			foreach ( $desired as $field => $value ) {
				if ( '' !== $value && '' === (string) ( $current[ $field ] ?? '' ) ) {
					$plan[ $field ] = $value;
				}
			}

			return $plan;
		}

		/**
		 * The quantity a backfilled line item gets — pure, id-derived, same 1..3 spread fresh seeding uses.
		 *
		 * @since 2.0.2
		 *
		 * @param int $order_id the order's id.
		 *
		 * @return int
		 */
		public static function backfill_qty( int $order_id ): int {
			return 1 + ( $order_id % 3 );
		}

		/**
		 * Whether a backfilled order should show a pickup point — pure, id-derived, every fifth like fresh seeding.
		 *
		 * @since 2.0.2
		 *
		 * @param int $order_id the order's id.
		 *
		 * @return bool
		 */
		public static function backfill_has_pickup( int $order_id ): bool {
			return 0 === $order_id % 5;
		}

		/**
		 * Applies {@see self::backfill_plan()} and the missing line items / pickup point to one order.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order one of this seeder's own orders.
		 *
		 * @return bool whether anything was written (and the order saved).
		 */
		private static function backfill_one( \WC_Order $order ): bool {
			$order_id = $order->get_id();
			$current  = [];

			foreach ( array_keys( self::backfill_plan( $order_id, [] ) ) as $field ) {
				$current[ $field ] = (string) $order->{ 'get_' . $field }( 'edit' );
			}

			$changed = false;

			foreach ( self::backfill_plan( $order_id, $current ) as $field => $value ) {
				$order->{ 'set_' . $field }( $value );
				$changed = true;
			}

			$items_added = false;

			if ( [] === $order->get_items( 'shipping' ) ) {
				$shipping_item = new \WC_Order_Item_Shipping();
				$shipping_item->set_method_title( 'Тестовая доставка' );
				$shipping_item->set_method_id( self::METHOD_ID );
				$shipping_item->set_total( '0' );
				$order->add_item( $shipping_item );
				$items_added = true;
			}

			if ( [] === $order->get_items( 'line_item' ) ) {
				$product = self::demo_product();

				if ( null !== $product ) {
					$order->add_product( $product, self::backfill_qty( $order_id ) );
					$items_added = true;
				}
			}

			// Only when items were added: an order that already had its lines keeps its totals.
			if ( $items_added ) {
				$order->calculate_totals();
				$changed = true;
			}

			if ( self::backfill_has_pickup( $order_id ) && '' === $order->get_meta( self::PICKUP_POINT_META_KEY, true ) ) {
				$pool = self::customer_pool();

				$order->update_meta_data(
					self::PICKUP_POINT_META_KEY,
					self::pickup_point( $pool[ $order_id % count( $pool ) ], $order_id )
				);
				$changed = true;
			}

			if ( $changed ) {
				$order->save();
			}

			return $changed;
		}

		/**
		 * Applies one {@see self::customer_pool()} persona to an order: billing +
		 * shipping fields always, a registered `WP_User` when the definition asks
		 * for one, and a pickup-point destination when it asks for that too (#861).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order                                                              $order      order being built.
		 * @param array{customer_index:int, is_guest:bool, has_pickup:bool}               $definition demo order definition.
		 *
		 * @return void
		 */
		private static function apply_customer( \WC_Order $order, array $definition ): void {
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

			if ( $definition['has_pickup'] ) {
				$order->update_meta_data( self::PICKUP_POINT_META_KEY, self::pickup_point( $persona, $order->get_id() ) );
			}
		}

		/**
		 * The pickup-point destination stored under {@see self::PICKUP_POINT_META_KEY} — shared
		 * by fresh seeding and the #868 backfill so the two cannot drift. Pure.
		 *
		 * @since 2.0.2
		 *
		 * @param array{city:string, address_1:string} $persona  a {@see self::customer_pool()} entry.
		 * @param int                                  $order_id the order the point belongs to.
		 *
		 * @return array<string, mixed>
		 */
		private static function pickup_point( array $persona, int $order_id ): array {
			return [
				'id'      => sprintf( 'TEST-PVZ-%d', $order_id ),
				'name'    => 'Пункт выдачи «Тестовый» на ' . $persona['city'],
				'address' => $persona['city'] . ', ' . $persona['address_1'],
				'lat'     => 55.7558,
				'lng'     => 37.6173,
				'type'    => [
					'code'  => 'pvz',
					'label' => 'Пункт выдачи',
				],
			];
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
				$product->set_name( 'Демо-товар для тестовой доставки' );
				$product->set_sku( self::DEMO_PRODUCT_SKU );
				$product->set_regular_price( '1990' );
				$product->set_price( '1990' );
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
					'user_email'   => 'demo.customer@woodev.test',
					'display_name' => 'Игорь Дорофеев',
					'first_name'   => 'Игорь',
					'last_name'    => 'Дорофеев',
					'role'         => 'customer',
				]
			);

			return is_wp_error( $user_id ) ? 0 : (int) $user_id;
		}
	}
}
