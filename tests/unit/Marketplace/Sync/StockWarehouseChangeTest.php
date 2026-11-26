<?php
/**
 * A product that changes its warehouse.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Sync;

use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Reseed_Stock_Runner;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Sync\Stock_Flusher;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Stock_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * One product feeds one warehouse (#2034): the warehouse it leaves is sent zero, the one it moves to is sent the stock without
 * waiting for the stock to change.
 */
final class StockWarehouseChangeTest extends Sync_Test_Case {
	private const OTHER = 99;
	private const THIRD = 77;

	/**
	 * The stock recorder of the test.
	 *
	 * @var Stock_Intents
	 */
	private $stock;

	/**
	 * Hooks the recorder, as the plugin does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->stock = $this->hook_stock();
	}

	/**
	 * The account's default warehouse changes: the reseed sends zero to the left one first, then the stock to the new one.
	 *
	 * @return void
	 */
	public function test_a_new_default_warehouse_takes_the_stock_and_the_left_one_gets_zero(): void {
		$product = $this->synced_product( 'a', 1001, 7 );

		$this->set_account( [ 'warehouse_id' => self::OTHER ] );
		$this->reseed()->run( $this->account_id );
		$this->flush();

		$this->assertSame(
			[
				[ self::WAREHOUSE, [ 'a' => 0 ] ],
				[ self::OTHER, [ 'a' => 7 ] ],
			],
			$this->pushes()
		);
		$this->assertSame( 7, (int) $this->db->get_var( 'SELECT last_pushed_stock FROM ' . Sqlite_Tables::table( 'links' ) . ' WHERE link_id = ' . $this->link_id( $product ) ), 'the zero of the left warehouse is not the product\'s stock' );
		$this->assertNull( $this->stock_row( $product )['remote_present'], 'nor is it read as what the marketplace holds' );

		$this->now += 31;
		$this->assertSame( 0, $this->flush()->requests, 'the move is sent once' );
	}

	/**
	 * A product's own warehouse changes, as the product tab and the bulk action record it.
	 *
	 * @return void
	 */
	public function test_recording_the_stock_after_a_move_zeroes_the_left_warehouse(): void {
		$product = $this->synced_product( 'a', 1001, 7 );

		$this->links()->set_warehouse( $this->account_id, [ $this->link_id( $product ) ], self::OTHER );
		$this->assertTrue( $this->stock->record_stock( $this->link_of( $product ) ) );
		$this->flush();

		$this->assertSame(
			[
				[ self::WAREHOUSE, [ 'a' => 0 ] ],
				[ self::OTHER, [ 'a' => 7 ] ],
			],
			$this->pushes()
		);
	}

	/**
	 * A product linked before any warehouse was chosen gets its stock sent once one is.
	 *
	 * @return void
	 */
	public function test_a_first_warehouse_gets_the_stock(): void {
		$this->set_account( [ 'warehouse_id' => 0 ] );
		$this->stock_product( 'a', 1001, 7 );

		$this->set_account( [ 'warehouse_id' => self::OTHER ] );
		$this->reseed()->run( $this->account_id );
		$this->flush();

		$this->assertSame( [ [ self::OTHER, [ 'a' => 7 ] ] ], $this->pushes() );
	}

	/**
	 * The warehouse is unchosen: the left one is zeroed and nothing else is sent.
	 *
	 * @return void
	 */
	public function test_unchoosing_the_warehouse_zeroes_it(): void {
		$this->synced_product( 'a', 1001, 7 );

		$this->set_account( [ 'warehouse_id' => 0 ] );
		$this->reseed()->run( $this->account_id );
		$this->flush();

		$this->assertSame( [ [ self::WAREHOUSE, [ 'a' => 0 ] ] ], $this->pushes() );
	}

