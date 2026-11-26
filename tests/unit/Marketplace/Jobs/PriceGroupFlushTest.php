<?php
/**
 * Price groups in the price half of the account's flush.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Diagnostics\Sync_Reason;
use Woodev\Framework\Marketplace\Jobs\Export_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Price_Poll_Runner;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Price\Flush_Result;
use Woodev\Framework\Marketplace\Price\Price_Refusal;
use Woodev\Framework\Marketplace\Price\Push_Receipt;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Grouped_Price_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Price_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Whole groups reach the pusher; a group the marketplace cannot take is held with its reason until its prices agree; a group
 * waits for a price task of one of its sizes and does not send a price the marketplace turned down.
 */
final class PriceGroupFlushTest extends Sync_Test_Case {
	private const HELD = Sync_Reason::PRICE_GROUP_DIFFERS;

	/**
	 * The marketplace double.
	 *
	 * @var Grouped_Price_Pusher
	 */
	private $prices;

	/**
	 * Builds the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->prices = new Grouped_Price_Pusher();
	}

	/**
	 * Equal prices of a card go out together, each size on its own line.
	 *
	 * @return void
	 */
	public function test_a_card_with_one_price_is_sent_whole(): void {
		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large );

		$this->assertSame( 'Отправлено: 2', $this->flush_now()->message() );
		$this->assertSame( [ [ 'coat|S', 'coat|L' ] ], $this->sent_articles() );
		$this->assertSame( [], $this->prices->asked, 'equal prices need no permission' );
	}

	/**
	 * When one size becomes due, the sizes the marketplace already holds go with it.
	 *
	 * @return void
	 */
	public function test_a_due_size_takes_its_whole_group_with_it(): void {
		$this->prices->size_prices = [ 1 ];

		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large );
		$this->flush_now();

		$this->now += 400;
		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->flush_now();

		$this->assertSame( [ 'coat|S', 'coat|L' ], $this->sent_articles()[1] );
		$this->assertSame( [ [ 'coat|S', 'coat|L' ] ], $this->prices->asked, 'the whole group is judged' );
		$this->assertSame( '120.00', $this->price_row( $large )['sent_payload']['price'] );
		$this->assertNull( $this->price_row( $small )['error_code'] );
	}

	/**
	 * Different prices on a card that keeps one price are not sent, and every size says why.
	 *
	 * @return void
	 */
	public function test_differing_prices_of_a_single_price_card_are_not_sent(): void {
		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large, [ 'price' => '120.00' ] );

		$result = $this->flush_now();

		$this->assertSame( [], $this->prices->inner->requests );
		$this->assertSame( 2, $result->failed() );
		$this->assertStringContainsString( 'у размеров разные цены, маркетплейс принимает одну', $result->error() );
		$this->assertSame( self::HELD, $this->price_row( $small )['error_code'] );
		$this->assertSame( self::HELD, $this->price_row( $large )['error_code'] );
		$this->assertStringContainsString( 'у размеров разные цены', $this->price_row( $large )['last_error'] );
		$this->assertSame( 0, (int) $this->price_row( $large )['attempts'], 'nothing was tried' );

		$this->now += 4000;
		$this->assertSame( 2, $this->flush_now()->failed(), 'a held group is still reported, and still not sent' );
		$this->assertSame( [], $this->prices->inner->requests );
	}

	/**
	 * A difference in the old price alone is a different price too; «100» and «100.00» are not.
	 *
	 * @return void
	 */
	public function test_the_whole_price_triple_is_compared_by_value(): void {
		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small, [ 'price' => '100' ] );
		$this->want_price( $large, [ 'price' => '100.00' ] );
		$this->flush_now();

		$this->assertCount( 1, $this->prices->inner->requests );

		$this->now += 400;
		$this->want_price( $large, [ 'old_price' => '150.00' ] );
		$this->flush_now();

		$this->assertCount( 1, $this->prices->inner->requests );
		$this->assertSame( self::HELD, $this->price_row( $small )['error_code'], 'the size the marketplace already holds says why too' );
	}

	/**
	 * Once the prices agree, the held group is released and goes out.
	 *
	 * @return void
	 */
	public function test_a_held_group_is_sent_once_its_prices_agree(): void {
		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->flush_now();

		$this->want_price( $small, [ 'price' => '120.00' ] );
		$result = $this->flush_now();

		$this->assertSame( 'Отправлено: 2', $result->message() );
		$this->assertNull( $this->price_row( $small )['error_code'] );
		$this->assertNull( $this->price_row( $large )['error_code'] );
		$this->assertNull( $this->price_row( $large )['last_error'] );
		$this->assertSame( '120.00', $this->price_row( $large )['sent_payload']['price'] );
	}

	/**
	 * A price taken back to what the marketplace holds releases the group with nothing to send.
	 *
	 * @return void
	 */
	public function test_a_price_taken_back_releases_the_group(): void {
		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large );
		$this->flush_now();

		$this->now += 400;
		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->flush_now();

		$this->want_price( $large );
		$result = $this->flush_now();

		$this->assertSame( 'Нечего отправлять (значения совпадают)', $result->message() );
		$this->assertNull( $this->price_row( $small )['error_code'] );
		$this->assertNull( $this->price_row( $large )['error_code'] );
		$this->assertNull( $this->price_row( $large )['last_error'] );
		$this->assertCount( 1, $this->prices->inner->requests );
	}

	/**
	 * A marketplace that starts taking size prices gets the held group.
	 *
	 * @return void
	 */
	public function test_a_card_that_starts_taking_size_prices_is_released(): void {
		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->flush_now();

		$this->prices->size_prices = [ 1 ];

		$this->assertSame( 'Отправлено: 2', $this->flush_now()->message() );
	}

	/**
	 * A size whose price is not synchronised leaves the group: it is neither compared nor sent, and the rest is released.
	 *
	 * @return void
	 */
	public function test_a_size_without_price_sync_leaves_the_group(): void {
		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->flush_now();

		$this->links()->set_sync_flag( $this->account_id, [ $this->link_id( $large ) ], 'sync_price', false );
		$result = $this->flush_now();

		$this->assertSame( 'Отправлено: 1', $result->message() );
		$this->assertSame( [ [ 'coat|S' ] ], $this->sent_articles() );
		$this->assertNull( $this->price_row( $large )['error_code'] );
	}

	/**
	 * A held card does not stop the other cards, even when it fills the first request.
	 *
	 * @return void
	 */
	public function test_a_held_group_does_not_stop_the_others(): void {
		$this->price_batch = 2;

		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large, [ 'price' => '120.00' ] );
		++$this->now;
		$this->want_price( $this->size( 'hat|0', 2 ) );

		$result = $this->flush_now();

		$this->assertSame( [ [ 'hat|0' ] ], $this->sent_articles() );
		$this->assertSame( 1, $result->sent() );
		$this->assertSame( 2, $result->failed() );
	}

	/**
	 * A group is never split between requests, and a group larger than a request goes whole.
	 *
	 * @return void
	 */
	public function test_a_group_is_never_split_between_requests(): void {
		$this->price_batch = 2;

		foreach ( [ 'coat|S' => 1, 'coat|M' => 1, 'coat|L' => 1, 'cap|0' => 2, 'hat|S' => 3, 'hat|L' => 3 ] as $offer => $card ) {
			$this->want_price( $this->size( $offer, $card ) );
			++$this->now;
		}

		$this->assertSame( 'Отправлено: 6', $this->flush_now()->message() );
		$this->assertSame( [ [ 'coat|S', 'coat|M', 'coat|L' ], [ 'cap|0' ], [ 'hat|S', 'hat|L' ] ], $this->sent_articles() );
	}

	/**
	 * While one size has to wait, its whole group waits; the other cards go out.
	 *
	 * @return void
	 */
	public function test_a_group_waits_for_its_slowest_size(): void {
		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large );
		$this->flush_now();

		$this->prices->size_prices = [ 1 ];

		$this->now += 100; // Less than the least time between two sends of one product.
		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->want_price( $this->size( 'hat|0', 2 ) );
		$this->flush_now();

		$this->assertSame( [ 'hat|0' ], $this->sent_articles()[1] );

		$this->now += 261; // The interval since the first send has passed.
		$this->flush_now();

		$this->assertSame( [ 'coat|S', 'coat|L' ], $this->sent_articles()[2] );
	}

	/**
	 * A refusal backs off the whole group, and the retry carries the whole group again.
	 *
	 * @return void
	 */
	public function test_a_refused_group_is_retried_whole(): void {
		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large );

		$this->prices->inner->script[] = function () use ( $small, $large ): array {
			return $this->refused( $small, 'fake_refused' ) + $this->refused( $large, 'fake_refused' );
		};
		$this->flush_now();
		$this->flush_now();

		$this->assertCount( 1, $this->prices->inner->requests );

		$this->now += 4000;
		$this->flush_now();

		$this->assertSame( [ 'coat|S', 'coat|L' ], $this->sent_articles()[1] );
	}

	/**
	 * Links without the card identifier, and sizes of different cards, are judged each on their own.
	 *
	 * @return void
	 */
	public function test_links_outside_a_group_stand_alone(): void {
		$this->want_price( $this->size( 'a', null ) );
		$this->want_price( $this->size( 'b', null ), [ 'price' => '120.00' ] );
		$this->want_price( $this->size( 'c', 1 ), [ 'price' => '130.00' ] );
		$this->want_price( $this->size( 'd', 2 ), [ 'price' => '140.00' ] );

		$this->assertSame( 'Отправлено: 4', $this->flush_now()->message() );
		$this->assertSame( [], $this->prices->asked );
	}

	/**
	 * A pusher without price groups sends every link by itself, whatever identifiers the links share.
	 *
	 * @return void
	 */
	public function test_a_pusher_without_groups_keeps_one_price_per_link(): void {
		$plain = new Scripted_Price_Pusher();
		$this->want_price( $this->size( 'coat|S', 1 ) );
		$this->want_price( $this->size( 'coat|L', 1 ), [ 'price' => '120.00' ] );

		$result = new Flush_Result();
		$token  = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$this->price_runner( $plain )->flush_under_claim( $this->account_id, $token, 10, null, $result );

		$this->assertSame( 'Отправлено: 2', $result->message() );
		$this->assertCount( 1, $plain->requests );
	}

	/**
	 * While one size waits for a price task, a newer price of its group is not sent; after the task decides, the group goes
	 * whole.
	 *
	 * @return void
	 */
	public function test_a_group_waits_while_one_of_its_sizes_waits_for_a_price_task(): void {
		$this->prices->size_prices = [ 1 ];

		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->flush_pending();

		$this->now += 400;
		$this->want_price( $large, [ 'price' => '120.00' ] );
		$result = $this->flush_now();

		$this->assertCount( 1, $this->prices->inner->requests, 'only the upload the task is about' );
		$this->assertSame( 1, $result->waiting() );

		$this->prices->decisions = [ Item_Result::success( $this->link_id( $small ) ) ];
		$this->poll_price_task();
		$this->now += Price_Poll_Runner::POLL_INTERVAL + 1;
		$this->flush_now();

		$this->assertCount( 1, $this->prices->inner->requests, 'the accepted size still has its least time between two sends' );

		$this->now += 400;
		$this->flush_now();

		$this->assertSame( [ 'coat|S', 'coat|L' ], $this->sent_articles()[1] );
	}

	/**
	 * A conflict with a waiting size holds the others at once, and the waiting one once its task has decided.
	 *
	 * @return void
	 */
	public function test_a_conflict_with_a_waiting_size_holds_it_after_its_task(): void {
		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->flush_pending();

		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->flush_now();

		$this->assertSame( self::HELD, $this->price_row( $large )['error_code'] );
		$this->assertNull( $this->price_row( $small )['error_code'], 'what waits for the marketplace is not touched' );
		$this->assertNotNull( $this->price_row( $small )['task_id'] );

		$this->prices->decisions = [ Item_Result::success( $this->link_id( $small ) ) ];
		$this->poll_price_task();
		$this->flush_now();

		$this->assertSame( self::HELD, $this->price_row( $small )['error_code'] );
		$this->assertCount( 1, $this->prices->inner->requests );
	}

	/**
	 * A price quarantined for a one-price card is not sent for a size that comes later: it is turned down with the same
	 * reason. A new price goes out for the whole card.
	 *
	 * @return void
	 */
	public function test_a_quarantined_price_of_a_one_price_card_is_not_sent_for_another_size(): void {
		$small = $this->size( 'coat|S', 1 );
		$this->want_price( $small );
		$this->flush_pending();
		$this->prices->decisions = [ Item_Result::failure( $this->link_id( $small ), Price_Refusal::QUARANTINED, 'quarantine' ) ];
		$this->poll_price_task();
		$reason = $this->price_row( $small )['last_error'];

		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $large );
		$result = $this->flush_now();

		$this->assertCount( 1, $this->prices->inner->requests );
		$this->assertSame( 1, $result->failed() );
		$this->assertSame( Price_Refusal::QUARANTINED, $this->price_row( $large )['error_code'] );
		$this->assertSame( $reason, $this->price_row( $large )['last_error'] );
		$this->assertSame( $this->price_row( $large )['pending_payload'], json_decode( $this->raw_price_row( $large )['refused_payload'], true ) );

		$this->now += 4000;
		$this->assertSame( 'Нечего отправлять (значения совпадают)', $this->flush_now()->message() );

		$this->want_price( $small, [ 'price' => '110.00' ] );
		$this->want_price( $large, [ 'price' => '110.00' ] );
		$this->flush_now();

		$this->assertSame( [ 'coat|S', 'coat|L' ], $this->sent_articles()[1] );
		$this->assertNull( $this->price_row( $large )['error_code'] );
	}

	/**
	 * Where sizes may differ, a refused size is left out and the others go.
	 *
	 * @return void
	 */
	public function test_a_refused_size_is_left_out_where_sizes_may_differ(): void {
		$this->prices->size_prices = [ 1 ];

		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large );
		$this->flush_pending();
		$this->prices->decisions = [
			Item_Result::failure( $this->link_id( $small ), 'price_error', 'too low' ),
			Item_Result::success( $this->link_id( $large ) ),
		];
		$this->poll_price_task();

		$this->now += 400;
		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->flush_now();

		$this->assertSame( [ 'coat|L' ], $this->sent_articles()[1] );
		$this->assertSame( Price_Refusal::REFUSED, $this->price_row( $small )['error_code'] );
		$this->assertSame( 'too low', $this->price_row( $small )['last_error'] );
	}

	/**
	 * A conflict does not wipe a refusal from the size that still wants the refused price; the size that was held and goes back
	 * to its refused price says it is refused again, and nothing is sent.
	 *
	 * @return void
	 */
	public function test_a_refusal_survives_a_conflict_and_a_price_taken_back(): void {
		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large );
		$this->flush_pending();
		$this->prices->decisions = [
			Item_Result::failure( $this->link_id( $small ), 'price_error', 'too low' ),
			Item_Result::failure( $this->link_id( $large ), 'price_error', 'too low' ),
		];
		$this->poll_price_task();

		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->flush_now();

		$this->assertSame( Price_Refusal::REFUSED, $this->price_row( $small )['error_code'] );
		$this->assertSame( self::HELD, $this->price_row( $large )['error_code'] );

		$this->want_price( $large );
		$this->flush_now();

		$this->assertSame( Price_Refusal::REFUSED, $this->price_row( $large )['error_code'] );
		$this->assertSame( 'чтобы отправить её снова, измените цену', $this->price_row( $large )['last_error'] );
		$this->assertCount( 1, $this->prices->inner->requests );
	}

	/**
	 * A size whose sends failed as often as allowed turns no other size down: a one-price card is sent whole again when
	 * another size becomes due, and where sizes may differ the failed one is left out.
	 *
	 * @dataProvider cards
	 *
	 * @param bool     $size_prices Whether the card takes a price per size.
	 * @param string[] $expected    Articles of the request the new size triggers.
	 * @return void
	 */
	public function test_a_size_that_kept_failing_turns_no_other_size_down( bool $size_prices, array $expected ): void {
		$this->prices->size_prices = $size_prices ? [ 1 ] : [];

		$small = $this->size( 'coat|S', 1 );
		$this->want_price( $small );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$this->prices->inner->script[] = function () use ( $small ): array {
				return $this->refused( $small, 'fake_refused' );
			};
			$this->flush_now();
			$this->now += 90000;
		}

		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $large );
		$this->flush_now();

		$this->assertCount( 6, $this->prices->inner->requests );
		$this->assertSame( $expected, $this->sent_articles()[5] );
		$this->assertSame( '100.00', $this->price_row( $large )['sent_payload']['price'] );
	}

	/**
	 * Both kinds of card.
	 *
	 * @return array<string,array{0:bool,1:string[]}>
	 */
	public function cards(): array {
		return [
			'one price'   => [ false, [ 'coat|S', 'coat|L' ] ],
			'size prices' => [ true, [ 'coat|L' ] ],
		];
	}

	/**
	 * A group whose members cannot be read is not «links that stand alone»: nothing is sent. The same holds when only the
	 * identifiers of the members cannot be read, and for the look at the held groups.
	 *
	 * @dataProvider group_reads
	 *
	 * @param string $read A fragment of the lost read.
	 * @return void
	 */
	public function test_nothing_is_sent_when_the_group_cannot_be_read( string $read ): void {
		$this->price_batch = 1;

		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->lose_one_read( $read );

		try {
			$this->flush_now();
			$this->fail( 'a lost read of the group must stop the sender' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'price groups could not be read', $e->getMessage() );
		}

		$this->assertNull( $this->db->fail_read, 'the read was lost' );
		$this->assertSame( [], $this->prices->inner->requests );

		$this->flush_now(); // The read works again: the group is held as a whole.
		$this->lose_one_read( $read );

		try {
			$this->flush_now();
			$this->fail( 'a lost read of a held group must stop the sender' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'price groups could not be read', $e->getMessage() );
		}

		$this->assertSame( [], $this->prices->inner->requests );
		$this->assertSame( self::HELD, $this->price_row( $small )['error_code'], 'what was held stays held' );
		$this->assertSame( self::HELD, $this->price_row( $large )['error_code'] );
	}

	/**
	 * The reads that form a group.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function group_reads(): array {
		return [
			'members'     => [ 'AS asked INNER JOIN' ],
			'identifiers' => [ 'SELECT link_id, id_name, id_value, id_type' ],
		];
	}

	/**
	 * An upload that expired undecided may have taken effect: a conflict keeps that in mind, and the group that agrees again
	 * on the old price sends it, although the shop holds it as accepted.
	 *
	 * @return void
	 */
	public function test_a_conflict_keeps_an_expired_upload_and_the_old_price_is_sent_again(): void {
		$small = $this->size( 'coat|S', 1 );
		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $small );
		$this->want_price( $large );
		$this->flush_now();

		$this->now += 400;
		$this->want_price( $small, [ 'price' => '120.00' ] );
		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->flush_pending();

		$this->want_price( $large ); // Back to the accepted price while the upload is still open.
		$this->expire_price_task();

		$this->assertSame( 2, $this->flush_now()->failed() );
		$this->assertSame( self::HELD, $this->price_row( $small )['error_code'] );
		$this->assertSame( self::HELD, $this->price_row( $large )['error_code'], 'the size that expired says why it waits' );
		$this->assertSame( '120.00', $this->price_row( $large )['in_flight_payload']['price'] );

		$this->now += 4000;
		$this->assertSame( 2, $this->flush_now()->failed(), 'looking again keeps what is known about the upload' );
		$this->assertCount( 2, $this->prices->inner->requests );

		$this->want_price( $small );
		$result = $this->flush_now();

		$this->assertSame( 'Отправлено: 2', $result->message() );
		$this->assertSame( [ 'coat|S', 'coat|L' ], $this->sent_articles()[2] );
		$this->assertSame( '100.00', $this->price_row( $small )['sent_payload']['price'] );
		$this->assertNull( $this->price_row( $small )['error_code'] );
		$this->assertNull( $this->price_row( $large )['error_code'] );
		$this->assertNull( $this->price_row( $large )['in_flight_payload'] );
	}

	/**
	 * A price that expired once and expires again stays unsent, also when its group conflicted in between.
	 *
	 * @return void
	 */
	public function test_a_conflict_does_not_restart_the_count_of_expiries(): void {
		$small = $this->size( 'coat|S', 1 );
		$this->want_price( $small );
		$this->flush_pending();
		$this->expire_price_task();

		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->flush_now();

		$this->assertSame( self::HELD, $this->price_row( $small )['error_code'] );

		$this->want_price( $large );
		$this->prices->inner->script[] = $this->pending_answer( 'upload-2' );
		$this->flush_now();

		$this->assertSame( [ 'coat|S', 'coat|L' ], $this->sent_articles()[1] );
		$this->assertSame( Price_Refusal::EXPIRED, $this->price_row( $small )['error_code'] );

		$this->expire_price_task( 2 );

		$this->assertSame( Price_Refusal::EXPIRED, $this->price_row( $small )['error_code'] );
		$this->assertStringContainsString( 'измените цену', $this->price_row( $small )['last_error'] );

		$this->now += 4000;
		$this->flush_now();

		$this->assertCount( 2, $this->prices->inner->requests, 'the same price is not uploaded a third time' );
	}

	/**
	 * A size that leaves a conflict with its price unchanged says again that its upload expired.
	 *
	 * @return void
	 */
	public function test_a_size_that_leaves_a_conflict_says_its_upload_expired(): void {
		$small = $this->size( 'coat|S', 1 );
		$this->want_price( $small );
		$this->flush_pending();
		$this->expire_price_task();
		$expired = $this->price_row( $small )['last_error'];

		$large = $this->size( 'coat|L', 1 );
		$this->want_price( $large, [ 'price' => '120.00' ] );
		$this->flush_now();

		$this->links()->set_sync_flag( $this->account_id, [ $this->link_id( $large ) ], 'sync_price', false );
		$this->release_without_sending();

		$this->assertSame( Price_Refusal::EXPIRED, $this->price_row( $small )['error_code'] );
		$this->assertSame( $expired, $this->price_row( $small )['last_error'] );
		$this->assertStringContainsString( 'будет отправлена ещё раз', $expired );
	}

	/**
	 * A held price that is taken into a request carries its expiry with it, not the reason of the conflict.
	 *
	 * @return void
	 */
	public function test_a_held_price_that_is_begun_keeps_its_expiry(): void {
		$small = $this->size( 'coat|S', 1 );
		$this->want_price( $small );
		$this->flush_pending();
		$this->expire_price_task();

		$token  = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$intent = $this->price_queue()->due( $this->account_id, 10 )[0];
		$this->price_queue()->hold( $this->account_id, [ $intent->link_id() ], 'held', $token );

		$this->assertTrue( $this->price_queue()->begin( $this->account_id, $intent, $token ) );
		$this->assertSame( Price_Refusal::EXPIRED, $this->price_row( $small )['error_code'] );
		$this->assertNull( $this->price_row( $small )['last_error'] );
	}

	/**
	 * Creates a size: a product with a created card, price sync on and, unless null, the identifier of its card.
	 *
	 * @param string   $offer Article.
	 * @param int|null $card  The marketplace's card id.
	 * @return int Product id.
	 */
	private function size( string $offer, ?int $card ): int {
		static $item = 9000;

		$id = $this->product( 10, [] );

		$this->links()->save(
			new Product_Link(
				$this->account_id,
				$id,
				0,
				$offer,
				null === $card ? [ 'item' => ++$item ] : [ 'item' => ++$item, 'card' => $card ],
				Product_Link::STATE_CREATED,
				[ 'sync_price' => true ]
			)
		);

		return $id;
	}

	/**
	 * Lists the articles of every request sent.
	 *
	 * @return array<int,string[]>
	 */
	private function sent_articles(): array {
		return array_map( 'array_keys', $this->prices->inner->requests );
	}

	/**
	 * Runs a flush whose request the marketplace takes for processing: every line waits for one price task.
	 *
	 * @return void
	 */
	private function flush_pending(): void {
		$this->prices->inner->script[] = $this->pending_answer( 'upload-1' );

		$this->flush_now();
	}

	/**
	 * Scripts the answer of a request the marketplace takes for processing.
	 *
	 * @param string $upload The marketplace's id of the upload.
	 * @return \Closure
	 */
	private function pending_answer( string $upload ): \Closure {
		return function ( array $lines ) use ( $upload ): Push_Receipt {
			return Push_Receipt::pending( new Async_Task( $this->account_id, Async_Task::KIND_PRICE, $upload, Async_Task::STATUS_POLLING, count( $lines ) ) );
		};
	}

	/**
	 * Lets a price task pass its deadline undecided.
	 *
	 * @param int $task_id Id of the task.
	 * @return void
	 */
	private function expire_price_task( int $task_id = 1 ): void {
		$this->prices->decisions = [];
		$this->now              += Price_Poll_Runner::TASK_DEADLINE + 1;
		$this->poll_price_task( $task_id );
	}

	/**
	 * Runs a flush with no request left: the held prices are looked at again and nothing is sent.
	 *
	 * @return void
	 */
	private function release_without_sending(): void {
		$token = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$this->price_runner( $this->prices )->flush_under_claim( $this->account_id, $token, 0 );
		$this->accounts()->release_claim( $this->account_id, $token );
	}

	/**
	 * Loses the next read whose text holds a fragment.
	 *
	 * @param string $fragment Part of the statement.
	 * @return void
	 */
	private function lose_one_read( string $fragment ): void {
		$this->db->fail_read = function ( string $query ) use ( $fragment ): bool {
			if ( false === strpos( $query, $fragment ) ) {
				return false;
			}

			$this->db->fail_read = null;

			return true;
		};
	}

	/**
	 * Reads a price task and settles what the pusher has decided.
	 *
	 * @param int $task_id Id of the task.
	 * @return void
	 */
	private function poll_price_task( int $task_id = 1 ): void {
		$runner = new Price_Poll_Runner(
			$this->job_gate(),
			$this->task_ledger(),
			$this->price_queue(),
			$this->prices,
			new Export_Scheduler( $this->names() ),
			$this->classifier,
			$this->refusal_writer(),
			new Api_Error_Ring( $this->db, Sqlite_Tables::table( 'api_errors' ), $this->clock() ),
			[ $this, 'log_line' ],
			$this->clock()
		);

		$runner->run( $task_id, 0 );
	}

	/**
	 * Reads the stored price row of a product's link, columns as they are.
	 *
	 * @param int $product_id Product id.
	 * @return array<string,mixed>
	 */
	private function raw_price_row( int $product_id ): array {
		return $this->db->get_row( 'SELECT * FROM ' . Sqlite_Tables::table( 'sync_state' ) . " WHERE kind = 'price' AND link_id = " . $this->link_id( $product_id ), 'ARRAY_A' );
	}

	/**
	 * Plays the outer sender of `flush_sync`.
	 *
	 * @return Flush_Result
	 */
	private function flush_now(): Flush_Result {
		$result = new Flush_Result();
		$token  = $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		$this->assertNotNull( $token, 'the outer sender must get the account claim' );

		try {
			$this->price_runner( $this->prices )->flush_under_claim( $this->account_id, (string) $token, 10, null, $result );
		} finally {
			$this->accounts()->release_claim( $this->account_id, (string) $token );
		}

		return $result;
	}
}
