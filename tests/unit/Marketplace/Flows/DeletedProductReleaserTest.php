<?php
/**
 * A product deleted for good releases its marketplace links; one in the trash keeps them.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Deleted_Product_Releaser;
use Woodev\Framework\Marketplace\Flows\Link_Detacher;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Sync\Stock_Flusher;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Price_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Stock_Pusher;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Deleted_Product_Releaser
 * @covers \Woodev\Framework\Marketplace\Flows\Link_Detacher::release_deleted
 * @covers \Woodev\Framework\Marketplace\Data\Wpdb_Link_Store::delete_product_rows
 */
final class DeletedProductReleaserTest extends Export_Test_Case {
	/**
	 * Builds the releaser.
	 *
	 * @return Deleted_Product_Releaser
	 */
	private function releaser(): Deleted_Product_Releaser {
		return new Deleted_Product_Releaser(
			new Link_Detacher( $this->links(), $this->queue(), $this->ledger(), $this->accounts() ),
			function ( string $line ): void {
				$this->log[] = $line;
			}
		);
	}

	/**
	 * The stock sender, wired to hand a settled link of a gone product back, as the plugin wires it.
	 *
	 * @return Stock_Flusher
	 */
	protected function flusher(): Stock_Flusher {
		return new Stock_Flusher( $this->queue(), $this->intents(), $this->links(), $this->market, $this->market, $this->limits(), $this->classifier, $this->pause(), [ $this->releaser(), 'release_settled' ] );
	}

	/**
	 * Deletes a product for good the way WordPress does: the stock recorder and the releaser hear `before_delete_post`, then the
	 * product is gone.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	private function delete_for_good( int $product_id ): void {
		if ( null !== $this->stock_hook ) {
			$this->stock_hook->on_removed( $product_id );
		}

		$this->releaser()->on_delete( $product_id );

		unset( $this->products[ $product_id ] );
	}

	/**
	 * Announces the post type WordPress would report for an id.
	 *
	 * @param string $type Post type.
	 * @return void
	 */
	private function post_type_is( string $type ): void {
		Functions\when( 'get_post_type' )->justReturn( $type );
	}

	/**
	 * A linked product with a card, a send history and a stock and a price intent.
	 *
	 * @param int    $id    Product id.
	 * @param string $offer Offer id.
	 * @param string $state Card state.
	 * @return Link_Record
	 */
	private function linked( int $id, string $offer, string $state = Link_Record::STATE_PRICE_SENT ): Link_Record {
		$product = $this->add_product(
			[
				'id'  => $id,
				'sku' => $offer,
			]
		);
		$link    = $this->add_link(
			$product,
			$state,
			[
				'offer_id'   => $offer,
				'remote_ids' => [ 'card_ref' => $id * 10 ],
			]
		);

		$link_id = (int) $link->link_id();
		$task    = $this->ledger()->open( new Async_Task( $this->account_id, Async_Task::KIND_IMPORT ), [] );
		$this->ledger()->add_items( $task, [ $link_id => $offer ] );
		$this->ledger()->mark_sending( $task, 'hash-' . $id, [ $link_id ] );
		$this->intents()->record( $this->account_id, $link_id, self::WAREHOUSE, Sync_State_Store::KIND_STOCK, [ 'stock' => 5 ] );
		$this->intents()->record( $this->account_id, $link_id, 0, Sync_State_Store::KIND_PRICE, [ 'price' => '1000.00' ] );

		return $this->link_of( $id );
	}

	/**
	 * Whether the link has any `sync_state` row.
	 *
	 * @param int $link_id Link id.
	 * @return bool
	 */
	private function has_intents( int $link_id ): bool {
		return null !== $this->queue()->get( Sync_State_Queue::key( $this->account_id, $link_id, self::WAREHOUSE, Sync_State_Store::KIND_STOCK ) )
			|| null !== $this->queue()->get( Sync_State_Queue::key( $this->account_id, $link_id, 0, Sync_State_Store::KIND_PRICE ) );
	}

