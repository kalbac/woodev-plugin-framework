<?php
/**
 * The «Заказы» provider against the records engine: what it declares, the rows it builds, the locks it reads, who may act on a row
 * and the answers it gives to a preview and an apply are all run through the engine's own normalisers.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Orders
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Orders;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_Response;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Row_Normalizer;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Activity;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Cancel;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Data_Status;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Ids;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Labels;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Provider;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Reader;
use Woodev\Framework\Marketplace\Admin\Order\Order_Canceller;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Refused;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Runner;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Rows;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Seller;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Shipments;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;
use Woodev\Framework\Marketplace\Flows\Shipment_Service;
use Woodev\Framework\Marketplace\Flows\Shipment_Snapshot;
use Woodev\Framework\Marketplace\Settings\Shipment_Noun;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Shipment_Marketplace;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Availability;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Allowed_Actions;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Readiness;
use Woodev\Framework\Marketplace\Data\Wpdb_Seller_Action_Store;
use Woodev\Framework\Marketplace\Jobs\Posting_Poll_Times;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Order_Canceller;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Reasonless_Canceller;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Seller_Adapter;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Flows\Label_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Meta;

require_once dirname( __DIR__, 3 ) . '/Shipping/Rest_Api/wp-rest-controller-stub.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Provider
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Definition
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Rows
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Activity
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Labels
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Cancel
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Seller
 * @covers \Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Runner
 */
final class SiteOrdersProviderTest extends Label_Test_Case {
	/**
	 * The stamp the account puts on its orders (the `cabinet_ref` credential of the shared account).
	 */
	private const OWNER = 'cabinet-4417';

	/**
	 * @var Orders_Shop
	 */
	private $shop;

	/**
	 * @var Fake_Order_Meta
	 */
	private $meta;

	/**
	 * @var Fake_Order_Canceller
	 */
	private $canceller;

	/**
	 * Orders the user may not edit.
	 *
	 * @var int[]
	 */
	private $forbidden = [];

	/**
	 * WooCommerce order doubles by id.
	 *
	 * @var array<int,\Mockery\MockInterface>
	 */
	private $wc_orders = [];

	/**
	 * Order notes by order id.
	 *
	 * @var array<int,string[]>
	 */
	private $notes = [];

	/**
	 * Lines the plugin logged.
	 *
	 * @var string[]
	 */
	private $logged = [];

	/**
	 * The transients, by key.
	 *
	 * @var array<string,mixed>
	 */
	private $transients = [];

	/**
	 * The «Статус данных» part the provider is built with; none by default.
	 *
	 * @var Site_Orders_Data_Status|null
	 */
	private $data_status;

	/**
	 * The adapter's label readiness the provider is built with; none by default.
	 *
	 * @var Label_Readiness|null
	 */
	private $readiness;

	/**
	 * The actions the marketplace allows, by order id (`null` for an order it has not said for); null here means the adapter has no such contract.
	 *
	 * @var array<int,string[]|null>|null
	 */
	private $allowed;

	/**
	 * The options, by name.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * The adapter's seller actions; null for a table without them.
	 *
	 * @var Fake_Seller_Adapter|null
	 */
	private $seller;

	/**
	 * «Add to a shipment»; null for a table without it.
	 *
	 * @var Site_Orders_Shipments|null
	 */
	private $shipments;

	/**
	 * Order id => its units, `[ id, label, status, action ids or null ]`.
	 *
	 * @var array<int,array<int,array<int,mixed>>>
	 */
	private $units = [];

