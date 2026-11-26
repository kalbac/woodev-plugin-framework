<?php
/**
 * The work of a «Товары сайта» bulk run: the cursor, one part with the ticked mode's code, the first send of D1, the gates.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Filter;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Run_Worker;
use Woodev\Framework\Marketplace\Data\Bulk_Run;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Lost;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;

require_once dirname( __DIR__ ) . '/Mappings/wp-term-stub.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Run_Worker
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Reader
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk
 */
final class BulkRunWorkerTest extends Products_Test_Case {
	/**
	 * Whether the user may edit products.
	 *
	 * @var bool
	 */
	private $may_edit = true;

	protected function setUp(): void {
		parent::setUp();

		$this->may_edit = true;

		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'user_can' )->alias(
			function (): bool {
				return $this->may_edit;
			}
		);
	}

	/**
	 * @param bool $groups_exports Whether the adapter groups its exports.
	 * @return Site_Products_Run_Worker
	 */
	private function worker( bool $groups_exports = false ): Site_Products_Run_Worker {
		return new Site_Products_Run_Worker(
			$this->reader(),
			$this->bulk(),
			new License_Gate(
				function (): bool {
					return $this->licensed;
				}
			),
			null,
			$groups_exports
		);
	}

	/**
	 * Reads one part from a cursor, without doing it.
	 *
	 * @param Site_Products_Filter $filter Filter.
	 * @param int                  $parent Cursor parent.
	 * @param int                  $child  Cursor child.
	 * @param int                  $size   Part size.
	 * @param bool                 $whole  Whether a product's variations are all read at once.
	 * @return array{offers:int[],editable:array<int,int>,cursor:array{parent:int,child:int},products:int,exhausted:bool}
	 */
	private function read( Site_Products_Filter $filter, int $parent = 0, int $child = 0, int $size = 2, bool $whole = false ): array {
		$method = new \ReflectionMethod( Site_Products_Run_Worker::class, 'read' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return $method->invoke( $this->worker(), $filter, $parent, $child, $size, $whole );
	}

	/**
	 * Reads parts one after another until the end, as the ticks do.
	 *
	 * @param Site_Products_Filter $filter Filter.
	 * @param int                  $size   Part size.
	 * @return array<int,int[]> Offers of each part.
	 */
	private function walk( Site_Products_Filter $filter, int $size ): array {
		$parts  = [];
		$parent = 0;
		$child  = 0;

		do {
			$part   = $this->read( $filter, $parent, $child, $size );
			$parent = $part['cursor']['parent'];
			$child  = $part['cursor']['child'];

			if ( [] !== $part['offers'] ) {
				$parts[] = $part['offers'];
			}

			$this->assertLessThanOrEqual( $size, count( $part['offers'] ), 'A part never holds more than its size.' );
		} while ( ! $part['exhausted'] );

		return $parts;
	}

	/**
	 * @param string $action  Action id.
	 * @param array  $payload Fields.
	 * @param array  $filters Filters of the table.
	 * @return Bulk_Run
	 */
	private function run_of( string $action, array $payload = [], array $filters = [] ): Bulk_Run {
		return new Bulk_Run(
			[
				'account_id' => $this->account_id,
				'run_id'     => 'r1',
				'state'      => 'running',
				'cancel'     => 0,
				'action'     => $action,
				'payload'    => (string) wp_json_encode( $payload ),
				'filter'     => (string) wp_json_encode( [ 'search' => '', 'filters' => $filters ] ),
				'user_id'    => 3,
				'started_at' => '2026-11-16 10:00:00',
			]
		);
	}

	/**
	 * Adds `$count` published variations under a variable product, ids from `$first`.
	 *
	 * @param int $parent Variable product id.
	 * @param int $first  First variation id.
	 * @param int $count  How many.
	 * @param bool $linked Whether each gets a created link with both switches on.
	 * @return int[]
	 */
	private function variations( int $parent, int $first, int $count, bool $linked = false ): array {
		$ids = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$id = $first + $i;
			$this->shop->variation( $id, $parent, 'V-' . $id, 1, '10', 'v' . $id );

			if ( $linked ) {
				$this->shop->link( $id, $parent, 'V-' . $id, Link_Record::STATE_CREATED );
			}

			$ids[] = $id;
		}

		return $ids;
	}

	public function test_a_walk_crosses_a_simple_product_a_variable_one_and_a_part_boundary_inside_it(): void {
		$parts = $this->walk( new Site_Products_Filter( $this->account_id ), 2 );

		// 10 is simple; 20 has the variations 21 and 22; 30 is simple. Size 2 cuts 20's variations from 10 and from 30.
		$this->assertSame( [ [ 10, 21 ], [ 22, 30 ] ], $parts );
	}

	public function test_a_variation_is_edited_by_the_right_to_edit_its_parent(): void {
		$part = $this->read( new Site_Products_Filter( $this->account_id ), 10, 0, 5 );

		$this->assertSame( [ 21, 22, 30 ], $part['offers'] );
		$this->assertSame( [ 21 => 20, 22 => 20, 30 => 30 ], $part['editable'] );
	}

	public function test_a_variable_product_with_101_variations_is_finished_over_two_parts_and_every_variation_is_reached_once(): void {
		$this->shop->product( 1000, 'Большой', 'BIG', null, null, 'variable' );
		$ids = $this->variations( 1000, 1001, 101 );

		$parts = $this->walk( new Site_Products_Filter( $this->account_id, '', 0, false, 'Большой' ), 100 );

		$this->assertSame( [ 100, 1 ], array_map( 'count', $parts ) );
		$this->assertSame( $ids, array_merge( ...$parts ) );
	}

	public function test_a_part_that_keeps_products_whole_reads_every_variation_of_the_product_it_enters(): void {
		$this->shop->product( 1000, 'Большой', 'BIG', null, null, 'variable' );
		$ids = $this->variations( 1000, 1001, 5 );
		$this->shop->product( 1100, 'Большой после', 'BIG-AFTER', 1, '5', 'simple' );
		$filter = new Site_Products_Filter( $this->account_id, '', 0, false, 'Большой' );

		$first = $this->read( $filter, 0, 0, 2, true );

		$this->assertSame( $ids, $first['offers'], 'Over the size: the product is not cut.' );
		$this->assertSame( [ 'parent' => 1000, 'child' => 0 ], $first['cursor'] );
		$this->assertSame( 1, $first['products'] );
		$this->assertSame( [ 1100 ], $this->read( $filter, 1000, 0, 2, true )['offers'] );
	}

	public function test_only_an_export_of_a_grouping_adapter_keeps_products_whole(): void {
		$this->shop->product( 1000, 'Большой', 'BIG', null, null, 'variable' );
		$this->variations( 1000, 1001, 101 );

		$export = $this->run_of( Site_Products_Bulk::EXPORT, [], [ 'q' => 'Большой' ] );
		$reset  = $this->run_of( Site_Products_Bulk::RESET, [], [ 'q' => 'Большой' ] );
		$owns   = static function (): bool {
			return true;
		};

		$this->assertSame( [ 'parent' => 1000, 'child' => 0 ], $this->worker( true )->part( $this->account_now(), $export, [], $owns )['cursor'] );
		$this->assertSame( [ 'parent' => 1000, 'child' => 1100 ], $this->worker()->part( $this->account_now(), $export, [], $owns )['cursor'] );
		$this->assertSame( [ 'parent' => 1000, 'child' => 0 ], $this->worker( true )->part( $this->account_now(), $reset, [], $owns )['cursor'], 'A part of 500 holds all 101 anyway.' );
	}

	public function test_a_variable_product_with_501_variations_is_finished_over_two_parts_and_the_parent_cursor_moves_after_the_last(): void {
		$this->shop->product( 1000, 'Огромный', 'HUGE', null, null, 'variable' );
		$this->variations( 1000, 1001, 501 );
		$filter = new Site_Products_Filter( $this->account_id, '', 0, false, 'Огромный' );

		$first = $this->read( $filter, 0, 0, 500 );
		$this->assertCount( 500, $first['offers'] );
		$this->assertSame( [ 'parent' => 1000, 'child' => 1500 ], $first['cursor'], 'Parent 1000 is entered but not finished.' );
		$this->assertSame( 0, $first['products'] );

		$second = $this->read( $filter, 1000, 1500, 500 );
		$this->assertSame( [ 1501 ], $second['offers'] );
		$this->assertSame( 1, $second['products'], 'The parent counts as done after its last variation.' );
		$this->assertTrue( $second['exhausted'] );
	}

	public function test_a_variation_deleted_between_two_reads_is_simply_absent_from_the_next_one(): void {
		$this->shop->product( 2000, 'Кеды', 'K', null, null, 'variable' );
		$this->variations( 2000, 2001, 5 );
		$filter = new Site_Products_Filter( $this->account_id, '', 0, false, 'Кеды' );

		$first = $this->read( $filter, 0, 0, 2 );
		$this->assertSame( [ 2001, 2002 ], $first['offers'] );

		$this->db->query( 'DELETE FROM wp_posts WHERE ID = 2003' );

		$second = $this->read( $filter, $first['cursor']['parent'], $first['cursor']['child'], 5 );
		$this->assertSame( [ 2004, 2005 ], $second['offers'] );
	}

	public function test_a_parent_deleted_before_its_variations_are_read_finishes_and_the_run_goes_on(): void {
		$this->shop->product( 3000, 'Исчез', 'GONE', null, null, 'variable' );
		$this->variations( 3000, 3001, 4 );
		$this->shop->product( 3100, 'Остался', 'STAY', 1, '5', 'simple' );
		$filter = new Site_Products_Filter( $this->account_id, '', 0, false, '' );

		// The run entered 3000 and read two variations; then the merchant deleted the product.
		$this->db->query( 'DELETE FROM wp_posts WHERE ID IN (3000, 3003, 3004)' );
		$part = $this->read( $filter, 3000, 3002, 5 );

		$this->assertSame( [ 3100 ], $part['offers'] );
		$this->assertSame( 2, $part['products'], 'The vanished parent counts as finished, then 3100.' );
	}

	public function test_a_product_that_left_the_filter_before_its_turn_is_not_processed(): void {
		foreach ( [ 40, 50, 60, 70 ] as $id ) {
			$this->shop->product( $id, 'К' . $id, 'C-' . $id, 1, '5', 'simple', [ 8 ] );
		}
		$filter = new Site_Products_Filter( $this->account_id, '', 8 );

		$first = $this->read( $filter, 0, 0, 2 );
		$this->assertSame( [ 40, 50 ], $first['offers'] );

		$this->db->query( 'DELETE FROM wp_term_relationships WHERE object_id = 60 AND term_taxonomy_id = 8' );

		$rest = $this->read( $filter, $first['cursor']['parent'], 0, 5 );
		$this->assertSame( [ 70 ], $rest['offers'] );
	}

	public function test_a_product_that_entered_the_filter_above_the_cursor_is_processed_and_one_below_is_not(): void {
		foreach ( [ 40, 50, 60 ] as $id ) {
			$this->shop->product( $id, 'К' . $id, 'C-' . $id, 1, '5', 'simple', [ 8 ] );
		}
		$this->shop->product( 45, 'Ниже', 'C-45', 1, '5', 'simple' );
		$this->shop->product( 80, 'Выше', 'C-80', 1, '5', 'simple' );
		$filter = new Site_Products_Filter( $this->account_id, '', 8 );

		$first = $this->read( $filter, 0, 0, 2 );
		$this->assertSame( [ 40, 50 ], $first['offers'] );

		$this->db->insert( 'wp_term_relationships', [ 'object_id' => 45, 'term_taxonomy_id' => 8 ] );
		$this->db->insert( 'wp_term_relationships', [ 'object_id' => 80, 'term_taxonomy_id' => 8 ] );

		$rest = $this->read( $filter, $first['cursor']['parent'], 0, 5 );
		$this->assertSame( [ 60, 80 ], $rest['offers'], 'The run moved past 45; 80 is still ahead.' );
	}

	public function test_an_action_that_moves_a_product_out_of_the_filter_cannot_make_the_run_repeat_or_skip(): void {
		foreach ( [ 91, 92, 93 ] as $id ) {
			$this->shop->product( $id, 'Н' . $id, 'N-' . $id, 1, '5', 'simple' );
		}
		$filter = new Site_Products_Filter( $this->account_id, 'unlinked', 0, false, 'Н' );
		$seen   = [];
		$parent = 0;

		for ( $part = 0; $part < 5; $part++ ) {
			$read = $this->read( $filter, $parent, 0, 1 );

			foreach ( $read['offers'] as $id ) {
				$seen[] = $id;
				// «Экспортировать» moves a product from «Не связан» to «В очереди»: it leaves the filter it was read under.
				$this->shop->link( $id, 0, 'N-' . $id, Link_Record::STATE_QUEUED );
			}

			$parent = $read['cursor']['parent'];

			if ( $read['exhausted'] ) {
				break;
			}
		}

		$this->assertSame( [ 91, 92, 93 ], $seen, 'Each once: the cursor is an id, not an offset.' );
	}

	public function test_a_part_runs_the_ticked_modes_code_and_counts_offers_and_products_apart(): void {
		$ids = [];

		foreach ( [ 501, 502, 503 ] as $id ) {
			$this->shop->product( $id, 'Пуф ' . $id, 'PX-' . $id, 1, '5', 'simple' );
			$this->shop->link( $id, 0, 'PX-' . $id, Link_Record::STATE_CREATED );
			$ids[] = $id;
		}

		$run  = $this->run_of( Site_Products_Bulk::SYNC_STOCK_OFF, [], [ 'q' => 'PX-' ] );
		$part = $this->worker()->part( $this->account_now(), $run, [], static function (): bool {
			return true;
		} );

		$this->assertSame( 3, $part['counts']['products_done'] );
		$this->assertSame( 3, $part['counts']['offers_accepted'] );
		$this->assertSame( 0, $part['counts']['offers_failed'] );
		$this->assertTrue( $part['finished'], 'It read to the end of the filter.' );
		$this->assertSame( 0, $part['busy'] );

		foreach ( $ids as $id ) {
			$this->assertFalse( $this->link_of( $id )->sync_stock() );
		}
	}

	public function test_an_empty_read_finishes_the_run_with_nothing_counted(): void {
		$run  = $this->run_of( Site_Products_Bulk::RESET, [], [ 'q' => 'такого нет' ] );
		$part = $this->worker()->part( $this->account_now(), $run, [], static function (): bool {
			return true;
		} );

		$this->assertTrue( $part['finished'] );
		$this->assertSame( 0, $part['counts']['offers_accepted'] );
		$this->assertSame( [], $part['groups'] );
	}

	public function test_the_reasons_of_a_part_arrive_as_groups_that_explain_every_skipped_offer(): void {
		$this->shop->product( 601, 'Нет связи 601', 'NL-601', 1, '5', 'simple' );
		$this->shop->product( 602, 'Нет связи 602', 'NL-602', 1, '5', 'simple' );
		$this->shop->link( 602, 0, 'NL-602', Link_Record::STATE_CREATED, [ 'sync_stock' => 0 ] );

		$run  = $this->run_of( Site_Products_Bulk::SYNC_STOCK_ON, [], [ 'q' => 'Нет связи' ] );
		$part = $this->worker()->part( $this->account_now(), $run, [], static function (): bool {
			return true;
		} );

		$this->assertSame( 1, $part['counts']['offers_skipped'] );
		$this->assertSame( 1, array_sum( array_column( $part['groups'], 'count' ) ), 'The groups account for what was skipped.' );
		$this->assertSame( 'skipped', $part['groups'][0]['kind'] );
	}

	public function test_a_part_asks_whether_it_still_owns_the_run_before_every_product_write_and_stops_when_it_does_not(): void {
		foreach ( [ 701, 702, 703 ] as $id ) {
			$this->shop->product( $id, 'З' . $id, 'Z-' . $id, 1, '5', 'simple' );
			$this->shop->link( $id, 0, 'Z-' . $id, Link_Record::STATE_CREATED );
		}

		$asked = 0;
		$run   = $this->run_of( Site_Products_Bulk::ARCHIVE, [], [ 'q' => 'З' ] );

		try {
			$this->worker()->part(
				$this->account_now(),
				$run,
				[],
				static function () use ( &$asked ): bool {
					++$asked;

					return $asked < 2; // The first product is written; the connection is gone before the second.
				}
			);
			$this->fail( 'The part must stop.' );
		} catch ( Bulk_Run_Lost $e ) {
			$this->assertSame( 2, $asked );
		}

		$written = (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Sqlite_Tables::table( 'sync_state' ) . ' WHERE pending_payload LIKE \'%archive%\'' );
		$this->assertSame( 1, $written, 'Only the write before the loss happened.' );
	}

	public function test_switching_sync_on_records_the_first_send_of_every_created_card_as_the_product_tab_does(): void {
		$sent  = [];
		$ready = $this->stock_product( 'S-1', 9001, 5, [ 'sync_stock' => false ] );
		$more  = $this->stock_product( 'S-2', 9002, 7, [ 'sync_stock' => false ] );
		$wait  = $this->stock_product( 'S-3', 9003, 5, [ 'sync_stock' => false, 'state' => Link_Record::STATE_QUEUED ] );

		$result = $this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::SYNC_STOCK_ON, [ $ready, $more, $wait ], [ $ready => $ready, $more => $more, $wait => $wait ], null, static function (): bool {
			return true;
		} );

		$this->assertTrue( $this->link_of( $ready )->sync_stock() );
		$this->assertNotNull( $this->stock_row( $ready ), 'The first send is recorded, so the stock goes out without waiting for a change.' );
		$this->assertNotNull( $this->stock_row( $more ) );
		$this->assertNull( $this->stock_row( $wait ), 'A card that is not created has nothing to send to.' );
		$this->assertSame( 3, $result['accepted'] );
		unset( $sent );
	}

	public function test_a_first_send_records_nothing_when_the_account_does_not_push_stock_or_the_product_has_no_managed_stock(): void {
		$off    = $this->stock_product( 'S-1', 9001, 5, [ 'sync_stock' => false ] );
		$plain  = $this->stock_product( 'S-2', 9002, 5, [ 'sync_stock' => false, 'manage_stock' => false ] );
		$owns   = static function (): bool {
			return true;
		};

		$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::SYNC_STOCK_ON, [ $plain ], [ $plain => $plain ], null, $owns );
		$this->assertTrue( $this->link_of( $plain )->sync_stock(), 'The switch is made.' );
		$this->assertNull( $this->stock_row( $plain ), 'No managed stock, no first send.' );

		$this->set_account( [ 'stock_mode' => 'marketplace' ] );
		$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::SYNC_STOCK_ON, [ $off ], [ $off => $off ], null, $owns );
		$this->assertTrue( $this->link_of( $off )->sync_stock() );
		$this->assertNull( $this->stock_row( $off ), 'The marketplace is the source of stock: nothing goes out.' );
	}

	/**
	 * An ownership answer that allows the first `$allowed` questions and refuses every later one: the connection is lost inside the
	 * service's loop.
	 *
	 * @param int $allowed Questions answered yes.
	 * @param int $asked   Questions asked so far (by reference).
	 * @return callable
	 */
	private function owns_for( int $allowed, int &$asked ): callable {
		return static function () use ( $allowed, &$asked ): bool {
			++$asked;

			return $asked <= $allowed;
		};
	}

	public function test_a_detach_that_loses_ownership_inside_its_loop_stops_at_the_next_product_and_frees_the_claim(): void {
		$ids = [];

		foreach ( [ 1, 2, 3 ] as $n ) {
			$product = $this->add_product();
			$this->add_link( $product, Link_Record::STATE_CREATED, [ 'offer_id' => 'D-' . $n ] );
			$ids[] = (int) $product->get_id();
		}

		$asked = 0;

		try {
			// Asked once per product in the detacher's loop: yes for the first, no for the second.
			$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::DETACH, $ids, array_combine( $ids, $ids ), null, $this->owns_for( 1, $asked ) );
			$this->fail( 'The loss must stop the part.' );
		} catch ( Bulk_Run_Lost $e ) {
			$this->assertSame( 2, $asked, 'Asked per product, not once per batch.' );
		}

		$detached = array_filter(
			$ids,
			function ( int $id ): bool {
				return Link_Record::STATE_UNLINKED === $this->link_of( $id )->card_state();
			}
		);
		$this->assertCount( 1, $detached, 'Only the product written before the loss.' );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'The claim was released.' );
	}

	public function test_an_export_that_loses_ownership_inside_its_loop_stops_before_the_next_product(): void {
		$ids = [];

		foreach ( [ 'E-1', 'E-2', 'E-3' ] as $sku ) {
			$ids[] = (int) $this->add_product( [ 'sku' => $sku ] )->get_id();
		}

		$asked = 0;

		try {
			$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::EXPORT, $ids, array_combine( $ids, $ids ), null, $this->owns_for( 1, $asked ) );
			$this->fail( 'The loss must stop the part.' );
		} catch ( Bulk_Run_Lost $e ) {
			$this->assertSame( 2, $asked, 'One product written, the second refused: asked per product.' );
		}

		$written = array_filter(
			$ids,
			function ( int $id ): bool {
				return null !== $this->links()->find( $this->account_id, $id );
			}
		);
		$this->assertCount( 1, $written, 'No link row was created for the products after the loss.' );
		$this->assertSame( [], $this->scheduler->of( 'woodev_fake_seller_export_batch' ), 'And no batch was scheduled.' );
	}

	public function test_an_export_that_loses_ownership_after_creating_a_link_does_not_queue_it(): void {
		$id    = (int) $this->add_product( [ 'sku' => 'W-1' ] )->get_id();
		$asked = 0;

		try {
			// The first write of the offer (the link row) is allowed; the second (queueing it) is asked on its own and refused.
			$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::EXPORT, [ $id ], [ $id => $id ], null, $this->owns_for( 1, $asked ) );
			$this->fail( 'The loss must stop the export between the two writes of one offer.' );
		} catch ( Bulk_Run_Lost $e ) {
			$this->assertSame( 2, $asked, 'Asked before each write, not once per offer.' );
		}

		$link = $this->links()->find( $this->account_id, $id );
		$this->assertNotNull( $link, 'The first write happened.' );
		$this->assertNotSame( Link_Record::STATE_QUEUED, $link->card_state(), 'The second did not.' );
		$this->assertSame( [], $this->scheduler->of( 'woodev_fake_seller_export_batch' ) );
	}

	public function test_an_export_that_loses_ownership_before_recording_a_blocked_card_does_not_record_it(): void {
		$id    = (int) $this->add_product( [ 'sku' => 'B-1' ] )->get_id();
		$link  = $this->add_link( $this->products[ $id ], Link_Record::STATE_UNLINKED );
		$asked = 0;

		$this->rule->blocked = [ $id ];

		try {
			// The link exists, so the first write is the blocked record: it is refused outright.
			$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::EXPORT, [ $id ], [ $id => $id ], null, $this->owns_for( 0, $asked ) );
			$this->fail( 'The loss must stop the part.' );
		} catch ( Bulk_Run_Lost $e ) {
			$this->assertSame( 1, $asked );
		}

		$this->assertSame( '', trim( $this->links()->last_error( (int) $link->link_id() )['text'] ), 'No error was written.' );
	}

	public function test_a_detach_that_loses_ownership_after_the_detach_keeps_the_send_history_and_the_queued_intents(): void {
		$id = $this->stock_product( 'S-1', 9301, 5 );
		$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::SEND_STOCK, [ $id ], [ $id => $id ], null, static function (): bool {
			return true;
		} );
		$this->assertNotNull( $this->stock_row( $id ), 'There is an intent to retire.' );
		$asked = 0;

		try {
			// The detach itself (the first write) is allowed; the cleanup that follows is asked on its own and refused.
			$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::DETACH, [ $id ], [ $id => $id ], null, $this->owns_for( 1, $asked ) );
			$this->fail( 'The loss must stop the detach between its writes.' );
		} catch ( Bulk_Run_Lost $e ) {
			$this->assertSame( 2, $asked, 'Asked before each write of the row, not once per row.' );
		}

		$this->assertSame( Link_Record::STATE_UNLINKED, $this->link_of( $id )->card_state(), 'The first write happened.' );
		$this->assertNotNull( $this->stock_row( $id ), 'The cleanup after it did not: the new owner replays it.' );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'The claim was released.' );
	}

	public function test_an_export_that_loses_ownership_before_its_rollback_writes_nothing_more(): void {
		$product = $this->add_product( [ 'sku' => 'R-1' ] );
		$id      = (int) $product->get_id();
		$asked   = 0;

		$this->scheduler->zero_for['woodev_fake_seller_export_batch'] = true; // Action Scheduler refuses the batch: a rollback follows.

		try {
			// Before the link is created: yes; before it is queued: yes; before scheduling: yes; before the rollback write: no.
			$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::EXPORT, [ $id ], [ $id => $id ], null, $this->owns_for( 3, $asked ) );
			$this->fail( 'The loss must stop the rollback.' );
		} catch ( Bulk_Run_Lost $e ) {
			$this->assertSame( 4, $asked );
		}

		$this->assertSame( Link_Record::STATE_QUEUED, $this->link_of( $id )->card_state(), 'The new owner re-plans the part; this tick rolled nothing back.' );
	}

	public function test_a_switch_on_interrupted_after_the_flags_changed_is_finished_by_its_retry(): void {
		$product = $this->stock_product( 'S-1', 9101, 5, [ 'sync_stock' => false ] );
		$asked   = 0;

		try {
			// The part asks once before the flag write (yes) and once before the first send (no): the connection is gone in between.
			$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::SYNC_STOCK_ON, [ $product ], [ $product => $product ], null, $this->owns_for( 1, $asked ) );
			$this->fail( 'The loss must stop the part.' );
		} catch ( Bulk_Run_Lost $e ) {
			$this->assertTrue( $this->link_of( $product )->sync_stock(), 'The flag is on.' );
			$this->assertNull( $this->stock_row( $product ), 'And the first send is missing.' );
		}

		// The part was not saved, so the next tick plans it again: the card is «already so», and still gets its first send.
		$retry = $this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::SYNC_STOCK_ON, [ $product ], [ $product => $product ], null, static function (): bool {
			return true;
		} );

		$this->assertNotNull( $this->stock_row( $product ), 'The retry recorded the first send.' );
		$this->assertSame( 0, $retry['accepted'] );
		$this->assertSame( 1, $retry['skipped'], 'It is still counted as already configured.' );
	}

	public function test_a_price_switch_on_interrupted_after_the_flags_changed_is_finished_by_its_retry(): void {
		$product = $this->add_product( [ 'price' => '1500' ] );
		$this->add_link( $product, Link_Record::STATE_CREATED, [ 'sync_price' => 0 ] );
		$id    = (int) $product->get_id();
		$asked = 0;

		try {
			$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::SYNC_PRICE_ON, [ $id ], [ $id => $id ], null, $this->owns_for( 1, $asked ) );
			$this->fail( 'The loss must stop the part.' );
		} catch ( Bulk_Run_Lost $e ) {
			$this->assertTrue( $this->link_of( $id )->sync_price() );
			$this->assertSame( 0, $this->price_intents_of( $id ) );
		}

		$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::SYNC_PRICE_ON, [ $id ], [ $id => $id ], null, static function (): bool {
			return true;
		} );

		$this->assertSame( 1, $this->price_intents_of( $id ), 'The retry recorded the price intent.' );
	}

	/**
	 * @param int $product_id Product.
	 * @return int Price rows of the sync state of the product's link.
	 */
	private function price_intents_of( int $product_id ): int {
		return (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Sqlite_Tables::table( 'sync_state' ) . ' WHERE kind = \'price\' AND link_id = ' . (int) $this->link_of( $product_id )->link_id() );
	}

	public function test_a_first_send_that_cannot_be_stored_is_never_reported_as_done_and_the_repeat_records_it(): void {
		$product = $this->stock_product( 'S-1', 9102, 5, [ 'sync_stock' => false ] );
		$owns    = static function (): bool {
			return true;
		};

		$this->db->refuse_inserts = true;

		try {
			$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::SYNC_STOCK_ON, [ $product ], [ $product => $product ], null, $owns );
			$this->fail( 'A refused intent must not end as accepted.' );
		} catch ( Action_State_Exception $e ) {
			$this->assertStringContainsString( 'первую отправку записать не удалось', $e->getMessage() );
		}

		$this->db->refuse_inserts = false;
		$this->assertNull( $this->stock_row( $product ) );

		$this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::SYNC_STOCK_ON, [ $product ], [ $product => $product ], null, $owns );

		$this->assertNotNull( $this->stock_row( $product ), 'Repeating the action finishes what was missing.' );
	}

	public function test_a_busy_detach_asks_for_the_same_part_again_instead_of_reporting_a_failure(): void {
		$product = $this->add_product();
		$this->add_link( $product, Link_Record::STATE_CREATED );
		$id = (int) $product->get_id();

		$this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ); // The account's sender holds the claim.

		$result = $this->bulk()->run_part( $this->account_now(), Site_Products_Bulk::DETACH, [ $id ], [ $id => $id ], null, static function (): bool {
			return true;
		} );

		$this->assertTrue( $result['busy'] );
		$this->assertSame( Link_Record::STATE_CREATED, $this->link_of( $id )->card_state(), 'Nothing changed.' );
	}

	public function test_the_user_who_lost_the_right_to_edit_products_stops_the_run(): void {
		$this->may_edit = false;

		$why = $this->worker()->refusal( $this->account_now(), $this->run_of( Site_Products_Bulk::SYNC_STOCK_ON ) );

		$this->assertSame( 'У пользователя, запустившего действие, больше нет прав.', $why );
	}

	public function test_a_lapsed_licence_stops_the_actions_that_write_to_the_marketplace_and_not_the_others(): void {
		$this->licensed = false;

		foreach ( [ Site_Products_Bulk::EXPORT, Site_Products_Bulk::SEND_STOCK, Site_Products_Bulk::SEND_PRICE, Site_Products_Bulk::ARCHIVE, Site_Products_Bulk::PULL_PRICES ] as $action ) {
			$this->assertNotNull( $this->worker()->refusal( $this->account_now(), $this->run_of( $action ) ), $action );
		}

		foreach ( [ Site_Products_Bulk::SYNC_STOCK_ON, Site_Products_Bulk::SET_WAREHOUSE, Site_Products_Bulk::RESET, Site_Products_Bulk::DETACH, Site_Products_Bulk::RESET_PRICE ] as $action ) {
			$this->assertNull( $this->worker()->refusal( $this->account_now(), $this->run_of( $action ) ), $action );
		}
	}

	public function test_a_matching_run_resets_the_own_price_of_every_product_under_the_filter_and_counts_the_rows_without_one_as_skipped(): void {
		foreach ( [ 511, 512, 513 ] as $id ) {
			$this->shop->product( $id, 'Лампа ' . $id, 'LP-' . $id, 1, '5', 'simple' );
			$this->shop->link( $id, 0, 'LP-' . $id, Link_Record::STATE_CREATED );
		}

		foreach ( [ 511, 512 ] as $id ) {
			$this->links()->set_price_override( (int) $this->link_of( $id )->link_id(), [ 'price' => [ 'kind' => 'percent', 'value' => 10 ] ] );
		}

		$part = $this->worker()->part( $this->account_now(), $this->run_of( Site_Products_Bulk::RESET_PRICE, [], [ 'q' => 'LP-' ] ), [], static function (): bool {
			return true;
		} );

		$this->assertSame( [ 3, 2, 1, 0 ], [ $part['counts']['products_done'], $part['counts']['offers_accepted'], $part['counts']['offers_skipped'], $part['counts']['offers_failed'] ] );
		$this->assertSame( 'своей цены нет', $part['groups'][0]['reason'] );
		$this->assertNull( $this->link_of( 511 )->price_rule_override() );
	}

	public function test_a_matching_run_queues_the_pull_of_the_created_cards_of_a_part_and_reports_the_rest(): void {
		foreach ( [ 521, 522 ] as $id ) {
			$this->shop->product( $id, 'Ваза ' . $id, 'VZ-' . $id, 1, '5', 'simple' );
			$this->shop->link( $id, 0, 'VZ-' . $id, Link_Record::STATE_CREATED );
		}

		$this->shop->product( 523, 'Ваза 523', 'VZ-523', 1, '5', 'simple' );
		$this->shop->link( 523, 0, 'VZ-523', Link_Record::STATE_UNLINKED );

		$part = $this->worker()->part( $this->account_now(), $this->run_of( Site_Products_Bulk::PULL_PRICES, [], [ 'q' => 'VZ-' ] ), [], static function (): bool {
			return true;
		} );

		$this->assertSame( [ 3, 2, 1 ], [ $part['counts']['products_done'], $part['counts']['offers_accepted'], $part['counts']['offers_skipped'] ] );
		$this->assertSame( [ [ 'VZ-521', 'VZ-522' ] ], $this->pulled );
	}

	public function test_part_sizes_are_the_proposals_of_the_spec(): void {
		$this->assertSame( 100, Site_Products_Run_Worker::size_of( Site_Products_Bulk::EXPORT ) );
		$this->assertSame( 200, Site_Products_Run_Worker::size_of( Site_Products_Bulk::DETACH ) );

		foreach ( [ Site_Products_Bulk::SEND_STOCK, Site_Products_Bulk::SEND_PRICE, Site_Products_Bulk::SYNC_STOCK_ON, Site_Products_Bulk::SET_WAREHOUSE, Site_Products_Bulk::RESET, Site_Products_Bulk::ARCHIVE, Site_Products_Bulk::RESET_PRICE, Site_Products_Bulk::PULL_PRICES ] as $action ) {
			$this->assertSame( 500, Site_Products_Run_Worker::size_of( $action ), $action );
		}
	}
}