	/**
	 * A stock change that reaches the sender before the reseed does is moved to the new warehouse, and the left one is zeroed.
	 *
	 * @return void
	 */
	public function test_a_pair_still_due_for_the_left_warehouse_becomes_its_zero(): void {
		$product = $this->synced_product( 'a', 1001, 7 );

		$this->set_stock( $product, 5 );
		$this->set_account( [ 'warehouse_id' => self::OTHER ] );
		$this->flush();
		$this->now += 31;
		$this->flush();

		$this->assertSame( 0, $this->present_in( self::WAREHOUSE ) );
		$this->assertSame( 5, $this->present_in( self::OTHER ) );
	}

	/**
	 * A send whose outcome is unknown may have reached the left warehouse, so it is zeroed.
	 *
	 * @return void
	 */
	public function test_an_unresolved_send_is_followed_by_a_zero(): void {
		$product = $this->stock_product( 'a', 1001, 7 );
		$this->set_stock( $product, 7 );
		$this->market->script[] = static function (): void {
			throw Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' );
		};
		$this->flush();

		$this->set_account( [ 'warehouse_id' => self::OTHER ] );
		$this->reseed()->run( $this->account_id );
		$this->now += 3600;
		$this->flush();

		$this->assertSame( 0, $this->present_in( self::WAREHOUSE ) );
		$this->assertSame( 7, $this->present_in( self::OTHER ) );
	}

	/**
	 * A warehouse that was only wanted, or that already holds zero, is not written to.
	 *
	 * @return void
	 */
	public function test_a_warehouse_that_holds_nothing_is_left_alone(): void {
		$unsent = $this->stock_product( 'unsent', 1001, 7 );
		$empty  = $this->stock_product( 'empty', 1002, 3 );
		$this->set_stock( $empty, 0 );
		$this->flush();
		$this->set_stock( $unsent, 7 );
		$this->market->requests = [];

		$this->set_account( [ 'warehouse_id' => self::OTHER ] );
		$this->reseed()->run( $this->account_id );
		$this->flush();

		$this->assertSame( [ [ self::OTHER, [ 'unsent' => 7, 'empty' => 0 ] ] ], $this->pushes() );
	}

	/**
	 * Moved back before the zero went out: it is cancelled and the marketplace keeps the stock.
	 *
	 * @return void
	 */
	public function test_moving_back_cancels_the_zero(): void {
		$product = $this->synced_product( 'a', 1001, 7 );

		$this->set_account( [ 'warehouse_id' => self::OTHER ] );
		$this->reseed()->run( $this->account_id );
		$this->set_account( [ 'warehouse_id' => self::WAREHOUSE ] );
		$this->reseed()->run( $this->account_id );
		$this->flush();
		$this->now += 31;
		$this->flush();

		$this->assertSame( [], $this->pushes(), 'warehouse 22 still holds 7 and the other one never received anything' );
		$this->assertNull( $this->stock_row( $product, self::OTHER )['pending_payload'] );
	}

	/**
	 * The sender finds the zero of the warehouse the link is back on before the reseed ran: it does not send it.
	 *
	 * @return void
	 */
	public function test_the_zero_of_the_current_warehouse_is_never_sent(): void {
		$product = $this->synced_product( 'a', 1001, 7 );

		$this->set_account( [ 'warehouse_id' => self::OTHER ] );
		$this->reseed()->run( $this->account_id );
		$this->set_account( [ 'warehouse_id' => self::WAREHOUSE ] );
		$this->flush();
		$this->now += 31;
		$this->flush();

		$this->assertSame( 7, $this->present_in( self::WAREHOUSE ) );
		$this->assertSame( 0, $this->present_in( self::OTHER ), 'the value already on its way there is taken back' );
	}

	/**
	 * Three warehouses in a row: both left ones end at zero.
	 *
	 * @return void
	 */
	public function test_every_left_warehouse_ends_at_zero(): void {
		$this->synced_product( 'a', 1001, 7 );

		foreach ( [ self::OTHER, self::THIRD ] as $warehouse ) {
			$this->set_account( [ 'warehouse_id' => $warehouse ] );
			$this->reseed()->run( $this->account_id );
			$this->flush();
			$this->now += 31;
		}

		$this->assertSame( 0, $this->present_in( self::WAREHOUSE ) );
		$this->assertSame( 0, $this->present_in( self::OTHER ) );
		$this->assertSame( 7, $this->present_in( self::THIRD ) );
	}

