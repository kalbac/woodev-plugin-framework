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
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;

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
		$this->assertSame( 0, (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . \Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables::table( 'link_ids' ) . ' WHERE link_id = ' . $link_id ), 'the remote identifiers are gone' );
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
	 * A card that is created keeps its row for the archive intent; one being submitted keeps it for the request.
	 *
	 * @return void
	 */
	public function test_a_created_or_submitting_row_stays(): void {
		$this->linked( 601, 'NB-601', Link_Record::STATE_CREATED );
		$this->linked( 602, 'NB-602', Link_Record::STATE_SUBMITTED );

		$this->post_type_is( 'product' );
		$this->releaser()->on_delete( 601 );
		$this->releaser()->on_delete( 602 );

		$this->assertNotNull( $this->links()->find( $this->account_id, 601 ) );
		$this->assertNotNull( $this->links()->find( $this->account_id, 602 ) );
	}

	/**
	 * The links go even while the account's sender runs: it finds no link and drops what it holds.
	 *
	 * @return void
	 */
	public function test_a_busy_sender_does_not_keep_the_row(): void {
		$link = $this->linked( 701, 'NB-701' );

		$this->assertNotNull( $this->accounts()->claim( $this->account_id, 120 ) );

		$this->post_type_is( 'product' );
		$this->releaser()->on_delete( 701 );

		$this->assertNull( $this->links()->get( (int) $link->link_id() ) );
		$this->assertNull( $this->ledger()->sent_offer_id( (int) $link->link_id() ) );
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
