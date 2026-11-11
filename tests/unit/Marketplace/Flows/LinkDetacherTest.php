<?php
/**
 * «Снять связь»: the link row, the send history and the sync state go back to a clean start.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Link_Detacher;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Link_Detacher
 */
final class LinkDetacherTest extends Export_Test_Case {
	/**
	 * Builds the detacher.
	 *
	 * @return Link_Detacher
	 */
	private function detacher(): Link_Detacher {
		return new Link_Detacher( $this->links(), $this->queue(), $this->ledger(), $this->accounts() );
	}

	/**
	 * A created link with a card, a frozen article, a stock intent and a price intent.
	 *
	 * @return Link_Record
	 */
	private function sent_link(): Link_Record {
		$product = $this->add_product( [ 'sku' => 'NB-1' ] );
		$link    = $this->add_link(
			$product,
			Link_Record::STATE_CREATED,
			[
				'offer_id'   => 'NB-1',
				'remote_ids' => [ 'card_ref' => 9001 ],
				'is_created' => 1,
			]
		);

		$link_id = (int) $link->link_id();
		$task    = $this->ledger()->open( new Async_Task( $this->account_id, Async_Task::KIND_IMPORT ), [] );
		$this->ledger()->add_items( $task, [ $link_id => 'NB-1' ] );
		$this->ledger()->mark_sending( $task, 'hash', [ $link_id ] );
		$this->intents()->record( $this->account_id, $link_id, self::WAREHOUSE, Sync_State_Store::KIND_STOCK, [ 'stock' => 5 ] );
		$this->intents()->record( $this->account_id, $link_id, 0, Sync_State_Store::KIND_PRICE, [ 'price' => '1000.00' ] );

		return $this->link_of( (int) $product->get_id() );
	}

	/**
	 * The row loses its identity but keeps the merchant's settings; the frozen article and the sync rows go with the old card.
	 *
	 * @return void
	 */
	public function test_a_detached_link_forgets_the_old_card_and_keeps_the_settings(): void {
		$link    = $this->sent_link();
		$link_id = (int) $link->link_id();

		$this->assertSame( 'NB-1', $this->ledger()->sent_offer_id( $link_id ) );

		$result = $this->detacher()->detach( $this->account_id, [ $link ] );

		$this->assertSame(
			[
				'detached' => 1,
				'kept'     => 0,
				'busy'     => false,
			],
			$result
		);

		$row = $this->link_of( $link->wc_product_id() );

		$this->assertSame( Link_Record::STATE_UNLINKED, $row->card_state() );
		$this->assertNull( $row->offer_id() );
		$this->assertSame( [], $row->remote_ids() );
		$this->assertTrue( $row->sync_stock() );
		$this->assertTrue( $row->sync_price() );
		$this->assertNull( $this->ledger()->sent_offer_id( $link_id ), 'an earlier request no longer freezes the article' );
		$this->assertNull( $this->queue()->get( Sync_State_Queue::key( $this->account_id, $link_id, self::WAREHOUSE, Sync_State_Store::KIND_STOCK ) ) );
		$this->assertNull( $this->queue()->get( Sync_State_Queue::key( $this->account_id, $link_id, 0, Sync_State_Store::KIND_PRICE ) ) );
	}

	/**
	 * While the account's sender holds the claim nothing is changed and the caller is told to try again.
	 *
	 * @return void
	 */
	public function test_a_busy_sender_changes_nothing(): void {
		$link = $this->sent_link();

		$this->assertNotNull( $this->accounts()->claim( $this->account_id, 120 ) );

		$result = $this->detacher()->detach( $this->account_id, [ $link ] );

		$this->assertTrue( $result['busy'] );
		$this->assertSame( 0, $result['detached'] );
		$this->assertSame( Link_Record::STATE_CREATED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertSame( 'NB-1', $this->ledger()->sent_offer_id( (int) $link->link_id() ) );
	}

	/**
	 * A row an export is submitting right now is kept: the request carries its article.
	 *
	 * @return void
	 */
	public function test_a_submitting_row_is_kept(): void {
		$product = $this->add_product( [ 'sku' => 'NB-2' ] );
		$link    = $this->add_link( $product, Link_Record::STATE_SUBMITTED, [ 'offer_id' => 'NB-2' ] );

		$result = $this->detacher()->detach( $this->account_id, [ $link ] );

		$this->assertSame( 1, $result['kept'] );
		$this->assertSame( 0, $result['detached'] );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->link_of( (int) $product->get_id() )->card_state() );
	}

	/**
	 * The claim is released after the call, whatever it did.
	 *
	 * @return void
	 */
	public function test_the_claim_is_released(): void {
		$link = $this->sent_link();

		$this->detacher()->detach( $this->account_id, [ $link ] );

		$this->assertNotNull( $this->accounts()->claim( $this->account_id, 120 ), 'the account can be claimed again' );
	}
}
