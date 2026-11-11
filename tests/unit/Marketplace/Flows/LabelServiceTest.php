<?php
/**
 * Label tasks and documents over the adapter's label provider.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Actions;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Flows\Label_Answer;
use Woodev\Framework\Marketplace\Flows\Label_Exception;
use Woodev\Framework\Marketplace\Flows\Label_Service;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;

/**
 * Moved from the Ozon plugin's `LabelsTest` (the service half): create → task row → poll → document in the database. Everything
 * the marketplace decides is the fake provider's: its limit, its task ids, how it words «still making it».
 */
final class LabelServiceTest extends Label_Test_Case {
	private const READY  = 'woodev_fake_seller_label_ready';
	private const FAILED = 'woodev_fake_seller_label_failed';

	/**
	 * `create` hands the cleaned key list to the provider and stores a `label` task row with the marketplace's task id.
	 *
	 * @return void
	 */
	public function test_create_asks_the_provider_with_cleaned_keys_and_stores_a_label_task(): void {
		$this->provider->remote_task_id = '9001';

		$task_id = $this->service()->create( $this->account(), [ ' 0001-1 ', '0001-1', '', '0002-1' ] );

		$this->assertSame( [ [ 'request', [ '0001-1', '0002-1' ] ] ], $this->provider->calls );

		$task = $this->labels->task( $task_id );
		$this->assertSame( Async_Task::KIND_LABEL, $task['kind'] );
		$this->assertSame( '9001', $task['remote_task_id'] );
		$this->assertSame( Async_Task::STATUS_SUBMITTED, $task['status'] );
		$this->assertSame( [ '0001-1', '0002-1' ], $task['result'][ Wpdb_Label_Store::RESULT_EXTERNAL_KEYS ] );
	}