	/**
	 * A left warehouse the marketplace has switched off cannot be written to: its zero waits, shows nothing in the products
	 * table, and goes out once the warehouse is listed again.
	 *
	 * @return void
	 */
	public function test_the_zero_of_a_switched_off_left_warehouse_goes_out_when_it_is_listed_again(): void {
		$product = $this->synced_product( 'a', 1001, 7 );

		$this->with_listed_warehouses(
			[ self::OTHER ],
			function () use ( $product ): void {
				$this->set_account( [ 'warehouse_id' => self::OTHER ] );
				$this->reseed()->run( $this->account_id );
				$this->flush();
				$this->now += Stock_Flusher::HOLD_SECONDS;
				$this->flush();

				$this->assertSame( [ [ self::OTHER, [ 'a' => 7 ] ] ], $this->pushes() );
				$this->assertSame( 0, $this->stock_row( $product )['pending_payload']['stock'] );
				$this->assertNull( $this->stock_row( $product )['last_error'] );
				$this->assertSame( 0, (int) $this->stock_row( $product )['attempts'] );
			}
		);

		$this->now += Stock_Flusher::HOLD_SECONDS;
		$this->with_listed_warehouses(
			[ self::WAREHOUSE, self::OTHER ],
			function (): void {
				$this->flush();
			}
		);

		$this->assertSame(
			[
				[ self::OTHER, [ 'a' => 7 ] ],
				[ self::WAREHOUSE, [ 'a' => 0 ] ],
			],
			$this->pushes()
		);
	}

	/**
	 * A pair still due for a left warehouse that is switched off becomes its waiting zero.
	 *
	 * @return void
	 */
	public function test_a_due_pair_of_a_switched_off_left_warehouse_becomes_its_waiting_zero(): void {
		$product = $this->synced_product( 'a', 1001, 7 );
		$this->set_stock( $product, 5 );
		$this->set_account( [ 'warehouse_id' => self::OTHER ] );

		$this->with_listed_warehouses(
			[ self::OTHER ],
			function (): void {
				$this->flush();
				$this->flush();
				$this->now += 31;
				$this->flush();

				$this->assertSame( [ [ self::OTHER, [ 'a' => 5 ] ] ], $this->pushes() );
			}
		);

		$this->now += Stock_Flusher::HOLD_SECONDS;
		$this->flush();

		$this->assertSame( 0, $this->present_in( self::WAREHOUSE ) );
	}

	/**
	 * Stock that changes while the chosen warehouse is switched off goes out, newest value, once it is listed again (#2042).
	 *
	 * @return void
	 */
	public function test_stock_changed_while_switched_off_is_sent_when_listed_again(): void {
		$product = $this->synced_product( 'a', 1001, 7 );

		$this->with_listed_warehouses(
			[ self::OTHER ],
			function () use ( $product ): void {
				$this->set_stock( $product, 4 );
				$this->flush();
				$this->set_stock( $product, 2 );
				$this->now += Stock_Flusher::HOLD_SECONDS;
				$this->flush();

				$this->assertSame( [], $this->pushes() );
				$this->assertFalse( $this->stock->record_stock( $this->link_of( $product ) ), 'nothing can be sent now' );
			}
		);

		$this->now += Stock_Flusher::HOLD_SECONDS;
		$this->with_listed_warehouses(
			[ self::WAREHOUSE, self::OTHER ],
			function (): void {
				$this->flush();
			}
		);

		$this->assertSame( [ [ self::WAREHOUSE, [ 'a' => 2 ] ] ], $this->pushes() );
	}