	/**
	 * The hook is the permanent deletion, and not the trash.
	 *
	 * @return void
	 */
	public function test_only_permanent_deletion_is_hooked(): void {
		Actions\expectAdded( 'before_delete_post' )->once();

		$this->releaser()->register();

		$this->assertFalse( has_action( 'wp_trash_post' ), 'moving to the trash keeps the links' );
	}

	/**
	 * The card can be linked or imported again, and everything the plugin remembered about it is gone.
	 *
	 * @return void
	 */
	public function test_a_deleted_simple_product_releases_its_card(): void {
		$link    = $this->linked( 301, 'NB-301' );
		$link_id = (int) $link->link_id();
		$other   = $this->linked( 302, 'NB-302' );

		$this->assertNotNull( $this->links()->find_by_offer_id( $this->account_id, 'NB-301' ) );
		$this->assertSame( 'NB-301', $this->ledger()->sent_offer_id( $link_id ) );

		$this->post_type_is( 'product' );
		$this->releaser()->on_delete( 301 );

		$this->assertNull( $this->links()->find( $this->account_id, 301 ), 'the row is gone' );
		$this->assertNull( $this->links()->find_by_offer_id( $this->account_id, 'NB-301' ), 'the card is free for a new link' );
		$this->assertNull( $this->links()->get( $link_id ) );
		$this->assertSame( 0, (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Sqlite_Tables::table( 'link_ids' ) . ' WHERE link_id = ' . $link_id ), 'the remote identifiers are gone' );
		$this->assertNull( $this->ledger()->sent_offer_id( $link_id ) );
		$this->assertFalse( $this->has_intents( $link_id ), 'queued stock and price are gone' );
		$this->assertNotNull( $this->link_of( 302 ), 'another product is not touched' );
		$this->assertTrue( $this->has_intents( (int) $other->link_id() ) );
		$this->assertSame( [], $this->log );
	}

	/**
	 * A variation takes only its own link.
	 *
	 * @return void
	 */
	public function test_a_deleted_variation_releases_only_its_own_link(): void {
		$this->links()->insert_if_absent( Link_Record::parent_row( $this->account_id, 400 ) );
		$this->add_link( $this->add_product( [ 'id' => 401, 'parent_id' => 400 ] ), Link_Record::STATE_PRICE_SENT, [ 'offer_id' => 'V-401' ] );
		$this->add_link( $this->add_product( [ 'id' => 402, 'parent_id' => 400 ] ), Link_Record::STATE_PRICE_SENT, [ 'offer_id' => 'V-402' ] );

		$this->post_type_is( 'product_variation' );
		$this->releaser()->on_delete( 401 );

		$this->assertNull( $this->links()->find( $this->account_id, 401 ) );
		$this->assertNotNull( $this->links()->find( $this->account_id, 402 ) );
		$this->assertNotNull( $this->links()->find( $this->account_id, 400 ), 'the group row stays while its other variation does' );
	}

	/**
	 * A variable product takes every variation's link and the group row.
	 *
	 * @return void
	 */
	public function test_a_deleted_variable_product_releases_every_variation(): void {
		$this->links()->insert_if_absent( Link_Record::parent_row( $this->account_id, 500 ) );
		$this->add_link( $this->add_product( [ 'id' => 501, 'parent_id' => 500 ] ), Link_Record::STATE_PRICE_SENT, [ 'offer_id' => 'V-501' ] );
		$this->add_link( $this->add_product( [ 'id' => 502, 'parent_id' => 500 ] ), Link_Record::STATE_UNLINKED );
		$this->linked( 503, 'NB-503' );

		$this->post_type_is( 'product' );
		$this->releaser()->on_delete( 500 );

		foreach ( [ 500, 501, 502 ] as $id ) {
			$this->assertNull( $this->links()->find( $this->account_id, $id ), 'row ' . $id );
		}

		$this->assertNull( $this->links()->find_by_offer_id( $this->account_id, 'V-501' ) );
		$this->assertNotNull( $this->links()->find( $this->account_id, 503 ), 'an unrelated product stays' );
	}

