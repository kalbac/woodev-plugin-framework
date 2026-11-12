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
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Activity;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Cancel;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Ids;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Labels;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Provider;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Reader;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Rows;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Order_Canceller;
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

	protected function setUp(): void {
		parent::setUp();

		$this->transients = [];
		$this->forbidden  = [];
		$this->notes     = [];
		$this->logged    = [];
		$this->meta      = new Fake_Order_Meta();
		$this->canceller = new Fake_Order_Canceller();

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
		Functions\when( 'wc_get_orders' )->alias(
			function ( array $args ) {
				return array_values( array_intersect_key( $this->wc_orders, array_flip( $args['include'] ) ) );
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
			static function ( $key ) use ( $meta ) {
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

		return new Site_Orders_Provider(
			$this->accounts(),
			new Site_Orders_Reader( $this->db, Sqlite_Tables::table( 'postings' ), $this->shop->storage() ),
			new Site_Orders_Rows(
				Site_Orders_Provider::offered_actions( null !== $labels, null !== $cancel ),
				$can,
				static function ( \WC_Order $order ) use ( $cancel ): bool {
					return null !== $cancel && $cancel->is_finished( $order );
				},
				function ( int $document_id ): string {
					return $this->label_url( $document_id );
				}
			),
			new Site_Orders_Activity( $this->clock() ),
			$labels,
			$cancel,
			$this->meta,
			$can,
			Sqlite_Tables::MARKETPLACE_ID,
			function ( string $line ): void {
				$this->logged[] = $line;
			}
		);
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

	public function test_the_definition_is_accepted_by_the_engine_and_declares_what_the_adapter_can_do(): void {
		$this->stock( false );

		$definition = $this->definition()->to_array();
		$actions    = array_column( $definition['row_actions'], null, 'id' );

		$this->assertSame( [ 'order', 'posting', 'status', 'date', 'customer', 'total', 'label', 'actions' ], array_column( $definition['columns'], 'id' ) );
		$this->assertSame( [ 'open', 'label', 'cancel' ], array_column( $definition['row_actions'], 'id' ) );
		$this->assertSame( [], $definition['bulk_actions'] );
		$this->assertSame( [ 'date', 'order', 'posting', 'status', 'total' ], $definition['sorts']['keys'] );
		$this->assertSame( [ 'key' => 'date', 'direction' => 'desc' ], $definition['sorts']['default'] );
		$this->assertSame( [ 'q', 'state', 'wc_status', 'date' ], array_column( $definition['filters'], 'id' ) );
		$this->assertSame( 'date_range', $definition['filters'][3]['type'] );
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
		$this->assertStringContainsString( '4', $body['messages']['summary'] );

		$cells = $rows[ $this->row_id( 104 ) ]['cells'];

		$this->assertSame( 'link', $cells['order'][0]['type'] );
		$this->assertSame( '№ 104', $cells['order'][0]['value']['label'] );
		$this->assertSame( 'https://shop.test/wp-admin/post.php?post=104&action=edit', $cells['order'][0]['value']['url'] );
		$this->assertSame( 'P-104', $cells['posting'] );
		$this->assertSame( [ 'label' => 'Processing', 'tone' => 'info' ], $cells['status'][0]['value'] );
		$this->assertSame( 'delivering', $cells['status'][1]['value'], 'The marketplace\'s own status is shown under the WooCommerce one.' );
		$this->assertSame( '2026-11-07T08:30:00Z', $cells['date'] );
		$this->assertNull( $cells['customer'], 'An order with no buyer name has an empty cell.' );
		$this->assertStringContainsString( '250', $cells['total'] );
		$this->assertStringNotContainsString( '<', $cells['total'], 'The price is plain text.' );
		$this->assertNull( $cells['label'] );
		$this->assertSame( 'Борис Лебедев', $rows[ $this->row_id( 102 ) ]['cells']['customer'] );
		$this->assertSame( 'danger', $rows[ $this->row_id( 103 ) ]['cells']['status'][0]['value']['tone'] );
		$this->assertFalse( $rows[ $this->row_id( 104 ) ]['selectable'] );
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

	public function test_a_finished_posting_cannot_be_cancelled_and_says_why(): void {
		$this->stock( false );

		$rows = $this->by_id( $this->list_body() );

		$this->assertSame( [ true, true, true ], array_column( $rows[ $this->row_id( 104 ) ]['actions'], 'enabled' ) );
		$this->assertSame( [ true, true, false ], array_column( $rows[ $this->row_id( 103 ) ]['actions'], 'enabled' ) );
		$this->assertSame( 'Это отправление уже завершено или отменено, отменить его нельзя.', $rows[ $this->row_id( 103 ) ]['actions'][2]['reason'] );
	}

	public function test_a_ready_label_document_is_a_link_in_the_label_column(): void {
		$this->stock( false );
		$document = $this->document( [ 'P-104', 'P-101' ] );

		$rows = $this->by_id( $this->list_body() );

		$this->assertSame( [ 'label' => 'Скачать', 'url' => $this->label_url( $document ) ], $rows[ $this->row_id( 104 ) ]['cells']['label'] );
		$this->assertSame( [ 'label' => 'Скачать', 'url' => $this->label_url( $document ) ], $rows[ $this->row_id( 101 ) ]['cells']['label'], 'One set covers every order it was made for.' );
		$this->assertNull( $rows[ $this->row_id( 102 ) ]['cells']['label'] );
	}

	public function test_a_busy_account_does_not_push_a_posting_out_of_view_and_a_similar_number_is_not_the_same_posting(): void {
		$this->stock( false );
		$document = $this->document( [ 'P-104' ] );
		$this->document( [ 'P-1040' ] ); // Newer, and a different posting.

		for ( $i = 0; $i < 305; $i++ ) {
			$this->document( [ 'Q-' . $i ] );
		}

		$rows = $this->by_id( $this->list_body() );

		$this->assertSame( [ 'label' => 'Скачать', 'url' => $this->label_url( $document ) ], $rows[ $this->row_id( 104 ) ]['cells']['label'], 'More than 300 newer documents of the account do not hide it.' );

		$body = Action_Response::apply( $this->definition(), $this->definition()->get_action( 'label' ), $this->provider()->apply( $this->context(), 'label', [ $this->row_id( 104 ) ], [] ) );

		$this->assertSame( $this->label_url( $document ), $body['download']['url'], 'The stored document is handed over, not asked for again.' );
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
}
