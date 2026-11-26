<?php
/**
 * «Сбросить состояние» of a card whose export task is still open (#2040).
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Import_Outcome;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Article_Lifecycle;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Releasing_Lifecycle;

require_once dirname( __DIR__ ) . '/Mappings/wp-term-stub.php';

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Export_Service
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk
 */
final class ResetOpenImportTest extends Products_Test_Case {
	private const BUSY = 'карточка сейчас отправляется на маркетплейс — дождитесь результата';

	public function test_a_reset_card_can_be_exported_at_once_while_the_rest_of_its_task_stays_busy(): void {
		$this->releasing_marketplace();
		list( $reset, $waiting ) = $this->sent( 'NB-1', 'NB-2' );

		$this->assertSame( 1, $this->apply( Site_Products_Bulk::RESET, [ $reset ] )['accepted'] );

		$activity = $this->activity_of( $reset, $waiting );
		$this->assertSame( [], $activity[ $reset ]['blocked_actions'] );
		$this->assertSame( [], $activity[ $reset ]['processes'] );
		$this->assertContains( Site_Products_Bulk::EXPORT, $activity[ $waiting ]['blocked_actions'] );

		$plan = $this->preview( Site_Products_Bulk::EXPORT, [ $reset, $waiting ] );

		$this->assertSame( 1, $plan['eligible'] );
		$this->assertSame( self::BUSY, $plan['groups'][0]['reason'] );

		$this->assertSame( 1, $this->apply( Site_Products_Bulk::EXPORT, [ $reset ] )['accepted'] );
		$this->assertSame( Link_Record::STATE_QUEUED, $this->link_of( $reset )->card_state() );
	}

	public function test_the_older_task_no_longer_asks_for_the_reset_card_and_does_not_move_it(): void {
		$this->releasing_marketplace();
		list( $reset, $waiting ) = $this->sent( 'NB-1', 'NB-2' );

		$this->apply( Site_Products_Bulk::RESET, [ $reset ] );
		$this->apply( Site_Products_Bulk::EXPORT, [ $reset ] );
		$this->run_batch( [ $reset ] );

		$this->lifecycle->reads  = [];
		$this->lifecycle->import = [
			'NB-1' => $this->imported(),
			'NB-2' => $this->imported(),
		];
		$this->poll_runner()->run( 1, 0 );

		$this->assertSame( [ [ 'by_articles', [ 'NB-2' ] ] ], $this->lifecycle->reads );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->link_of( $reset )->card_state(), 'The newer task owns it.' );
		$this->assertSame( Task_Ledger::ITEM_SKIPPED, $this->item_status( 1, $reset ) );
		$this->assertSame( Async_Task::STATUS_DONE_WITH_ERRORS, $this->ledger()->get( 1 )->status() );

		$this->poll_runner()->run( 2, 0 );