	/**
	 * A held pair goes to the back of the due list, so more held pairs than one run lists do not keep the others out for good.
	 *
	 * @return void
	 */
	public function test_held_pairs_do_not_starve_the_others(): void {
		$held    = [ $this->synced_product( 'held-1', 1001, 7 ), $this->synced_product( 'held-2', 1002, 7 ) ];
		$healthy = $this->synced_product( 'healthy', 1003, 7 );
		$this->set_stock( $held[0], 3 );
		$this->set_stock( $held[1], 3 );
		++$this->now;
		$this->set_stock( $healthy, 3 );

		++$this->now;
		$token = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		foreach ( $this->queue()->due( $this->account_id, Sync_State_Store::KIND_STOCK, 2 ) as $row ) {
			$key = Sync_State_Queue::key( $this->account_id, (int) $row['link_id'], (int) $row['warehouse_id'], Sync_State_Store::KIND_STOCK );
			$this->assertTrue( $this->queue()->hold( $key, Stock_Flusher::HOLD_SECONDS, '', $token ) );
		}

		$this->now += Stock_Flusher::HOLD_SECONDS;

		$this->assertSame( $this->link_id( $healthy ), (int) $this->queue()->due( $this->account_id, Sync_State_Store::KIND_STOCK, 1 )[0]['link_id'] );
	}

	/**
	 * A zero refused for good is tried again at the next stock change, not while it is merely backing off.
	 *
	 * @return void
	 */
	public function test_a_zero_refused_for_good_is_tried_again_at_the_next_stock_change(): void {
		$product = $this->synced_product( 'a', 1001, 7 );

		$this->set_account( [ 'warehouse_id' => self::OTHER ] );
		$this->reseed()->run( $this->account_id );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$this->market->script = [
				function () use ( $product ): array {
					return $this->refused( $product, 'fake_bad_item', 'invalid item' );
				},
			];
			$this->flush();
			$this->set_stock( $product, 7 );
			$this->assertSame( $attempt < 4 ? $attempt + 1 : 0, (int) $this->stock_row( $product )['attempts'] );
			$this->now += 1801;
		}

		$this->market->requests = [];
		$this->market->script   = [];
		$this->flush();

