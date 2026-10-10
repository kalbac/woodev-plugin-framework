<?php
/**
 * Unit tests for the shipment facts seam: baselines, one event per real change, serialisation, effects.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Order\Shipment_Facts;
use Woodev\Framework\Shipping\Order\Shipment_Facts_Events;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-order-lock.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipment-facts.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipment-facts-events.php';

/** @covers \Woodev\Framework\Shipping\Order\Shipment_Facts_Events */
final class ShipmentFactsEventsTest extends TestCase {

	/** @var array<int,array<string,mixed>> the "database": persisted order meta by order id. */
	private array $db = [];

	/** @var array<int,array<string,mixed>> each loaded order object's own copy of its meta, by object number. */
	private array $local = [];

	/** @var string[] order notes written, in order. */
	private array $notes = [];

	/** @var array<string,callable> filter overrides by tag. */
	private array $filters = [];

	/** @var object the fake `$wpdb`. */
	private $wpdb;

	/** @var mixed whatever `$GLOBALS['wpdb']` held before the test. */
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->db      = [];
		$this->local   = [];
		$this->notes   = [];
		$this->filters = [];

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, $value ) {
				return isset( $this->filters[ $tag ] ) ? ( $this->filters[ $tag ] )( $value ) : $value;
			}
		);

		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb          = new class() {
			public string $dbname = 'wp';
			public string $prefix = 'wp_';
			public bool $grant    = true;
			/** @var string[] */
			public array $log = [];

			public function prepare( string $query, ...$args ): string {
				return vsprintf( str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $query ), $args );
			}

			public function get_var( string $query ): string {
				$this->log[] = 'acquire';

				return $this->grant ? '1' : '0';
			}

			public function query( string $query ): int {
				$this->log[] = 'release';

				return 1;
			}
		};
		$GLOBALS['wpdb']     = $this->wpdb;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;

		parent::tearDown();
	}

	private function provider(): Orders_Provider {
		return Orders_Provider::create( 'test', 'Тестовая доставка', '_test_marker', [ 'test_shipping' ] );
	}

	/**
	 * An order object loaded NOW: its own copy of the persisted meta, like a `wc_get_order()` of that moment.
	 *
	 * @param int $id Order id.
	 * @return \WC_Order&\Mockery\MockInterface
	 */
	private function load_order( int $id = 55 ) {
		$n                 = count( $this->local );
		$this->local[ $n ] = $this->db[ $id ] ?? [];

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'read_meta_data' )->andReturnUsing(
			function () use ( $n, $id ) {
				$this->local[ $n ] = $this->db[ $id ] ?? [];
			}
		);
		$order->shouldReceive( 'meta_exists' )->andReturnUsing( fn( string $key ) => array_key_exists( $key, $this->local[ $n ] ) );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( string $key ) => $this->local[ $n ][ $key ] ?? '' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ) use ( $n ) {
				$this->local[ $n ][ $key ] = $value;
			}
		);
		$order->shouldReceive( 'save_meta_data' )->andReturnUsing(
			function () use ( $n, $id ) {
				$this->db[ $id ] = $this->local[ $n ];
			}
		);
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( string $note ) {
				$this->notes[] = $note;

				return 1;
			}
		);
		// The seam never touches WooCommerce totals, shipping lines or a full save.
		foreach ( [ 'set_total', 'set_shipping_total', 'calculate_totals', 'save', 'get_items', 'remove_item' ] as $method ) {
			$order->shouldReceive( $method )->never();
		}

		return $order;
	}

	private function everything( string $date = '2026-11-03', float $cost = 465.0 ): Shipment_Facts {
		return Shipment_Facts::create()
			->with_cost( $cost )
			->with_delivery_date( $date )
			->with_issues( [] )
			->with_courier( null );
	}

	public function test_every_fact_is_initialised_silently_when_it_has_no_baseline(): void {
		Actions\expectDone( 'woodev_shipping_carrier_cost_changed' )->never();
		Actions\expectDone( 'woodev_shipping_delivery_date_changed' )->never();
		Actions\expectDone( 'woodev_shipping_delivery_issue' )->never();
		Actions\expectDone( 'woodev_shipping_courier_assigned' )->never();

		$facts = Shipment_Facts::create()
			->with_cost( 465.0 )
			->with_delivery_date( '2026-11-03' )
			->with_issues( [ [ 'code' => '13', 'label' => 'Контактное лицо отсутствует', 'at' => '2026-11-02T10:00:00+0300' ] ] )
			->with_courier( [ 'name' => 'Иван' ] );

		$this->assertTrue( Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $facts ) );

		$this->assertSame( [], $this->notes, 'the first read of an order is its baseline, not news' );
		$this->assertSame( [ 'amount' => '465.00', 'currency' => 'RUB' ], $this->db[55]['_woodev_shipment_fact_cost_test'] );
		$this->assertSame( '2026-11-03', $this->db[55]['_woodev_shipment_fact_date_test']['date'] );
		$this->assertCount( 1, $this->db[55]['_woodev_shipment_fact_issues_test'] );
		$this->assertSame( 'Иван', $this->db[55]['_woodev_shipment_fact_courier_test']['name'] );
		$this->assertArrayNotHasKey( '_woodev_shipment_fact_attention_test', $this->db[55], 'a baseline asks for no attention' );
	}

	public function test_each_fact_has_a_baseline_of_its_own(): void {
		// The cost is known; the date was never recorded — a date now is the initial value, not a change.
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_cost( 465.0 ) );
		$this->assertArrayNotHasKey( '_woodev_shipment_fact_date_test', $this->db[55], 'a fact the carrier never reported is not initialised' );

		Actions\expectDone( 'woodev_shipping_delivery_date_changed' )->never();
		Actions\expectDone( 'woodev_shipping_carrier_cost_changed' )->never();

		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_cost( 465.0 )->with_delivery_date( '2026-11-03' ) );

		$this->assertSame( [], $this->notes );
		$this->assertSame( '2026-11-03', $this->db[55]['_woodev_shipment_fact_date_test']['date'] );
	}

	public function test_a_value_that_follows_a_known_empty_one_is_a_change(): void {
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything_empty() );

		$this->assertSame( [], $this->db[55]['_woodev_shipment_fact_date_test'], 'known to be none is stored explicitly' );
		$this->assertSame( [], $this->db[55]['_woodev_shipment_fact_issues_test'] );
		$this->assertSame( [], $this->db[55]['_woodev_shipment_fact_courier_test'] );

		Actions\expectDone( 'woodev_shipping_delivery_date_changed' )->once()->with( Mockery::type( '\WC_Order' ), '2026-11-03', null, 'planned', '', Mockery::type( Orders_Provider::class ) );
		Actions\expectDone( 'woodev_shipping_delivery_issue' )->once()->with( Mockery::type( '\WC_Order' ), '13', 'Контактное лицо отсутствует', '2026-11-02T10:00:00+0300', Mockery::type( Orders_Provider::class ) );
		Actions\expectDone( 'woodev_shipping_courier_assigned' )->once()->with(
			Mockery::type( '\WC_Order' ),
			[ 'name' => 'Иван', 'phone' => '', 'vehicle' => '', 'plate' => '' ],
			Mockery::type( Orders_Provider::class )
		);

		Shipment_Facts_Events::record(
			$this->load_order(),
			$this->provider(),
			Shipment_Facts::create()
				->with_delivery_date( '2026-11-03' )
				->with_issues( [ [ 'code' => '13', 'label' => 'Контактное лицо отсутствует', 'at' => '2026-11-02T10:00:00+0300' ] ] )
				->with_courier( [ 'name' => 'Иван' ] )
		);

		$this->assertCount( 3, $this->notes, 'one note per change' );
	}

	private function everything_empty(): Shipment_Facts {
		return Shipment_Facts::create()->with_cost( 465.0 )->with_delivery_date( null )->with_issues( [] )->with_courier( null );
	}

	public function test_a_cost_change_fires_one_action_and_one_note_naming_the_carrier(): void {
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything() );

		Actions\expectDone( 'woodev_shipping_carrier_cost_changed' )->once()->with( Mockery::type( '\WC_Order' ), 465.0, 1234.5, 'RUB', Mockery::type( Orders_Provider::class ) );

		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything( '2026-11-03', 1234.5 ) );

		$this->assertCount( 1, $this->notes );
		$this->assertStringContainsString( 'Тестовая доставка изменил стоимость доставки: 465 → 1' . "\u{00A0}" . '234,5 ₽', $this->notes[0] );
		$this->assertStringContainsString( 'Сумма заказа и доставка в магазине не менялись', $this->notes[0] );
		$this->assertStringNotContainsString( 'СДЭК', $this->notes[0], 'the carrier name comes from the plugin, never from the framework' );
		$this->assertSame( '1234.50', $this->db[55]['_woodev_shipment_fact_cost_test']['amount'] );
	}

	public function test_the_same_facts_again_announce_nothing(): void {
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything() );
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything( '2026-11-03', 520.0 ) );
		$this->assertCount( 1, $this->notes );

		Actions\expectDone( 'woodev_shipping_carrier_cost_changed' )->never();
		Actions\expectDone( 'woodev_shipping_delivery_date_changed' )->never();

		// A replayed webhook, the next poll, a retry: the very same read.
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything( '2026-11-03', 520.0 ) );
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything( '2026-11-03', 520.0 ) );

		$this->assertCount( 1, $this->notes, 'a replay writes no second note' );
	}

	public function test_a_currency_alone_moving_is_recorded_without_an_event(): void {
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_cost( 100.0, 'RUB' ) );

		Actions\expectDone( 'woodev_shipping_carrier_cost_changed' )->never();

		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_cost( 100.0, 'KZT' ) );

		$this->assertSame( [], $this->notes );
		$this->assertSame( 'KZT', $this->db[55]['_woodev_shipment_fact_cost_test']['currency'] );
	}

	public function test_a_moved_date_a_new_kind_or_a_window_is_one_change_with_the_previous_date(): void {
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_delivery_date( '2026-11-03' ) );

		Actions\expectDone( 'woodev_shipping_delivery_date_changed' )->once()->with(
			Mockery::type( '\WC_Order' ),
			'2026-11-05',
			[ 'from' => '10:00', 'to' => '14:00' ],
			'agreed',
			'2026-11-03',
			Mockery::type( Orders_Provider::class )
		);

		Shipment_Facts_Events::record(
			$this->load_order(),
			$this->provider(),
			Shipment_Facts::create()->with_delivery_date( '2026-11-05', 'agreed', [ 'from' => '10:00', 'to' => '14:00' ] )
		);

		$this->assertCount( 1, $this->notes );
		$this->assertSame( 'Тестовая доставка согласовал дату доставки: 05.11.2026, 10:00–14:00.', $this->notes[0] );
	}

	public function test_a_planned_date_move_reads_old_to_new(): void {
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_delivery_date( '2026-11-03' ) );
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_delivery_date( '2026-11-04' ) );

		$this->assertSame( [ 'Тестовая доставка изменил плановую дату доставки: 03.11.2026 → 04.11.2026.' ], $this->notes );
	}

	public function test_an_empty_report_never_overwrites_a_known_date_or_courier(): void {
		Shipment_Facts_Events::record(
			$this->load_order(),
			$this->provider(),
			Shipment_Facts::create()->with_delivery_date( '2026-11-03' )->with_courier( [ 'name' => 'Иван' ] )
		);

		Actions\expectDone( 'woodev_shipping_delivery_date_changed' )->never();
		Actions\expectDone( 'woodev_shipping_courier_assigned' )->never();

		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_delivery_date( null )->with_courier( null ) );

		$this->assertSame( [], $this->notes );
		$this->assertSame( '2026-11-03', $this->db[55]['_woodev_shipment_fact_date_test']['date'] );
		$this->assertSame( 'Иван', $this->db[55]['_woodev_shipment_fact_courier_test']['name'] );
	}

	public function test_a_courier_change_is_announced_once_and_a_replay_is_not(): void {
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_courier( [ 'name' => 'Иван' ] ) );

		$changed = Shipment_Facts::create()->with_courier( [ 'name' => 'Пётр', 'phone' => '+7 900 000-00-01', 'vehicle' => 'Lada Largus', 'plate' => 'А123ВС36' ] );

		Actions\expectDone( 'woodev_shipping_courier_assigned' )->once();

		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $changed );
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $changed );

		$this->assertSame(
			[ 'Тестовая доставка назначил курьера: Пётр, тел. +7 900 000-00-01, транспорт: Lada Largus, госномер: А123ВС36.' ],
			$this->notes
		);
	}

	public function test_each_new_issue_is_one_event_and_a_known_one_is_not_repeated(): void {
		$first  = [ 'code' => '13', 'label' => 'Контактное лицо отсутствует', 'at' => '2026-11-02T10:00:00+0300' ];
		$second = [ 'code' => '19', 'label' => 'Смена адреса', 'at' => '2026-11-03T09:00:00+0300' ];

		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_issues( [] ) );

		Actions\expectDone( 'woodev_shipping_delivery_issue' )->twice();

		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_issues( [ $first ] ) );
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_issues( [ $first, $second ] ) );
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_issues( [ $first, $second ] ) );

		$this->assertSame(
			[
				'Тестовая доставка: проблема доставки — Контактное лицо отсутствует',
				'Тестовая доставка: проблема доставки — Смена адреса',
			],
			$this->notes
		);
		$this->assertSame( [ '13', '19' ], array_column( $this->db[55]['_woodev_shipment_fact_issues_test'], 'code' ) );
	}

	public function test_an_issue_without_wording_is_shown_by_its_code_and_the_history_is_capped(): void {
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_issues( [] ) );
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_issues( [ [ 'code' => '77' ] ] ) );

		$this->assertSame( [ 'Тестовая доставка: проблема доставки — Проблема доставки (код 77)' ], $this->notes );

		$many = [];
		for ( $i = 1; $i <= 25; ++$i ) {
			$many[] = [ 'code' => (string) $i, 'label' => '', 'at' => sprintf( '2026-11-%02dT10:00:00+0300', $i ) ];
		}
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_issues( $many ) );

		$this->assertCount( 20, $this->db[55]['_woodev_shipment_fact_issues_test'], 'an order remembers 20 issues' );
	}

	public function test_a_fact_the_carrier_did_not_report_is_left_alone(): void {
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything() );
		$before = $this->db[55];

		Actions\expectDone( 'woodev_shipping_carrier_cost_changed' )->once();

		// Only the cost is reported this time: the other three baselines stay as they were.
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), Shipment_Facts::create()->with_cost( 999.0 ) );

		$this->assertSame( $before['_woodev_shipment_fact_date_test'], $this->db[55]['_woodev_shipment_fact_date_test'] );
		$this->assertSame( $before['_woodev_shipment_fact_issues_test'], $this->db[55]['_woodev_shipment_fact_issues_test'] );
		$this->assertSame( $before['_woodev_shipment_fact_courier_test'], $this->db[55]['_woodev_shipment_fact_courier_test'] );
	}

	public function test_two_orders_loaded_before_either_recorded_announce_the_change_once(): void {
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything( '2026-11-03', 100.0 ) );

		// A webhook request and a poll both loaded the order while the baseline was 100 …
		$webhook = $this->load_order();
		$poll    = $this->load_order();

		Actions\expectDone( 'woodev_shipping_carrier_cost_changed' )->once();

		// … and both carry the same news. The second one finds the baseline already moved once it holds the lock.
		$this->assertTrue( Shipment_Facts_Events::record( $webhook, $this->provider(), $this->everything( '2026-11-03', 150.0 ) ) );
		$this->assertTrue( Shipment_Facts_Events::record( $poll, $this->provider(), $this->everything( '2026-11-03', 150.0 ) ) );

		$this->assertCount( 1, $this->notes, 'one note, not two' );
		$this->assertSame( [ 'acquire', 'release', 'acquire', 'release', 'acquire', 'release' ], $this->wpdb->log, 'each record takes and frees the lock' );
	}

	public function test_a_record_that_cannot_take_the_lock_applies_nothing(): void {
		$this->wpdb->grant = false;

		Actions\expectDone( 'woodev_shipping_carrier_cost_changed' )->never();

		// A mock that knows nothing but its id: any read or write of meta would fail the test.
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 55 );

		$this->assertFalse( Shipment_Facts_Events::record( $order, $this->provider(), $this->everything() ) );
		$this->assertSame( [], $this->db );
		$this->assertSame( [ 'acquire' ], $this->wpdb->log, 'a lock that was never granted is not released' );
	}

	public function test_the_lock_is_released_when_the_save_fails(): void {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 55 );
		$order->shouldReceive( 'read_meta_data' );
		$order->shouldReceive( 'meta_exists' )->andReturn( false );
		$order->shouldReceive( 'update_meta_data' );
		$order->shouldReceive( 'save_meta_data' )->andThrow( new \RuntimeException( 'database gone' ) );

		try {
			Shipment_Facts_Events::record( $order, $this->provider(), $this->everything() );
			$this->fail( 'the failure is not swallowed' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( [ 'acquire', 'release' ], $this->wpdb->log );
		}
	}

	public function test_an_empty_set_of_facts_touches_nothing(): void {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 55 );

		$this->assertTrue( Shipment_Facts_Events::record( $order, $this->provider(), Shipment_Facts::create() ) );
		$this->assertSame( [], $this->wpdb->log, 'no lock for nothing' );
	}

	public function test_an_order_without_an_id_is_refused(): void {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 0 );

		$this->assertFalse( Shipment_Facts_Events::record( $order, $this->provider(), $this->everything() ) );
		$this->assertSame( [], $this->wpdb->log );
	}

	public function test_a_cost_change_and_an_issue_leave_attention_and_a_date_or_courier_do_not(): void {
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything() );

		Shipment_Facts_Events::record(
			$this->load_order(),
			$this->provider(),
			Shipment_Facts::create()->with_delivery_date( '2026-11-09' )->with_courier( [ 'name' => 'Иван' ] )
		);
		$this->assertArrayNotHasKey( '_woodev_shipment_fact_attention_test', $this->db[55], 'a date and a courier ask for no attention' );

		Shipment_Facts_Events::record(
			$this->load_order(),
			$this->provider(),
			Shipment_Facts::create()
				->with_cost( 520.0 )
				->with_issues( [ [ 'code' => '13', 'label' => 'Контактное лицо отсутствует', 'at' => '2026-11-02T10:00:00+0300' ] ] )
		);

		$attention = $this->db[55]['_woodev_shipment_fact_attention_test'];

		$this->assertSame( [ 'code' => '13', 'label' => 'Контактное лицо отсутствует', 'at' => '2026-11-02T10:00:00+0300' ], $attention['issue'] );
		$this->assertSame( [ 'from' => 465.0, 'to' => 520.0, 'currency' => 'RUB' ], $attention['cost'] );
	}

	public function test_the_flag_can_be_switched_off_per_kind(): void {
		$this->filters['woodev_shipping_shipment_fact_flag'] = static fn( $flag ) => false;

		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything() );
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything( '2026-11-03', 520.0 ) );

		$this->assertCount( 1, $this->notes, 'the note is a separate effect' );
		$this->assertArrayNotHasKey( '_woodev_shipment_fact_attention_test', $this->db[55] );
	}

	public function test_the_note_can_be_reworded_or_switched_off_and_the_action_still_fires(): void {
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything() );

		$this->filters['woodev_shipping_shipment_fact_note'] = static fn( $note ) => 'своя заметка';
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything( '2026-11-03', 520.0 ) );
		$this->assertSame( [ 'своя заметка' ], $this->notes );

		$this->filters['woodev_shipping_shipment_fact_note'] = static fn( $note ) => '';

		Actions\expectDone( 'woodev_shipping_carrier_cost_changed' )->once();
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything( '2026-11-03', 530.0 ) );

		$this->assertSame( [ 'своя заметка' ], $this->notes, 'an empty note is no note' );
	}

	public function test_woocommerce_totals_and_lines_are_never_touched(): void {
		// load_order() forbids set_total / set_shipping_total / calculate_totals / save / get_items / remove_item; a
		// full cycle of changes must pass through those mocks untouched.
		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything() );
		Shipment_Facts_Events::record(
			$this->load_order(),
			$this->provider(),
			Shipment_Facts::create()
				->with_cost( 9999.0 )
				->with_delivery_date( '2026-12-01' )
				->with_issues( [ [ 'code' => '1' ] ] )
				->with_courier( [ 'name' => 'Иван' ] )
		);

		$this->assertCount( 4, $this->notes );
	}

	public function test_baselines_are_kept_per_carrier(): void {
		$other = Orders_Provider::create( 'other', 'Другая доставка', '_other_marker', [ 'other_shipping' ] );

		Shipment_Facts_Events::record( $this->load_order(), $this->provider(), $this->everything( '2026-11-03', 100.0 ) );

		Actions\expectDone( 'woodev_shipping_carrier_cost_changed' )->never();

		// Another carrier's first read of the same order is ITS baseline, whatever the first carrier said.
		Shipment_Facts_Events::record( $this->load_order(), $other, $this->everything( '2026-11-03', 700.0 ) );

		$this->assertSame( [], $this->notes );
		$this->assertArrayHasKey( '_woodev_shipment_fact_cost_other', $this->db[55] );
		$this->assertSame( '100.00', $this->db[55]['_woodev_shipment_fact_cost_test']['amount'] );
	}

	public function test_a_carrier_that_never_calls_the_seam_leaves_no_trace(): void {
		$this->assertSame( [], $this->db );
		$this->assertSame( [], $this->notes );
		$this->assertSame( [], $this->wpdb->log );
		$this->assertSame( [], Shipment_Facts_Events::get_attention( $this->load_order(), $this->provider() ) );
	}
}
