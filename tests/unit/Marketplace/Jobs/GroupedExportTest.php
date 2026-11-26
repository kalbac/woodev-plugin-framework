<?php
/**
 * A group of offers (the sizes of a card) is never split between two import requests.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Jobs\Export_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Poll_Task_Runner;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Grouping_Export_Facts;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Export_Scheduler
 * @covers \Woodev\Framework\Marketplace\Jobs\Export_Batch_Runner
 * @covers \Woodev\Framework\Marketplace\Flows\Export_Service
 */
final class GroupedExportTest extends Export_Test_Case {
	private const EXPORT = 'woodev_fake_seller_export_batch';

	/**
	 * Links without a group are cut every 100, as before.
	 *
	 * @return void
	 */
	public function test_links_without_a_group_are_cut_every_hundred(): void {
		$this->export_scheduler()->queue_export( $this->account_id, range( 1, 250 ), [ 7 => '' ] );

		$this->assertSame( [ range( 1, 100 ), range( 101, 200 ), range( 201, 250 ) ], $this->batches() );
	}

	/**
	 * A group that does not fit into the batch moves to the next one whole.
	 *
	 * @return void
	 */
	public function test_a_group_that_does_not_fit_moves_to_the_next_batch(): void {
		$this->export_scheduler()->queue_export( $this->account_id, range( 1, 103 ), array_fill_keys( range( 99, 103 ), 'coat' ) );

		$this->assertSame( [ range( 1, 98 ), range( 99, 103 ) ], $this->batches() );
	}

	/**
	 * A group larger than a batch goes alone in one batch; what comes before and after keeps the usual size.
	 *
	 * @return void
	 */
	public function test_a_group_larger_than_a_batch_goes_whole(): void {
		$this->export_scheduler()->queue_export( $this->account_id, range( 1, 152 ), array_fill_keys( range( 2, 151 ), 'coat' ) );

		$this->assertSame( [ [ 1 ], range( 2, 151 ), [ 152 ] ], $this->batches() );
	}

	/**
	 * The members of a group are gathered at the place of the first one, whatever the order they came in.
	 *
	 * @return void
	 */
	public function test_scattered_members_of_a_group_are_gathered(): void {
		$this->export_scheduler()->queue_export(
			$this->account_id,
			[ 1, 2, 3, 4, 5 ],
			[
				1 => 'a',
				2 => 'b',
				4 => 'a',
				5 => 'b',
			]
		);

		$this->assertSame( [ [ 1, 4, 2, 5, 3 ] ], $this->batches() );
	}

	/**
	 * The service asks the adapter for the groups: variations that would straddle the boundary go out together, and the job
	 * hands the gateway the whole group.
	 *
	 * @return void
	 */
	public function test_the_variations_of_a_product_reach_the_gateway_in_one_request(): void {
		$this->facts = new Grouping_Export_Facts();
		$ids         = $this->simple_products( 99 );
		$variations  = $this->variable_product( 900, 3 );

		$this->service()->enqueue( $this->account_now(), array_merge( $ids, [ 900 ] ) );
		$batches = $this->batches();

		$this->assertSame( [ 99, 3 ], array_map( 'count', $batches ) );
		$this->assertSame( $this->link_ids( $variations ), $batches[1] );

		$this->export_runner()->run( $this->account_id, $batches[1], 0 );

		$this->assertSame( $this->link_ids( $variations ), array_keys( $this->gateway->submitted[0][1] ) );
	}

	/**
	 * Without grouping the same selection is cut at the boundary (the released behaviour).
	 *
	 * @return void
	 */
	public function test_an_adapter_without_groups_is_cut_at_the_boundary(): void {
		$ids = $this->simple_products( 99 );
		$this->variable_product( 900, 3 );

		$this->service()->enqueue( $this->account_now(), array_merge( $ids, [ 900 ] ) );

		$this->assertSame( [ 100, 2 ], array_map( 'count', $this->batches() ) );
	}

	/**
	 * A batch larger than a hundred is sent whole: nothing is cut off and left queued.
	 *
	 * @return void
	 */
	public function test_a_large_group_is_sent_in_one_request(): void {
		$this->facts = new Grouping_Export_Facts();
		$variations  = $this->variable_product( 900, 120 );

		$this->service()->enqueue( $this->account_now(), [ 900 ] );
		$batches = $this->batches();

		$this->assertCount( 1, $batches );
		$this->export_runner()->run( $this->account_id, $batches[0], 0 );

		$this->assertCount( 1, $this->gateway->submitted );
		$this->assertCount( 120, $this->gateway->submitted[0][1] );
		$this->assertSame( [], $this->links_in( Link_Record::STATE_QUEUED, $variations ) );
	}

	/**
	 * The sweep gives the links of a large task that never reached the marketplace back as one batch.
	 *
	 * @return void
	 */
	public function test_the_sweep_queues_the_links_of_a_task_as_one_batch(): void {
		$this->facts = new Grouping_Export_Facts();
		$this->variable_product( 900, 120 );
		$this->service()->enqueue( $this->account_now(), [ 900 ] );
		$link_ids                 = $this->batches()[0];
		$this->scheduler->actions = [];

		$task_id = $this->ledger()->open( new Async_Task( $this->account_id, Async_Task::KIND_IMPORT ), [] );
		$this->links()->claim_for_task( $task_id, $link_ids );
		$this->now += Poll_Task_Runner::STALE_AFTER + 1;

		$this->poll_runner()->run( Poll_Task_Runner::SWEEP_TASK, 0 );

		$this->assertSame( [ 120 ], array_map( 'count', $this->batches() ) );
	}

	/**
	 * Creates queued-ready simple products.
	 *
	 * @param int $count How many.
	 * @return int[] Product ids.
	 */
	private function simple_products( int $count ): array {
		$ids = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = (int) $this->add_product( [ 'id' => 1000 + $i ] )->get_id();
		}

		return $ids;
	}

	/**
	 * Creates a variable product with variations.
	 *
	 * @param int $parent_id Parent id.
	 * @param int $count     How many variations.
	 * @return int[] Variation ids.
	 */
	private function variable_product( int $parent_id, int $count ): array {
		$ids = range( $parent_id * 10 + 1, $parent_id * 10 + $count );

		foreach ( $ids as $id ) {
			$this->add_product(
				[
					'id'        => $id,
					'parent_id' => $parent_id,
				]
			);
		}

		$this->add_product(
			[
				'id'       => $parent_id,
				'type'     => 'variable',
				'children' => $ids,
			]
		);

		return $ids;
	}

	/**
	 * Returns the link ids of the scheduled export batches, in order.
	 *
	 * @return array<int,int[]>
	 */
	private function batches(): array {
		return array_values(
			array_map(
				static function ( array $action ): array {
					return $action['args'][1];
				},
				$this->scheduler->of( self::EXPORT )
			)
		);
	}

	/**
	 * Returns the link ids of products.
	 *
	 * @param int[] $product_ids Products.
	 * @return int[]
	 */
	private function link_ids( array $product_ids ): array {
		return array_map(
			function ( int $id ): int {
				return (int) $this->link_of( $id )->link_id();
			},
			$product_ids
		);
	}

	/**
	 * Returns the products whose link is in a state.
	 *
	 * @param string $state       Card state.
	 * @param int[]  $product_ids Products to look at.
	 * @return int[]
	 */
	private function links_in( string $state, array $product_ids ): array {
		return array_values(
			array_filter(
				$product_ids,
				function ( int $id ) use ( $state ): bool {
					return $this->link_of( $id )->card_state() === $state;
				}
			)
		);
	}
}