	/**
	 * A submitting row stays for its request.
	 *
	 * @return void
	 */
	public function test_a_submitting_row_stays(): void {
		$this->linked( 602, 'NB-602', Link_Record::STATE_SUBMITTED );

		$this->post_type_is( 'product' );
		$this->releaser()->on_delete( 602 );

		$this->assertNotNull( $this->links()->find( $this->account_id, 602 ) );
	}

	/**
	 * A created card is still zeroed and archived on the marketplace so it stops selling; its row stays until that settles, and
	 * is gone, with everything the plugin remembered about it, right after.
	 *
	 * @return void
	 */
	public function test_a_created_card_is_archived_first_then_its_link_goes(): void {
		$product = $this->stock_product( 'gone', 1001, 5 );
		$link_id = $this->link_id( $product );

		$this->hook_stock();
		$this->want_price( $product );
		$this->delete_for_good( $product );

		$this->assertSame( Link_Record::STATE_CREATED, $this->links()->get( $link_id )->card_state(), 'the row stays until the archive settles' );
		$this->assertSame(
			[
				'stock'   => 0,
				'archive' => true,
			],
			$this->queue()->get( Sync_State_Queue::key( $this->account_id, $link_id, self::WAREHOUSE, Sync_State_Store::KIND_STOCK ) )['pending_payload']
		);

		$result = $this->flush();

		$this->assertSame( [ [ 'gone' => 0 ] ], $this->market->sent( Scripted_Stock_Pusher::PUSH ) );
		$this->assertSame( [ [ 'gone' ] ], $this->market->sent( Scripted_Stock_Pusher::ARCHIVE ) );
		$this->assertSame( 1, $result->archived );
		$this->assertNull( $this->links()->get( $link_id ), 'the link is released once the archive settled' );
		$this->assertNull( $this->links()->find_by_offer_id( $this->account_id, 'gone' ), 'the card is not linked any more' );
		$this->assertSame( 0, (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Sqlite_Tables::table( 'link_ids' ) . ' WHERE link_id = ' . $link_id ) );
		$this->assertSame( 0, (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Sqlite_Tables::table( 'sync_state' ) . ' WHERE link_id = ' . $link_id ) );
	}

	/**
	 * A product only in the trash is not gone: its archived card keeps its row, so restoring it finds the link.
	 *
	 * @return void
	 */
	public function test_a_trashed_product_keeps_its_link_after_the_archive(): void {
		$product = $this->stock_product( 'trashed', 1101, 5 );

		$this->hook_stock();
		$this->trash( $product );
		$this->flush();

		$this->assertSame( Link_Record::STATE_ARCHIVED, $this->link_of( $product )->card_state() );
	}

	/**
	 * An archive the marketplace refuses for good (the card is not there) ends the link after the last attempt, not before.
	 *
	 * @return void
	 */
	public function test_a_refused_archive_releases_the_link_after_the_last_attempt(): void {
		$product = $this->stock_product( 'refused', 1201, 5 );
		$link_id = $this->link_id( $product );

		$this->hook_stock();
		$this->delete_for_good( $product );

		$this->market->script[] = static function () {
			return null;
		};

		for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
			$this->market->script[] = static function (): bool {
				return false;
			};
		}

		for ( $attempt = 1; $attempt <= 4; ++$attempt ) {
			$this->flush();
			$this->now += 100000;

			$this->assertNotNull( $this->links()->get( $link_id ), 'attempt ' . $attempt . ' is not the last' );
		}

		$this->flush();

