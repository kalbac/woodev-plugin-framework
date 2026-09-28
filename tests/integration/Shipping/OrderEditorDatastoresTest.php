<?php
/**
 * Integration: the admin order wizard's create / update / load service on BOTH WooCommerce order
 * datastores — HPOS and the legacy CPT (#710 spec D4 / D5, card #968).
 *
 * What the unit tier cannot see. The unit tests pin the editor's decisions against doubles of the
 * checkout handler, the pickup handler and the order. Here the REAL fixture carriers run on the REAL
 * WooCommerce: an order is created and edited through {@see Order_Editor}, and everything is read
 * back from a FRESH order object, so a write that lands only on one datastore (or only on an object
 * the datastore never saved) is caught. The order is then judged the way the orders page judges it:
 * by the carrier marker, by {@see Orders_Registry::resolve_provider_for_order()} and by the page's
 * real list query ({@see Orders_Query}) — never by a hand-rolled `meta_query`, which
 * `wc_get_orders()` DROPS on the legacy CPT datastore (gotcha
 * `wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore`).
 *
 * Also proven here, because only the real WooCommerce can:
 *  - «New order» is sent exactly ONCE for a processing order and not at all for a pending one — the
 *    editor issues no explicit trigger (#962 I0 contradiction 1: it would double-send) — and an EDIT
 *    (pending→processing) sends none (spec C4);
 *  - stock follows WooCommerce's own status transition on create and its own line adjustment on edit;
 *  - the three checkout hooks stay silent on an admin save, the admin hook fires.
 *
 * Fixture facts this file leans on: the realistic carrier (`realistic`) owns a courier method
 * `woodev_realistic_shipping` and a pickup method `woodev_realistic_pickup_shipping` (slot field
 * `realistic_pickup_point`); the test carrier (`test_shipping`) owns the pickup method
 * `woodev_test_shipping` (slot `carrier_pickup_point`) and declares a carrier-order-id meta key. Zone
 * instance ids are NOT verified by the service, so any positive id serves.
 *
 * NOT RUN BY THE WORKER THAT AUTHORED THIS FILE — the coordinator runs the integration suite.
 * How both datastores run in one process: see {@see OrderPersistenceDatastoresTest}.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\Order_Editor;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Checkout\Checkout_Config;
use Woodev\Framework\Shipping\Order\Order_Marker;
use Woodev\Tests\Integration\TestCase;

class OrderEditorDatastoresTest extends TestCase {

	private const REALISTIC_COURIER = 'woodev_realistic_shipping';
	private const REALISTIC_PICKUP  = 'woodev_realistic_pickup_shipping';
	private const REALISTIC_MARKER  = '_woodev_realistic_shipping_marker';
	private const REALISTIC_SLOT    = 'realistic_pickup_point';
	private const REALISTIC_POINT   = 'REAL-MSK-1';

	private const TEST_METHOD   = 'woodev_test_shipping';
	private const TEST_MARKER   = '_woodev_test_shipping_marker';
	private const TEST_EXPORTED = '_woodev_test_shipping_carrier_order_id';
	private const TEST_SLOT     = 'carrier_pickup_point';
	private const TEST_POINT    = 'PVZ-968';

	/** @var int the tests' managed-stock product. */
	private $product_id = 0;

	/** @var array<string,array{0:string,1:callable}> hooks the test added, to remove. */
	private $listeners = [];

	/** @var \mysqli[] the «other request's» database connections the test opened, to close. */
	private $connections = [];

	/**
	 * Re-registers the fixtures' own providers on a clean registry, and gives the tests a product
	 * whose stock WooCommerce manages.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\Woodev_Test_Shipping_Method_Plugin' ) || ! class_exists( '\Woodev_Realistic_Shipping_Plugin' ) ) {
			$this->markTestSkipped( 'The shipping fixture plugins are not loaded.' );
		}

		$this->ensure_mailer_listens();

		Orders_Registry::instance()->reset_for_tests();

		$this->register_fixture_provider( \Woodev_Test_Shipping_Method_Plugin::instance(), 'init_test_shipping_orders_page' );
		$this->register_fixture_provider( \Woodev_Realistic_Shipping_Plugin::instance(), 'init_realistic_orders_page' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Товар мастера заказов' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '100' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$this->product_id = $product->save();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->listeners as $listener ) {
			remove_action( $listener[0], $listener[1], 10 );
			remove_filter( $listener[0], $listener[1], 10 );
		}

		$this->listeners = [];

		foreach ( $this->connections as $connection ) {
			$connection->close(); // Releases every named lock it still holds.
		}

		$this->connections = [];

		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * Runs a fixture's private orders-page registration (the code that builds its provider,
	 * marker writer included, and registers it WITH the plugin).
	 *
	 * @param object $plugin fixture plugin instance.
	 * @param string $method its private registration method.
	 * @return void
	 */
	private function register_fixture_provider( $plugin, string $method ): void {
		$reflection = new \ReflectionMethod( $plugin, $method );

		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		$reflection->invoke( $plugin );
	}

	/** @return array<string,array{0:bool}> */
	public function datastore_provider(): array {
		return [
			'HPOS'       => [ true ],
			'legacy CPT' => [ false ],
		];
	}

	/**
	 * Selects the datastore for everything created after this call (data sync off, so an order
	 * lives in exactly one of them) and PROVES the framework sees it.
	 *
	 * @param bool $hpos true => HPOS `wc_orders*`; false => legacy `posts` / `postmeta`.
	 * @return void
	 */
	private function use_datastore( bool $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );

		$this->assertSame( $hpos, \Woodev_Plugin_Compatibility::is_hpos_enabled(), 'the framework must see the datastore this test selected' );
	}

	/**
	 * A payload the wizard would send for a courier delivery, to be bent one field at a time.
	 *
	 * @param array<string,mixed> $override top-level keys to replace.
	 * @return array<string,mixed>
	 */
	private function payload( array $override = [] ): array {
		return array_replace(
			[
				'customer'       => [ 'id' => 0 ],
				'billing'        => [
					'first_name' => 'Иван',
					'last_name'  => 'Иванов',
					'country'    => 'RU',
					'city'       => 'Москва',
					'address_1'  => 'ул. Тверская, 1',
					'postcode'   => '125009',
					'phone'      => '+79991234567',
					'email'      => 'ivan-968@example.test',
				],
				'items'          => [
					[
						'product_id' => $this->product_id,
						'quantity'   => 2,
						'price'      => '150',
					],
				],
				'shipping_line'  => [
					'method_id'   => self::REALISTIC_COURIER,
					'instance_id' => 6,
					'label'       => 'Курьер',
					'cost'        => '350',
					'meta'        => [ 'delivery_time' => '2-3 дня' ],
				],
				'payment_method' => 'cod',
				'status'         => 'processing',
			],
			$override
		);
	}

	/**
	 * The payload for a delivery to a pickup point of the realistic carrier.
	 *
	 * @param array<string,mixed> $override top-level keys to replace.
	 * @return array<string,mixed>
	 */
	private function pickup_payload( array $override = [] ): array {
		return $this->payload(
			array_replace(
				[
					'shipping_line' => [
						'method_id'   => self::REALISTIC_PICKUP,
						'instance_id' => 5,
						'label'       => 'ПВЗ',
						'cost'        => '200',
					],
					'pickup_point'  => [ 'id' => self::REALISTIC_POINT ],
				],
				$override
			)
		);
	}

	/**
	 * Creates an order through the service and fails the test with the service's own message when
	 * it refuses.
	 *
	 * @param array<string,mixed> $payload the request body.
	 * @return \WC_Order
	 */
	private function create( array $payload ): \WC_Order {
		$result = ( new Order_Editor() )->create( $payload );

		$this->assertInstanceOf( \WC_Order::class, $result, $result instanceof \WP_Error ? $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );

		return $result;
	}

	/**
	 * A fresh read of an order, so only what the datastore really holds is compared.
	 *
	 * @param int $order_id order id.
	 * @return \WC_Order
	 */
	private function fresh( int $order_id ): \WC_Order {
		$fresh = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $fresh );

		return $fresh;
	}

	/**
	 * @param int $product_id product id.
	 * @return int the product's stock, read fresh.
	 */
	private function stock( int $product_id ): int {
		wp_cache_flush();

		return (int) wc_get_product( $product_id )->get_stock_quantity();
	}

	/**
	 * Ids of the orders the orders page lists for a carrier tab ('' = the aggregate tab), asked
	 * through the page's REAL list query.
	 *
	 * @param string $carrier provider id, or '' for the aggregate.
	 * @return int[]
	 */
	private function listed_ids( string $carrier ): array {
		$result = ( new Orders_Query() )->get_results(
			[
				'carrier'  => $carrier,
				'per_page' => 200,
			]
		);

		return array_map(
			static function ( \WC_Order $order ): int {
				return (int) $order->get_id();
			},
			$result->orders
		);
	}

	/**
	 * Counts how often a hook fires, until the test ends.
	 *
	 * @param string $hook the hook.
	 * @return \ArrayObject<string,int> `count` holds the number of firings.
	 */
	private function count_hook( string $hook ): \ArrayObject {
		$counter = new \ArrayObject( [ 'count' => 0 ] );

		$listener = static function ( $first = null ) use ( $counter ) {
			$counter['count'] = $counter['count'] + 1;

			return $first;
		};

		add_filter( $hook, $listener, 10, 5 );

		$this->listeners[] = [ $hook, $listener ];

		return $counter;
	}

	/**
	 * Counts the e-mails of one WooCommerce e-mail type that WooCommerce actually SENT — on
	 * `woocommerce_email_sent`, which every supported WooCommerce fires once per `wp_mail()` of a
	 * transactional e-mail and never for a disabled or muted one.
	 *
	 * @param string $email_id WooCommerce e-mail id, e.g. `new_order`.
	 * @return \ArrayObject live counter; read `['count']`.
	 */
	private function count_sent_email( string $email_id ): \ArrayObject {
		$counter = new \ArrayObject( [ 'count' => 0 ] );

		$listener = static function ( $sent = null, $id = null ) use ( $counter, $email_id ) {
			if ( $sent && (string) $id === $email_id ) {
				$counter['count'] = $counter['count'] + 1;
			}

			return $sent;
		};

		add_action( 'woocommerce_email_sent', $listener, 10, 3 );

		$this->listeners[] = [ 'woocommerce_email_sent', $listener ];

		return $counter;
	}

	// -------------------------------------------------------------------------
	// WooCommerce's mailer — the harness half of #981
	// -------------------------------------------------------------------------

	/**
	 * Makes sure THIS test's WooCommerce mailer is listening for its transactional e-mails, and
	 * asserts it rather than assuming it.
	 *
	 * WooCommerce's e-mail objects register their `…_notification` listeners in their constructors,
	 * i.e. when the mailer singleton is FIRST built. WP_UnitTestCase restores the hook table after
	 * every test, so once the singleton was built inside some earlier test's scope its listeners are
	 * gone for the rest of the process while `WC()->mailer()` stays a no-op — every «New order» after
	 * that is a `_notification` action nobody hears (0 e-mails on WC 8.5.1 / 9.3.0 in CI; WC 11
	 * builds the mailer during bootstrap, so its listeners sit in the backup and survive; traced in
	 * docs-internal/research/2026-09-28-981-new-order-email-trace/).
	 *
	 * The repair, `WC_Emails::init()`, rebuilds every e-mail object and hooks the NEW ones; it never
	 * unhooks the old ones. So it runs only when the mailer's own «New order» listener is missing,
	 * every callback the OLD objects still hold anywhere is removed first, and afterwards the two
	 * e-mails this file counts are asserted to be hooked exactly once — a partially restored hook
	 * table can neither silence them nor double-send them unnoticed (critic, round 2). WP_UnitTestCase
	 * drops whatever this adds once the test ends.
	 *
	 * @return void
	 */
	private function ensure_mailer_listens(): void {
		$mailer = \WC()->mailer();
		$hook   = 'woocommerce_order_status_pending_to_processing_notification';

		if ( ! isset( $mailer->emails['WC_Email_New_Order'] ) || false === has_action( $hook, [ $mailer->emails['WC_Email_New_Order'], 'trigger' ] ) ) {
			foreach ( $mailer->emails as $stale ) {
				$this->unhook_object( $stale );
			}

			$mailer->init();
		}

		foreach ( [ 'WC_Email_New_Order', 'WC_Email_Customer_Processing_Order' ] as $class ) {
			$this->assertSame( 1, $this->count_listeners( $hook, $class, 'trigger' ), "{$class} listens for pending→processing exactly once" );
		}
	}

	/**
	 * Removes every callback an object holds, on any hook at any priority.
	 *
	 * @param object $subject the object whose callbacks go.
	 * @return void
	 */
	private function unhook_object( object $subject ): void {
		foreach ( $GLOBALS['wp_filter'] as $hook => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && isset( $callback['function'][0] ) && $callback['function'][0] === $subject ) {
						remove_filter( (string) $hook, $callback['function'], (int) $priority );
					}
				}
			}
		}
	}

	/**
	 * How many `[ <instance of $class>, $method ]` callbacks sit on a hook.
	 *
	 * @param string $hook   the hook.
	 * @param string $class  class of the callback's object.
	 * @param string $method the callback's method.
	 * @return int
	 */
	private function count_listeners( string $hook, string $class, string $method ): int {
		$count = 0;

		foreach ( ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? [] ) as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && isset( $callback['function'][0] ) && $callback['function'][0] instanceof $class && $method === $callback['function'][1] ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Switches WooCommerce to DEFERRED transactional e-mails for the rest of this test.
	 *
	 * WooCommerce takes that decision once, on `init`, in `WC_Emails::init_transactional_emails()`:
	 * with `woocommerce_defer_transactional_emails` true it hooks `queue_transactional_email` on
	 * every e-mail action instead of `send_transactional_email`. Re-taken here the same way — the
	 * synchronous dispatcher is unhooked from every action it sits on, the filter answers true, and
	 * WooCommerce's own method hooks its queue. WP_UnitTestCase's hook restore undoes all of it.
	 *
	 * @return void
	 */
	private function defer_transactional_emails(): void {
		$synchronous = [ 'WC_Emails', 'send_transactional_email' ];
		$actions     = [];

		foreach ( array_keys( $GLOBALS['wp_filter'] ) as $hook ) {
			if ( false !== has_action( (string) $hook, $synchronous ) ) {
				$actions[] = (string) $hook;
			}
		}

		$this->assertContains( 'woocommerce_order_status_pending_to_processing', $actions, 'WooCommerce dispatches its e-mails synchronously until this test switches it' );

		foreach ( $actions as $hook ) {
			remove_action( $hook, $synchronous, 10 );
		}

		add_filter( 'woocommerce_defer_transactional_emails', '__return_true' );

		\WC_Emails::init_transactional_emails();

		$this->assertNotFalse( has_action( 'woocommerce_order_status_pending_to_processing', [ 'WC_Emails', 'queue_transactional_email' ] ), 'WooCommerce now queues its e-mails instead of sending them' );
	}

	/**
	 * Runs WooCommerce's deferred e-mail queue the way WooCommerce runs it in the LATER request, and
	 * says how many queued notifications it ran.
	 *
	 * WooCommerce 10.8+ (`DeferredEmailQueue`): the request's queue becomes one Action Scheduler
	 * action per notification on `shutdown` (`dispatch()`); each is run here through Action
	 * Scheduler's own runner, so the order travels as an id and is re-read from the datastore,
	 * exactly as in production. WooCommerce 8.5–10.7 (`WC_Background_Emailer`): the request's list
	 * is saved to an option on `shutdown` and a loopback request runs `handle()` over it; both are
	 * called here in turn — the save serialises the order down to its id (`WC_Data::__sleep()`) and
	 * the handler re-reads it. The two members WooCommerce keeps private are reached by reflection;
	 * nothing about the dispatch itself is hand-rolled.
	 *
	 * @return int notifications run.
	 */
	private function drain_deferred_emails(): int {
		if ( class_exists( '\Automattic\WooCommerce\Internal\Email\DeferredEmailQueue' ) ) {
			$hook = 'woocommerce_send_queued_transactional_email';

			$this->assertNotFalse( has_action( $hook ), 'WooCommerce hooked its Action Scheduler handler at boot' );

			wc_get_container()->get( \Automattic\WooCommerce\Internal\Email\DeferredEmailQueue::class )->dispatch();

			$ids = as_get_scheduled_actions(
				[
					'hook'     => $hook,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				],
				'ids'
			);

			foreach ( $ids as $id ) {
				\ActionScheduler::runner()->process_action( (int) $id, 'woodev-test' );
			}

			return count( $ids );
		}

		$emailer_property = new \ReflectionProperty( \WC_Emails::class, 'background_emailer' );

		if ( PHP_VERSION_ID < 80100 ) {
			$emailer_property->setAccessible( true );
		}

		$emailer = $emailer_property->getValue();

		$this->assertInstanceOf( \WC_Background_Emailer::class, $emailer, 'WooCommerce built its background emailer when it switched to deferral' );

		$data_property = new \ReflectionProperty( $emailer, 'data' );
		$handle        = new \ReflectionMethod( $emailer, 'handle' );

		if ( PHP_VERSION_ID < 80100 ) {
			$data_property->setAccessible( true );
			$handle->setAccessible( true );
		}

		$queued = count( (array) $data_property->getValue( $emailer ) );

		$emailer->save(); // What `shutdown` → `dispatch_queue()` does before its loopback request.
		$data_property->setValue( $emailer, [] ); // So this process' own shutdown has nothing left to dispatch.

		$handle->invoke( $emailer ); // The loopback request's work.

		return $queued;
	}

	/**
	 * LOSES WooCommerce's deferred e-mail queue the way a request that dies before `shutdown` loses
	 * it — nothing of what the transition queued is ever dispatched — and says how many notifications
	 * were lost. The in-memory list is emptied through the same private member the drain reads
	 * ({@see drain_deferred_emails()}); no option, no Action Scheduler action is ever written.
	 *
	 * @return int notifications lost.
	 */
	private function lose_deferred_emails(): int {
		if ( class_exists( '\Automattic\WooCommerce\Internal\Email\DeferredEmailQueue' ) ) {
			$queue = wc_get_container()->get( \Automattic\WooCommerce\Internal\Email\DeferredEmailQueue::class );
			$list  = new \ReflectionProperty( $queue, 'queue' );

			if ( PHP_VERSION_ID < 80100 ) {
				$list->setAccessible( true );
			}

			$lost = count( (array) $list->getValue( $queue ) );
			$list->setValue( $queue, [] );

			return $lost;
		}

		$emailer_property = new \ReflectionProperty( \WC_Emails::class, 'background_emailer' );

		if ( PHP_VERSION_ID < 80100 ) {
			$emailer_property->setAccessible( true );
		}

		$emailer = $emailer_property->getValue();

		$this->assertInstanceOf( \WC_Background_Emailer::class, $emailer, 'WooCommerce built its background emailer when it switched to deferral' );

		$data_property = new \ReflectionProperty( $emailer, 'data' );

		if ( PHP_VERSION_ID < 80100 ) {
			$data_property->setAccessible( true );
		}

		$lost = count( (array) $data_property->getValue( $emailer ) );
		$data_property->setValue( $emailer, [] );

		return $lost;
	}

	/**
	 * The mute entries an order carries, read fresh from the datastore.
	 *
	 * @param int $order_id order id.
	 * @return array<int, array{transition: string, created_at: int}>
	 */
	private function armed_entries( int $order_id ): array {
		$stored = $this->fresh( $order_id )->get_meta( Order_Editor::NEW_ORDER_EMAIL_MUTE_META );

		if ( '' === $stored ) {
			return [];
		}

		$this->assertIsArray( $stored, 'the mute meta is a list of entries' );

		foreach ( $stored as $entry ) {
			$this->assertIsArray( $entry );
			$this->assertArrayHasKey( 'transition', $entry );
			$this->assertArrayHasKey( 'created_at', $entry );
		}

		return array_values( $stored );
	}

	/**
	 * Asserts that nothing is left on the order that could mute a later «New order».
	 *
	 * @param int    $order_id order id.
	 * @param string $message  why.
	 * @return void
	 */
	private function assert_not_armed( int $order_id, string $message ): void {
		$this->assertSame( '', $this->fresh( $order_id )->get_meta( Order_Editor::NEW_ORDER_EMAIL_MUTE_META ), $message );
	}

	/**
	 * Ages every mute entry of an order past {@see Order_Editor::NEW_ORDER_EMAIL_MUTE_TTL}, as if the
	 * edit that wrote it had happened more than a day ago — the clock cannot be moved, so the entries
	 * are moved instead, through the very datastore write an old edit would have left behind.
	 *
	 * @param int $order_id order id.
	 * @return void
	 */
	private function age_mute_entries( int $order_id ): void {
		$entries = $this->armed_entries( $order_id );

		$this->assertNotSame( [], $entries, 'there is an entry to age' );

		foreach ( $entries as &$entry ) {
			$entry['created_at'] = time() - Order_Editor::NEW_ORDER_EMAIL_MUTE_TTL - 1;
		}

		unset( $entry );

		$order = $this->fresh( $order_id );
		$order->update_meta_data( Order_Editor::NEW_ORDER_EMAIL_MUTE_META, $entries );
		$order->save();
	}

	// -------------------------------------------------------------------------
	// The other request — a second database connection, for the edit lock (#981 round 4)
	// -------------------------------------------------------------------------

	/**
	 * A second connection to the test database: «the other request», as far as MySQL's named locks
	 * go — a name held by one connection is refused to every other until the holder releases it or
	 * disconnects. It sees none of this test's uncommitted rows and writes none; it only holds and
	 * releases the lock. Closed by {@see tearDown()}.
	 *
	 * @return \mysqli
	 */
	private function other_request(): \mysqli {
		if ( ! class_exists( '\mysqli' ) ) {
			$this->markTestSkipped( 'mysqli is not available in this PHP' );
		}

		$host = (string) DB_HOST;
		$port = null;

		if ( false !== strpos( $host, ':' ) ) {
			[ $host, $port ] = explode( ':', $host, 2 );
			$port            = is_numeric( $port ) ? (int) $port : null;
		}

		$connection = null === $port
			? new \mysqli( $host, DB_USER, DB_PASSWORD, DB_NAME )
			: new \mysqli( $host, DB_USER, DB_PASSWORD, DB_NAME, $port );

		$this->assertSame( 0, $connection->connect_errno, 'the other request connected to the test database' );

		$this->connections[] = $connection;

		return $connection;
	}

	/**
	 * Runs one lock function on the other request's connection and answers what MySQL answered.
	 *
	 * @param \mysqli $other    the other request.
	 * @param string  $call     the function call, `%s` standing for the order's quoted lock name —
	 *                          e.g. `GET_LOCK(%s, 0)`.
	 * @param int     $order_id the order.
	 * @return string MySQL's answer: '1' done, '0' not, '' NULL.
	 */
	private function lock_call( \mysqli $other, string $call, int $order_id ): string {
		$result = $other->query( 'SELECT ' . sprintf( $call, "'" . $other->real_escape_string( Order_Editor::update_lock_name( $order_id ) ) . "'" ) );

		$this->assertInstanceOf( \mysqli_result::class, $result );

		$row = $result->fetch_row();

		return (string) ( $row[0] ?? '' );
	}

	/**
	 * Makes the other request hold the order's edit lock, and proves it does.
	 *
	 * @param int $order_id the order.
	 * @return \mysqli the holder.
	 */
	private function hold_edit_lock_elsewhere( int $order_id ): \mysqli {
		$other = $this->other_request();

		$this->assertSame( '1', $this->lock_call( $other, 'GET_LOCK(%s, 0)', $order_id ), 'the other request holds the order\'s edit lock' );

		return $other;
	}

	// -------------------------------------------------------------------------
	// create
	// -------------------------------------------------------------------------

	/**
	 * The order's data lands as the wizard sent it: lines at the EDITED price, ONE shipping line at
	 * the chosen rate with its meta, addresses, payment method, status, created-by-admin.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_writes_the_order_as_the_wizard_sent_it( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->fresh( $this->create( $this->payload() )->get_id() );

		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'admin', $order->get_created_via() );
		$this->assertSame( 0, $order->get_customer_id(), 'a guest by default (O11)' );

		$this->assertSame( 'Иван', $order->get_billing_first_name() );
		$this->assertSame( 'Москва', $order->get_shipping_city(), 'an empty shipping address means ship-to-billing' );
		$this->assertSame( 'ivan-968@example.test', $order->get_billing_email() );

		$this->assertSame( 'cod', $order->get_payment_method() );
		$this->assertNotSame( '', $order->get_payment_method_title() );

		$lines = array_values( $order->get_items( 'line_item' ) );

		$this->assertCount( 1, $lines );
		$this->assertSame( $this->product_id, (int) $lines[0]->get_product_id() );
		$this->assertSame( 2, (int) $lines[0]->get_quantity() );
		$this->assertEquals( 300, (float) $lines[0]->get_total(), 'the manager edited the unit price to 150 (O8)' );

		$shipping = array_values( $order->get_shipping_methods() );

		$this->assertCount( 1, $shipping );
		$this->assertSame( self::REALISTIC_COURIER, $shipping[0]->get_method_id() );
		$this->assertSame( 6, (int) $shipping[0]->get_instance_id() );
		$this->assertSame( 'Курьер', $shipping[0]->get_name() );
		$this->assertEquals( 350, (float) $shipping[0]->get_total() );
		$this->assertSame( '2-3 дня', $shipping[0]->get_meta( 'delivery_time', true ), 'the rate meta is copied onto the line' );

		// No tax is configured on the test site: the total is items + delivery.
		$this->assertEquals( 650, (float) $order->get_total() );
	}

	/**
	 * The order is a row of the orders page by the very rule a customer's is: a valid marker, a
	 * resolvable owner, a place in the carrier's list — and only there.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_created_order_is_a_row_of_the_orders_page( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->fresh( $this->create( $this->payload() )->get_id() );

		$this->assertTrue( Order_Marker::is_valid_value( $order->get_meta( self::REALISTIC_MARKER, true ) ) );
		$this->assertSame( 'realistic', Orders_Registry::instance()->resolve_provider_for_order( $order )->get_id() );
		$this->assertContains( $order->get_id(), $this->listed_ids( 'realistic' ) );
		$this->assertNotContains( $order->get_id(), $this->listed_ids( 'test_shipping' ) );
		$this->assertContains( $order->get_id(), $this->listed_ids( '' ) );
	}

	/**
	 * A pickup tariff persists the point through the carrier's own checkout handler — the slot
	 * field a checkout would have written — and a courier tariff leaves no slot at all (#745).
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_persists_the_pickup_point_only_for_a_pickup_tariff( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$this->assertContains( self::REALISTIC_PICKUP, Checkout_Config::pickup_method_ids(), 'the fixture must declare the pickup method' );

		$pickup  = $this->fresh( $this->create( $this->pickup_payload() )->get_id() );
		$courier = $this->fresh( $this->create( $this->payload() )->get_id() );

		$this->assertSame( self::REALISTIC_POINT, $pickup->get_meta( self::REALISTIC_SLOT, true ) );
		$this->assertSame( '', $courier->get_meta( self::REALISTIC_SLOT, true ) );
		$this->assertTrue( Order_Marker::is_valid_value( $pickup->get_meta( self::REALISTIC_MARKER, true ) ) );
	}

	/**
	 * The second fixture carrier goes through the same code with ITS OWN handler and marker.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_works_for_the_other_carrier_with_its_own_marker_and_slot( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->fresh(
			$this->create(
				$this->payload(
					[
						'shipping_line' => [
							'method_id'   => self::TEST_METHOD,
							'instance_id' => 3,
							'label'       => 'Тестовая доставка',
							'cost'        => '100',
						],
						'pickup_point'  => [ 'id' => self::TEST_POINT ],
					]
				)
			)->get_id()
		);

		$this->assertSame( self::TEST_POINT, $order->get_meta( self::TEST_SLOT, true ) );
		$this->assertTrue( Order_Marker::is_valid_value( $order->get_meta( self::TEST_MARKER, true ) ) );
		$this->assertSame( '', (string) $order->get_meta( self::REALISTIC_MARKER, true ), "another carrier's marker never lands here" );
		$this->assertSame( 'test_shipping', Orders_Registry::instance()->resolve_provider_for_order( $order )->get_id() );
	}

	/**
	 * «New order» goes out once through WooCommerce's own status transition — an explicit extra
	 * trigger would double-send (#962 I0, contradiction 1) — and a pending order sends none.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_the_new_order_email_is_sent_once_for_processing_and_not_for_pending( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$emails = $this->count_sent_email( 'new_order' );

		$this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$this->assertSame( 0, $emails['count'], 'a pending order sends nothing, like a checkout order awaiting payment' );

		$this->create( $this->payload( [ 'status' => 'processing' ] ) );
		$this->assertSame( 1, $emails['count'], 'once, from WooCommerce\'s own pending→processing notification' );
	}

	/**
	 * An EDIT sends no «New order» (spec C4): an allowed pending→processing update runs the same
	 * WooCommerce transition that announces a created order, so the editor mutes that one e-mail for
	 * that order while it runs — and nothing else: a later create still sends it.
	 *
	 * Counted on the e-mail actually going out ({@see count_sent_email()}). Neither of the two
	 * filters is a send: `woocommerce_email_enabled_new_order` fires for a muted e-mail too, and
	 * `woocommerce_email_recipient_new_order` is read by WooCommerce 10.9+'s EmailLogger for the
	 * e-mail it did NOT send (traced for #981), and twice per real send on WooCommerce 8.5.1.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_update_from_pending_to_processing_sends_no_new_order_email( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$sent = $this->count_sent_email( 'new_order' );

		$pending = $this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$this->assertSame( 0, $sent['count'], 'a pending order sends nothing' );

		$updated = ( new Order_Editor() )->update( $pending->get_id(), $this->payload( [ 'status' => 'processing' ] ) );

		$this->assertInstanceOf( \WC_Order::class, $updated );
		$this->assertSame( 'processing', $this->fresh( $pending->get_id() )->get_status(), 'the transition itself still happens' );
		$this->assertSame( 0, $sent['count'], 'an edit sends no «New order»' );
		$this->assert_not_armed( $pending->get_id(), 'the synchronous dispatch consumed the marker: nothing is left on the order to mute a later e-mail' );

		$this->create( $this->payload( [ 'status' => 'processing' ] ) );
		$this->assertSame( 1, $sent['count'], 'the mute was scoped to the edit: a created order still announces itself' );
	}

	/**
	 * The mute must hold when WooCommerce DEFERS its transactional e-mails (#981 round 2).
	 *
	 * With `woocommerce_defer_transactional_emails` on, a status transition only QUEUES its
	 * `…_notification`; WooCommerce dispatches it in a later request — a loopback of
	 * `WC_Background_Emailer` up to WooCommerce 10.7, an Action Scheduler action since 10.8 — long
	 * after the editor's call has returned. A mute that lives only as long as the edit is gone by then
	 * and the queued «New order» goes out. So the edit is made under deferral, the queue is drained the
	 * way WooCommerce drains it ({@see drain_deferred_emails()}), and only then is the count read. The
	 * customer's own e-mail is counted alongside as proof that the drain delivered anything at all: a
	 * queue that never ran would show the same zero for «New order» (the false green of round 1).
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_update_sends_no_new_order_email_when_woocommerce_defers_its_emails( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->defer_transactional_emails();

		$new_order = $this->count_sent_email( 'new_order' );
		$customer  = $this->count_sent_email( 'customer_processing_order' );

		$pending = $this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$updated = ( new Order_Editor() )->update( $pending->get_id(), $this->payload( [ 'status' => 'processing' ] ) );

		$this->assertInstanceOf( \WC_Order::class, $updated );
		$this->assertSame( 'processing', $this->fresh( $pending->get_id() )->get_status(), 'the transition itself still happens' );
		$this->assertSame( 0, $customer['count'], 'deferred: nothing leaves during the request that edits the order' );

		$this->assertGreaterThan( 0, $this->drain_deferred_emails(), 'the edit\'s notification was queued, and the drain ran it' );
		$this->assertSame( 1, $customer['count'], 'the drain delivered the customer\'s e-mail, so a zero below is a mute and not a dead queue' );
		$this->assertSame( 0, $new_order['count'], 'an edit sends no «New order» — not after the deferred dispatch either' );
		$this->assert_not_armed( $pending->get_id(), 'the deferred dispatch consumed the marker: nothing is left on the order to mute a later e-mail' );

		$this->create( $this->payload( [ 'status' => 'processing' ] ) );
		$this->drain_deferred_emails();
		$this->assertSame( 1, $new_order['count'], 'the mute was scoped to the edit: a created order still announces itself through the deferred dispatch' );
	}

	/**
	 * Two edits interleaved with the deferred dispatch must each keep their own mute (#981 round 3,
	 * the critic's blocker on round 2).
	 *
	 * Under deferral edit A (pending→processing) only QUEUES its notification. Before WooCommerce
	 * runs the queue, edit B (processing→pending — allowed, and a transition WooCommerce sends no
	 * e-mail for) arms and un-arms the order in turn. Round 2 kept ONE marker per order, so B's edit
	 * overwrote A's and then dropped it, and A's queued «New order» went out when the queue ran.
	 * Now each edit appends its own entry: B's is dropped as unneeded, A's waits for its dispatch.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_edit_between_a_queued_edit_and_its_dispatch_does_not_unmute_it( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->defer_transactional_emails();

		$new_order = $this->count_sent_email( 'new_order' );
		$customer  = $this->count_sent_email( 'customer_processing_order' );

		$pending = $this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$editor  = new Order_Editor();

		$this->assertInstanceOf( \WC_Order::class, $editor->update( $pending->get_id(), $this->payload( [ 'status' => 'processing' ] ) ) );
		$this->assertInstanceOf( \WC_Order::class, $editor->update( $pending->get_id(), $this->payload( [ 'status' => 'pending' ] ) ) );

		$this->assertSame( 'pending', $this->fresh( $pending->get_id() )->get_status(), 'both edits happened' );
		$this->assertSame( [ 'woocommerce_order_status_pending_to_processing' ], array_column( $this->armed_entries( $pending->get_id() ), 'transition' ), 'edit A\'s mute waits for its queued dispatch; edit B\'s transition sends nothing and left no entry' );
		$this->assertSame( 0, $customer['count'], 'deferred: nothing leaves during the requests that edit the order' );

		$this->assertGreaterThan( 0, $this->drain_deferred_emails(), 'edit A\'s notification was queued, and the drain ran it' );
		$this->assertSame( 1, $customer['count'], 'the drain delivered the customer\'s e-mail, so a zero below is a mute and not a dead queue' );
		$this->assertSame( 0, $new_order['count'], 'edit A\'s «New order» stays muted although edit B ran in between' );
		$this->assert_not_armed( $pending->get_id(), 'the dispatch consumed edit A\'s entry: nothing is left on the order' );
	}

	/**
	 * Two queued edits of the SAME transition each mute their own notification (#981 round 3).
	 *
	 * pending→processing, back to pending, pending→processing again — all before WooCommerce runs
	 * its queue — leaves two `pending_to_processing` notifications queued and two entries armed.
	 * Each dispatch consumes one; neither «New order» goes out, and nothing is left afterwards.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_two_queued_edits_of_the_same_transition_are_both_muted( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->defer_transactional_emails();

		$new_order = $this->count_sent_email( 'new_order' );
		$customer  = $this->count_sent_email( 'customer_processing_order' );

		$pending = $this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$editor  = new Order_Editor();

		foreach ( [ 'processing', 'pending', 'processing' ] as $status ) {
			$this->assertInstanceOf( \WC_Order::class, $editor->update( $pending->get_id(), $this->payload( [ 'status' => $status ] ) ) );
		}

		$this->assertSame( 'processing', $this->fresh( $pending->get_id() )->get_status(), 'all three edits happened' );
		$this->assertCount( 2, $this->armed_entries( $pending->get_id() ), 'one entry per queued pending→processing edit' );

		$this->assertSame( 2, $this->drain_deferred_emails(), 'both pending→processing notifications were queued, and the drain ran them' );
		$this->assertSame( 2, $customer['count'], 'the drain delivered both customer e-mails' );
		$this->assertSame( 0, $new_order['count'], 'neither edit\'s «New order» goes out' );
		$this->assert_not_armed( $pending->get_id(), 'both entries were consumed: nothing is left on the order' );
	}

	/**
	 * An entry whose dispatch was LOST stops muting after {@see Order_Editor::NEW_ORDER_EMAIL_MUTE_TTL}
	 * (#981 round 3, the critic's major on round 2).
	 *
	 * The edit's notification is queued and then lost (the request dies before WooCommerce's
	 * `shutdown`), so its entry is never consumed. A day later the order legitimately makes the
	 * very same transition outside the editor — the customer pays — and WooCommerce must announce it:
	 * the stale entry is pruned instead of muting, and the key goes with it.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_stale_entry_from_a_lost_queue_does_not_mute_a_later_transition( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->defer_transactional_emails();

		$new_order = $this->count_sent_email( 'new_order' );
		$customer  = $this->count_sent_email( 'customer_processing_order' );

		$pending = $this->create( $this->payload( [ 'status' => 'pending' ] ) );

		$this->assertInstanceOf( \WC_Order::class, ( new Order_Editor() )->update( $pending->get_id(), $this->payload( [ 'status' => 'processing' ] ) ) );
		$this->assertGreaterThan( 0, $this->lose_deferred_emails(), 'the edit\'s notification was queued — and is now lost for good' );
		$this->assertCount( 1, $this->armed_entries( $pending->get_id() ), 'the entry waits for a dispatch that will never come' );

		$this->age_mute_entries( $pending->get_id() );

		$order = $this->fresh( $pending->get_id() );
		$order->update_status( 'pending' );
		$order->update_status( 'processing' ); // Not an edit: the same transition, from outside the editor.

		$this->assertSame( 1, $this->drain_deferred_emails(), 'the repeat transition\'s notification was queued, and the drain ran it' );
		$this->assertSame( 1, $customer['count'], 'the drain delivered the customer\'s e-mail' );
		$this->assertSame( 1, $new_order['count'], 'a day-old entry of a lost queue mutes nothing: the legitimate «New order» goes out' );
		$this->assert_not_armed( $pending->get_id(), 'the stale entry was pruned and the key deleted' );
	}

	// -------------------------------------------------------------------------
	// #981 round 4 — one save of an order at a time
	// -------------------------------------------------------------------------

	/**
	 * A save of an order that another request is saving is refused — 409, nothing written — and
	 * goes through once that request is done (#981 round 4, the critic's blocker on round 3).
	 *
	 * A double submit, or two tabs, used to run two pending→processing saves of one order at once:
	 * each read no mute entry, each armed its own and ran the transition, the later meta write
	 * dropped the first entry, and under deferral WooCommerce's queue sent one «New order». Now an
	 * update holds the order's edit lock — a MySQL named lock, one holder per name server-wide —
	 * for its whole duration. Here «the other request» is a second connection holding that name:
	 * a save that cannot get it within its timeout answers 409 and changes nothing; the same save
	 * proceeds the moment the holder lets go; and the editor releases its own lock when it is done,
	 * so the next request can take it at once.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_save_of_an_order_another_request_is_saving_is_refused_with_409_and_changes_nothing( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$sent        = $this->count_sent_email( 'new_order' );
		$transitions = $this->count_hook( 'woocommerce_order_status_pending_to_processing' );

		$pending = $this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$other   = $this->hold_edit_lock_elsewhere( $pending->get_id() );

		$editor  = new Order_Editor( null, null, 1 ); // One second: the test's timeout, not production's ten.
		$started = microtime( true );
		$result  = $editor->update( $pending->get_id(), $this->payload( [ 'status' => 'processing' ] ) );
		$waited  = microtime( true ) - $started;

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 409, $result->get_error_data()['status'], 'the transport contract\'s «try again»' );
		$this->assertSame( 'woodev_shipping_order_busy', $result->get_error_code() );
		$this->assertGreaterThanOrEqual( 0.9, $waited, 'the save waited its whole timeout for the lock before giving up' );

		$this->assertSame( 'pending', $this->fresh( $pending->get_id() )->get_status(), 'a refused save writes nothing' );
		$this->assertSame( 0, $transitions['count'], 'a refused save runs no transition' );
		$this->assertSame( 0, $sent['count'] );
		$this->assert_not_armed( $pending->get_id(), 'a refused save arms nothing' );

		$this->assertSame( '1', $this->lock_call( $other, 'RELEASE_LOCK(%s)', $pending->get_id() ), 'the other request is done' );

		$updated = $editor->update( $pending->get_id(), $this->payload( [ 'status' => 'processing' ] ) );

		$this->assertInstanceOf( \WC_Order::class, $updated, $updated instanceof \WP_Error ? $updated->get_error_message() : '' );
		$this->assertSame( 'processing', $this->fresh( $pending->get_id() )->get_status(), 'the same save goes through once the lock is free' );
		$this->assertSame( 1, $transitions['count'] );
		$this->assertSame( 0, $sent['count'], 'an edit sends no «New order»' );
		$this->assert_not_armed( $pending->get_id(), 'the synchronous dispatch consumed the entry' );

		$this->assertSame( '1', $this->lock_call( $other, 'GET_LOCK(%s, 0)', $pending->get_id() ), 'the editor released its lock when it was done: the next request takes it at once' );
	}

	/**
	 * A save does not give up on a busy order: it WAITS for the save in flight and proceeds the
	 * moment that one releases the lock — within the ten seconds of the default timeout, not
	 * after them (#981 round 4).
	 *
	 * The other request holds the lock and lets go after one second, from a query it runs
	 * asynchronously so this process is free to make the save that has to wait.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_save_waits_for_the_save_in_flight_and_proceeds_once_it_is_released( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$sent = $this->count_sent_email( 'new_order' );

		$pending = $this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$other   = $this->hold_edit_lock_elsewhere( $pending->get_id() );

		$name = "'" . $other->real_escape_string( Order_Editor::update_lock_name( $pending->get_id() ) ) . "'";

		$this->assertTrue( $other->query( 'SELECT IF(SLEEP(1) = 0, RELEASE_LOCK(' . $name . '), NULL)', MYSQLI_ASYNC ), 'the other request will let go in a second' );

		$started = microtime( true );
		$result  = ( new Order_Editor() )->update( $pending->get_id(), $this->payload( [ 'status' => 'processing' ] ) );
		$waited  = microtime( true ) - $started;

		$this->assertInstanceOf( \WC_Order::class, $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertGreaterThanOrEqual( 0.9, $waited, 'the save waited for the release' );
		$this->assertLessThan( Order_Editor::UPDATE_LOCK_TIMEOUT, $waited, 'and went through as soon as it came, not when the timeout ran out' );

		$released = $other->reap_async_query();

		$this->assertInstanceOf( \mysqli_result::class, $released );
		$this->assertSame( '1', (string) $released->fetch_row()[0], 'it was the other request\'s release that let the save through' );

		$this->assertSame( 'processing', $this->fresh( $pending->get_id() )->get_status() );
		$this->assertSame( 0, $sent['count'], 'an edit sends no «New order»' );
		$this->assert_not_armed( $pending->get_id(), 'the synchronous dispatch consumed the entry' );
	}

	/**
	 * The save that waited acts on the RESULT of the save it waited for, not on the state both
	 * started from (#981 round 4): the order is read only once the lock is held.
	 *
	 * The critic's race, end to end, under deferral: the same pending→processing save twice — a
	 * double submit. The first save completes (lock taken and released) right before the second's
	 * lock is granted, through the editor's lock seam; the second then reads an order that is
	 * already processing, runs no transition, arms nothing. When WooCommerce's queue runs, ONE
	 * notification is dispatched and the first save's entry mutes it. Before the lock the second
	 * save read `pending` too, armed a second entry, ran the transition again and overwrote the
	 * first save's list with its own — and the queue then sent one «New order».
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_save_that_waited_acts_on_the_result_of_the_save_it_waited_for( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->defer_transactional_emails();

		$new_order   = $this->count_sent_email( 'new_order' );
		$customer    = $this->count_sent_email( 'customer_processing_order' );
		$transitions = $this->count_hook( 'woocommerce_order_status_pending_to_processing' );

		$pending = $this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$payload = $this->payload( [ 'status' => 'processing' ] );
		$first   = null;

		$first_save = function ( int $order_id ) use ( $payload, &$first ): void {
			$first = ( new Order_Editor() )->update( $order_id, $payload );
		};

		$waiting = new class( $first_save ) extends Order_Editor {
			/** @var \Closure what the other request does before this one's lock is granted. */
			private $first_save;

			/**
			 * @param \Closure $first_save the other request's save.
			 */
			public function __construct( \Closure $first_save ) {
				parent::__construct();

				$this->first_save = $first_save;
			}

			/**
			 * The seam: the other request's save completes — lock taken and released — right
			 * before this request's lock is granted.
			 *
			 * @param int $order_id the order.
			 * @return bool
			 */
			protected function lock_order( int $order_id ): bool {
				( $this->first_save )( $order_id );

				return parent::lock_order( $order_id );
			}
		};

		$second = $waiting->update( $pending->get_id(), $payload );

		$this->assertInstanceOf( \WC_Order::class, $first, 'the first save ran, and went through' );
		$this->assertInstanceOf( \WC_Order::class, $second, $second instanceof \WP_Error ? $second->get_error_message() : '' );
		$this->assertSame( 'processing', $this->fresh( $pending->get_id() )->get_status() );
		$this->assertSame( 1, $transitions['count'], 'the transition ran once, in the first save: the second read an order already processing' );
		$this->assertCount( 1, $this->armed_entries( $pending->get_id() ), 'one entry, the first save\'s, waits for the deferred dispatch; the second save armed nothing' );

		$this->assertSame( 1, $this->drain_deferred_emails(), 'one notification was queued, and the drain ran it' );
		$this->assertSame( 1, $customer['count'], 'the drain delivered the customer\'s e-mail' );
		$this->assertSame( 0, $new_order['count'], 'a double submit sends no «New order»' );
		$this->assert_not_armed( $pending->get_id(), 'the dispatch consumed the first save\'s entry; nothing is left' );
	}

	/**
	 * Stock follows WooCommerce's own status transition on create.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_reduces_stock_through_the_status_transition_only( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$this->assertSame( 10, $this->stock( $this->product_id ), 'a pending order does not touch stock' );

		$this->create( $this->payload( [ 'status' => 'processing' ] ) );
		$this->assertSame( 8, $this->stock( $this->product_id ) );
	}

	/**
	 * The three checkout hooks are CHECKOUT-ONLY; an admin save fires its own.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_admin_save_fires_its_own_hook_and_none_of_the_checkout_ones( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$prefix = \Woodev_Realistic_Shipping_Plugin::instance()->get_checkout_handler()->plugin_id();

		$admin    = $this->count_hook( 'woodev_shipping_' . $prefix . '_admin_order_saved' );
		$checkout = [
			$this->count_hook( 'woodev_shipping_' . $prefix . '_checkout_field_saved' ),
			$this->count_hook( 'woodev_shipping_' . $prefix . '_checkout_data_saved' ),
			$this->count_hook( 'woodev_shipping_' . $prefix . '_checkout_processed' ),
		];

		$this->create( $this->pickup_payload() );

		$this->assertSame( 1, $admin['count'] );

		foreach ( $checkout as $counter ) {
			$this->assertSame( 0, $counter['count'], 'plugins listening to the checkout hooks must never see an admin save' );
		}
	}

	/**
	 * «Создать аккаунт» (O11): WooCommerce creates the user; the order belongs to it.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_account_creates_the_user_and_links_the_order( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->fresh( $this->create( $this->payload( [ 'customer' => [ 'create_account' => true ] ] ) )->get_id() );
		$user  = get_user_by( 'email', 'ivan-968@example.test' );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( (int) $user->ID, $order->get_customer_id() );
	}

	/**
	 * A payload the validator refuses leaves NO order behind — and answers 422 with the problems as
	 * data.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_invalid_payload_creates_nothing_and_answers_422( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$before = count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) );

		$result = ( new Order_Editor() )->create( $this->payload( [ 'items' => [] ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertContains( 'items', array_column( $result->get_error_data()['errors'], 'field' ) );
		$this->assertSame( $before, count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ) );
	}

	/**
	 * A foreign shipping method is refused (O12) — it would not appear on the page.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_foreign_shipping_method_is_refused( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$result = ( new Order_Editor() )->create(
			$this->payload(
				[
					'shipping_line' => [
						'method_id'   => 'flat_rate',
						'instance_id' => 1,
						'cost'        => '10',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains( 'shipping_line.method_id', array_column( $result->get_error_data()['errors'], 'field' ) );
	}

	// -------------------------------------------------------------------------
	// update
	// -------------------------------------------------------------------------

	/**
	 * An edit replaces lines, the shipping line, addresses and the persisted fields IN PLACE: an
	 * edited line keeps its identity, the marker stays valid and the order stays a row of the page.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_update_edits_the_order_in_place( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$line_id = (int) array_key_first( $created->get_items( 'line_item' ) );

		$updated = ( new Order_Editor() )->update(
			$created->get_id(),
			$this->pickup_payload(
				[
					'status'  => 'pending',
					'items'   => [
						[
							'item_id'    => $line_id,
							'product_id' => $this->product_id,
							'quantity'   => 3,
							'price'      => '120',
						],
					],
					'billing' => [
						'country' => 'RU',
						'city'    => 'Санкт-Петербург',
						'phone'   => '+79990000000',
					],
				]
			)
		);

		$this->assertInstanceOf( \WC_Order::class, $updated, $updated instanceof \WP_Error ? $updated->get_error_message() : '' );

		$order = $this->fresh( $created->get_id() );
		$lines = $order->get_items( 'line_item' );

		$this->assertCount( 1, $lines );
		$this->assertArrayHasKey( $line_id, $lines, 'the edited line keeps its identity' );
		$this->assertSame( 3, (int) $lines[ $line_id ]->get_quantity() );
		$this->assertEquals( 360, (float) $lines[ $line_id ]->get_total() );

		$shipping = array_values( $order->get_shipping_methods() );

		$this->assertCount( 1, $shipping, 'the shipping line is REPLACED, never added to' );
		$this->assertSame( self::REALISTIC_PICKUP, $shipping[0]->get_method_id() );
		$this->assertEquals( 200, (float) $shipping[0]->get_total() );

		$this->assertSame( 'Санкт-Петербург', $order->get_billing_city() );
		$this->assertSame( self::REALISTIC_POINT, $order->get_meta( self::REALISTIC_SLOT, true ) );
		$this->assertEquals( 560, (float) $order->get_total() );

		$this->assertTrue( Order_Marker::is_valid_value( $order->get_meta( self::REALISTIC_MARKER, true ) ) );
		$this->assertContains( $order->get_id(), $this->listed_ids( 'realistic' ) );
	}

	/**
	 * Leaving a pickup tariff leaves no stale point behind — the slot the persistence core skips
	 * is removed by the editor (#745, on the edit path).
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_update_from_pickup_to_courier_removes_the_stale_point( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->pickup_payload( [ 'status' => 'pending' ] ) );

		$this->assertSame( self::REALISTIC_POINT, $this->fresh( $created->get_id() )->get_meta( self::REALISTIC_SLOT, true ) );

		$updated = ( new Order_Editor() )->update( $created->get_id(), $this->payload( [ 'status' => 'pending' ] ) );

		$this->assertInstanceOf( \WC_Order::class, $updated );
		$this->assertSame( '', (string) $this->fresh( $created->get_id() )->get_meta( self::REALISTIC_SLOT, true ) );
	}

	/**
	 * Moving an order to ANOTHER carrier moves its marker: the old one is removed, the new one is
	 * written, and the order is listed under the new carrier only (an order carries one marker, #928).
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_update_to_another_carrier_moves_the_marker( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->payload( [ 'status' => 'pending' ] ) );

		$updated = ( new Order_Editor() )->update(
			$created->get_id(),
			$this->payload(
				[
					'status'        => 'pending',
					'shipping_line' => [
						'method_id'   => self::TEST_METHOD,
						'instance_id' => 3,
						'label'       => 'Тестовая доставка',
						'cost'        => '100',
					],
					'pickup_point'  => [ 'id' => self::TEST_POINT ],
				]
			)
		);

		$this->assertInstanceOf( \WC_Order::class, $updated );

		$order = $this->fresh( $created->get_id() );

		$this->assertSame( '', (string) $order->get_meta( self::REALISTIC_MARKER, true ), 'the previous carrier\'s marker is gone' );
		$this->assertTrue( Order_Marker::is_valid_value( $order->get_meta( self::TEST_MARKER, true ) ) );
		$this->assertSame( 'test_shipping', Orders_Registry::instance()->resolve_provider_for_order( $order )->get_id() );
		$this->assertContains( $order->get_id(), $this->listed_ids( 'test_shipping' ) );
		$this->assertNotContains( $order->get_id(), $this->listed_ids( 'realistic' ) );
	}

	/**
	 * An order with no shipping address of its own delivers to its billing one; the wizard hands that copy back
	 * in step ②, and an untouched «Сохранить» must not turn it into a stored shipping address (m4). A shipping
	 * address that really differs is still written.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_untouched_update_of_an_order_without_a_shipping_address_does_not_write_one( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->payload( [ 'status' => 'pending' ] ) );

		// An order made at checkout with «ship to billing»: nothing in its shipping address.
		$bare = $this->fresh( $created->get_id() );
		$bare->set_address( array_fill_keys( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ], '' ), 'shipping' );
		$bare->save();

		$this->assertSame( '', $this->fresh( $created->get_id() )->get_shipping_city(), 'the fixture order has no shipping address' );

		// What the wizard sends back untouched: the billing place as the delivery place, the names from billing.
		$copy = $this->payload( [ 'status' => 'pending' ] );
		$copy['shipping'] = [
			'first_name' => 'Иван',
			'last_name'  => 'Иванов',
			'phone'      => '+79991234567',
			'country'    => 'RU',
			'city'       => 'Москва',
			'address_1'  => 'ул. Тверская, 1',
			'postcode'   => '125009',
		];

		$this->assertInstanceOf( \WC_Order::class, ( new Order_Editor() )->update( $created->get_id(), $copy ) );

		$after = $this->fresh( $created->get_id() );

		$this->assertSame( '', $after->get_shipping_city(), 'nothing was written into the shipping address' );
		$this->assertSame( '', $after->get_shipping_country() );
		$this->assertSame( 'Москва', $after->get_billing_city(), 'billing is as it was' );

		// A delivery address the manager really changed is stored.
		$changed             = $copy;
		$changed['shipping'] = array_replace( $copy['shipping'], [ 'city' => 'Казань', 'address_1' => 'ул. Баумана, 1' ] );

		$this->assertInstanceOf( \WC_Order::class, ( new Order_Editor() )->update( $created->get_id(), $changed ) );
		$this->assertSame( 'Казань', $this->fresh( $created->get_id() )->get_shipping_city() );
	}

	/**
	 * Switching CARRIER on an edit removes the previous carrier's declared field metas (m5): the set to clean
	 * up after is resolved from the order's CURRENT shipping line, not from the carrier the order moves to.
	 * The new carrier's own values are stored under its own keys.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_update_to_another_carrier_removes_the_previous_carriers_field_metas( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->payload( [ 'status' => 'pending', 'carrier_fields' => [ 'declared_value' => 5000 ] ] ) );

		$this->assertEquals( 5000, $this->fresh( $created->get_id() )->get_meta( '_woodev_realistic_declared_value', true ), 'the first carrier stored its declared field' );

		$updated = ( new Order_Editor() )->update(
			$created->get_id(),
			$this->payload(
				[
					'status'         => 'pending',
					'shipping_line'  => [
						'method_id'   => self::TEST_METHOD,
						'instance_id' => 3,
						'label'       => 'Тестовая доставка',
						'cost'        => '100',
					],
					'pickup_point'   => [ 'id' => self::TEST_POINT ],
					'carrier_fields' => [ 'declared_value' => 700 ],
				]
			)
		);

		$this->assertInstanceOf( \WC_Order::class, $updated, $updated instanceof \WP_Error ? $updated->get_error_message() . ' ' . wp_json_encode( $updated->get_error_data() ) : '' );

		$order = $this->fresh( $created->get_id() );

		$this->assertSame( '', (string) $order->get_meta( '_woodev_realistic_declared_value', true ), 'the previous carrier\'s field meta is gone' );
		$this->assertEquals( 700, $order->get_meta( '_woodev_test_shipping_declared_value', true ), 'the new carrier\'s value is stored under its own key' );
	}

	/**
	 * A line the wizard no longer sends is removed and its stock released; an edited line's stock
	 * follows WooCommerce's own adjustment — never a hand-made one.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_update_keeps_stock_in_step_with_the_lines( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->payload( [ 'status' => 'processing' ] ) );
		$line_id = (int) array_key_first( $created->get_items( 'line_item' ) );

		$this->assertSame( 8, $this->stock( $this->product_id ) );

		( new Order_Editor() )->update(
			$created->get_id(),
			$this->payload(
				[
					'status' => 'processing',
					'items'  => [
						[
							'item_id'    => $line_id,
							'product_id' => $this->product_id,
							'quantity'   => 3,
						],
					],
				]
			)
		);

		$this->assertSame( 7, $this->stock( $this->product_id ), 'quantity 2 → 3 takes one more from stock' );

		$other = new \WC_Product_Simple();
		$other->set_name( 'Другой товар' );
		$other->set_status( 'publish' );
		$other->set_regular_price( '10' );
		$other_id = $other->save();

		( new Order_Editor() )->update(
			$created->get_id(),
			$this->payload(
				[
					'status' => 'processing',
					'items'  => [
						[
							'product_id' => $other_id,
							'quantity'   => 1,
						],
					],
				]
			)
		);

		$order = $this->fresh( $created->get_id() );
		$lines = array_values( $order->get_items( 'line_item' ) );

		$this->assertCount( 1, $lines );
		$this->assertSame( $other_id, (int) $lines[0]->get_product_id() );
		$this->assertEquals( 10, (float) $lines[0]->get_total(), 'an absent price takes the product own' );
		$this->assertSame( 10, $this->stock( $this->product_id ), 'the removed line released its stock' );
	}

	/**
	 * Editing a PAID order so that its total changes leaves a private order note (O14); an unpaid
	 * one, or one whose total did not change, leaves none.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_editing_a_paid_order_that_changes_its_total_adds_a_private_note( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$edit_notes = static function ( int $order_id ): array {
			return array_filter(
				wc_get_order_notes( [ 'order_id' => $order_id ] ),
				static function ( $note ): bool {
					return false !== strpos( (string) $note->content, 'Заказ изменён из админки' );
				}
			);
		};

		$paid = $this->create( $this->payload( [ 'status' => 'processing' ] ) );

		( new Order_Editor() )->update( $paid->get_id(), $this->payload( [ 'status' => 'processing' ] ) );
		$this->assertCount( 0, $edit_notes( $paid->get_id() ), 'nothing changed the total' );

		( new Order_Editor() )->update(
			$paid->get_id(),
			$this->payload(
				[
					'status'  => 'processing',
					'items'   => [
						[
							'product_id' => $this->product_id,
							'quantity'   => 2,
							'price'      => '200',
						],
					],
				]
			)
		);
		$notes = $edit_notes( $paid->get_id() );

		$this->assertCount( 1, $notes );
		$this->assertFalse( (bool) reset( $notes )->customer_note, 'the note is private' );

		$unpaid = $this->create( $this->payload( [ 'status' => 'pending' ] ) );

		( new Order_Editor() )->update(
			$unpaid->get_id(),
			$this->payload(
				[
					'status' => 'pending',
					'items'  => [
						[
							'product_id' => $this->product_id,
							'quantity'   => 5,
							'price'      => '200',
						],
					],
				]
			)
		);

		$this->assertCount( 0, $edit_notes( $unpaid->get_id() ), 'an unpaid order has nothing to refund or top up' );
	}

	// -------------------------------------------------------------------------
	// D5: the editable-state policy, on both datastores
	// -------------------------------------------------------------------------

	/**
	 * An exported order is refused with 409 — on update AND on load — and nothing is written.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_exported_order_is_refused_with_409_and_left_untouched( bool $hpos ): void {
		$this->use_datastore( $hpos );

		// The test carrier declares a carrier-order-id meta key, so the exported order is one of ITS.
		$test = $this->create(
			$this->payload(
				[
					'status'        => 'pending',
					'shipping_line' => [
						'method_id'   => self::TEST_METHOD,
						'instance_id' => 3,
						'cost'        => '100',
					],
					'pickup_point'  => [ 'id' => self::TEST_POINT ],
				]
			)
		);

		$exported = $this->fresh( $test->get_id() );
		$exported->update_meta_data( self::TEST_EXPORTED, 'CARRIER-968' );
		$exported->save();

		$result = ( new Order_Editor() )->update( $test->get_id(), $this->payload( [ 'status' => 'pending' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );

		$loaded = ( new Order_Editor() )->load( $test->get_id() );

		$this->assertInstanceOf( \WP_Error::class, $loaded );
		$this->assertSame( 409, $loaded->get_error_data()['status'] );

		$this->assertSame( 'CARRIER-968', $this->fresh( $test->get_id() )->get_meta( self::TEST_EXPORTED, true ) );
		$this->assertSame( self::TEST_POINT, $this->fresh( $test->get_id() )->get_meta( self::TEST_SLOT, true ), 'a refused edit writes nothing' );
	}

	/**
	 * The stale-row race: an order exported AFTER the wizard loaded it is refused on the write, on a
	 * fresh read — the client's row is never trusted.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_order_exported_after_the_wizard_loaded_it_is_refused( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create(
			$this->payload(
				[
					'status'        => 'pending',
					'shipping_line' => [
						'method_id'   => self::TEST_METHOD,
						'instance_id' => 3,
						'cost'        => '100',
					],
					'pickup_point'  => [ 'id' => self::TEST_POINT ],
				]
			)
		);

		$editor = new Order_Editor();
		$prefill = $editor->load( $created->get_id() );

		$this->assertIsArray( $prefill, 'editable while nothing exported it' );

		// Another admin exports it while this manager is still typing.
		$order = $this->fresh( $created->get_id() );
		$order->update_meta_data( self::TEST_EXPORTED, 'CARRIER-968' );
		$order->save();

		$result = $editor->update( $created->get_id(), $this->payload( [ 'status' => 'pending' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Every final status refuses an edit, and never becomes a target of one.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_final_status_is_neither_edited_nor_set_by_an_edit( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->payload( [ 'status' => 'pending' ] ) );

		$to_final = ( new Order_Editor() )->update( $created->get_id(), $this->payload( [ 'status' => 'cancelled' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $to_final );
		$this->assertSame( 422, $to_final->get_error_data()['status'] );
		$this->assertContains( 'status', array_column( $to_final->get_error_data()['errors'], 'field' ) );

		$order = $this->fresh( $created->get_id() );
		$order->set_status( 'completed' );
		$order->save();

		$from_final = ( new Order_Editor() )->update( $created->get_id(), $this->payload( [ 'status' => 'pending' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $from_final );
		$this->assertSame( 409, $from_final->get_error_data()['status'] );
	}

	/**
	 * An order that is not a row of the page — a plain WooCommerce order — is a 404, never editable
	 * here (O3), and an unknown id is a 404 too.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_order_that_is_not_a_row_of_the_page_is_a_404( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$plain = wc_create_order();
		$plain->save();

		$editor = new Order_Editor();

		foreach ( [ $plain->get_id(), 99999999 ] as $id ) {
			$result = $editor->update( $id, $this->payload() );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 404, $result->get_error_data()['status'] );
			$this->assertSame( 404, $editor->load( $id )->get_error_data()['status'] );
		}
	}

	// -------------------------------------------------------------------------
	// load
	// -------------------------------------------------------------------------

	/**
	 * The prefill is what the wizard's steps hold, read back: it round-trips into an update that
	 * changes nothing.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_load_returns_the_prefill_the_steps_need( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->pickup_payload( [ 'status' => 'processing' ] ) );

		$prefill = ( new Order_Editor() )->load( $created->get_id() );

		$this->assertIsArray( $prefill );
		$this->assertSame( $created->get_id(), $prefill['order']['id'] );
		$this->assertSame( 'processing', $prefill['order']['status'] );
		$this->assertTrue( $prefill['order']['is_paid'] );
		$this->assertSame( 'realistic', $prefill['carrier'] );

		$this->assertSame( 'Москва', $prefill['billing']['city'] );
		$this->assertSame( 'ivan-968@example.test', $prefill['billing']['email'] );
		$this->assertSame( 'cod', $prefill['payment_method'] );

		$this->assertCount( 1, $prefill['items'] );
		$this->assertSame( $this->product_id, $prefill['items'][0]['product_id'] );
		$this->assertSame( 2, $prefill['items'][0]['quantity'] );
		$this->assertEquals( 150, (float) $prefill['items'][0]['price'], 'the unit price the manager set, not the line total' );

		$this->assertSame( self::REALISTIC_PICKUP, $prefill['shipping_line']['method_id'] );
		$this->assertEquals( 200, (float) $prefill['shipping_line']['cost'] );
		$this->assertSame( self::REALISTIC_POINT, $prefill['pickup_point']['id'] );
		$this->assertSame( self::REALISTIC_POINT, $prefill['fields'][ self::REALISTIC_SLOT ] );
	}

	/**
	 * Loading an order and sending its prefill straight back changes nothing — the prefill and the
	 * payload are the same shape.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_the_prefill_round_trips_into_an_update_that_changes_nothing( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->pickup_payload( [ 'status' => 'pending' ] ) );
		$before  = $this->fresh( $created->get_id() );

		$editor  = new Order_Editor();
		$prefill = $editor->load( $created->get_id() );

		$updated = $editor->update( $created->get_id(), $prefill );

		$this->assertInstanceOf( \WC_Order::class, $updated, $updated instanceof \WP_Error ? $updated->get_error_message() . ' ' . wp_json_encode( $updated->get_error_data() ) : '' );

		$after = $this->fresh( $created->get_id() );

		$this->assertEquals( (float) $before->get_total(), (float) $after->get_total() );
		$this->assertSame( $before->get_billing_city(), $after->get_billing_city() );
		$this->assertSame( $before->get_meta( self::REALISTIC_SLOT, true ), $after->get_meta( self::REALISTIC_SLOT, true ) );
		$this->assertCount( 1, $after->get_shipping_methods() );
		$this->assertCount( 1, $after->get_items( 'line_item' ) );
	}

	/**
	 * The test carrier's payload — the one whose fixture handler really exports (D6, #974).
	 *
	 * @param array<string,mixed> $override top-level keys to replace.
	 * @return array<string,mixed>
	 */
	private function test_carrier_payload( array $override = [] ): array {
		return $this->payload(
			array_replace(
				[
					'shipping_line' => [
						'method_id'   => self::TEST_METHOD,
						'instance_id' => 3,
						'label'       => 'Тестовая доставка',
						'cost'        => '100',
					],
					'pickup_point'  => [ 'id' => self::TEST_POINT ],
				],
				$override
			)
		);
	}

	/**
	 * «Сразу выгрузить перевозчику» (#974, D6): the order made by the wizard is exported through the
	 * very path the row action uses — the carrier order id lands on the order, read back fresh.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_order_created_by_the_wizard_can_be_exported_at_once( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$editor  = new Order_Editor();
		$created = $this->create( $this->test_carrier_payload() );

		$outcome = $editor->export_created( $created );

		$this->assertTrue( $outcome['success'], $outcome['message'] );

		$order = $this->fresh( $created->get_id() );

		$this->assertStringStartsWith( 'TESTCARRIER-EXPORT-', (string) $order->get_meta( self::TEST_EXPORTED, true ) );
		$this->assertSame( 'processing', $order->get_status(), 'the export does not move the order' );
	}

	/**
	 * A carrier that refuses (the fixture answers with no order id for a delivery to «Урюпинск») does
	 * not undo the order: it stays, unexported, in the status the manager chose (D6).
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_refused_export_leaves_the_created_order_in_place( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$editor  = new Order_Editor();
		$created = $this->create(
			$this->test_carrier_payload(
				[
					'shipping' => [
						'country'   => 'RU',
						'city'      => 'Урюпинск',
						'address_1' => 'ул. Ленина, 1',
					],
				]
			)
		);

		$outcome = $editor->export_created( $created );

		$this->assertFalse( $outcome['success'] );
		$this->assertNotSame( '', $outcome['message'] );

		$order = $this->fresh( $created->get_id() );

		$this->assertSame( '', (string) $order->get_meta( self::TEST_EXPORTED, true ), 'nothing was exported' );
		$this->assertSame( 'processing', $order->get_status(), 'the order was neither rolled back nor moved' );
		$this->assertNotNull( Orders_Registry::instance()->resolve_provider_for_order( $order ), 'and it is still a row of the orders page' );
	}

	/**
	 * A status the export is not offered in is refused with the framework's own reason — the carrier
	 * is never called, the order stays.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_order_in_a_status_the_export_is_not_offered_in_is_not_exported( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->test_carrier_payload( [ 'status' => 'completed' ] ) );

		$outcome = ( new Order_Editor() )->export_created( $created );

		$this->assertFalse( $outcome['success'] );
		$this->assertStringContainsString( 'Выгрузить можно только заказ', $outcome['message'] );
		$this->assertSame( '', (string) $this->fresh( $created->get_id() )->get_meta( self::TEST_EXPORTED, true ) );
	}
}