	protected function setUp(): void {
		parent::setUp();

		$this->transients  = [];
		$this->options     = [];
		$this->data_status = null;
		$this->forbidden   = [];
		$this->notes     = [];
		$this->seller    = null;
		$this->shipments = null;
		$this->units     = [];
		$this->logged    = [];
		$this->meta      = new Fake_Order_Meta();
		$this->canceller = new Fake_Order_Canceller();

		Functions\when( 'wp_date' )->justReturn( '2026-11-15' );
		Functions\when( 'get_transient' )->alias(
			function ( string $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_timezone' )->alias(
			static function (): \DateTimeZone {
				return new \DateTimeZone( 'UTC' );
			}
		);
		Functions\when( 'home_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.test' . $path;
			}
		);
		Functions\when( 'site_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.test' . $path;
			}
		);
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			[
				'wc-processing' => 'Processing',
				'wc-completed'  => 'Completed',
				'wc-cancelled'  => 'Cancelled',
			]
		);
		Functions\when( 'wc_get_order_status_name' )->alias(
			static function ( string $status ): string {
				return ucfirst( $status );
			}
		);
		Functions\when( 'wc_price' )->alias(
			static function ( $amount ): string {
				return '<span class="amount">' . $amount . '&nbsp;<span>&#8381;</span></span>';
			}
		);
		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				return $this->wc_orders[ (int) $id ] ?? false;
			}
		);
		// Like WooCommerce on HPOS: only `post__in` narrows the query. Any other key (`include` is not one) is ignored, and the newest
		// `limit` orders of the store come back — which is what hid the rows of an account whose orders are not the newest ones (#1928).
		Functions\when( 'wc_get_orders' )->alias(
			function ( array $args ) {
				$orders = $this->wc_orders;

				krsort( $orders );

				if ( isset( $args['post__in'] ) ) {
					$orders = array_intersect_key( $orders, array_flip( $args['post__in'] ) );
				}

				return array_slice( array_values( $orders ), 0, $args['limit'] ?? -1 );
			}
		);
	}

	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Fills the order store and the double WooCommerce orders that go with the shared catalogue.
	 *
	 * @param bool $hpos Whether the orders live in the HPOS table.
	 * @return void
	 */
	private function stock( bool $hpos ): void {
		$this->shop = new Orders_Shop( $this->db, $hpos, $this->account_id );

		$this->add_order( 101, 'processing', '2026-11-01 10:00:00', '500', 'Анна Смирнова', 'P-101', false );
		$this->add_order( 102, 'completed', '2026-11-03 09:00:00', '1500.5', 'Борис Лебедев', 'P-102', true );
		$this->add_order( 103, 'cancelled', '2026-11-05 12:00:00', '99', 'Вера Ким', 'P-103', true );
		$this->add_order( 104, 'processing', '2026-11-07 08:30:00', '250', '', 'P-104', false );
	}

	/**
	 * @param int    $id       Order id.
	 * @param string $status   WooCommerce status without `wc-`.
	 * @param string $created  Creation time, UTC.
	 * @param string $total    Total.
	 * @param string $name     Buyer's name.
	 * @param string $posting  Posting number.
	 * @param bool   $finished Whether the posting is finished.
	 * @return void
	 */
	private function add_order( int $id, string $status, string $created, string $total, string $name, string $posting, bool $finished ): void {
		$meta  = [
			Fake_Order_Canceller::META_POSTING  => $posting,
			Fake_Order_Canceller::META_FINISHED => $finished ? '1' : '0',
		];
		$order = Mockery::mock( '\WC_Order' );

		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_order_number' )->andReturn( (string) $id );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://shop.test/wp-admin/post.php?post=' . $id . '&action=edit' );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		$order->shouldReceive( 'get_date_created' )->andReturn( new \DateTime( $created . ' UTC' ) );
		$order->shouldReceive( 'get_total' )->andReturn( $total );
		$order->shouldReceive( 'get_currency' )->andReturn( 'RUB' );
		$order->shouldReceive( 'get_formatted_billing_full_name' )->andReturn( $name );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			function ( $key ) use ( $meta, $id ) {
				if ( Fake_Seller_Adapter::META_UNITS === $key ) {
					return $this->units[ $id ] ?? [];
				}

				return $meta[ $key ] ?? '';
			}
		);
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( $note ) use ( $id ) {
				$this->notes[ $id ][] = $note;

				return 1;
			}
		);

		$this->wc_orders[ $id ]    = $order;
		$this->meta->owners[ $id ] = self::OWNER;
	}

	/**
	 * The provider over the test database, wired like the module wires it.
	 *
	 * @param bool $with_labels Whether the adapter has label calls.
	 * @param bool $with_cancel Whether the adapter can cancel.
	 * @return Site_Orders_Provider
	 */
	private function provider( bool $with_labels = true, bool $with_cancel = true ): Site_Orders_Provider {
		$labels = $with_labels ? $this->labels_part() : null;
		$cancel = $with_cancel ? new Site_Orders_Cancel( $this->canceller, $this->license(), 'Фейк' ) : null;
		$can    = function ( int $order_id ): bool {
			return ! in_array( $order_id, $this->forbidden, true );
		};
		$gate   = new Posting_Action_Gate( new Label_Availability( $this->readiness ), $this->allowed_actions(), $this->write_gate, null, $this->seller );
		$seller = null === $this->seller ? null : new Site_Orders_Seller(
			new Seller_Action_Runner(
				$this->seller,
				$gate,
				$this->license(),
				'Фейк',
				new Wpdb_Seller_Action_Store( $this->db, Sqlite_Tables::table( 'seller_actions' ), $this->clock() ),
				function ( string $line ): void {
					$this->logged[] = $line;
				}
			)
		);

		return new Site_Orders_Provider(
			$this->accounts(),
			new Site_Orders_Reader( $this->db, Sqlite_Tables::table( 'postings' ), $this->shop->storage() ),
			new Site_Orders_Rows(
				Site_Orders_Provider::offered_actions( null !== $labels, null !== $cancel ),
				$can,
				static function ( \WC_Order $order ) use ( $cancel ): bool {
					return null !== $cancel && $cancel->is_finished( $order );
				},
				null,
				$gate
			),
			new Site_Orders_Activity( $this->clock() ),
			$labels,
			$cancel,
			$this->meta,
			$can,
			Sqlite_Tables::MARKETPLACE_ID,
			function ( string $line ): void {
				$this->logged[] = $line;
			},
			$this->data_status,
			null,
			$gate,
			null,
			$seller,
			$this->shipments
		);
	}

	/**
	 * The adapter's allowed actions over {@see self::$allowed}; none when the test gives no list.
	 *
	 * @return Posting_Allowed_Actions|null
	 */
	private function allowed_actions(): ?Posting_Allowed_Actions {
		if ( null === $this->allowed ) {
			return null;
		}

		$allowed = Mockery::mock( Posting_Allowed_Actions::class );
		$allowed->shouldReceive( 'allowed_posting_actions' )->andReturnUsing(
			function ( \WC_Order $order ): ?array {
				return $this->allowed[ $order->get_id() ] ?? null;
			}
		);

		return $allowed;
	}

	/**
	 * @return Site_Orders_Labels
	 */
	private function labels_part(): Site_Orders_Labels {
		$jobs = $this->jobs();

		return new Site_Orders_Labels(
			$this->db,
			Sqlite_Tables::table( 'tasks' ),
			Sqlite_Tables::table( 'documents' ),
			function ( int $document_id ): string {
				return $this->label_url( $document_id );
			},
			[ $jobs, 'request' ],
			$this->clock()
		);
	}

	/**
	 * @param int $document_id Document id.
	 * @return string
	 */
	private function label_url( int $document_id ): string {
		return 'https://shop.test/wp-admin/admin-post.php?action=label&document_id=' . $document_id;
	}

	/**
	 * @return array<string,string>
	 */
	private function context(): array {
		return [ 'account_id' => (string) $this->account_id ];
	}

	/**
	 * @param int $order_id Order id.
	 * @return string
	 */
	private function row_id( int $order_id ): string {
		return Site_Orders_Ids::row_id( $this->account_id, $order_id );
	}

	/**
	 * @param bool $with_labels Whether the adapter has label calls.
	 * @param bool $with_cancel Whether the adapter can cancel.
	 * @return Records_Definition
	 */
	private function definition( bool $with_labels = true, bool $with_cancel = true ): Records_Definition {
		return Records_Definition::from_array( $this->provider( $with_labels, $with_cancel )->get_definition( $this->context() ) );
	}

	/**
	 * @param array<string,mixed> $query Query as the engine validates it.
	 * @return array<string,mixed> The list body, as the engine would send it.
	 */
	private function list_body( array $query = [] ): array {
		$result = $this->provider()->query( $this->context(), array_merge( [ 'page' => 1, 'per_page' => 20 ], $query ) );
		$body   = Row_Normalizer::page( $this->definition(), $result, 20 );

		$this->assertIsArray( $body, 'The engine accepts the list.' );

		return $body;
	}

	/**
	 * @param array<string,mixed> $body List body.
	 * @return array<string,array<string,mixed>> Rows by id.
	 */
	private function by_id( array $body ): array {
		return array_column( $body['rows'], null, 'id' );
	}

	/**
	 * @return array<string,array{0:bool}>
	 */
	public function storages(): array {
		return [
			'posts table (CPT)' => [ false ],
			'HPOS table'        => [ true ],
		];
	}

	public function test_add_to_a_shipment_runs_through_the_table(): void {
		$this->stock( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);

		$marketplace     = new Fake_Shipment_Marketplace();
		$store           = new Wpdb_Shipment_Store( $this->db, Sqlite_Tables::table( 'shipments' ), Sqlite_Tables::table( 'shipment_units' ), 'woodev_fake-seller_', $this->clock() );
		$service         = new Shipment_Service(
			$marketplace,
			$store,
			Shipment_Snapshot::over( $marketplace, $store, $this->registry() ),
			new Posting_Action_Gate( new Label_Availability( null ), null, $this->write_gate ),
			$this->license(),
			$this->classifier,
			$this->pause(),
			Shipment_Noun::dispatch(),
			static function (): void {}
		);
		$this->shipments = new Site_Orders_Shipments( $service, $store, Shipment_Noun::dispatch() );

		$definition = $this->definition();
		$row        = $this->by_id( $this->list_body() )[ $this->row_id( 101 ) ];

		$this->assertNotNull( $definition->get_action( 'bulk-ship-add' ) );
		$this->assertTrue( $row['selectable'] );
		$this->assertContains( 'ship-add', array_column( $row['actions'], 'id' ) );

		$result = $this->provider()->apply( $this->context(), 'ship-add', [ $this->row_id( 101 ) ], [ 'target' => 'new', 'name' => 'Первая' ] );
		$body   = Action_Response::apply( $definition, $definition->get_action( 'ship-add' ), $result );

		$this->assertIsArray( $body, 'The engine accepts the answer.' );
		$this->assertSame( 1, $body['accepted'] );
		$this->assertSame( [ 'create_shipment', 'read_shipment', 'add_units', 'read_shipment' ], $marketplace->remote );
	}

	public function test_the_definition_is_accepted_by_the_engine_and_declares_what_the_adapter_can_do(): void {
		$this->stock( false );

		$definition = $this->definition()->to_array();
		$actions    = array_column( $definition['row_actions'], null, 'id' );

		$this->assertSame( [ 'order', 'posting', 'status', 'date', 'customer', 'total', 'actions' ], array_column( $definition['columns'], 'id' ) );
		$this->assertSame( [ 'open', 'label', 'cancel' ], array_column( $definition['row_actions'], 'id' ) );
		$this->assertSame( [], $definition['bulk_actions'] );
		$this->assertSame( [ 'date', 'order', 'posting', 'status', 'total' ], $definition['sorts']['keys'] );
		$this->assertSame( [ 'key' => 'date', 'direction' => 'desc' ], $definition['sorts']['default'] );
		$this->assertSame( [ 'q', 'state', 'date', 'posting_status', 'wc_status' ], array_column( $definition['filters'], 'id' ) );
		$this->assertSame( 'date_range', $definition['filters'][2]['type'] );
		$this->assertSame( [ false, false, false, true, true ], array_map( static function ( array $filter ): bool {
			return ! empty( $filter['advanced'] );
		}, $definition['filters'] ), 'Both statuses wait under «Расширенные фильтры».' );
		$this->assertTrue( $definition['filters'][3]['options_source'], 'The marketplace statuses arrive with each list.' );
		$this->assertSame( [ 'visibility', 'media-document', 'remove' ], array_column( $definition['row_actions'], 'icon' ) );
		$this->assertTrue( $definition['filters'][1]['views'], 'The posting state is the counted row.' );
		$this->assertSame( 'Всё время', $definition['messages']['period_all'], 'The date range is the period picker, which needs its words.' );
		$this->assertArrayNotHasKey( 'summary_label', $definition['messages'], 'The count word comes with each list, in the right plural form.' );
		$this->assertSame( '2026-11-15', $definition['filters'][2]['today'], 'The period picker counts «Сегодня» from the site\'s day.' );
		$this->assertSame( [ [ 'value' => (string) $this->account_id, 'label' => 'Основной' ] ], $definition['context']['options'] );
		$this->assertNull( $definition['children'] );
		$this->assertNull( $definition['analytics'] );

		$this->assertSame( 'download', $actions['label']['interaction'] );
		$this->assertTrue( $actions['label']['skip_preview'], 'Asking for labels is not destructive: no confirmation window.' );
		$this->assertSame( 'danger', $actions['cancel']['tone'] );
		$this->assertSame( 'dialog', $actions['cancel']['interaction'] );
		$this->assertSame( [ 'reason', 'message' ], array_column( $actions['cancel']['fields'], 'id' ) );
		$this->assertTrue( $actions['cancel']['fields'][0]['required'] );
		$this->assertTrue( $actions['cancel']['fields'][0]['options_source'], 'The reasons come from the marketplace for the row.' );
	}

	public function test_an_adapter_without_label_calls_or_cancellation_gets_a_table_without_them(): void {
		$this->stock( false );

		$plain = $this->definition( false, false )->to_array();

		$this->assertSame( [ 'open' ], array_column( $plain['row_actions'], 'id' ) );
		$this->assertNotContains( 'label', array_column( $plain['columns'], 'id' ) );

		$result = $this->provider( false, false )->query( $this->context(), [ 'page' => 1, 'per_page' => 20 ] );

		$this->assertIsArray( Row_Normalizer::page( Records_Definition::from_array( $this->provider( false, false )->get_definition( $this->context() ) ), $result, 20 ), 'The rows carry no label cell.' );

		$cancel_only = $this->definition( false, true )->to_array();

		$this->assertSame( [ 'open', 'cancel' ], array_column( $cancel_only['row_actions'], 'id' ) );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_list_is_the_orders_of_the_account_newest_first_with_their_cells( bool $hpos ): void {
		$this->stock( $hpos );

		$body = $this->list_body();
		$rows = $this->by_id( $body );

		$this->assertSame( 4, $body['total'] );
		$this->assertSame( [ $this->row_id( 104 ), $this->row_id( 103 ), $this->row_id( 102 ), $this->row_id( 101 ) ], array_column( $body['rows'], 'id' ) );
		$this->assertArrayNotHasKey( 'summary', $body['messages'] ?? [], 'The card footer counts the rows; no sentence repeats it.' );
		$this->assertSame( 'заказа', $body['messages']['summary_label'], 'The footer\'s word travels with the list, in the Russian form of the count (4 заказа).' );

		$cells = $rows[ $this->row_id( 104 ) ]['cells'];

		$this->assertSame( 'link', $cells['order'][0]['type'] );
		$this->assertSame( '№ 104', $cells['order'][0]['value']['label'] );
		$this->assertSame( 'https://shop.test/wp-admin/post.php?post=104&action=edit', $cells['order'][0]['value']['url'] );
		$this->assertSame( 'P-104', $cells['posting'] );
		$this->assertCount( 1, $cells['status'], 'One badge: the marketplace\'s own status, the WooCommerce one stays in the order.' );
		$this->assertSame( [ 'label' => 'Доставляется', 'tone' => 'info' ], $cells['status'][0]['value'], 'A Russian word, never the code.' );
		$this->assertSame( '2026-11-07T08:30:00Z', $cells['date'] );
		$this->assertNull( $cells['customer'], 'An order with no buyer name has an empty cell.' );
		$this->assertStringContainsString( '250', $cells['total'] );
		$this->assertStringNotContainsString( '<', $cells['total'], 'The price is plain text.' );
		$this->assertArrayNotHasKey( 'label', $cells, 'The table has no label column; the action downloads them.' );
		$this->assertSame( 'Борис Лебедев', $rows[ $this->row_id( 102 ) ]['cells']['customer'] );
		$this->assertSame( [ 'label' => 'Отменено', 'tone' => 'danger' ], $rows[ $this->row_id( 103 ) ]['cells']['status'][0]['value'] );
		$this->assertSame( [ 'label' => 'Доставлено', 'tone' => 'success' ], $rows[ $this->row_id( 102 ) ]['cells']['status'][0]['value'] );
		$this->assertFalse( $rows[ $this->row_id( 104 ) ]['selectable'] );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_an_account_whose_orders_are_not_the_newest_of_the_store_still_gets_its_rows( bool $hpos ): void {
		$this->stock( $hpos );

		// Four newer orders of the store that no posting of the account points at: other plugins' orders, a later sync's orders.
		foreach ( [ 201, 202, 203, 204 ] as $id ) {
			$this->add_order( $id, 'completed', '2026-11-20 10:00:00', '10', 'Чужой заказ', 'X-' . $id, false );
		}

		$body = $this->list_body();

		$this->assertSame( 4, $body['total'] );
		$this->assertCount( $body['total'], $body['rows'], 'The count and the rows are one set: no order is counted and then left out.' );
		$this->assertSame( [ $this->row_id( 104 ), $this->row_id( 103 ), $this->row_id( 102 ), $this->row_id( 101 ) ], array_column( $body['rows'], 'id' ) );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_state_facets_split_the_orders_into_open_and_finished_under_every_other_filter( bool $hpos ): void {
		$this->stock( $hpos );

		$counts = function ( array $query ): array {
			return array_column( $this->list_body( $query )['facets']['state'], 'count', 'value' );
		};
		$all    = $counts( [] );

		$this->assertSame( [ 'open', 'finished' ], array_keys( $all ) );
		$this->assertSame( 4, array_sum( $all ), 'A posting is open or finished, so the two add up to every order.' );
		$this->assertSame( $all, $counts( [ 'filters' => [ 'state' => 'open' ] ] ), 'The chosen state does not change the counts.' );

		$processing = $counts( [ 'filters' => [ 'wc_status' => 'wc-processing' ] ] );

		$this->assertSame( 2, array_sum( $processing ), 'Another filter does.' );
		$this->assertSame( $this->list_body( [ 'filters' => [ 'wc_status' => 'wc-processing', 'state' => 'open' ] ] )['total'], $processing['open'] );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_marketplace_statuses_are_the_options_of_their_filter_with_counts_and_narrow_the_list( bool $hpos ): void {
		$this->stock( $hpos );

		$this->assertSame(
			[
				[ 'value' => 'awaiting_packaging', 'label' => 'Ожидает упаковки', 'count' => 1 ],
				[ 'value' => 'cancelled', 'label' => 'Отменено', 'count' => 1 ],
				[ 'value' => 'delivered', 'label' => 'Доставлено', 'count' => 1 ],
				[ 'value' => 'delivering', 'label' => 'Доставляется', 'count' => 1 ],
			],
			$this->list_body()['facets']['posting_status'],
			'The badge\'s own words, only for the statuses the orders are in.'
		);

		$body = $this->list_body( [ 'filters' => [ 'posting_status' => 'delivering' ] ] );

		$this->assertSame( [ $this->row_id( 104 ) ], array_column( $body['rows'], 'id' ) );
		$this->assertCount( 4, $body['facets']['posting_status'], 'The chosen status does not hide the others.' );
	}

	public function test_two_statuses_nobody_names_keep_their_codes_in_the_filter(): void {
		$rows = new Site_Orders_Rows( [], '__return_true', '__return_false' );

		$this->assertSame(
			[
				[ 'value' => 'delivering', 'label' => 'Доставляется', 'count' => 2 ],
				[ 'value' => 'odd_one', 'label' => 'Другой статус (odd_one)', 'count' => 1 ],
				[ 'value' => 'odd_two', 'label' => 'Другой статус (odd_two)', 'count' => 3 ],
			],
			$rows->posting_status_options( [ 'delivering' => 2, 'odd_one' => 1, 'odd_two' => 3 ] )
		);
	}

	public function test_the_list_carries_the_data_status_of_the_account_only_when_the_table_has_one(): void {
		$this->stock( false );
		$this->assertArrayNotHasKey( 'data_status', array_filter( $this->list_body() ), 'No poll, no block.' );

		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);
		$this->options['woodev_fake-plugin_postings_polled'] = [ $this->account_id => 1795000000 ];
		$asked             = [];
		$this->data_status = new Site_Orders_Data_Status(
			new Posting_Poll_Times( 'fake-plugin' ),
			static function ( int $account_id ) use ( &$asked ) {
				$asked[] = $account_id;

				return true;
			}
		);

		$status = $this->list_body()['data_status'];

		$this->assertSame( [ $this->account_id ], $asked, 'The next run of this account\'s poll.' );
		$this->assertSame(
			[
				[ 'label' => 'Последний опрос', 'value' => '2026-11-15' ],
				[ 'label' => 'Следующий опрос', 'value' => 'Сейчас' ],
			],
			$status['items']
		);
		$this->assertNotSame( '', $status['title'] );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_sorting_filtering_and_paging_are_server_side_on_either_storage( bool $hpos ): void {
		$this->stock( $hpos );

		$ids = function ( array $query ): array {
			return array_column( $this->list_body( $query )['rows'], 'id' );
		};

		$this->assertSame( [ $this->row_id( 102 ), $this->row_id( 101 ), $this->row_id( 104 ), $this->row_id( 103 ) ], $ids( [ 'sort' => 'total', 'direction' => 'desc' ] ) );
		$this->assertSame( [ $this->row_id( 104 ), $this->row_id( 101 ) ], $ids( [ 'filters' => [ 'wc_status' => 'wc-processing' ] ] ) );
		$this->assertSame( [ $this->row_id( 103 ), $this->row_id( 102 ) ], $ids( [ 'filters' => [ 'date' => [ 'from' => '2026-11-02', 'to' => '2026-11-06' ] ] ] ) );
		$this->assertSame( [ $this->row_id( 103 ) ], $ids( [ 'filters' => [ 'q' => 'P-103' ] ] ) );

		$second = $this->list_body( [ 'per_page' => 3, 'page' => 2 ] );

		$this->assertSame( [ $this->row_id( 101 ) ], array_column( $second['rows'], 'id' ) );
		$this->assertSame( 4, $second['total'], 'The count is of the whole filtered table.' );
	}

	public function test_a_table_with_no_account_is_empty(): void {
		$this->stock( false );

		$result = $this->provider()->query( [ 'account_id' => '99' ], [ 'page' => 1, 'per_page' => 20 ] );

		$this->assertSame( [ 'rows' => [], 'total' => 0 ], $result );
	}

	public function test_a_row_without_the_right_to_edit_shows_its_number_without_a_link_and_its_actions_off(): void {
		$this->stock( false );
		$this->forbidden = [ 104 ];

		$row = $this->by_id( $this->list_body() )[ $this->row_id( 104 ) ];

		$this->assertSame( [ 'type' => 'text', 'value' => '№ 104' ], $row['cells']['order'][0] );
		$this->assertSame( [ false, false, false ], array_column( $row['actions'], 'enabled' ) );
		$this->assertSame( 'Нет права изменять этот заказ.', $row['actions'][1]['reason'] );
	}

	public function test_a_finished_posting_is_not_offered_the_cancel_action_at_all(): void {
		$this->stock( false );

		$rows = $this->by_id( $this->list_body() );

		$this->assertSame( [ 'open', 'label', 'cancel' ], array_column( $rows[ $this->row_id( 104 ) ]['actions'], 'id' ) );
		$this->assertSame( [ true, true, true ], array_column( $rows[ $this->row_id( 104 ) ]['actions'], 'enabled' ) );
		$this->assertSame( [ 'open', 'label' ], array_column( $rows[ $this->row_id( 103 ) ]['actions'], 'id' ), 'No disabled button and no sentence: the action is simply not there.' );
		$this->assertSame( [ true, false ], array_column( $rows[ $this->row_id( 103 ) ]['actions'], 'enabled' ), 'A finished posting needs no label: the button is off, with the reason, like on the order screen.' );
		$this->assertSame( 'Отправление завершено — этикетка не нужна.', $rows[ $this->row_id( 103 ) ]['actions'][1]['reason'] );
	}

	public function test_a_label_the_adapter_says_is_not_ready_is_off_in_the_row_with_its_reason(): void {
		$this->stock( false );
		$this->readiness = Mockery::mock( Label_Readiness::class );
		$this->readiness->shouldReceive( 'label_readiness' )->andReturn( 'Этикетка появится, когда заказ будет собран.' );

		$actions = $this->by_id( $this->list_body() )[ $this->row_id( 104 ) ]['actions'];

		$this->assertSame( [ true, false, true ], array_column( $actions, 'enabled' ) );
		$this->assertSame( 'Этикетка появится, когда заказ будет собран.', $actions[1]['reason'] );
		$this->assertSame( '', $actions[2]['reason'], 'Cancelling is not a label question.' );
	}

	public function test_a_write_the_marketplace_does_not_allow_for_the_posting_is_off_in_its_row_with_the_reason(): void {
		$this->stock( false );
		$this->allowed = [
			101 => [ 'cancel' ],
			104 => [],
		];

		$rows = $this->by_id( $this->list_body() );

		$this->assertSame( [ true, false, true ], array_column( $rows[ $this->row_id( 101 ) ]['actions'], 'enabled' ), 'Only the cancellation is allowed for 101.' );
		$this->assertSame( 'Маркетплейс ещё не выдал этикетку для этого отправления — она станет доступна, когда отправление будет собрано.', $rows[ $this->row_id( 101 ) ]['actions'][1]['reason'] );
		$this->assertSame( [ true, false, false ], array_column( $rows[ $this->row_id( 104 ) ]['actions'], 'enabled' ), 'An empty list allows nothing.' );
		$this->assertSame( 'Маркетплейс сейчас не разрешает отменить это отправление — например, оно уже передано в доставку.', $rows[ $this->row_id( 104 ) ]['actions'][2]['reason'] );
	}

	public function test_a_posting_the_marketplace_has_not_said_for_keeps_both_actions_as_before(): void {
		$this->stock( false );
		$this->allowed = [ 101 => null ];

		$this->assertSame( [ true, true, true ], array_column( $this->by_id( $this->list_body() )[ $this->row_id( 101 ) ]['actions'], 'enabled' ) );
	}

	public function test_an_adapter_without_the_allowed_actions_contract_offers_both_write_actions(): void {
		$this->stock( false );

		$this->assertNull( $this->allowed );
		$this->assertSame( [ true, true, true ], array_column( $this->by_id( $this->list_body() )[ $this->row_id( 104 ) ]['actions'], 'enabled' ) );
	}

	public function test_a_cancellation_the_marketplace_does_not_allow_is_refused_by_the_plan_and_the_apply_without_a_remote_call(): void {
		$this->stock( false );
		$this->allowed = [ 104 => [ 'label' ] ];
		$provider      = $this->provider();
		$action        = $this->definition()->get_action( 'cancel' );

		$plan = Action_Response::preview( $action, $provider->preview( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [] ) );
		$body = Action_Response::apply( $this->definition(), $action, $provider->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => '1' ] ) );

		$this->assertFalse( $plan['can_apply'] );
		$this->assertSame( 'failed', $body['status'] );
		$this->assertSame( 'Маркетплейс сейчас не разрешает отменить это отправление — например, оно уже передано в доставку.', $body['groups'][0]['reason'] );
		$this->assertSame( [ 'P-104' ], $body['groups'][0]['examples'] );
		$this->assertSame( [], $this->canceller->cancelled, 'A hand-made request cancels nothing either.' );
	}

	public function test_a_cancellation_the_marketplace_allows_goes_through(): void {
		$this->stock( false );
		$this->allowed = [ 104 => [ 'cancel' ] ];

		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'cancel' ), $this->provider()->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => '1' ] ) );

		$this->assertSame( 'done', $body['status'] );
		$this->assertSame( [ [ $this->account_id, 'P-104', 1, '' ] ], $this->canceller->cancelled );
	}

	public function test_a_label_the_marketplace_has_not_released_is_never_asked_for_and_the_reason_is_a_sentence(): void {
		$this->stock( false );
		$this->allowed = [ 104 => [ 'cancel' ] ];
		$provider      = $this->provider();

		$plan = Action_Response::preview( $this->definition()->get_action( 'label' ), $provider->preview( $this->context(), 'label', [ $this->row_id( 104 ) ], [] ) );
		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $provider->apply( $this->context(), 'label', [ $this->row_id( 104 ) ], [] ) );

		$this->assertFalse( $plan['can_apply'] );
		$this->assertSame( 'failed', $body['status'] );
		$this->assertSame( 'Маркетплейс ещё не выдал этикетку для этого отправления — она станет доступна, когда отправление будет собрано.', $body['groups'][0]['reason'] );
		$this->assertSame( [], $this->provider_calls(), 'No label request, so no HTTP 400 from the marketplace.' );
	}

	public function test_a_ready_label_is_still_handed_over_when_the_marketplace_no_longer_lists_the_action(): void {
		$this->stock( false );
		$this->allowed = [ 104 => [ 'cancel' ] ];
		$document      = $this->document( [ 'P-104' ] );

		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $this->provider()->apply( $this->context(), 'label', [ $this->row_id( 104 ) ], [] ) );

		$this->assertSame( $this->label_url( $document ), $body['download']['url'] );
	}

	public function test_a_stored_label_stays_downloadable_from_the_row_of_a_finished_posting_with_no_optional_contract(): void {
		$this->stock( false );
		$this->document( [ 'P-103' ] );

		$rows = $this->by_id( $this->list_body() );

		$this->assertSame( [ true, true ], array_column( $rows[ $this->row_id( 103 ) ]['actions'], 'enabled' ), 'Downloading what is already made is not asking for a label.' );
		$this->assertSame( '', $rows[ $this->row_id( 103 ) ]['actions'][1]['reason'] );
		$this->assertSame( [ true, false ], array_column( $rows[ $this->row_id( 102 ) ]['actions'], 'enabled' ), 'A finished posting with no stored label is still off.' );

		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $this->provider()->apply( $this->context(), 'label', [ $this->row_id( 103 ) ], [] ) );

		$this->assertArrayHasKey( 'download', $body, 'The server hands the stored PDF over, as the button says.' );
	}

	public function test_a_stored_label_stays_downloadable_when_the_marketplace_no_longer_lists_the_label_action(): void {
		$this->stock( false );
		$this->allowed = [ 104 => [ 'cancel' ] ];
		$this->document( [ 'P-104' ] );

		$actions = $this->by_id( $this->list_body() )[ $this->row_id( 104 ) ]['actions'];

		$this->assertSame( [ true, true, true ], array_column( $actions, 'enabled' ) );
		$this->assertSame( '', $actions[1]['reason'], 'No false «not released» sentence over a label that exists.' );
	}

	public function test_a_stored_label_stays_downloadable_for_a_key_that_cannot_write_but_cancel_and_new_requests_do_not(): void {
		$this->stock( false );
		$this->write_gate = static function (): bool {
			return false;
		};
		$document         = $this->document( [ 'P-104' ] );
		$provider         = $this->provider();

		$actions = $this->by_id( $this->list_body() )[ $this->row_id( 104 ) ]['actions'];

		$this->assertSame( [ true, true, false ], array_column( $actions, 'enabled' ) );
		$this->assertSame( 1, $provider->preview( $this->context(), 'label', [ $this->row_id( 104 ) ], [] )['eligible'], 'The plan does not refuse a download.' );

		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $provider->apply( $this->context(), 'label', [ $this->row_id( 104 ) ], [] ) );

		$this->assertSame( $this->label_url( $document ), $body['download']['url'] );

		foreach ( [ 'preview', 'apply' ] as $method ) {
			try {
				$provider->$method( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => '1' ] );
				$this->fail( 'A read-only key cannot cancel.' );
			} catch ( Action_State_Exception $e ) {
				$this->assertStringContainsString( 'не позволяет менять данные на маркетплейсе', $e->getMessage() );
			}
		}
	}

	public function test_an_account_whose_key_cannot_write_has_both_write_actions_off_in_every_row_and_open_still_on(): void {
		$this->stock( false );
		$this->write_gate = static function (): bool {
			return false;
		};

		$rows = $this->by_id( $this->list_body() );

		foreach ( [ 101, 104 ] as $order ) {
			$actions = $rows[ $this->row_id( $order ) ]['actions'];

			$this->assertSame( [ true, false, false ], array_column( $actions, 'enabled' ) );
			$this->assertStringContainsString( 'не позволяет менять данные на маркетплейсе', $actions[1]['reason'] );
			$this->assertSame( $actions[1]['reason'], $actions[2]['reason'] );
		}
	}

	public function test_an_account_whose_key_cannot_write_is_refused_both_actions_before_any_remote_call(): void {
		$this->stock( false );
		$this->write_gate = static function (): bool {
			return false;
		};
		$provider         = $this->provider();

		foreach ( [ 'label', 'cancel' ] as $action ) {
			foreach ( [ 'preview', 'apply' ] as $method ) {
				try {
					$provider->$method( $this->context(), $action, [ $this->row_id( 104 ) ], [ 'reason' => '1' ] );
					$this->fail( 'A read-only key cannot ' . $action . '.' );
				} catch ( Action_State_Exception $e ) {
					$this->assertStringContainsString( 'не позволяет менять данные на маркетплейсе', $e->getMessage() );
				}
			}
		}

		$this->assertSame( [], $this->canceller->cancelled );
		$this->assertSame( [], $this->provider_calls() );
	}

	public function test_the_write_gate_is_asked_with_the_account_of_the_table_and_a_gate_that_lets_it_write_changes_nothing(): void {
		$this->stock( false );
		$asked            = [];
		$this->write_gate = function ( Account $account ) use ( &$asked ): bool {
			$asked[] = $account->id();

			return true;
		};

		$actions = $this->by_id( $this->list_body() )[ $this->row_id( 104 ) ]['actions'];

		$this->assertSame( [ true, true, true ], array_column( $actions, 'enabled' ) );
		$this->assertSame( [ $this->account_id ], array_unique( $asked ) );
	}

	public function test_a_ready_label_document_is_handed_over_by_the_label_action(): void {
		$this->stock( false );
		$document = $this->document( [ 'P-104', 'P-101' ] );

		foreach ( [ 104, 101 ] as $order ) {
			$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $this->provider()->apply( $this->context(), 'label', [ $this->row_id( $order ) ], [] ) );

			$this->assertSame( $this->label_url( $document ), $body['download']['url'], 'One set covers every order it was made for.' );
		}

		$this->assertSame( [], $this->provider_calls(), 'Nothing is asked of the marketplace again.' );
	}

	public function test_a_busy_account_does_not_push_a_posting_out_of_view_and_a_similar_number_is_not_the_same_posting(): void {
		$this->stock( false );
		$document = $this->document( [ 'P-104' ] );
		$this->document( [ 'P-1040' ] ); // Newer, and a different posting.

		for ( $i = 0; $i < 305; $i++ ) {
			$this->document( [ 'Q-' . $i ] );
		}

		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $this->provider()->apply( $this->context(), 'label', [ $this->row_id( 104 ) ], [] ) );

		$this->assertSame( $this->label_url( $document ), $body['download']['url'], 'The stored document is handed over, not asked for again — more than 300 newer documents of the account do not hide it.' );
		$this->assertSame( [], $this->provider_calls() );
	}

	public function test_a_label_task_in_the_making_is_a_process_and_blocks_asking_again(): void {
		$this->stock( false );
		$task = $this->task( [ 'P-104' ] );

		$activity = $this->by_id( $this->list_body() )[ $this->row_id( 104 ) ]['activity'];

		$this->assertSame( [ 'label' ], $activity['blocked_actions'] );
		$this->assertNotSame( '', $activity['reason'], 'A blocked action says why.' );
		$this->assertSame( [ 'label-' . $task ], array_column( $activity['processes'], 'id' ) );
		$this->assertSame( 'waiting', $activity['processes'][0]['state'] );
		$this->assertSame( [], $this->by_id( $this->list_body() )[ $this->row_id( 101 ) ]['activity']['processes'], 'The other orders are idle.' );
	}

	public function test_a_label_task_that_ended_without_a_document_is_a_failed_process_with_the_marketplaces_sentence(): void {
		$this->stock( false );
		$task = $this->task( [ 'P-104' ] );
		$this->labels->finish_task(
			$task,
			Async_Task::STATUS_FAILED,
			[
				'external_keys' => [ 'P-104' ],
				'message'       => 'Заказ ещё не собран',
			]
		);

		$activity = $this->by_id( $this->list_body() )[ $this->row_id( 104 ) ]['activity'];

		$this->assertSame( 'failed', $activity['processes'][0]['state'] );
		$this->assertSame( 'Заказ ещё не собран', $activity['processes'][0]['text'] );
		$this->assertSame( [], $activity['blocked_actions'], 'A failed try may be repeated.' );
	}

	public function test_a_posting_a_writer_holds_is_a_running_process_and_blocks_nothing(): void {
		$this->stock( false );
		$this->db->update( Sqlite_Tables::table( 'postings' ), [ 'claim_until' => gmdate( 'Y-m-d H:i:s', $this->now + 60 ) ], [ 'external_key' => 'P-101' ] );
		$this->db->update( Sqlite_Tables::table( 'postings' ), [ 'claim_until' => gmdate( 'Y-m-d H:i:s', $this->now - 60 ) ], [ 'external_key' => 'P-102' ] );

		$rows = $this->by_id( $this->list_body() );

		$this->assertSame( 'running', $rows[ $this->row_id( 101 ) ]['activity']['processes'][0]['state'] );
		$this->assertSame( [], $rows[ $this->row_id( 101 ) ]['activity']['blocked_actions'] );
		$this->assertSame( [], $rows[ $this->row_id( 102 ) ]['activity']['processes'], 'An expired claim is nothing.' );
	}

	public function test_the_activity_read_answers_the_rows_on_screen_and_names_the_ones_that_vanished(): void {
		$this->stock( false );
		$this->task( [ 'P-104' ] );

		$ids    = [ $this->row_id( 104 ), $this->row_id( 105 ), $this->row_id( 101 ) ];
		$result = $this->provider()->get_activity( $this->context(), $ids );

		$this->assertSame( [ $this->row_id( 105 ) ], $result['removed_ids'], 'The trashed order is gone.' );
		$this->assertSame( [ $this->row_id( 104 ), $this->row_id( 101 ) ], array_column( $result['rows'], 'id' ) );
		$this->assertSame( 'waiting', $result['rows'][0]['activity']['processes'][0]['state'] );
		$this->assertSame( [ $this->row_id( 1 ) ], $this->provider()->get_activity( [ 'account_id' => '99' ], [ $this->row_id( 1 ) ] )['removed_ids'] );
	}

	public function test_only_an_order_of_the_account_that_the_user_may_edit_is_authorised_for_an_action(): void {
		$this->stock( false );
		$provider = $this->provider();

		$this->assertTrue( $provider->authorize( 'list', $this->context(), [] ) );
		$this->assertTrue( $provider->authorize( 'cancel', $this->context(), [ $this->row_id( 104 ) ] ) );
		$this->assertFalse( $provider->authorize( 'cancel', $this->context(), [ '2:104' ] ), 'A row of another account.' );
		$this->assertFalse( $provider->authorize( 'cancel', $this->context(), [ $this->row_id( 999 ) ] ), 'An order that is not a registry row.' );
		$this->assertFalse( $provider->authorize( 'cancel', $this->context(), [ $this->row_id( 105 ) ] ), 'A trashed order.' );
		$this->assertFalse( $provider->authorize( 'cancel', $this->context(), [ 'nonsense' ] ) );
		$this->assertFalse( $provider->authorize( 'children', $this->context(), [ $this->row_id( 104 ) ] ), 'Orders have no children.' );
		$this->assertFalse( $provider->authorize( 'cancel', [ 'account_id' => '99' ], [ '99:104' ] ), 'An account that does not exist.' );

		$this->forbidden = [ 104 ];

		$this->assertFalse( $provider->authorize( 'label', $this->context(), [ $this->row_id( 104 ) ] ), 'The user may not edit this order.' );

		$this->forbidden       = [];
		$this->meta->owners[104] = 'cabinet-other';

		$this->assertFalse( $provider->authorize( 'label', $this->context(), [ $this->row_id( 104 ) ] ), 'The order carries another account\'s stamp.' );

		$this->meta->owners[104] = '';

		$this->assertFalse( $provider->authorize( 'label', $this->context(), [ $this->row_id( 104 ) ] ), 'An order with no stamp is nobody\'s.' );
	}

	public function test_the_activity_refresh_is_authorised_for_a_vanished_row_but_not_for_another_accounts(): void {
		$this->stock( false );
		$provider = $this->provider();

		$this->assertTrue( $provider->authorize( 'activity', $this->context(), [ $this->row_id( 105 ), $this->row_id( 104 ) ] ) );
		$this->assertFalse( $provider->authorize( 'activity', $this->context(), [ '2:104' ] ) );
	}

	public function test_asking_for_labels_queues_the_task_and_the_poll_and_the_row_shows_it(): void {
		$this->stock( false );
		$provider = $this->provider();
		$apply    = $provider->apply( $this->context(), 'label', [ $this->row_id( 104 ) ], [] );
		$body     = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $apply );

		$this->assertIsArray( $body );
		$this->assertSame( 'queued', $body['status'], 'Accepted, not done: the marketplace is still rendering.' );
		$this->assertSame( 1, $body['accepted'] );
		$this->assertSame( [ 'request', [ 'P-104' ] ], $this->provider_calls()[0] );
		$this->assertCount( 1, $this->scheduler->actions, 'The first poll is queued.' );
		$this->assertSame( 'waiting', $body['rows'][0]['activity']['processes'][0]['state'] );
		$this->assertSame( [ 'label' ], $body['rows'][0]['activity']['blocked_actions'] );
	}

	public function test_asking_again_while_the_labels_are_being_made_is_skipped_with_a_reason(): void {
		$this->stock( false );
		$this->task( [ 'P-104' ] );
		$provider = $this->provider();

		$plan = Action_Response::preview( $this->definition()->get_action( 'label' ), $provider->preview( $this->context(), 'label', [ $this->row_id( 104 ) ], [] ) );
		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $provider->apply( $this->context(), 'label', [ $this->row_id( 104 ) ], [] ) );

		$this->assertSame( [ 0, 1 ], [ $plan['eligible'], $plan['skipped'] ] );
		$this->assertSame( 'failed', $body['status'] );
		$this->assertSame( 1, $body['skipped'] );
		$this->assertSame( [ 'P-104' ], $body['groups'][0]['examples'] );
		$this->assertSame( [], $this->provider_calls(), 'The marketplace is not asked twice.' );
	}

	public function test_a_ready_document_is_handed_over_for_download_without_a_new_request(): void {
		$this->stock( false );
		$document = $this->document( [ 'P-104' ] );

		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $this->provider()->apply( $this->context(), 'label', [ $this->row_id( 104 ) ], [] ) );

		$this->assertIsArray( $body );
		$this->assertSame( 'done', $body['status'] );
		$this->assertSame( $this->label_url( $document ), $body['download']['url'] );
		$this->assertSame( [], $this->provider_calls() );
	}

	public function test_a_finished_posting_is_refused_a_label_request_without_asking_the_marketplace(): void {
		$this->stock( false );

		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $this->provider()->apply( $this->context(), 'label', [ $this->row_id( 102 ) ], [] ) );

		$this->assertSame( 'failed', $body['status'] );
		$this->assertSame( 'Отправление завершено — этикетка не нужна.', $body['groups'][0]['reason'] );
		$this->assertSame( [], $this->provider_calls(), 'No remote call for a finished posting.' );
	}

	public function test_a_posting_the_adapter_says_is_not_ready_is_refused_with_its_reason_and_no_remote_call(): void {
		$this->stock( false );
		$this->readiness = Mockery::mock( Label_Readiness::class );
		$this->readiness->shouldReceive( 'label_readiness' )->andReturn( 'Этикетка появится, когда заказ будет собран.' );

		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $this->provider()->apply( $this->context(), 'label', [ $this->row_id( 104 ) ], [] ) );

		$this->assertSame( 'failed', $body['status'] );
		$this->assertSame( 'Этикетка появится, когда заказ будет собран.', $body['groups'][0]['reason'] );
		$this->assertSame( [], $this->provider_calls() );
	}

	public function test_a_posting_the_adapter_says_is_ready_is_asked_for_as_before(): void {
		$this->stock( false );
		$this->readiness = Mockery::mock( Label_Readiness::class );
		$this->readiness->shouldReceive( 'label_readiness' )->andReturn( null );

		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $this->provider()->apply( $this->context(), 'label', [ $this->row_id( 104 ) ], [] ) );

		$this->assertSame( 'queued', $body['status'] );
		$this->assertSame( [ 'request', [ 'P-104' ] ], $this->provider_calls()[0] );
	}

	public function test_a_label_request_the_licence_or_the_marketplace_refuses_is_a_sentence_for_the_merchant(): void {
		$this->stock( false );
		$provider = $this->provider();

		$this->licensed = false;

		try {
			$provider->apply( $this->context(), 'label', [ $this->row_id( 104 ) ], [] );
			$this->fail( 'The licence is not in force.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertStringContainsString( 'этикетки', $e->getMessage() );
		}

		$this->licensed                 = true;
		$this->provider->request_fails = new \RuntimeException( 'cURL error 28 token=SECRET' );

		try {
			$provider->apply( $this->context(), 'label', [ $this->row_id( 104 ) ], [] );
			$this->fail( 'The marketplace did not answer.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertSame( 'Маркетплейс не ответил. Повторите позже.', $e->getMessage(), 'The raw failure never reaches the merchant.' );
			$this->assertStringContainsString( 'Asking for labels failed', $this->logged[0] );
		}
	}

	public function test_an_unknown_action_or_a_vanished_order_is_a_state_conflict(): void {
		$this->stock( false );

		foreach ( [ [ 'open', $this->row_id( 104 ) ], [ 'label', $this->row_id( 999 ) ] ] as $case ) {
			try {
				$this->provider()->apply( $this->context(), $case[0], [ $case[1] ], [] );
				$this->fail( 'Refused.' );
			} catch ( Action_State_Exception $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}

		try {
			$this->provider( true, false )->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => '1' ] );
			$this->fail( 'This adapter cannot cancel.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
	}

	public function test_the_cancel_window_offers_the_marketplaces_reasons_for_the_row(): void {
		$this->stock( false );
		$provider = $this->provider();

		$result = $provider->get_field_options( $this->context(), 'cancel', 'reason', [ 'row_id' => $this->row_id( 104 ), 'search' => '', 'cursor' => '' ] );

		$this->assertSame( [ [ 'value' => '1', 'label' => 'Нет товара' ], [ 'value' => '9', 'label' => 'Другое' ] ], $result['options'] );
		$this->assertNull( $result['next_cursor'] );
		$this->assertIsArray( Row_Normalizer::options_page( $result ), 'The engine accepts the options.' );

		$found = $provider->get_field_options( $this->context(), 'cancel', 'reason', [ 'row_id' => $this->row_id( 104 ), 'search' => 'дру', 'cursor' => '' ] );

		$this->assertSame( [ '9' ], array_column( $found['options'], 'value' ) );
		$this->assertSame( 1, $this->canceller->reason_calls, 'Typing in the search box filters the list already read.' );
	}

	public function test_the_reasons_of_a_posting_are_read_once_per_posting_and_a_failed_or_empty_read_is_not_kept(): void {
		$this->stock( false );
		$provider = $this->provider();
		$query    = static function ( string $row_id ): array {
			return [ 'row_id' => $row_id, 'search' => '', 'cursor' => '' ];
		};

		$provider->get_field_options( $this->context(), 'cancel', 'reason', $query( $this->row_id( 104 ) ) );
		$provider->get_field_options( $this->context(), 'cancel', 'reason', $query( $this->row_id( 104 ) ) );

		$this->assertSame( 1, $this->canceller->reason_calls );

		$provider->get_field_options( $this->context(), 'cancel', 'reason', $query( $this->row_id( 101 ) ) );

		$this->assertSame( 2, $this->canceller->reason_calls, 'Another posting is another list.' );

		$this->transients         = [];
		$this->canceller->reasons = [];
		$provider->get_field_options( $this->context(), 'cancel', 'reason', $query( $this->row_id( 104 ) ) );
		$provider->get_field_options( $this->context(), 'cancel', 'reason', $query( $this->row_id( 104 ) ) );

		$this->assertSame( 4, $this->canceller->reason_calls, 'An empty answer is asked for again.' );
	}

	public function test_the_cancel_window_does_not_ask_the_marketplace_without_the_account_and_the_licence(): void {
		$this->stock( false );
		$provider = $this->provider();
		$query    = [ 'row_id' => $this->row_id( 104 ), 'search' => '', 'cursor' => '' ];
		$none     = [ 'options' => [], 'next_cursor' => null ];

		$this->licensed = false;

		$this->assertSame( $none, $provider->get_field_options( $this->context(), 'cancel', 'reason', $query ), 'No licence in force.' );

		$this->licensed = true;
		$this->set_account( [ 'orders_enabled' => false ] );

		$this->assertSame( $none, $provider->get_field_options( $this->context(), 'cancel', 'reason', $query ), 'Order receiving is off.' );
		$this->assertSame( 0, $this->canceller->reason_calls );
	}

	public function test_the_cancel_window_asks_the_marketplace_only_for_an_order_the_user_may_cancel(): void {
		$this->stock( false );
		$provider = $this->provider();
		$none     = [ 'options' => [], 'next_cursor' => null ];

		$this->forbidden = [ 104 ];

		$this->assertSame( $none, $provider->get_field_options( $this->context(), 'cancel', 'reason', [ 'row_id' => $this->row_id( 104 ), 'search' => '', 'cursor' => '' ] ) );
		$this->assertSame( $none, $provider->get_field_options( $this->context(), 'cancel', 'reason', [ 'row_id' => '', 'search' => '', 'cursor' => '' ] ) );
		$this->assertSame( $none, $provider->get_field_options( $this->context(), 'cancel', 'message', [ 'row_id' => $this->row_id( 101 ), 'search' => '', 'cursor' => '' ] ) );
		$this->assertSame( $none, $provider->get_field_options( $this->context(), 'label', 'reason', [ 'row_id' => $this->row_id( 101 ), 'search' => '', 'cursor' => '' ] ) );
		$this->assertSame( 0, $this->canceller->reason_calls );
	}

	public function test_the_cancel_plan_counts_the_order_and_skips_a_finished_posting(): void {
		$this->stock( false );
		$provider = $this->provider();
		$action   = $this->definition()->get_action( 'cancel' );

		$open     = Action_Response::preview( $action, $provider->preview( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [] ) );
		$finished = Action_Response::preview( $action, $provider->preview( $this->context(), 'cancel', [ $this->row_id( 103 ) ], [] ) );

		$this->assertTrue( $open['can_apply'] );
		$this->assertSame( [ 1, 1, 0 ], [ $open['requested'], $open['eligible'], $open['skipped'] ] );
		$this->assertFalse( $finished['can_apply'] );
		$this->assertSame( [ 'P-103' ], $finished['groups'][0]['examples'] );
		$this->assertSame( [], $this->canceller->cancelled, 'A preview cancels nothing.' );
	}

	public function test_cancelling_asks_the_marketplace_notes_the_order_and_queues_the_refresh(): void {
		$this->stock( false );
		$provider = $this->provider();

		$result = $provider->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => '1', 'message' => '' ] );
		$body   = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'cancel' ), $result );

		$this->assertIsArray( $body );
		$this->assertSame( 'done', $body['status'] );
		$this->assertSame( [ [ $this->account_id, 'P-104', 1, '' ] ], $this->canceller->cancelled );
		$this->assertSame( [ [ $this->account_id, 'P-104' ] ], $this->canceller->refreshed );
		$this->assertCount( 1, $this->notes[104] );
		$this->assertStringContainsString( 'Нет товара', $this->notes[104][0] );
		$this->assertSame( 'Отправление отменено. Заказ обновится в течение нескольких минут.', $body['messages']['action_status_done'] );
		$this->assertSame( [ $this->row_id( 104 ) ], array_column( $body['rows'], 'id' ) );
	}

	public function test_the_reason_that_asks_for_a_message_needs_one_and_a_missing_reason_is_a_field_error(): void {
		$this->stock( false );
		$provider = $this->provider();

		try {
			$provider->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => '9', 'message' => '  ' ] );
			$this->fail( 'The «other» reason needs words.' );
		} catch ( Action_Input_Exception $e ) {
			$this->assertSame( [ 'message' ], array_keys( $e->get_errors() ) );
		}

		try {
			$provider->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => 'x' ] );
			$this->fail( 'No reason.' );
		} catch ( Action_Input_Exception $e ) {
			$this->assertSame( [ 'reason' ], array_keys( $e->get_errors() ) );
		}

		$provider->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => '9', 'message' => 'Покупатель передумал' ] );

		$this->assertSame( [ [ $this->account_id, 'P-104', 9, 'Покупатель передумал' ] ], $this->canceller->cancelled );
	}

	public function test_a_finished_posting_is_skipped_by_the_apply_too(): void {
		$this->stock( false );

		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'cancel' ), $this->provider()->apply( $this->context(), 'cancel', [ $this->row_id( 103 ) ], [ 'reason' => '1' ] ) );

		$this->assertSame( 'failed', $body['status'] );
		$this->assertSame( 1, $body['skipped'] );
		$this->assertSame( [], $this->canceller->cancelled );
	}

	public function test_a_cancellation_the_marketplace_refuses_is_a_conflict_with_its_sentence(): void {
		$this->stock( false );

		try {
			$this->provider()->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => '77' ] );
			$this->fail( 'The reason is unknown to the marketplace.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertSame( 'Неизвестная причина', $e->getMessage() );
		}

		$this->assertSame( [], $this->notes, 'Nothing is written on the order.' );
	}

	public function test_the_account_and_the_licence_must_allow_cancelling_before_the_marketplace_is_asked(): void {
		$this->stock( false );
		$provider = $this->provider();

		$this->licensed = false;

		foreach ( [ 'preview', 'apply' ] as $method ) {
			try {
				$provider->$method( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => '1' ] );
				$this->fail( 'The licence is not in force.' );
			} catch ( Action_State_Exception $e ) {
				$this->assertStringContainsString( 'отменить отправление', $e->getMessage() );
			}
		}

		$this->licensed = true;
		$this->set_account( [ 'orders_enabled' => false ] );

		try {
			$provider->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => '1' ] );
			$this->fail( 'With order receiving off the order would never be updated.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertStringContainsString( 'Приём заказов', $e->getMessage() );
		}

		$this->assertSame( [], $this->canceller->cancelled );
	}

	public function test_the_cancel_check_reads_the_order_receiving_switch_in_force(): void {
		$this->stock( false );
		$this->licensed = true;
		$provider       = $this->provider();

		// The account follows «Общие»; its own column says «on», the default says «off».
		$this->set_account( [ 'orders_enabled' => true, Effective_Settings::OVERRIDES_KEY => Effective_Settings::default_overrides() ] );
		$option = Effective_Settings::option_name( $this->account_now()->adapter_id() );
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( $option ) {
				return $option === $key ? [ 'orders_enabled' => false ] : $default;
			}
		);

		try {
			$provider->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => '1' ] );
			$this->fail( 'The default turns order receiving off, whatever the account column holds.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertStringContainsString( 'Приём заказов', $e->getMessage() );
		}

		// The reverse: own column off, but the account follows a default that is on.
		$this->set_account( [ 'orders_enabled' => false ] );
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( $option ) {
				return $option === $key ? [ 'orders_enabled' => true ] : $default;
			}
		);

		$provider->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [ 'reason' => '1' ] );
		$this->assertNotSame( [], $this->canceller->cancelled );
	}

	/**
	 * The calls the marketplace's label provider received.
	 *
	 * @return array<int,array{0:string,1:mixed}>
	 */
	private function provider_calls(): array {
		return $this->provider->calls;
	}

	/**
	 * Orders 101 (two goods, new), 102 (handed to delivery) and 104 (one good, new) with the seller actions on.
	 *
	 * @return void
	 */
	private function stock_with_seller_actions(): void {
		$this->stock( false );
		$this->seller = new Fake_Seller_Adapter();
		$this->units  = [
			101 => [
				[ 'u-1', 'Футболка, M', 'new' ],
				[ 'u-2', 'Кепка', 'new' ],
			],
			102 => [
				[ 'u-3', 'Носки', 'deliver' ],
			],
			104 => [
				[ 'u-4', 'Худи, L', 'new' ],
			],
		];
	}

	/**
	 * @param string              $action Action id.
	 * @param int[]               $ids    Order ids.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,mixed> The apply answer as the engine would send it.
	 */
	private function seller_apply( string $action, array $ids, array $payload = [] ): array {
		$definition = $this->definition();
		$result     = $this->provider()->apply( $this->context(), $action, array_map( [ $this, 'row_id' ], $ids ), $payload );
		$body       = Action_Response::apply( $definition, $definition->get_action( $action ), $result );

		$this->assertIsArray( $body, 'The engine accepts the report.' );

		return $body;
	}

	public function test_declared_seller_actions_are_row_and_bulk_actions_of_the_table(): void {
		$this->stock_with_seller_actions();

		$definition = $this->definition();

		$this->assertSame( [ 'open', 'label', 'act-confirm', 'act-receive', 'cancel' ], $definition->get_row_action_ids(), 'The marking code is offered on a unit only.' );
		$this->assertSame( 'primary', $definition->get_action( 'bulk-confirm' )['tone'] );
		$this->assertSame( 'После этого покупатель не сможет отменить заказ.', $definition->get_action( 'act-confirm' )['scope_sentence'] );
		$this->assertSame( [ 'batch' ], array_column( $definition->get_action( 'bulk-supply' )['fields'], 'id' ) );
		$this->assertNull( $definition->get_action( 'act-supply' ), 'A bulk-only action has no row button.' );
		$this->assertNull( $definition->get_action( 'bulk-receive' ) );
		$this->assertSame( 'Выберите действие', $definition->to_array()['messages']['bulk_placeholder'] );
	}

	public function test_a_table_without_seller_actions_has_no_bulk_actions_and_rows_that_cannot_be_ticked(): void {
		$this->stock( false );

		$this->assertSame( [], $this->definition()->to_array()['bulk_actions'] );
		$this->assertFalse( $this->by_id( $this->list_body() )[ $this->row_id( 104 ) ]['selectable'] );
	}

	public function test_a_row_shows_a_seller_action_off_with_the_reason_in_a_status_that_does_not_allow_it(): void {
		$this->stock_with_seller_actions();

		$rows = $this->by_id( $this->list_body() );
		$row  = array_column( $rows[ $this->row_id( 102 ) ]['actions'], null, 'id' );

		$this->assertTrue( $rows[ $this->row_id( 102 ) ]['selectable'] );
		$this->assertSame( [ 'open', 'label', 'act-confirm', 'act-receive' ], array_keys( $row ), 'A finished posting has no cancellation.' );
		$this->assertFalse( $row['act-confirm']['enabled'] );
		$this->assertSame( 'В статусе «Статус deliver» это действие недоступно.', $row['act-confirm']['reason'] );
		$this->assertTrue( $row['act-receive']['enabled'] );
	}

	public function test_a_row_shows_the_marketplaces_own_objection_and_leaves_out_an_action_that_does_not_exist_for_its_goods(): void {
		$this->stock_with_seller_actions();
		$this->units[104]               = [ [ 'u-4', 'Худи, L', 'new', [ 'confirm' ] ] ];
		$this->seller                   = new Fake_Seller_Adapter();
		$this->seller->objections['u-4'] = 'Покупатель ещё может отменить заказ.';

		$row = array_column( $this->by_id( $this->list_body() )[ $this->row_id( 104 ) ]['actions'], null, 'id' );

		$this->assertSame( [ 'open', 'label', 'act-confirm', 'cancel' ], array_keys( $row ) );
		$this->assertSame( 'Покупатель ещё может отменить заказ.', $row['act-confirm']['reason'] );
	}

	public function test_a_very_long_objection_of_the_marketplace_is_shortened_not_a_broken_table(): void {
		$this->stock_with_seller_actions();
		$this->seller                    = new Fake_Seller_Adapter();
		$this->seller->objections['u-4'] = str_repeat( 'Причина. ', 100 );

		$row = array_column( $this->by_id( $this->list_body() )[ $this->row_id( 104 ) ]['actions'], null, 'id' );

		$this->assertSame( 500, mb_strlen( $row['act-confirm']['reason'] ) );
	}

	public function test_a_user_who_may_not_edit_the_order_sees_its_seller_actions_off_and_cannot_tick_it(): void {
		$this->stock_with_seller_actions();
		$this->forbidden = [ 104 ];

		$row = $this->by_id( $this->list_body() )[ $this->row_id( 104 ) ];

		$this->assertFalse( $row['selectable'] );
		$this->assertSame( 'Нет права изменять этот заказ.', array_column( $row['actions'], null, 'id' )['act-confirm']['reason'] );
	}

	public function test_a_bulk_action_applies_every_unit_that_takes_it_and_shows_the_refused_ones_with_their_own_text(): void {
		$this->stock_with_seller_actions();
		$this->seller->refusals['u-2'] = 'Товар уже отменён покупателем.';

		$body = $this->seller_apply( 'bulk-confirm', [ 101, 102, 104 ] );

		$this->assertSame( [ 'u-1', 'u-2', 'u-4' ], Fake_Seller_Adapter::unit_ids( $this->seller->calls[0] ), 'One call, only the units that take the action.' );
		$this->assertSame( [ 'partial', 4, 2, 1, 1 ], [ $body['status'], $body['requested'], $body['accepted'], $body['failed'], $body['skipped'] ] );
		$this->assertSame( [ 'failed', 'Товар уже отменён покупателем.', 1, [ '№ 101: Кепка' ] ], [ $body['groups'][0]['kind'], $body['groups'][0]['reason'], $body['groups'][0]['count'], $body['groups'][0]['examples'] ] );
		$this->assertSame( [ 'skipped', 'В статусе «Статус deliver» это действие недоступно.', [ '№ 102: Носки' ] ], [ $body['groups'][1]['kind'], $body['groups'][1]['reason'], $body['groups'][1]['examples'] ] );
		$this->assertSame( [ 101, 104 ], $this->seller->refreshed, 'The skipped order was not sent, so it is not refreshed.' );
		$this->assertSame( [ 'Фейк: действие «Принять в работу», из WooCommerce. Выполнено: Футболка, M. Не выполнено: Кепка — Товар уже отменён покупателем.' ], $this->notes[101] );
		$this->assertArrayNotHasKey( 102, $this->notes );
		$this->assertCount( 3, $body['rows'] );
	}

	public function test_a_retry_of_a_partly_accepted_batch_sends_only_what_did_not_go(): void {
		$this->stock_with_seller_actions();
		$this->seller->refusals['u-2'] = 'Склад не готов.';
		$this->seller_apply( 'bulk-confirm', [ 101, 104 ] );

		// The queued refresh has not run: the stored orders still say «new».
		$this->seller->refusals = [];
		$plan                   = $this->provider()->preview( $this->context(), 'bulk-confirm', [ $this->row_id( 101 ), $this->row_id( 104 ) ], [] );
		$body                   = $this->seller_apply( 'bulk-confirm', [ 101, 104 ] );

		$this->assertSame( [ 3, 1, 2 ], [ $plan['requested'], $plan['eligible'], $plan['skipped'] ] );
		$this->assertCount( 2, $this->seller->calls );
		$this->assertSame( [ 'u-2' ], Fake_Seller_Adapter::unit_ids( $this->seller->calls[1] ), 'The accepted units are not sent again.' );
		$this->assertSame( [ 'partial', 1, 2, 0 ], [ $body['status'], $body['accepted'], $body['skipped'], $body['failed'] ] );
		$this->assertSame( 'Это действие уже отправлено для этой позиции. Дождитесь, пока заказ обновится.', $body['groups'][0]['reason'] );
		$this->assertSame( [ '№ 101: Футболка, M', '№ 104: Худи, L' ], $body['groups'][0]['examples'] );
		$this->assertCount( 2, $this->notes[101], 'One note per sending: nothing was repeated.' );
		$this->assertCount( 1, $this->notes[104] );

		$third = $this->seller_apply( 'bulk-confirm', [ 101, 104 ] );

		$this->assertSame( [ 'failed', 0, 3 ], [ $third['status'], $third['accepted'], $third['skipped'] ] );
		$this->assertCount( 2, $this->seller->calls );
	}

	public function test_a_preview_counts_the_goods_and_calls_no_marketplace(): void {
		$this->stock_with_seller_actions();

		$plan = Action_Response::preview( $this->definition()->get_action( 'bulk-confirm' ), $this->provider()->preview( $this->context(), 'bulk-confirm', [ $this->row_id( 101 ), $this->row_id( 102 ) ], [] ) );

		$this->assertSame( [ 3, 2, 1, true ], [ $plan['requested'], $plan['eligible'], $plan['skipped'], $plan['can_apply'] ] );
		$this->assertSame( [], $this->seller->calls );
	}

	public function test_a_seller_action_the_status_does_not_allow_is_refused_by_the_server_without_a_remote_call(): void {
		$this->stock_with_seller_actions();

		$plan = $this->provider()->preview( $this->context(), 'act-receive', [ $this->row_id( 104 ) ], [] );
		$body = $this->seller_apply( 'act-receive', [ 104 ] );

		$this->assertSame( 0, $plan['eligible'] );
		$this->assertSame( [ 'failed', 0, 1 ], [ $body['status'], $body['accepted'], $body['skipped'] ] );
		$this->assertSame( [], $this->seller->calls );
		$this->assertSame( [], $this->seller->checked );
		$this->assertSame( [], $this->notes );
	}

	public function test_a_unit_the_marketplace_does_not_mention_is_failed_never_done(): void {
		$this->stock_with_seller_actions();
		$this->seller->unanswered = [ 'u-2' ];

		$body = $this->seller_apply( 'act-confirm', [ 101 ] );

		$this->assertSame( [ 1, 1 ], [ $body['accepted'], $body['failed'] ] );
		$this->assertStringContainsString( 'не ответил по этой позиции', $body['groups'][0]['reason'] );
	}

	public function test_the_input_is_checked_by_the_marketplace_before_anything_is_sent(): void {
		$this->stock_with_seller_actions();
		$this->seller->input_errors = [ 'batch' => 'Эта поставка уже передана в доставку.' ];

		foreach ( [ 'preview', 'apply' ] as $method ) {
			try {
				$this->provider()->$method( $this->context(), 'bulk-supply', [ $this->row_id( 101 ) ], [ 'batch' => 'WB-1' ] );
				$this->fail( $method . ' accepted a refused input.' );
			} catch ( Action_Input_Exception $e ) {
				$this->assertSame( [ 'batch' => 'Эта поставка уже передана в доставку.' ], $e->get_errors() );
			}
		}

		$this->assertSame( 'WB-1', $this->seller->checked[0]->value( 'batch' ) );
		$this->assertSame( [], $this->seller->calls );
	}

	public function test_a_required_field_left_empty_never_reaches_the_marketplace(): void {
		$this->stock_with_seller_actions();

		$this->expectException( Action_Input_Exception::class );

		try {
			$this->provider()->apply( $this->context(), 'bulk-supply', [ $this->row_id( 101 ) ], [] );
		} finally {
			$this->assertSame( [], $this->seller->checked );
			$this->assertSame( [], $this->seller->calls );
		}
	}

	public function test_a_refusal_of_the_whole_call_is_the_sentence_of_the_dialog(): void {
		$this->stock_with_seller_actions();
		$this->seller->failure = new Seller_Action_Refused( 'Кабинет заблокирован за долг.', 502 );

		try {
			$this->seller_apply( 'act-confirm', [ 101 ] );
			$this->fail( 'A refused call is not a report.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertSame( 'Кабинет заблокирован за долг.', $e->getMessage() );
		}

		$this->assertSame( [], $this->seller->refreshed, 'Nothing was done.' );
		$this->assertSame( [], $this->notes );
	}

	public function test_a_lost_answer_says_the_action_may_have_happened_refreshes_the_orders_and_is_logged(): void {
		$this->stock_with_seller_actions();
		$this->seller->failure = new \RuntimeException( 'HTTP 503' );

		try {
			$this->seller_apply( 'act-confirm', [ 101 ] );
			$this->fail( 'A lost answer is not a report.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertStringContainsString( 'действие могло выполниться', $e->getMessage() );
		}

		$this->assertSame( [ 101 ], $this->seller->refreshed );
		$this->assertStringContainsString( 'HTTP 503', $this->logged[0] );
	}

	public function test_without_a_licence_or_a_key_that_can_write_a_seller_action_never_reaches_the_marketplace(): void {
		$this->stock_with_seller_actions();
		$this->licensed = false;

		try {
			$this->provider()->apply( $this->context(), 'act-confirm', [ $this->row_id( 101 ) ], [] );
			$this->fail( 'No licence, no action.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertStringContainsString( 'выполнить действие «Принять в работу» на маркетплейсе', $e->getMessage() );
		}

		$row = array_column( $this->by_id( $this->list_body() )[ $this->row_id( 101 ) ]['actions'], null, 'id' );

		$this->assertFalse( $row['act-confirm']['enabled'] );

		$this->licensed   = true;
		$this->write_gate = '__return_false';

		try {
			$this->provider()->apply( $this->context(), 'bulk-confirm', [ $this->row_id( 101 ) ], [] );
			$this->fail( 'A read-only key writes nothing.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertStringContainsString( 'не позволяет менять данные', $e->getMessage() );
		}

		$this->assertSame( [], $this->seller->calls );
	}

	public function test_the_choices_of_a_seller_field_come_from_the_adapter(): void {
		$this->stock_with_seller_actions();

		$options = $this->provider()->get_field_options( $this->context(), 'bulk-supply', 'batch', [ 'row_id' => '', 'search' => '', 'cursor' => '' ] );

		$this->assertSame( [ [ 'value' => 'WB-1', 'label' => 'Поставка от 26.11' ] ], $options['options'] );
	}

	public function test_a_cancellation_that_takes_no_reason_asks_for_none_and_writes_a_note_without_one(): void {
		$this->stock( false );
		$this->canceller = new Fake_Reasonless_Canceller();

		$action = $this->definition()->get_action( 'cancel' );

		$this->assertSame( [], $action['fields'] );
		$this->assertStringContainsString( 'За отмену по вине продавца начисляется штраф.', $action['scope_sentence'] );

		$body = Action_Response::apply( $this->definition(), $action, $this->provider()->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [] ) );

		$this->assertSame( 'done', $body['status'] );
		$this->assertSame( [ [ $this->account_id, 'P-104', 0, '' ] ], $this->canceller->cancelled );
		$this->assertSame( 0, $this->canceller->reason_calls );
		$this->assertSame( [ 'Отправление P-104 отменено: Фейк, из WooCommerce.' ], $this->notes[104] );
	}

	public function test_a_cancellation_with_reasons_still_asks_for_one(): void {
		$this->stock( false );

		$this->assertSame( [ 'reason', 'message' ], array_column( $this->definition()->get_action( 'cancel' )['fields'], 'id' ) );

		$this->expectException( Action_Input_Exception::class );
		$this->provider()->apply( $this->context(), 'cancel', [ $this->row_id( 104 ) ], [] );
	}
}