		$this->assertContains( [ self::WAREHOUSE, [ 'a' => 0 ] ], $this->pushes() );
	}

	/**
	 * A new warehouse the marketplace switched off takes nothing over: the old one keeps its stock and the pairs say why. Once
	 * another warehouse is chosen, the move happens.
	 *
	 * @return void
	 */
	public function test_a_switched_off_new_warehouse_leaves_the_old_one_alone(): void {
		$product = $this->synced_product( 'a', 1001, 7 );

		$this->with_listed_warehouses(
			[ self::WAREHOUSE, self::THIRD ],
			function () use ( $product ): void {
				$this->set_account( [ 'warehouse_id' => self::OTHER ] );
				$this->reseed()->run( $this->account_id );
				$this->flush();

				$this->assertSame( [], $this->pushes(), 'nothing is zeroed and nothing is sent' );
				$this->assertSame( [ 'stock' => 7 ], $this->stock_row( $product )['sent_payload'] );
				$this->assertSame( [ 'stock' => 7 ], $this->stock_row( $product, self::OTHER )['pending_payload'], 'kept for later' );

				$this->now += Stock_Flusher::HOLD_SECONDS;
				$this->set_account( [ 'warehouse_id' => self::THIRD ] );
				$this->reseed()->run( $this->account_id );
				$this->flush();

				$this->assertSame(
					[
						[ self::WAREHOUSE, [ 'a' => 0 ] ],
						[ self::THIRD, [ 'a' => 7 ] ],
					],
					$this->pushes()
				);
			}
		);
	}

	/**
	 * Pairs already due when the chosen warehouse turns out to be switched off wait with the reason: neither the pending stock of
	 * the old warehouse nor a recorded zero goes out. Listed again, the zero goes first.
	 *
	 * @return void
	 */
	public function test_due_pairs_wait_while_the_chosen_warehouse_is_switched_off(): void {
		$moved  = $this->synced_product( 'moved', 1001, 7 );
		$zeroed = $this->synced_product( 'zeroed', 1002, 3 );
		$this->links()->set_warehouse( $this->account_id, [ $this->link_id( $zeroed ) ], self::OTHER );
		$this->stock->record_stock( $this->link_of( $zeroed ) ); // The zero and the stock are recorded while 99 is still listed.
		$this->set_stock( $moved, 5 );
		$this->set_account( [ 'warehouse_id' => self::OTHER ] );

		$this->with_listed_warehouses(
			[ self::WAREHOUSE ],
			function () use ( $moved, $zeroed ): void {
				$result = $this->flush();

				$this->assertSame( [], $this->pushes() );
				$this->assertSame( 3, $result->waiting );
				$this->assertStringContainsString( 'отключён', (string) $this->stock_row( $moved )['last_error'] );
				$this->assertSame( 0, (int) $this->stock_row( $zeroed )['attempts'] );
				$this->assertSame( 0, $this->stock_row( $zeroed )['pending_payload']['stock'] );
			}
		);

		$this->now += Stock_Flusher::HOLD_SECONDS;
		$this->with_listed_warehouses(
			[ self::WAREHOUSE, self::OTHER ],
			function (): void {
				$this->flush();

				$this->assertEquals(
					[
						[
							self::WAREHOUSE,
							[
								'zeroed' => 0,
								'moved'  => 0,
							],
						],
						[ self::OTHER, [ 'zeroed' => 3 ] ],
					],
					$this->pushes(),
					'the zeros of the left warehouse first'
				);

				$this->now += 31;
				$this->flush();

				$this->assertSame( [ self::OTHER, [ 'moved' => 5 ] ], $this->pushes()[2] );
			}
		);
	}

	/**
	 * A product whose stock is not managed gets nothing in the new warehouse, so the old one is not zeroed either.
	 *
	 * @return void
	 */
	public function test_a_product_without_managed_stock_keeps_the_old_warehouse(): void {
		$product = $this->synced_product( 'a', 1001, 7 );
		$unmanaged = \Mockery::mock( 'WC_Product' );
		$unmanaged->shouldReceive( 'get_id' )->andReturn( $product );
		$unmanaged->shouldReceive( 'managing_stock' )->andReturn( false );
		$unmanaged->shouldReceive( 'get_stock_managed_by_id' )->andReturn( $product );
		$this->products[ $product ] = $unmanaged;

		$this->set_account( [ 'warehouse_id' => self::OTHER ] );
		$this->reseed()->run( $this->account_id );
		$this->flush();

		$this->assertSame( [], $this->pushes() );
	}

	/**
	 * The zeros go out before any stock, also when the left warehouse has the higher id and when two products swap warehouses.
	 *
	 * @return void
	 */
	public function test_the_zeros_are_sent_before_any_stock(): void {
		$this->set_account( [ 'warehouse_id' => self::OTHER ] );
		$first  = $this->synced_product( 'first', 1001, 7 );
		$second = $this->synced_product( 'second', 1002, 3 );
		$this->links()->set_warehouse( $this->account_id, [ $this->link_id( $second ) ], self::WAREHOUSE );
		$this->stock->record_stock( $this->link_of( $second ) );
		$this->flush();
		$this->now             += 31;
		$this->market->requests = [];

		// Swap: 99 → 22 and 22 → 99.
		$this->links()->set_warehouse( $this->account_id, [ $this->link_id( $first ) ], self::WAREHOUSE );
		$this->stock->record_stock( $this->link_of( $first ) );
		$this->links()->set_warehouse( $this->account_id, [ $this->link_id( $second ) ], self::OTHER );
		$this->stock->record_stock( $this->link_of( $second ) );
		$this->flush();

		$sent = array_map(
			static function ( array $push ): int {
				return array_sum( $push[1] );
			},
			$this->pushes()
		);
		$this->assertSame( [ 0, 0 ], array_slice( $sent, 0, 2 ), 'both zeros first' );
		$this->assertSame( 10, array_sum( array_slice( $sent, 2 ) ) );
	}

	/**
	 * A limit on the due list never takes the stock and leaves its zero behind.
	 *
	 * @return void
	 */
	public function test_the_due_list_starts_with_the_zeros(): void {
		$product = $this->synced_product( 'a', 1001, 7 );
		$older   = $this->stock_product( 'older', 1002, 4 );
		$this->set_stock( $older, 4 );
		++$this->now;

		$this->links()->set_warehouse( $this->account_id, [ $this->link_id( $product ) ], self::OTHER );
		$this->stock->record_stock( $this->link_of( $product ) );

		$due = $this->queue()->due( $this->account_id, Sync_State_Store::KIND_STOCK, 1 );

		$this->assertSame( self::WAREHOUSE, (int) $due[0]['warehouse_id'] );
		$this->assertSame( 0, $due[0]['pending_payload']['stock'] );
	}

	/**
	 * A zero refused by the marketplace stays on its row: it is not the product's stock error.
	 *
	 * @return void
	 */
	public function test_a_refused_zero_is_not_the_products_stock_error(): void {
		$product = $this->synced_product( 'a', 1001, 7 );
		$this->set_account( [ 'warehouse_id' => self::OTHER ] );
		$this->reseed()->run( $this->account_id );
		$this->market->script = [
			function () use ( $product ): array {
				return $this->refused( $product, 'fake_bad_item', 'invalid item' );
			},
		];

		$result = $this->flush();

		$this->assertSame( [ 'invalid item' ], $result->errors );
		$this->assertStringContainsString( 'invalid item', (string) $this->stock_row( $product )['last_error'] );
		$this->assertSame( '', (string) $this->links()->last_error( $this->link_id( $product ) )['text'] );
	}

	/**
	 * An account whose stock the marketplace leads is not written to by a move.
	 *
	 * @return void
	 */
	public function test_marketplace_mode_sends_nothing_after_a_move(): void {
		$this->synced_product( 'a', 1001, 7 );
		$this->set_account(
			[
				'warehouse_id' => self::OTHER,
				'stock_mode'   => 'marketplace',
			]
		);

		$this->reseed()->run( $this->account_id );
		$this->flush();

		$this->assertSame( [], $this->pushes() );
	}

	/**
	 * The change of a warehouse queues one job; a full page queues the next one from its last link; uncreated cards are skipped.
	 *
	 * @return void
	 */
	public function test_the_reseed_is_queued_once_and_walks_the_account_in_pages(): void {
		$runner = $this->reseed();

		$runner->queue( $this->account_id );
		$runner->queue( $this->account_id );

		$this->assertSame( [ [ $this->account_id, 0 ] ], array_column( $this->scheduler->of( 'woodev_fake_seller_reseed_stock' ), 'args' ) );

		$last = 0;

		for ( $number = 1; $number <= Reseed_Stock_Runner::PAGE; $number++ ) {
			$last = $this->stock_product( 'p' . $number, 2000 + $number, 1, [ 'state' => 1 === $number ? Product_Link::STATE_CREATED : Product_Link::STATE_UNLINKED ] );
		}

		$this->scheduler->actions = [];
		$runner->run( $this->account_id );

		$this->assertSame( [ [ $this->account_id, $this->link_id( $last ) ] ], array_column( $this->scheduler->of( 'woodev_fake_seller_reseed_stock' ), 'args' ) );
		$this->assertSame( 1, (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Sqlite_Tables::table( 'sync_state' ) . " WHERE kind = '" . Sync_State_Store::KIND_STOCK . "'" ) );
	}

	/**
	 * A job already running may be past some links, so another change queues a new one.
	 *
	 * @return void
	 */
	public function test_a_change_during_a_running_reseed_queues_another(): void {
		$runner = $this->reseed();
		$runner->queue( $this->account_id );
		$this->scheduler->actions[0]['status'] = 'in-progress';

		$runner->queue( $this->account_id );

		$this->assertCount( 2, $this->scheduler->of( 'woodev_fake_seller_reseed_stock' ) );
	}

	/**
	 * A trashed product keeps its pending archive.
	 *
	 * @return void
	 */
	public function test_the_reseed_leaves_a_trashed_product_to_its_archive(): void {
		$product = $this->synced_product( 'a', 1001, 7 );
		$this->trash( $product );

		$this->reseed()->run( $this->account_id );

		$this->assertTrue( $this->stock_row( $product )['pending_payload'][ Stock_Intents::ARCHIVE_FLAG ] );
	}

	/**
	 * A refused schedule is logged and never reaches the settings save; a failed page is retried with its cursor.
	 *
	 * @return void
	 */
	public function test_a_failure_of_the_reseed_is_logged_and_retried(): void {
		$runner = $this->reseed();
		$this->stock_product( 'a', 1001, 7 );

		$this->scheduler->zero_for['woodev_fake_seller_reseed_stock'] = true;
		$runner->queue( $this->account_id );
		$this->assertStringContainsString( 'was not queued', (string) end( $this->log ) );

		$this->scheduler->zero_for = [];
		$failing                   = new Stock_Intents(
			static function (): void {
				throw new \RuntimeException( 'database is away' );
			},
			$this->links(),
			$this->intents(),
			$this->queue(),
			$this->policy
		);
		$this->reseed( $failing )->run( $this->account_id );

		$this->assertSame( [ [ $this->account_id, 0, 1 ] ], array_column( $this->scheduler->of( 'woodev_fake_seller_reseed_stock' ), 'args' ) );
	}

	/**
	 * Runs something while the account's current warehouse list holds these ids only.
	 *
	 * @param int[]    $ids  Listed warehouses.
	 * @param callable $then What to run.
	 * @return void
	 */
	private function with_listed_warehouses( array $ids, callable $then ): void {
		// The system clock: the sender and the intents judge the list's age by it.
		$cache = new Wpdb_Catalog_Cache( $this->db, Sqlite_Tables::table( 'catalog_cache' ) );
		$list  = array_map(
			static function ( int $id ): array {
				return [ 'warehouse_id' => $id ];
			},
			$ids
		);

		$cache->put( Warehouse_Options::key_for( $this->account_id ), [ 'warehouses' => $list ] );
		$GLOBALS['wpdb'] = $this->db;

		try {
			$then();
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}

	/**
	 * A created, synced product whose stock the marketplace already holds in the account's warehouse.
	 *
	 * @param string $offer  Article.
	 * @param int    $remote Marketplace item id.
	 * @param int    $stock  Stock.
	 * @return int Product id.
	 */
	private function synced_product( string $offer, int $remote, int $stock ): int {
		$product = $this->stock_product( $offer, $remote, $stock );
		$this->set_stock( $product, $stock );
		$this->flush();

		$this->market->requests = [];
		$this->now             += 31;

		return $product;
	}

	/**
	 * Builds the reseed runner.
	 *
	 * @param Stock_Intents|null $stock The recorder; the hooked one when null.
	 * @return Reseed_Stock_Runner
	 */
	private function reseed( ?Stock_Intents $stock = null ): Reseed_Stock_Runner {
		return new Reseed_Stock_Runner( $this->names(), $this->links(), $stock ?? $this->stock, [ $this, 'log_line' ] );
	}

	/**
	 * The stock sends, in order: warehouse and quantities by article.
	 *
	 * @return array<int,array{0:int,1:array<string,int>}>
	 */
	private function pushes(): array {
		$pushes = [];

		foreach ( $this->market->requests as $request ) {
			if ( Scripted_Stock_Pusher::PUSH === $request[0] ) {
				$pushes[] = [ $request[1], $request[2] ];
			}
		}

		return $pushes;
	}

	/**
	 * What a warehouse was last sent for the only product of a test, counting the sends before {@see self::synced_product()} cleared
	 * the list as «still held».
	 *
	 * @param int $warehouse Warehouse.
	 * @return int|null Null when nothing is known.
	 */
	private function present_in( int $warehouse ): ?int {
		$present = self::WAREHOUSE === $warehouse ? 7 : 0;

		foreach ( $this->pushes() as $push ) {
			if ( $warehouse === $push[0] ) {
				$present = (int) reset( $push[1] );
			}
		}

		return $present;
	}
}