		$this->assertNull( $this->links()->get( $link_id ) );
	}

	/**
	 * The link of a deleted product goes even while the account's sender holds the claim; the sender's next pass drops the stock
	 * and the price it still held for it.
	 *
	 * @return void
	 */
	public function test_a_busy_sender_leaves_no_rows_after_its_next_pass(): void {
		$product = $this->stock_product( 'busy', 1301, 5, [ 'sync_price' => true ] );
		$link_id = $this->link_id( $product );

		$this->want_price( $product );
		$this->intents()->record( $this->account_id, $link_id, self::WAREHOUSE, Sync_State_Store::KIND_STOCK, [ 'stock' => 3 ] );
		$this->links()->set_state( $link_id, Link_Record::STATE_PRICE_SENT );

		$token = $this->accounts()->claim( $this->account_id, 120 );
		$this->assertNotNull( $token );

		$this->delete_for_good( $product );

		$this->assertNull( $this->links()->get( $link_id ) );
		$this->assertSame( 2, (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Sqlite_Tables::table( 'sync_state' ) . ' WHERE link_id = ' . $link_id ), 'the rows wait for the sender' );

		$this->accounts()->release_claim( $this->account_id, (string) $token );

		$prices = $this->price_runner( new Scripted_Price_Pusher() );
		$this->flush_runner( [ $prices, 'flush_under_claim' ] )->run( $this->account_id );

		$this->assertSame( 0, (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Sqlite_Tables::table( 'sync_state' ) . ' WHERE link_id = ' . $link_id ), 'no stock and no price row is left' );
	}

	/**
	 * The price half does the same for a link that only had a price queued.
	 *
	 * @return void
	 */
	public function test_the_price_sender_drops_a_price_of_a_gone_link(): void {
		$product = $this->stock_product( 'priceonly', 1401, 5, [ 'sync_price' => true ] );
		$link_id = $this->link_id( $product );

		$this->want_price( $product );
		$this->links()->set_state( $link_id, Link_Record::STATE_PRICE_SENT );

		$token = $this->accounts()->claim( $this->account_id, 120 );
		$this->assertNotNull( $token );

		$this->delete_for_good( $product );
		$this->accounts()->release_claim( $this->account_id, (string) $token );

		$pusher = new Scripted_Price_Pusher();
		$prices = $this->price_runner( $pusher );
		$this->flush_runner( [ $prices, 'flush_under_claim' ] )->run( $this->account_id );

		$this->assertSame( 0, (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . Sqlite_Tables::table( 'sync_state' ) . ' WHERE link_id = ' . $link_id ) );
		$this->assertSame( [], $pusher->requests, 'nothing was sent for a gone card' );
	}

	/**
	 * Other post types are not looked up at all.
	 *
	 * @return void
	 */
	public function test_another_post_type_is_ignored(): void {
		$this->linked( 801, 'NB-801' );

		$this->post_type_is( 'page' );
		$this->releaser()->on_delete( 801 );

		$this->assertNotNull( $this->links()->find( $this->account_id, 801 ) );
	}

	/**
	 * A product without links costs one lookup and no write.
	 *
	 * @return void
	 */
	public function test_a_product_without_links_costs_one_lookup(): void {
		$db = \Mockery::mock( \wpdb::class );

		$db->shouldReceive( 'prepare' )->once()->andReturn( 'SELECT 1' );
		$db->shouldReceive( 'get_results' )->once()->andReturn( [] );
		$db->shouldNotReceive( 'query' );

		$this->assertSame( [], ( new Wpdb_Link_Store( $db, 'links', 'link_ids' ) )->delete_product_rows( 900 ) );
	}

	/**
	 * A failure is logged and never stops the deletion.
	 *
	 * @return void
	 */
	public function test_a_failure_is_logged_not_thrown(): void {
		$db = \Mockery::mock( \wpdb::class );

		$db->shouldReceive( 'prepare' )->andReturn( 'SELECT 1' );
		$db->shouldReceive( 'get_results' )->andThrow( new \RuntimeException( 'database is gone' ) );

		$releaser = new Deleted_Product_Releaser(
			new Link_Detacher( new Wpdb_Link_Store( $db, 'links', 'link_ids' ), $this->queue(), $this->ledger(), $this->accounts() ),
			function ( string $line ): void {
				$this->log[] = $line;
			}
		);

		$this->post_type_is( 'product' );
		$releaser->on_delete( 951 );

		$this->assertCount( 1, $this->log );
		$this->assertStringContainsString( 'deleted product 951', $this->log[0] );
		$this->assertStringContainsString( 'database is gone', $this->log[0] );
	}
}