		$this->assertSame( Link_Record::STATE_OFFER_VALIDATED, $this->link_of( $reset )->card_state() );
		$this->assertSame( Async_Task::STATUS_DONE, $this->ledger()->get( 2 )->status() );
	}

	public function test_a_task_whose_only_card_was_reset_ends_without_asking_the_marketplace(): void {
		$this->releasing_marketplace();
		list( $reset ) = $this->sent( 'NB-1' );

		$this->service()->reset( $this->account_now(), [ $reset ] );
		$this->poll_runner()->run( 1, 0 );

		$this->assertSame( [], $this->lifecycle->reads );
		$this->assertSame( Async_Task::STATUS_DONE_WITH_ERRORS, $this->ledger()->get( 1 )->status() );
		$this->assertSame( Link_Record::STATE_UNLINKED, $this->link_of( $reset )->card_state() );
	}

	public function test_a_marketplace_that_keeps_reset_imports_leaves_the_card_busy_until_its_task_ends(): void {
		$this->lifecycle            = new Scripted_Article_Lifecycle();
		$this->gateway->task_number = '';

		list( $reset ) = $this->sent( 'NB-1' );

		$this->apply( Site_Products_Bulk::RESET, [ $reset ] );

		$this->assertSame( Task_Ledger::ITEM_PENDING, $this->item_status( 1, $reset ) );
		$this->assertContains( Site_Products_Bulk::EXPORT, $this->activity_of( $reset )[ $reset ]['blocked_actions'] );

		$plan = $this->preview( Site_Products_Bulk::EXPORT, [ $reset ] );

		$this->assertSame( 0, $plan['eligible'] );
		$this->assertSame( self::BUSY, $plan['groups'][0]['reason'] );
	}

	/**
	 * A write the database loses during the reset: the one that looks up and skips the open items, the reset of the links, the
	 * start of their transaction.
	 *
	 * @return array<string,array{0:string}> Pattern of the statement.
	 */
	public function lost_statements(): array {
		return [
			'the lookup and release of the open items' => [ '/^UPDATE \S*task_items/' ],
			'the reset of the links'                   => [ '/^UPDATE \S*_links\S* SET card_state/' ],
			'the start of the transaction'             => [ '/^START TRANSACTION$/' ],
		];
	}

	/**
	 * @dataProvider lost_statements
	 *
	 * @param string $lost Pattern of the statement that fails.
	 * @return void
	 */
	public function test_a_reset_the_database_refuses_changes_nothing_says_so_and_can_be_retried( string $lost ): void {
		$this->releasing_marketplace();
		list( $reset ) = $this->sent( 'NB-1' );

		$failures             = 0;
		$this->db->fail_query = static function ( string $query ) use ( $lost, &$failures ): bool {
			$fails     = 0 === $failures && 1 === preg_match( $lost, $query );
			$failures += (int) $fails;

			return $fails;
		};

		$result = $this->apply( Site_Products_Bulk::RESET, [ $reset ] );

		$this->assertSame( 1, $failures, 'The fault was injected.' );
		$this->assertSame( 0, $result['accepted'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 'failed', $result['groups'][0]['kind'] );
		$this->assertSame( 'состояние не сброшено: изменения не записались в базу данных — повторите действие', $result['groups'][0]['reason'] );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->link_of( $reset )->card_state() );
		$this->assertSame( Task_Ledger::ITEM_PENDING, $this->item_status( 1, $reset ) );

		$this->assertSame( 1, $this->apply( Site_Products_Bulk::RESET, [ $reset ] )['accepted'] );
		$this->assertSame( Link_Record::STATE_UNLINKED, $this->link_of( $reset )->card_state() );
		$this->assertSame( Task_Ledger::ITEM_SKIPPED, $this->item_status( 1, $reset ) );
		$this->assertSame( 1, $this->preview( Site_Products_Bulk::EXPORT, [ $reset ] )['eligible'] );
	}

	public function test_the_release_does_not_depend_on_a_read_of_the_ledger(): void {
		$this->releasing_marketplace();
		list( $reset ) = $this->sent( 'NB-1' );

		$this->db->fail_read = static function ( string $query ): bool {
			return false !== strpos( $query, 'task_items' );
		};

		$this->assertSame( 1, $this->apply( Site_Products_Bulk::RESET, [ $reset ] )['accepted'] );

		$this->db->fail_read = null;

		$this->assertSame( Task_Ledger::ITEM_SKIPPED, $this->item_status( 1, $reset ) );
	}

	/**
	 * Makes the fake marketplace one without task numbers whose reset cards leave their open tasks.
	 *
	 * @return void
	 */
	private function releasing_marketplace(): void {
		$this->lifecycle            = new Scripted_Releasing_Lifecycle();
		$this->gateway->task_number = '';
	}

	/**
	 * Exports new products in one task that now waits for the marketplace.
	 *
	 * @param string ...$skus Articles.
	 * @return int[] Product ids.
	 */
	private function sent( string ...$skus ): array {
		$ids = [];

		foreach ( $skus as $sku ) {
			$ids[] = (int) $this->add_product( [ 'sku' => $sku ] )->get_id();
		}

		$this->service()->enqueue( $this->account_now(), $ids );
		$this->run_batch( $ids );

		return $ids;
	}

	/**
	 * Runs the export job for the links of products.
	 *
	 * @param int[] $product_ids Product ids.
	 * @return void
	 */
	private function run_batch( array $product_ids ): void {
		$this->export_runner()->run(
			$this->account_id,
			array_map(
				function ( int $product_id ): int {
					return (int) $this->link_of( $product_id )->link_id();
				},
				$product_ids
			),
			0
		);
	}

	/**
	 * @param string $action Action id.
	 * @param int[]  $offers Offers.
	 * @return array<string,mixed>
	 */
	private function preview( string $action, array $offers ): array {
		return $this->bulk()->preview( $this->account_now(), $action, $offers, array_combine( $offers, $offers ) ?: [], null );
	}

	/**
	 * @param string $action Action id.
	 * @param int[]  $offers Offers.
	 * @return array<string,mixed>
	 */
	private function apply( string $action, array $offers ): array {
		return $this->bulk()->apply( $this->account_now(), $action, $offers, array_combine( $offers, $offers ) ?: [], null );
	}

	/**
	 * The row activity the table would show.
	 *
	 * @param int ...$product_ids Product ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function activity_of( int ...$product_ids ): array {
		$offers = [];

		foreach ( $product_ids as $product_id ) {
			$link                  = $this->link_of( $product_id );
			$offers[ $product_id ] = [
				'link_id'    => $link->link_id(),
				'card_state' => $link->card_state(),
			];
		}

		return $this->activity()->for_offers( $this->account_id, $offers );
	}

	/**
	 * The status of a product's item in a task.
	 *
	 * @param int $task_id    Task id.
	 * @param int $product_id Product id.
	 * @return string
	 */
	private function item_status( int $task_id, int $product_id ): string {
		return $this->ledger()->items( $task_id )[ (int) $this->link_of( $product_id )->link_id() ]['status'];
	}

	/**
	 * Returns a successful import verdict.
	 *
	 * @return Import_Outcome
	 */
	private function imported(): Import_Outcome {
		return new Import_Outcome( true, Link_Record::STATE_OFFER_VALIDATED, Task_Ledger::ITEM_IMPORTED );
	}
}
