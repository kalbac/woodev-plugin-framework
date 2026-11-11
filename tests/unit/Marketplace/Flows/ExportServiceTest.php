<?php
/**
 * Request-time export: validate, create the offer row, queue.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Jobs\Export_Not_Scheduled;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Export_Service
 */
final class ExportServiceTest extends Export_Test_Case {
	/**
	 * A valid product gets an offer row, goes to `queued` and one batch is scheduled for it.
	 *
	 * @return void
	 */
	public function test_a_valid_product_is_queued_and_scheduled(): void {
		$product = $this->add_product( [ 'sku' => 'NB-1' ] );

		$result = $this->service()->enqueue( $this->account_for(), [ $product->get_id() ] );

		$link = $this->link_of( (int) $product->get_id() );

		$this->assertSame( [ (int) $product->get_id() ], $result['queued'] );
		$this->assertSame( [], $result['refused'] );
		$this->assertTrue( $result['report']->report_for( (int) $product->get_id() )->is_valid() );
		$this->assertSame( Link_Record::STATE_QUEUED, $link->card_state() );
		$this->assertSame( 'NB-1', $link->offer_id() );
		$this->assertTrue( $link->sync_stock() );
		$this->assertTrue( $link->sync_price() );

		$batches = $this->scheduler->of( 'woodev_fake_seller_export_batch' );
		$this->assertCount( 1, $batches );
		$this->assertSame( [ $this->account_id, [ (int) $link->link_id() ], 0 ], $batches[0]['args'] );
		$this->assertSame( 'woodev-fake-seller', $batches[0]['group'] );
	}

	/**
	 * A product the validator blocks keeps its row with the reason and is not queued.
	 *
	 * @return void
	 */
	public function test_a_blocked_product_keeps_the_reason_and_is_not_queued(): void {
		$product            = $this->add_product();
		$this->rule->blocked = [ (int) $product->get_id() ];

		$result = $this->service()->enqueue( $this->account_for(), [ $product->get_id() ] );
		$link   = $this->link_of( (int) $product->get_id() );

		$this->assertSame( [], $result['queued'] );
		$this->assertSame( [ (int) $product->get_id() ], $result['report']->blocked_ids() );
		$this->assertNotSame( Link_Record::STATE_QUEUED, $link->card_state() );
		$this->assertSame( 'blocked_by_test', $this->links()->last_error( (int) $link->link_id() )['code'] );
		$this->assertSame( [], $this->scheduler->of( 'woodev_fake_seller_export_batch' ) );
	}

	/**
	 * A variable product stands for its variations, and a variation the marketplace does not sell is left out.
	 *
	 * @return void
	 */
	public function test_a_variable_product_expands_to_its_exportable_variations(): void {
		$first   = $this->add_product( [ 'parent_id' => 300 ] );
		$second  = $this->add_product( [ 'parent_id' => 300 ] );
		$digital = $this->add_product( [ 'parent_id' => 300, 'virtual' => true ] );
		$parent  = $this->add_product(
			[
				'id'       => 300,
				'type'     => 'variable',
				'children' => [ $first->get_id(), $second->get_id(), $digital->get_id() ],
			]
		);

		$result = $this->service()->enqueue( $this->account_for(), [ $parent->get_id() ] );

		$this->assertSame( [ (int) $first->get_id(), (int) $second->get_id() ], $result['queued'] );
		$this->assertNull( $this->links()->find( $this->account_id, (int) $digital->get_id() ) );
		$this->assertNull( $this->links()->find( $this->account_id, 300 ) );
	}

	/**
	 * More than one batch's worth of links is split into batches of the scheduler's size.
	 *
	 * @return void
	 */
	public function test_links_are_queued_in_batches(): void {
		$ids = [];
		for ( $i = 0; $i < 101; $i++ ) {
			$ids[] = (int) $this->add_product()->get_id();
		}

		$this->service()->enqueue( $this->account_for(), $ids );

		$batches = $this->scheduler->of( 'woodev_fake_seller_export_batch' );
		$this->assertCount( 2, $batches );
		$this->assertCount( 100, $batches[0]['args'][1] );
		$this->assertCount( 1, $batches[1]['args'][1] );
	}

