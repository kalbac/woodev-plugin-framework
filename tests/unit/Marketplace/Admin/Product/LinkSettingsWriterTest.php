<?php
/**
 * Unit tests for the writer behind the product tab.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Admin\Product\Link_Settings_Writer;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Flows\Offer_Id_Renames;
use Woodev\Framework\Marketplace\Jobs\Rename_Scheduler;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Article_Policy;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Nothing is created for a product the merchant never configured; a changed switch lands in the link row. The price and mapping
 * paths are not exercised here, so their collaborators are left bare.
 */
final class LinkSettingsWriterTest extends Sync_Test_Case {
	public function test_saving_the_defaults_of_a_product_without_a_row_creates_nothing(): void {
		$id = $this->product( 5 );

		$errors = $this->writer()->save(
			$this->account_now(),
			$this->products[ $id ],
			[
				'sync_stock' => true,
				'sync_price' => true,
			]
		);

		$this->assertSame( [], $errors );
		$this->assertNull( $this->links()->find( $this->account_id, $id ) );
	}

	public function test_switching_stock_sync_off_creates_the_row_with_the_switch_off(): void {
		$id = $this->product( 5 );
		$this->products[ $id ]->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
		$this->products[ $id ]->shouldReceive( 'get_parent_id' )->andReturn( 0 );

		$this->writer()->save( $this->account_now(), $this->products[ $id ], [ 'sync_stock' => false ] );

		$row = $this->links()->find( $this->account_id, $id );

		$this->assertNotNull( $row );
		$this->assertFalse( $row->sync_stock() );
	}

	public function test_a_switch_of_an_existing_row_is_updated(): void {
		$id = $this->stock_product( 'ART-1', 7001 );

		$this->writer()->save( $this->account_now(), $this->products[ $id ], [ 'sync_stock' => false ] );

		$this->assertFalse( $this->link_of( $id )->sync_stock() );
	}

	public function test_the_article_is_editable_only_before_the_first_export(): void {
		$this->assertTrue( Link_Settings_Writer::article_is_editable( null ) );
		$this->assertTrue( Link_Settings_Writer::article_is_editable( Link_Record::offer( 1, 2, 0, '' ) ) );

		$created = $this->link_of( $this->stock_product( 'ART-2', 7002 ) );

		$this->assertFalse( Link_Settings_Writer::article_is_editable( $created ) );
	}

	public function test_the_article_of_a_sent_card_is_frozen_for_an_adapter_that_cannot_change_it(): void {
		$id = $this->stock_product( 'ART-1', 7001 );

		$errors = $this->writer()->save( $this->account_now(), $this->products[ $id ], [ 'offer_id' => 'ART-NEW' ] );

		$this->assertSame( [], $errors );
		$this->assertNull( $this->link_of( $id )->pending_offer_id(), 'nothing is recorded' );
		$this->assertSame( [], $this->scheduler->of( ( new Rename_Scheduler( $this->names() ) )->hook() ) );
	}

	public function test_a_changed_article_of_a_sent_card_becomes_a_waiting_change_and_queues_the_job(): void {
		$id = $this->stock_product( 'ART-1', 7001 );

		$errors = $this->writer( true )->save( $this->account_now(), $this->products[ $id ], [ 'offer_id' => 'ART-NEW', 'sync_stock' => false ] );

		$row = $this->link_of( $id );
		$this->assertSame( [], $errors );
		$this->assertSame( 'ART-1', $row->offer_id(), 'a save never waits for the marketplace' );
		$this->assertSame( 'ART-NEW', $row->pending_offer_id() );
		$this->assertFalse( $row->sync_stock(), 'the rest of the form is saved in the same request' );
		$this->assertCount( 1, $this->scheduler->of( ( new Rename_Scheduler( $this->names() ) )->hook() ) );
	}

	public function test_an_article_the_account_already_uses_is_refused_with_a_sentence_and_the_rest_is_still_saved(): void {
		$first = $this->stock_product( 'ART-1', 7001 );
		$this->stock_product( 'ART-2', 7002 );

		$errors = $this->writer( true )->save( $this->account_now(), $this->products[ $first ], [ 'offer_id' => 'ART-2', 'sync_stock' => false ] );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'ART-2', $errors[0] );
		$this->assertNull( $this->link_of( $first )->pending_offer_id() );
		$this->assertFalse( $this->link_of( $first )->sync_stock() );
	}

	public function test_a_cleared_field_leaves_the_article_of_a_sent_card_alone(): void {
		$id = $this->stock_product( 'ART-1', 7001 );

		$errors = $this->writer( true )->save( $this->account_now(), $this->products[ $id ], [ 'offer_id' => null ] );

		$this->assertSame( [], $errors );
		$this->assertNull( $this->link_of( $id )->pending_offer_id() );
		$this->assertSame( 'ART-1', $this->link_of( $id )->offer_id() );
	}

	public function test_the_form_posting_the_unchanged_article_changes_nothing(): void {
		$id = $this->stock_product( 'ART-1', 7001 );

		$errors = $this->writer( true )->save( $this->account_now(), $this->products[ $id ], [ 'offer_id' => 'ART-1' ] );

		$this->assertSame( [], $errors );
		$this->assertNull( $this->link_of( $id )->pending_offer_id() );
		$this->assertSame( [], $this->scheduler->of( ( new Rename_Scheduler( $this->names() ) )->hook() ), 'a plain save of an untouched product sends nothing' );
	}

	/**
	 * Builds the writer over the real link store.
	 *
	 * @param bool $renames Whether the adapter changes the article of a sent card.
	 * @return Link_Settings_Writer
	 */
	private function writer( bool $renames = false ): Link_Settings_Writer {
		return new Link_Settings_Writer(
			$this->links(),
			( new \ReflectionClass( Price_Intents::class ) )->newInstanceWithoutConstructor(),
			( new \ReflectionClass( Wpdb_Mapping_Store::class ) )->newInstanceWithoutConstructor(),
			( new \ReflectionClass( Warehouse_Options::class ) )->newInstanceWithoutConstructor(),
			$this->hook_stock(),
			new Pinned_Offer_Id( 'fake', new Fake_Article_Policy() ),
			$renames ? new Offer_Id_Renames( $this->links(), new Rename_Scheduler( $this->names() ), static function (): void {}, [ $this, 'log_line' ] ) : null
		);
	}
}