	/**
	 * More orders than the provider takes, none, or no licence: nothing is sent.
	 *
	 * @return void
	 */
	public function test_create_refuses_bad_lists_and_a_lapsed_licence_before_calling_the_marketplace(): void {
		$many = array_map(
			static function ( int $i ): string {
				return 'p-' . $i;
			},
			range( 1, $this->provider->max + 1 )
		);

		foreach ( [ $many, [] ] as $list ) {
			try {
				$this->service()->create( $this->account(), $list );
				$this->fail( 'The list must be refused.' );
			} catch ( Label_Exception $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}

		$this->licensed = false;

		try {
			$this->service()->create( $this->account(), [ '0001-1' ] );
			$this->fail( 'The lapsed licence must stop the request.' );
		} catch ( Label_Exception $e ) {
			$this->assertStringContainsString( 'Лицензия плагина не действует', $e->getMessage() );
		}

		$this->assertSame( [], $this->provider->calls );
	}

	/**
	 * The limit is the provider's: a set the provider takes is accepted.
	 *
	 * @return void
	 */
	public function test_the_largest_set_is_the_providers_limit(): void {
		$this->provider->max = 3;

		$this->service()->create( $this->account(), [ 'a', 'b', 'c' ] );
		$this->assertCount( 1, $this->provider->calls );

		$this->expectException( Label_Exception::class );
		$this->service()->create( $this->account(), [ 'a', 'b', 'c', 'd' ] );
	}

	/**
	 * A refusal the provider words for the merchant surfaces as it is and leaves no task row.
	 *
	 * @return void
	 */
	public function test_a_refusal_of_the_provider_surfaces_and_stores_nothing(): void {
		$this->provider->request_fails = new Label_Exception( 'Этикетка ещё не готова' );

		try {
			$this->service()->create( $this->account(), [ '00000000-0000-1' ] );
			$this->fail( 'The refusal must surface.' );
		} catch ( Label_Exception $e ) {
			$this->assertSame( 'Этикетка ещё не готова', $e->getMessage() );
		}

		$this->assertNull( $this->labels->task( 1 ), 'no task row for a refused request' );
	}

	/**
	 * A marketplace that gives no task id leaves no task row either.
	 *
	 * @return void
	 */
	public function test_an_answer_without_a_task_id_is_a_refusal(): void {
		$this->provider->remote_task_id = '';

		try {
			$this->service()->create( $this->account(), [ '0001-1' ] );
			$this->fail( 'No task id must be a refusal.' );
		} catch ( Label_Exception $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}

		$this->assertNull( $this->labels->task( 1 ) );
	}

	/**
	 * A poll that finds the file stores its body in the documents table, finishes the task and keeps the marketplace's answer.
	 *
	 * @return void
	 */
	public function test_a_completed_poll_stores_the_body_in_the_database(): void {
		$task_id               = $this->task( [ '0001-1', '0002-1' ] );
		$this->provider->answer = $this->file_answer();

		Actions\expectDone( self::READY )->once();
		$this->assertSame( Label_Service::COLLECTED, $this->service()->collect( $this->account(), $task_id ) );

		$this->assertSame( [ [ 'fetch', '777' ] ], $this->provider->calls );

		$document = $this->labels->document( (int) $this->labels->document_of_task( $task_id ) );
		$this->assertSame( self::PDF, $document['body'] );
		$this->assertSame( 'application/pdf', $document['content_type'] );
		$this->assertSame( [ '0001-1', '0002-1' ], $document['external_keys'] );

		$task = $this->labels->task( $task_id );
		$this->assertSame( Async_Task::STATUS_DONE, $task['status'] );
		$this->assertSame( [ 'result' => [ 'status' => 'completed' ] ], $task['result'][ Wpdb_Label_Store::RESULT_ANSWER ] );
	}

	/**
	 * The ready event carries the document, the task and the account.
	 *
	 * @return void
	 */
	public function test_the_ready_event_carries_the_document_the_task_and_the_account(): void {
		$task_id               = $this->task( [ '0001-1' ] );
		$this->provider->answer = $this->file_answer();

		Actions\expectDone( self::READY )->once()->with( 1, $task_id, $this->account_id );
		$this->assertSame( Label_Service::COLLECTED, $this->service()->collect( $this->account(), $task_id ) );
	}

	/**
	 * An answer of a shape the provider does not know is stored as it came, as JSON.
	 *
	 * @return void
	 */
	public function test_an_unknown_shape_is_stored_as_json(): void {
		$task_id = $this->task( [ '0001-1' ] );
		$answer  = [
			'result' => [
				'printed_count' => 1,
				'url'           => 'https://example.test/x.pdf',
			],
		];

		$this->provider->answer = Label_Answer::other( $answer );

		$this->assertSame( Label_Service::COLLECTED, $this->service()->collect( $this->account(), $task_id ) );

		$document = $this->labels->document( (int) $this->labels->document_of_task( $task_id ) );
		$this->assertSame( 'application/json', $document['content_type'] );
		$this->assertSame( $answer, json_decode( $document['body'], true ) );
	}

	/**
	 * Still rendering: pending, the task is `polling`, nothing stored.
	 *
	 * @return void
	 */
	public function test_a_pending_answer_keeps_the_task_polling(): void {
		$task_id = $this->task( [ '0001-1' ] );

		$this->assertSame( Label_Service::PENDING, $this->service()->collect( $this->account(), $task_id ) );
		$this->assertSame( Async_Task::STATUS_POLLING, $this->labels->task( $task_id )['status'] );
		$this->assertNull( $this->labels->document_of_task( $task_id ) );
	}

	/**
	 * After 15 minutes the task expires without another request.
	 *
	 * @return void
	 */
	public function test_a_task_expires_after_fifteen_minutes_without_a_request(): void {
		$task_id    = $this->task( [ '0001-1' ] );
		$this->now += Label_Service::POLL_SECONDS + 1;

		Actions\expectDone( self::FAILED )->once();
		$this->assertSame( Label_Service::ENDED, $this->service()->collect( $this->account(), $task_id ) );
		$this->assertSame( Async_Task::STATUS_EXPIRED, $this->labels->task( $task_id )['status'] );
		$this->assertSame( [], $this->provider->calls );
	}

	/**
	 * A marketplace that does not know the task (a lasting, item-level answer) ends it as failed with its words.
	 *
	 * @return void
	 */
	public function test_a_lost_task_fails_with_the_marketplaces_message(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = Fake_Remote_Exception::of( Remote_Error::FAIL, 'task not found' );

		Actions\expectDone( self::FAILED )->once();
		$this->assertSame( Label_Service::ENDED, $this->service()->collect( $this->account(), $task_id ) );

		$task = $this->labels->task( $task_id );
		$this->assertSame( Async_Task::STATUS_FAILED, $task['status'] );
		$this->assertStringContainsString( 'task not found', $task['result'][ Wpdb_Label_Store::RESULT_MESSAGE ] );
	}

	/**
	 * A sentence the provider words for a poll ends the task with it.
	 *
	 * @return void
	 */
	public function test_a_provider_sentence_ends_the_task(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = new Label_Exception( 'Заказ не собран' );

		$this->assertSame( Label_Service::ENDED, $this->service()->collect( $this->account(), $task_id ) );

		$task = $this->labels->task( $task_id );
		$this->assertSame( Async_Task::STATUS_FAILED, $task['status'] );
		$this->assertSame( 'Заказ не собран', $task['result'][ Wpdb_Label_Store::RESULT_MESSAGE ] );
	}

	/**
	 * An answer that says the marketplace could not make the label ends the task with its text, or with ours when it has none.
	 *
	 * @return void
	 */
	public function test_a_broken_answer_ends_the_task(): void {
		$first                  = $this->task( [ '0001-1' ] );
		$this->provider->answer = Label_Answer::broken( 'нет такого заказа' );

		$this->assertSame( Label_Service::ENDED, $this->service()->collect( $this->account(), $first ) );
		$this->assertSame( 'нет такого заказа', $this->labels->task( $first )['result'][ Wpdb_Label_Store::RESULT_MESSAGE ] );

		$second                 = $this->task( [ '0002-1' ] );
		$this->provider->answer = Label_Answer::broken( '' );

		$this->service()->collect( $this->account(), $second );
		$this->assertNotSame( '', $this->labels->task( $second )['result'][ Wpdb_Label_Store::RESULT_MESSAGE ] );
	}

	/**
	 * A server failure is rethrown for the job to reschedule; the task stays open.
	 *
	 * @return void
	 */
	public function test_a_temporary_failure_is_rethrown_and_the_task_stays_open(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = Fake_Remote_Exception::of( Remote_Error::RETRY, 'bad gateway' );

		try {
			$this->service()->collect( $this->account(), $task_id );
			$this->fail( 'A temporary failure must reach the job.' );
		} catch ( Fake_Remote_Exception $e ) {
			$this->assertSame( Remote_Error::RETRY, $e->error()->consequence() );
		}

		$this->assertSame( Async_Task::STATUS_POLLING, $this->labels->task( $task_id )['status'] );
	}

	/**
	 * A task that is gone and a finished one are `ENDED` without a request.
	 *
	 * @return void
	 */
	public function test_a_finished_or_missing_task_is_ended_without_a_request(): void {
		$task_id = $this->task( [ '0001-1' ] );
		$this->labels->finish_task( $task_id, Async_Task::STATUS_DONE, [] );

		$this->assertSame( Label_Service::ENDED, $this->service()->collect( $this->account(), $task_id ) );
		$this->assertSame( Label_Service::ENDED, $this->service()->collect( $this->account(), 404 ) );
		$this->assertSame( [], $this->provider->calls );
	}

	/**
	 * `abandon` ends an open task visibly and leaves a finished one alone.
	 *
	 * @return void
	 */
	public function test_abandon_ends_an_open_task_once(): void {
		$task_id = $this->task( [ '0001-1' ] );

		Actions\expectDone( self::FAILED )->once()->with( $task_id, $this->account_id, 'сдаёмся' );
		$this->service()->abandon( $task_id, 'сдаёмся' );
		$this->service()->abandon( $task_id, 'ещё раз' );

		$this->assertSame( 'сдаёмся', $this->labels->task( $task_id )['result'][ Wpdb_Label_Store::RESULT_MESSAGE ] );
		$this->assertSame( Async_Task::STATUS_FAILED, $this->labels->task( $task_id )['status'] );
	}

	/**
	 * A poll that returns a file after another poll expired the task publishes nothing and fires no event.
	 *
	 * @return void
	 */
	public function test_a_late_success_after_expiry_publishes_no_document_and_no_event(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = $this->file_answer();
		$this->now             += Label_Service::POLL_SECONDS - 1;

		$this->provider->during_fetch = function () use ( $task_id ): void {
			$this->now                   += 2;
			$this->provider->during_fetch = null;
			$this->service()->collect( $this->account(), $task_id );
		};

		Actions\expectDone( self::READY )->never();
		$this->assertSame( Label_Service::ENDED, $this->service()->collect( $this->account(), $task_id ) );

		$this->assertSame( Async_Task::STATUS_EXPIRED, $this->labels->task( $task_id )['status'] );
		$this->assertNull( $this->labels->document_of_task( $task_id ) );
	}

	/**
	 * Even when the database refuses to DELETE a document, a poll that lost the task publishes none, because the insert and the
	 * end of the task are one transaction (nothing to clean up).
	 *
	 * @return void
	 */
	public function test_a_losing_poll_leaves_no_document_even_when_deletes_are_refused(): void {
		$this->db->define( 'CREATE TRIGGER refuse_delete BEFORE DELETE ON ' . Sqlite_Tables::table( 'documents' ) . " BEGIN SELECT RAISE(FAIL, 'DELETE refused'); END" );

		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = $this->file_answer();
		$this->now             += Label_Service::POLL_SECONDS - 1;

		$this->provider->during_fetch = function () use ( $task_id ): void {
			$this->now                   += 2;
			$this->provider->during_fetch = null;
			$this->service()->collect( $this->account(), $task_id );
		};

		$this->assertSame( Label_Service::ENDED, $this->service()->collect( $this->account(), $task_id ) );

		$this->assertSame( Async_Task::STATUS_EXPIRED, $this->labels->task( $task_id )['status'] );
		$this->assertNull( $this->labels->document_of_task( $task_id ) );
		$this->assertNull( $this->labels->document( 1 ) );
	}

	/**
	 * The two events are named after the plugin, so two marketplace plugins on one site do not hear each other.
	 *
	 * @return void
	 */
	public function test_the_events_are_named_after_the_plugin(): void {
		$this->assertSame( self::READY, $this->service()->ready_hook() );
		$this->assertSame( self::FAILED, $this->service()->failed_hook() );
	}
}