	/**
	 * The store decides what is queued: a card being submitted is valid but refused.
	 *
	 * @return void
	 */
	public function test_a_submitted_card_is_valid_but_refused(): void {
		$product = $this->add_product();
		$link    = $this->add_link( $product, Link_Record::STATE_SUBMITTED );

		$result = $this->service()->enqueue( $this->account_for(), [ $product->get_id() ] );

		$this->assertSame( [], $result['queued'] );
		$this->assertSame( [ (int) $product->get_id() => Link_Record::STATE_SUBMITTED ], $result['refused'] );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->link_of( (int) $link->wc_product_id() )->card_state() );
		$this->assertSame( [], $this->scheduler->of( 'woodev_fake_seller_export_batch' ) );
	}

	/**
	 * The preview reports exactly what the queue would and writes nothing.
	 *
	 * @return void
	 */
	public function test_preview_writes_nothing(): void {
		$ok      = $this->add_product();
		$blocked = $this->add_product();
		$this->rule->blocked = [ (int) $blocked->get_id() ];

		$report = $this->service()->preview( $this->account_for(), [ $ok->get_id(), $blocked->get_id() ] );

		$this->assertSame( [ (int) $ok->get_id() ], $report->valid_ids() );
		$this->assertSame( [ (int) $blocked->get_id() ], $report->blocked_ids() );
		$this->assertNull( $this->links()->find( $this->account_id, (int) $ok->get_id() ) );
		$this->assertSame( [], $this->scheduler->actions );
	}

	/**
	 * A reset puts every link of the selection back to `unlinked`, even a product the marketplace does not sell.
	 *
	 * @return void
	 */
	public function test_reset_reaches_every_link_of_the_selection(): void {
		$sold    = $this->add_product();
		$digital = $this->add_product( [ 'virtual' => true ] );
		$this->add_link( $sold, Link_Record::STATE_FAILED );
		$this->add_link( $digital, Link_Record::STATE_FAILED );

		$reset = $this->service()->reset( $this->account_for(), [ $sold->get_id(), $digital->get_id() ] );

		$this->assertSame( 2, $reset );
		$this->assertSame( Link_Record::STATE_UNLINKED, $this->link_of( (int) $sold->get_id() )->card_state() );
		$this->assertSame( Link_Record::STATE_UNLINKED, $this->link_of( (int) $digital->get_id() )->card_state() );
	}

	/**
	 * A scheduler that cannot store the batch makes the call fail loudly.
	 *
	 * @return void
	 */
	public function test_a_refused_batch_is_an_error(): void {
		$product = $this->add_product();
		$this->scheduler->zero_for['woodev_fake_seller_export_batch'] = true;

		$this->expectException( \RuntimeException::class );

		$this->service()->enqueue( $this->account_for(), [ $product->get_id() ] );
	}

	/**
	 * When Action Scheduler takes the first batch and refuses the second, the links of the second are taken out of the queue (a
	 * `queued` row with no task and no batch is found by nothing), the first batch stays scheduled, and the caller is told.
	 *
	 * @return void
	 */
	public function test_a_refused_second_batch_does_not_strand_its_links(): void {
		$ids = [];
		for ( $i = 0; $i < 101; $i++ ) {
			$ids[] = (int) $this->add_product()->get_id();
		}
		$this->scheduler->zero_after['woodev_fake_seller_export_batch'] = 1;

		try {
			$this->service()->enqueue( $this->account_for(), $ids );
			$this->fail( 'the refused batch must be reported' );
		} catch ( Export_Not_Scheduled $e ) {
			$stranded = $e->link_ids();
		}

		$batches = $this->scheduler->of( 'woodev_fake_seller_export_batch' );
		$this->assertCount( 1, $batches );
		$this->assertCount( 100, $batches[0]['args'][1] );
		$this->assertCount( 1, $stranded );

		$left = $this->links()->get( $stranded[0] );
		$this->assertSame( Link_Record::STATE_UNLINKED, $left->card_state(), 'not left queued' );
		$this->assertNotSame( '', (string) $this->links()->last_error( $stranded[0] )['text'], 'the merchant sees why' );
		$this->assertNotContains( $stranded[0], $batches[0]['args'][1] );

		foreach ( $batches[0]['args'][1] as $scheduled ) {
			$this->assertSame( Link_Record::STATE_QUEUED, $this->links()->get( $scheduled )->card_state() );
		}
	}

	/**
	 * A link goes back to the state it had: a failed one keeps showing a reason, a created card comes back clean.
	 *
	 * @return void
	 */
	public function test_a_refused_batch_restores_the_previous_state(): void {
		$failed  = $this->add_product();
		$created = $this->add_product();
		$this->add_link( $failed, Link_Record::STATE_FAILED );
		$this->add_link( $created, Link_Record::STATE_CREATED );
		$this->scheduler->zero_for['woodev_fake_seller_export_batch'] = true;

		try {
			$this->service()->enqueue( $this->account_for(), [ $failed->get_id(), $created->get_id() ] );
			$this->fail( 'the refused batch must be reported' );
		} catch ( Export_Not_Scheduled $e ) {
			$this->assertCount( 2, $e->link_ids() );
		}

		$this->assertSame( Link_Record::STATE_FAILED, $this->link_of( (int) $failed->get_id() )->card_state() );
		$this->assertNotSame( '', (string) $this->links()->last_error( (int) $this->link_of( (int) $failed->get_id() )->link_id() )['text'] );
		$this->assertSame( Link_Record::STATE_CREATED, $this->link_of( (int) $created->get_id() )->card_state() );
		$this->assertSame( '', (string) $this->links()->last_error( (int) $this->link_of( (int) $created->get_id() )->link_id() )['text'] );
	}

	/**
	 * The account the service works for.
	 *
	 * @return \Woodev\Framework\Marketplace\Account\Account
	 */
	private function account_for() {
		return $this->account_now();
	}
}
