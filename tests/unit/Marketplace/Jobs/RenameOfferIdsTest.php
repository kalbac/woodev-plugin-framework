<?php
/**
 * Unit tests for changing the article of a card after the export: the waiting change, the job, the answer of the marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Jobs\Account_Job;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Flows\Offer_Id_Renames;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Jobs\Rename_Offer_Ids_Runner;
use Woodev\Framework\Marketplace\Jobs\Rename_Scheduler;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Offer_Id_Renamer;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * The merchant changes the article of a card the marketplace already has: the link keeps the old article until the marketplace confirms the new
 * one; a refusal leaves it and keeps the marketplace's words.
 */
final class RenameOfferIdsTest extends Sync_Test_Case {
	/**
	 * The marketplace double.
	 *
	 * @var Scripted_Offer_Id_Renamer
	 */
	private $renamer;

	/**
	 * Calls of the «move the chosen article» callback: `[ product id, new article ]`.
	 *
	 * @var array<int,array{0:int,1:string}>
	 */
	private $moved = [];

	/**
	 * Boots the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->renamer = new Scripted_Offer_Id_Renamer();
		$this->moved   = [];
	}

	public function test_a_requested_change_waits_on_the_link_and_queues_the_job_without_sending_anything(): void {
		$id = $this->stock_product( 'ART-1', 7001 );

		$problem = $this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-NEW' );

		$this->assertNull( $problem );
		$row = $this->link_of( $id );
		$this->assertSame( 'ART-1', $row->offer_id(), 'the link keeps the old article until the marketplace confirms' );
		$this->assertSame( 'ART-NEW', $row->pending_offer_id() );
		$this->assertNull( $row->rename_error() );
		$this->assertSame( [], $this->renamer->calls );
		$this->assertSame( [ [ $this->account_id, 0, 0 ] ], array_column( $this->scheduler->of( $this->hook() ), 'args' ) );
	}

	public function test_two_changes_saved_before_the_job_runs_queue_one_job(): void {
		$first  = $this->stock_product( 'ART-1', 7001 );
		$second = $this->stock_product( 'ART-2', 7002 );

		$this->renames()->request( $this->account_now(), $this->link_of( $first ), 'N-1' );
		$this->renames()->request( $this->account_now(), $this->link_of( $second ), 'N-2' );

		$this->assertCount( 1, $this->scheduler->of( $this->hook() ) );
	}

	public function test_the_job_sends_the_article_and_moves_the_link_the_pin_and_the_index_together(): void {
		$id = $this->stock_product( 'ART-1', 7001 );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-NEW' );

		$this->run_job();

		$this->assertSame( [ [ 'ART-1' => 'ART-NEW' ] ], $this->renamer->calls );
		$row = $this->link_of( $id );
		$this->assertSame( 'ART-NEW', $row->offer_id() );
		$this->assertNull( $row->pending_offer_id() );
		$this->assertNull( $row->rename_error() );
		$this->assertSame( [ [ $id, 'ART-NEW' ] ], $this->moved );
		$this->assertNull( $this->links()->find_by_offer_id( $this->account_id, 'ART-1' ), 'the old article no longer finds the link' );
		$this->assertSame( $id, $this->links()->find_by_offer_id( $this->account_id, 'ART-NEW' )->wc_product_id() );
		$this->assertContains( 'rename_offer_ids: account ' . $this->account_id . ': article ART-1 changed to ART-NEW (product ' . $id . ')', $this->log, 'the change is in the log' );
		$this->assertCount( 1, $this->scheduler->of( $this->hook() ), 'the run queued nothing more' );
	}

	public function test_a_refused_change_keeps_the_old_article_and_the_marketplaces_words(): void {
		$id                              = $this->stock_product( 'ART-1', 7001 );
		$this->renamer->refuse['ART-1'] = 'OFFER_ID_ALREADY_EXISTS: артикул занят';
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'TAKEN' );

		$this->run_job();

		$row = $this->link_of( $id );
		$this->assertSame( 'ART-1', $row->offer_id(), 'the old article stays linked' );
		$this->assertSame( 'TAKEN', $row->pending_offer_id() );
		$this->assertSame( 'OFFER_ID_ALREADY_EXISTS: артикул занят', $row->rename_error() );
		$this->assertSame( [], $this->moved );
		$this->assertContains( 'rename_offer_ids: account ' . $this->account_id . ': article ART-1 was not changed to TAKEN: OFFER_ID_ALREADY_EXISTS: артикул занят', $this->log );
	}

	public function test_a_refused_change_does_not_stop_the_others_of_the_same_request(): void {
		$first                           = $this->stock_product( 'ART-1', 7001 );
		$second                          = $this->stock_product( 'ART-2', 7002 );
		$this->renamer->refuse['ART-1'] = 'занят';
		$this->renames()->request( $this->account_now(), $this->link_of( $first ), 'N-1' );
		$this->renames()->request( $this->account_now(), $this->link_of( $second ), 'N-2' );

		$this->run_job();

		$this->assertSame( 'занят', $this->link_of( $first )->rename_error() );
		$this->assertSame( 'N-2', $this->link_of( $second )->offer_id() );
	}

	public function test_an_article_another_product_holds_or_waits_for_is_refused_before_anything_is_queued(): void {
		$first  = $this->stock_product( 'ART-1', 7001 );
		$second = $this->stock_product( 'ART-2', 7002 );
		$third  = $this->stock_product( 'ART-3', 7003 );

		$held = $this->renames()->request( $this->account_now(), $this->link_of( $first ), 'ART-2' );
		$this->renames()->request( $this->account_now(), $this->link_of( $second ), 'WANTED' );
		$wanted = $this->renames()->request( $this->account_now(), $this->link_of( $third ), 'WANTED' );

		$this->assertStringContainsString( 'ART-2', (string) $held );
		$this->assertStringContainsString( 'уже используется', (string) $held );
		$this->assertStringContainsString( 'WANTED', (string) $wanted );
		$this->assertNull( $this->link_of( $first )->pending_offer_id() );
		$this->assertNull( $this->link_of( $third )->pending_offer_id() );
	}

	public function test_an_article_a_refused_change_wanted_is_free_again(): void {
		$first  = $this->stock_product( 'ART-1', 7001 );
		$second = $this->stock_product( 'ART-2', 7002 );
		$this->renamer->refuse['ART-1'] = 'не подходит';
		$this->renames()->request( $this->account_now(), $this->link_of( $first ), 'WANTED' );
		$this->run_job();

		$this->assertNull( $this->renames()->request( $this->account_now(), $this->link_of( $second ), 'WANTED' ) );
	}

	public function test_the_same_article_is_taken_in_another_account_only_in_that_account(): void {
		$id = $this->stock_product( 'ART-1', 7001 );
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id + 50, 999, 0, 'WANTED', [ 'card_state' => 'created' ] ) );

		$this->assertNull( $this->renames()->request( $this->account_now(), $this->link_of( $id ), 'WANTED' ), 'an article is unique inside one account' );
	}

	public function test_typing_the_current_article_takes_a_waiting_change_back(): void {
		$id = $this->stock_product( 'ART-1', 7001 );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-NEW' );

		$this->assertNull( $this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-1' ) );

		$this->assertNull( $this->link_of( $id )->pending_offer_id() );
		$this->run_job();
		$this->assertSame( [], $this->renamer->calls, 'nothing is sent for a change that was taken back' );
	}

	public function test_a_refused_change_is_asked_again_when_the_merchant_saves_the_same_article(): void {
		$id                              = $this->stock_product( 'ART-1', 7001 );
		$this->renamer->refuse['ART-1'] = 'временная ошибка';
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-NEW' );
		$this->run_job();
		$this->renamer->refuse = [];

		$this->assertNull( $this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-NEW' ) );
		$this->assertNull( $this->link_of( $id )->rename_error() );
		$this->run_job();

		$this->assertSame( 'ART-NEW', $this->link_of( $id )->offer_id() );
	}

	public function test_a_card_the_marketplace_does_not_know_yet_has_no_change(): void {
		$id = $this->stock_product( 'ART-1', 7001, 10, [ 'state' => 'submitted' ] );

		$problem = $this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-NEW' );

		$this->assertNotNull( $problem );
		$this->assertNull( $this->link_of( $id )->pending_offer_id() );
		$this->assertSame( [], $this->scheduler->of( $this->hook() ) );
	}

	public function test_an_answer_the_adapter_did_not_give_is_a_refusal_not_a_silent_success(): void {
		$id                    = $this->stock_product( 'ART-1', 7001 );
		$this->renamer->omit   = [ 'ART-1' ];
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-NEW' );

		$this->run_job();

		$row = $this->link_of( $id );
		$this->assertSame( 'ART-1', $row->offer_id() );
		$this->assertStringContainsString( 'не подтвердил', (string) $row->rename_error() );
	}

	public function test_a_request_that_fails_as_a_whole_for_good_leaves_every_row_of_it_with_the_reason(): void {
		$id                   = $this->stock_product( 'ART-1', 7001 );
		$this->renamer->throw = Fake_Remote_Exception::of( Remote_Error::FAIL, 'Ozon: item_limit_exceeded' );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-NEW' );

		$this->run_job();

		$row = $this->link_of( $id );
		$this->assertSame( 'ART-1', $row->offer_id() );
		$this->assertStringContainsString( 'Ozon: item_limit_exceeded', (string) $row->rename_error() );
	}

	public function test_a_retryable_failure_keeps_the_change_waiting_and_runs_the_job_again(): void {
		$id                   = $this->stock_product( 'ART-1', 7001 );
		$this->renamer->throw = Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-NEW' );

		$this->run_job();

		$row = $this->link_of( $id );
		$this->assertSame( 'ART-NEW', $row->pending_offer_id() );
		$this->assertNull( $row->rename_error() );
		$this->assertCount( 2, $this->scheduler->of( $this->hook() ), 'the retry is scheduled' );
	}

	public function test_a_change_made_while_the_request_was_on_its_way_is_kept_for_the_next_run(): void {
		$id = $this->stock_product( 'ART-1', 7001 );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'FIRST' );
		$row = $this->link_of( $id );

		// The job read the row (FIRST); the merchant then asks for SECOND; the marketplace confirms FIRST.
		$this->links()->request_offer_id_rename( $this->account_id, (int) $row->link_id(), 'SECOND' );
		$this->assertSame( 'done', $this->links()->finish_offer_id_rename( (int) $row->link_id(), 'ART-1', 'FIRST' ) );

		$after = $this->link_of( $id );
		$this->assertSame( 'FIRST', $after->offer_id() );
		$this->assertSame( 'SECOND', $after->pending_offer_id(), 'the later wish survives' );
		$this->assertNull( $after->rename_error() );
	}

	public function test_a_link_detached_while_the_request_was_on_its_way_loses_nothing_else(): void {
		$id = $this->stock_product( 'ART-1', 7001 );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-NEW' );
		$row = $this->link_of( $id );
		$this->links()->detach( $this->account_id, (int) $row->link_id() );

		$this->assertSame( 'gone', $this->links()->finish_offer_id_rename( (int) $row->link_id(), 'ART-1', 'ART-NEW' ) );
		$this->assertNull( $this->link_of( $id )->offer_id() );
		$this->assertNull( $this->link_of( $id )->pending_offer_id(), 'detaching dropped the waiting change' );
	}

	public function test_a_clash_on_the_unique_index_after_the_marketplace_confirmed_is_reported_not_half_applied(): void {
		$first  = $this->stock_product( 'ART-1', 7001 );
		$second = $this->stock_product( 'ART-2', 7002 );
		$this->renames()->request( $this->account_now(), $this->link_of( $first ), 'WANTED' );
		// Another row of the shop takes the article behind the check's back (an export computing it from a SKU).
		$this->db->query( 'UPDATE ' . Sqlite_Tables::table( 'links' ) . " SET offer_id = 'WANTED' WHERE wc_product_id = " . $second );

		$this->run_job();

		$row = $this->link_of( $first );
		$this->assertSame( 'ART-1', $row->offer_id(), 'the row is not half-moved' );
		$this->assertStringContainsString( 'уже занят', (string) $row->rename_error() );
	}

	public function test_an_account_the_plugin_holds_back_from_writing_sends_nothing_and_keeps_the_change(): void {
		$this->write_gate = static function (): bool {
			return false;
		};
		$id = $this->stock_product( 'ART-1', 7001 );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-NEW' );

		$this->run_job();

		$this->assertSame( [], $this->renamer->calls );
		$this->assertSame( 'ART-NEW', $this->link_of( $id )->pending_offer_id() );
		$this->assertNull( $this->link_of( $id )->rename_error() );
	}

	public function test_a_lapsed_licence_sends_nothing(): void {
		$this->licensed = false;
		$id             = $this->stock_product( 'ART-1', 7001 );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'ART-NEW' );

		$this->run_job();

		$this->assertSame( [], $this->renamer->calls );
		$this->assertSame( 'ART-NEW', $this->link_of( $id )->pending_offer_id() );
	}

	public function test_a_request_carries_at_most_what_the_marketplace_takes_and_the_rest_follows(): void {
		$this->renamer->limit = 2;
		$ids                  = [];

		foreach ( [ 'A', 'B', 'C' ] as $index => $offer ) {
			$ids[] = $this->stock_product( $offer, 7100 + $index );
			$this->renames()->request( $this->account_now(), $this->link_of( end( $ids ) ), $offer . '2' );
		}

		$this->run_job();

		$this->assertSame( [ 2, 1 ], array_map( 'count', $this->renamer->calls ) );
		$this->assertSame( 'C2', $this->link_of( $ids[2] )->offer_id() );
	}

	public function test_a_destination_waiting_for_the_marketplace_is_not_given_to_another_product_by_any_assignment(): void {
		$a = $this->stock_product( 'A', 7001 );
		$this->renames()->request( $this->account_now(), $this->link_of( $a ), 'DEST' );
		$b = $this->product( 10 );

		$this->assertSame( $a, $this->links()->owner_of_article( $this->account_id, 'DEST' )->wc_product_id(), 'the export validator and the linker see the owner' );
		$this->assertFalse( $this->links()->insert_if_absent( Link_Record::offer( $this->account_id, $b, 0, 'DEST' ) ), 'an export creating the row' );
		$this->assertNull( $this->links()->find( $this->account_id, $b ) );

		$this->assertTrue( $this->links()->insert_if_absent( Link_Record::offer( $this->account_id, $b, 0, '' ) ) );
		$row = $this->link_of( $b );
		$this->assertFalse( $this->links()->queue( (int) $row->link_id(), 'DEST' ), 'an export queueing the row' );
		$this->assertFalse( $this->links()->adopt_card( (int) $row->link_id(), [ 'offer_id' => 'DEST', 'remote_ids' => [ 'product_id' => 9 ], 'state' => 'created' ] ), 'linking an existing card' );
		$this->assertSame( \Woodev\Framework\Marketplace\Data\Wpdb_Link_Store::EXISTS, $this->links()->insert_card( Link_Record::offer( $this->account_id, 999, 0, 'DEST', [ 'card_state' => 'created' ] ) ), 'importing a card' );
		$this->assertSame( Card_Linker::CARD_LINKED, ( new Card_Linker( $this->links() ) )->link( $this->account_id, $b, 0, [ 'offer_id' => 'DEST', 'remote_ids' => [ 'product_id' => 9 ] ] ), 'link by article treats it as owned' );
		$this->assertNull( $this->link_of( $b )->offer_id() );
	}

	public function test_a_refused_change_releases_the_destination_for_every_assignment(): void {
		$a = $this->stock_product( 'A', 7001 );
		$this->renamer->refuse['A'] = 'нет';
		$this->renames()->request( $this->account_now(), $this->link_of( $a ), 'DEST' );
		$this->run_job();
		$b = $this->product( 10 );

		$this->assertTrue( $this->links()->insert_if_absent( Link_Record::offer( $this->account_id, $b, 0, 'DEST' ) ) );
		$this->assertNull( $this->links()->owner_of_article( $this->account_id, 'MISSING' ) );
	}

	public function test_a_database_error_after_the_marketplace_confirmed_keeps_the_destination_and_is_retried_without_sending_again(): void {
		$id = $this->stock_product( 'A', 7001 );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'DEST' );
		$this->db->define( 'CREATE TRIGGER reject_rename BEFORE UPDATE OF offer_id ON ' . Sqlite_Tables::table( 'links' ) . " WHEN NEW.offer_id = 'DEST' BEGIN SELECT RAISE(ABORT, 'database write failed'); END" );

		$this->run_job();

		$row = $this->link_of( $id );
		$this->assertSame( 'A', $row->offer_id() );
		$this->assertSame( 'DEST', $row->pending_offer_id(), 'the destination is kept' );
		$this->assertTrue( $row->rename_confirmed() );
		$this->assertStringContainsString( 'Маркетплейс уже сменил артикул на «DEST»', (string) $row->rename_error() );
		$this->assertStringNotContainsString( 'had gone', implode( "\n", $this->log ) );
		$this->assertCount( 2, $this->scheduler->of( $this->hook() ), 'the retry is scheduled' );
		$this->assertTrue( $this->links()->offer_id_is_reserved( $this->account_id, 'DEST' ), 'the destination stays reserved' );

		$this->db->query( 'DROP TRIGGER reject_rename' );
		$this->run_job();

		$this->assertSame( [ [ 'A' => 'DEST' ] ], $this->renamer->calls, 'the remote request is not sent again' );
		$row = $this->link_of( $id );
		$this->assertSame( 'DEST', $row->offer_id() );
		$this->assertNull( $row->pending_offer_id() );
		$this->assertFalse( $row->rename_confirmed() );
		$this->assertNull( $row->rename_error() );
	}

	public function test_saving_the_confirmed_destination_again_retries_the_move_and_resets_nothing(): void {
		$id = $this->stock_product( 'A', 7001 );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'DEST' );
		$this->links()->confirm_offer_id_rename( (int) $this->link_of( $id )->link_id(), 'DEST', 'сайт не записал' );

		$this->assertNull( $this->renames()->request( $this->account_now(), $this->link_of( $id ), 'DEST' ) );
		$this->assertTrue( $this->link_of( $id )->rename_confirmed(), 'the confirmation survives a save' );
		$this->run_job();

		$this->assertSame( [], $this->renamer->calls );
		$this->assertSame( 'DEST', $this->link_of( $id )->offer_id() );
	}

	public function test_a_job_that_runs_while_the_card_is_being_exported_comes_back_and_sends_afterwards(): void {
		$id = $this->stock_product( 'A', 7001 );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'DEST' );
		$link_id = (int) $this->link_of( $id )->link_id();
		$this->assertTrue( $this->links()->queue( $link_id, 'A' ) );

		$this->run_job();

		$this->assertSame( [], $this->renamer->calls, 'nothing is sent for a queued card' );
		$this->assertCount( 2, $this->scheduler->of( $this->hook() ), 'the job comes back for the waiting change' );
		$this->assertSame( 'DEST', $this->link_of( $id )->pending_offer_id() );
		$this->assertNull( $this->link_of( $id )->rename_error() );

		$this->links()->set_state( $link_id, Link_Record::STATE_CREATED );
		$this->run_job();

		$this->assertSame( 'DEST', $this->link_of( $id )->offer_id() );
	}

	public function test_a_change_whose_card_the_marketplace_refused_says_so_instead_of_waiting_for_ever(): void {
		$id = $this->stock_product( 'A', 7001 );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'DEST' );
		$this->links()->set_state( (int) $this->link_of( $id )->link_id(), Link_Record::STATE_FAILED, 'нет' );

		$this->run_job();

		$this->assertStringContainsString( 'Карточка сейчас не принята', (string) $this->link_of( $id )->rename_error() );
		$this->assertCount( 1, $this->scheduler->of( $this->hook() ) );
	}

	public function test_a_request_that_fails_for_good_marks_the_batches_it_never_reached_too(): void {
		$ids = [];

		for ( $i = 1; $i <= 26; $i++ ) {
			$ids[] = $this->stock_product( 'ART-' . $i, 7000 + $i );
			$this->renames()->request( $this->account_now(), $this->link_of( end( $ids ) ), 'NEW-' . $i );
		}

		$this->renamer->throw = Fake_Remote_Exception::of( Remote_Error::FAIL, 'validation error' );
		$this->run_job();

		$this->assertCount( 25, $this->renamer->calls[0] );
		$this->assertStringContainsString( 'validation error', (string) $this->link_of( $ids[25] )->rename_error(), 'the 26th change is not left looking queued' );
		$this->assertSame( 'NEW-26', $this->link_of( $ids[25] )->pending_offer_id(), 'saving it again asks again' );
	}

	public function test_a_job_that_gives_up_before_sending_marks_every_waiting_change(): void {
		$id = $this->stock_product( 'A', 7001 );
		$this->renames()->request( $this->account_now(), $this->link_of( $id ), 'DEST' );
		$this->licensed = false;

		$this->run_job( Account_Job::MAX_PUT_OFFS );

		$this->assertSame( [], $this->renamer->calls );
		$this->assertNotNull( $this->link_of( $id )->rename_error(), 'the product shows why the change will not happen' );
		$this->assertSame( 'A', $this->link_of( $id )->offer_id() );
	}

	public function test_a_variation_is_changed_like_a_simple_product(): void {
		$parent    = $this->product( null );
		$variation = $this->stock_product( 'VAR-1', 7201 );
		$this->links()->save(
			new \Woodev\Framework\Marketplace\Links\Product_Link( $this->account_id, $variation, $parent, 'VAR-1', [ 'item' => 7201 ], 'created', [ 'sync_stock' => true, 'sync_price' => false ], null )
		);

		$this->assertNull( $this->renames()->request( $this->account_now(), $this->link_of( $variation ), 'VAR-RED' ) );
		$this->run_job();

		$this->assertSame( 'VAR-RED', $this->link_of( $variation )->offer_id() );
		$this->assertSame( $parent, $this->link_of( $variation )->wc_parent_id() );
	}

	public function test_a_product_that_was_never_exported_has_no_rename_columns_to_set(): void {
		$row = Link_Record::offer( $this->account_id, 55, 0, '' );

		$this->assertFalse( $row->can_rename_offer_id() );
		$this->assertNull( $row->pending_offer_id() );
		$this->assertArrayNotHasKey( 'pending_offer_id', $row->to_columns(), 'an insert never names the columns a later schema revision added' );
	}

	/**
	 * The service over the real stores.
	 *
	 * @return Offer_Id_Renames
	 */
	private function renames(): Offer_Id_Renames {
		return new Offer_Id_Renames(
			$this->links(),
			new Rename_Scheduler( $this->names() ),
			function ( Link_Record $link, string $offer_id ): void {
				$this->moved[] = [ $link->wc_product_id(), $offer_id ];
			},
			[ $this, 'log_line' ]
		);
	}

	/**
	 * Runs the job of the account once, as Action Scheduler would.
	 *
	 * @param int $put_offs Waits the action already had.
	 * @return void
	 */
	private function run_job( int $put_offs = 0 ): void {
		$scheduler = new Rename_Scheduler( $this->names() );
		$gate      = $this->job_gate();

		( new Rename_Offer_Ids_Runner(
			$gate,
			$this->renames(),
			$this->renamer,
			$scheduler,
			$this->classifier,
			new Api_Error_Ring( $this->db, Sqlite_Tables::table( 'api_errors' ), $this->clock() ),
			[ $this, 'log_line' ]
		) )->run( $this->account_id, 0, $put_offs );
	}

	/**
	 * The hook of the job.
	 *
	 * @return string
	 */
	private function hook(): string {
		return ( new Rename_Scheduler( $this->names() ) )->hook();
	}
}
