<?php
/**
 * Sends an account's due stock pairs to the marketplace.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Account_Run;
use Woodev\Framework\Marketplace\Jobs\Backoff;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Jobs\Error_Classifier;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Stock\Stock_Line;
use Woodev\Framework\Marketplace\Stock\Stock_Pusher;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The stock half of `flush_sync`. It runs inside an {@see Account_Run}: the account claim is renewed before every marketplace
 * request — the archive call included — and counted against the run's one budget; every `sync_state` and link write is fenced
 * by the claim and checked by the run. A write the fence refuses stops the run (`claim_lost`), so a sender that was taken over
 * neither archives nor settles anything.
 *
 * Per run: the due pairs (pending ≠ sent, or a send unresolved; not backing off; not failed), one request's worth at a time
 * ({@see Sync_Limits::stock_batch()}). Before anything is sent the pair's warehouse is resolved again ({@see Stock_Warehouse}):
 * a pair for a warehouse that is no longer selected is retired, and its payload moves to the selected one (none = «Не выбран
 * склад», nothing is sent). Right before a request each pair's newest pending payload is frozen as in flight. Per item of the
 * answer ({@see Stock_Rules::verdict()}):
 *
 * | The marketplace says | The pair |
 * |---|---|
 * | accepted | the in-flight revision becomes the sent one; a newer pending revision stays due |
 * | `WAIT` | waits what the answer names (31 s when it names nothing), not counted as a failure |
 * | `NOT_READY` | the card is not created yet: waits 5 min with the text, not counted |
 * | `ARCHIVED` | the link becomes `archived`, the pending value is dropped with the reason |
 * | `FAILED` | backs off by {@see Backoff} with the marketplace's text; the last counted failure makes the pair **failed** (kept, not sent) |
 *
 * A request that fails as a whole with an **uncertain outcome** (timeout, server error) leaves the in-flight snapshot, which
 * keeps the pair due; the next run sends the newest pending payload, even when it equals an older acknowledged one. With one
 * sender per account and absolute writes, the marketplace applies the latest send last.
 *
 * **Archive.** A pending `{stock: 0, archive: true}` ({@see Stock_Intents}) is acknowledged as `{stock: 0}` once the
 * marketplace accepts the zero, so the archive part stays durably pending; the archive call (in this run when the budget
 * allows, else the next) settles it and marks the link `archived`. A pair without a warehouse is archived without the zero. A
 * refused archive is retried, without sending the zero again, and counts as a failure.
 *
 * @since 2.1.0
 */
final class Stock_Flusher {
	public const MAX_REQUESTS   = 10;
	public const BUDGET_SECONDS = 20.0;

	/**
	 * Seconds a throttled pair waits when the marketplace names no wait.
	 */
	public const THROTTLE_SECONDS = 31;

	/**
	 * Seconds a pair waits for its card to be created.
	 */
	public const NOT_READY_SECONDS = 300;

	/**
	 * Code stored for a line the marketplace left out of its answer.
	 */
	public const CODE_NO_ANSWER = 'no_answer';

	/**
	 * Flusher side of `sync_state`.
	 *
	 * @var Sync_State_Queue
	 */
	private $queue;

	/**
	 * Pending side of `sync_state`, for moving a pair to its current warehouse.
	 *
	 * @var Sync_State_Store
	 */
	private $intents;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * The marketplace's stock calls.
	 *
	 * @var Stock_Pusher
	 */
	private $pusher;

	/**
	 * The marketplace's rules of a stock send.
	 *
	 * @var Stock_Rules
	 */
	private $rules;

	/**
	 * Per-request limits of the marketplace.
	 *
	 * @var Sync_Limits
	 */
	private $limits;

	/**
	 * Classifies a failed request.
	 *
	 * @var Error_Classifier
	 */
	private $classifier;

	/**
	 * Account pause, applied when the marketplace refuses the credentials.
	 *
	 * @var Account_Pause
	 */
	private $pause;

	/**
	 * Creates the flusher.
	 *
	 * @since 2.1.0
	 *
	 * @param Sync_State_Queue $queue      Flusher side of `sync_state`.
	 * @param Sync_State_Store $intents    Pending side of `sync_state`.
	 * @param Wpdb_Link_Store  $links      Links.
	 * @param Stock_Pusher     $pusher     The marketplace's stock calls.
	 * @param Stock_Rules      $rules      The marketplace's rules of a stock send.
	 * @param Sync_Limits      $limits     Per-request limits of the marketplace.
	 * @param Error_Classifier $classifier Classifies a failed request.
	 * @param Account_Pause    $pause      Account pause.
	 */
	public function __construct( Sync_State_Queue $queue, Sync_State_Store $intents, Wpdb_Link_Store $links, Stock_Pusher $pusher, Stock_Rules $rules, Sync_Limits $limits, Error_Classifier $classifier, Account_Pause $pause ) {
		$this->queue      = $queue;
		$this->intents    = $intents;
		$this->links      = $links;
		$this->pusher     = $pusher;
		$this->rules      = $rules;
		$this->limits     = $limits;
		$this->classifier = $classifier;
		$this->pause      = $pause;
	}

	/**
	 * Sends what is due, within the run's budget.
	 *
	 * @since 2.1.0
	 *
	 * @param Account_Run $run The account's run under its claim.
	 * @return Stock_Flush_Result `requests` counts every marketplace request of this half, archive calls included.
	 */
	public function flush( Account_Run $run ): Stock_Flush_Result {
		$result = new Stock_Flush_Result();
		$before = $run->requests();

		try {
			$this->flush_due( $run, $result );
		} catch ( Claim_Lost $e ) {
			$result->claim_lost = true;
		}

		$result->requests = $run->requests() - $before;

		return $result;
	}

	/**
	 * Sends the pushes, then the archives that need no push.
	 *
	 * @param Account_Run        $run    The run.
	 * @param Stock_Flush_Result $result Result.
	 * @return void
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function flush_due( Account_Run $run, Stock_Flush_Result $result ): void {
		$batches = $this->batches( $run, $result );

		foreach ( $batches['push'] as $warehouse_id => $pairs ) {
			foreach ( array_chunk( $pairs, $this->limits->stock_batch() ) as $chunk ) {
				if ( ! $run->can_request() || ! $this->send( $run, (int) $warehouse_id, $chunk, $result ) ) {
					return;
				}
			}
		}

		$this->archive( $run, $batches['archive'], $result );
	}

	/**
	 * Reads the due pairs and sorts them: pairs to push, by warehouse, and pairs whose only work left is the archive.
	 *
	 * @param Account_Run        $run    The run.
	 * @param Stock_Flush_Result $result Result.
	 * @return array{push:array<int,array<int,array<string,mixed>>>,archive:array<int,array<string,mixed>>}
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function batches( Account_Run $run, Stock_Flush_Result $result ): array {
		$account = $run->account();
		$push    = [];
		$archive = [];
		$site    = Stock_Mode::is_site( $account );

		foreach ( $this->queue->due( $account->id(), Sync_State_Store::KIND_STOCK, self::MAX_REQUESTS * $this->limits->stock_batch() ) as $row ) {
			$key      = Sync_State_Queue::key( (int) $row['account_id'], (int) $row['link_id'], (int) $row['warehouse_id'], Sync_State_Store::KIND_STOCK );
			$link     = $this->links->get( (int) $row['link_id'] );
			$archives = self::archives( $row['pending_payload'] );
			$refusal  = self::refusal( $link, $site, $archives );

			if ( null !== $refusal ) {
				$run->write( $this->queue->drop( $key, $refusal, $run->token() ) );
				continue;
			}

			if ( null === $link || Link_Record::STATE_CREATED !== $link->card_state() ) {
				// Stock waits until the card is created; the card's creation seeds it again.
				$run->write( $this->queue->defer( $key, self::NOT_READY_SECONDS, '', false, $run->token() ) );
				++$result->waiting;
				continue;
			}

			if ( ! $this->in_current_warehouse( $run, $key, $link, $row, $archives ) ) {
				continue;
			}

			$pair = [
				'key'  => $key,
				'link' => $link,
			];

			// Archive only when the last acknowledged value is a zero and no later send is unresolved: an uncertain nonzero after an
			// old zero needs a new zero first. The snapshot of a refused archive is that zero's own revision, so it does not count
			// as a later send: an archive retry never repeats the push, which would clear the failure count of the refusals.
			if ( $archives && ( 0 === (int) $row['warehouse_id'] || ( self::zero_sent( $row['sent_payload'] ) && self::settled_at_sent( $row ) ) ) ) {
				$archive[] = $pair;
			} else {
				$push[ (int) $row['warehouse_id'] ][] = $pair;
			}
		}

		return [
			'push'    => $push,
			'archive' => $archive,
		];
	}

	/**
	 * Retires a pair whose warehouse is no longer the link's, moving its payload to the current one (an archive without a
	 * warehouse goes to warehouse 0, archive only). Only the selected warehouse is ever sent to. The payload is moved first and
	 * only where the destination holds nothing newer ({@see Sync_State_Store::move_in()}), then the source is retired.
	 *
	 * @param Account_Run         $run      The run.
	 * @param array<string,mixed> $key      Row key.
	 * @param Link_Record         $link     Link.
	 * @param array<string,mixed> $row      The due row.
	 * @param bool                $archives Whether the payload archives the card.
	 * @return bool True when the pair is the current one.
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function in_current_warehouse( Account_Run $run, array $key, Link_Record $link, array $row, bool $archives ): bool {
		$current = Stock_Warehouse::for_link( $run->account(), $link );
		$target  = null === $current && $archives ? 0 : $current;

		if ( $target === (int) $key['warehouse_id'] ) {
			return true;
		}

		if ( null !== $target ) {
			$run->guard();
			$this->intents->move_in( (int) $key['account_id'], (int) $key['link_id'], $target, Sync_State_Store::KIND_STOCK, $row['pending_payload'], (string) $row['pending_since'], (int) $row['pending_rev'] );
		}

		$reason = null === $target
			? __( 'Не выбран склад.', 'woodev-plugin-framework' )
			: __( 'Склад товара изменился.', 'woodev-plugin-framework' );

		$run->write( $this->queue->drop( $key, $reason, $run->token() ) );

		return false;
	}

	/**
	 * Tells why a pair is not sent at all, or null when it may be.
	 *
	 * @param Link_Record|null $link     Link.
	 * @param bool             $site     Whether the account is in `site` mode.
	 * @param bool             $archives Whether the pending payload archives the card.
	 * @return string|null
	 */
	private static function refusal( ?Link_Record $link, bool $site, bool $archives ): ?string {
		if ( null === $link || ! $link->is_offer() ) {
			return __( 'Связь с карточкой маркетплейса удалена.', 'woodev-plugin-framework' );
		}

		if ( Link_Record::STATE_ARCHIVED === $link->card_state() ) {
			return __( 'Карточка в архиве маркетплейса.', 'woodev-plugin-framework' );
		}

		if ( ! $archives && ! $site ) {
			return __( 'Остатки ведёт маркетплейс: магазин их не отправляет.', 'woodev-plugin-framework' );
		}

		if ( ! $archives && ! $link->sync_stock() ) {
			return __( 'Синхронизация остатков товара выключена.', 'woodev-plugin-framework' );
		}

		return null;
	}

	/**
	 * Sends one request and settles its pairs.
	 *
	 * @param Account_Run                    $run          The run.
	 * @param int                            $warehouse_id Warehouse.
	 * @param array<int,array<string,mixed>> $pairs        Pairs, one request's worth.
	 * @param Stock_Flush_Result             $result       Result.
	 * @return bool False when the run must stop.
	 * @throws Claim_Lost When the claim was taken over.
	 * @throws \Throwable What the pusher threw, when it is not a marketplace answer.
	 */
	private function send( Account_Run $run, int $warehouse_id, array $pairs, Stock_Flush_Result $result ): bool {
		$lines  = [];
		$flight = [];

		foreach ( $this->freeze( $run, $pairs ) as $link_id => $pair ) {
			$product = $this->links->by_product( $run->account()->id(), $pair['link']->wc_product_id() );

			if ( null !== $product ) {
				$lines[]            = new Stock_Line( $product, max( 0, (int) ( $pair['payload']['stock'] ?? 0 ) ) );
				$flight[ $link_id ] = $pair;
			}
		}

		if ( [] === $lines ) {
			return true;
		}

		$run->spend_request();

		try {
			$answers = $this->pusher->push( $run->account(), $warehouse_id, $lines );
		} catch ( \Throwable $thrown ) {
			return $this->after_failed_request( $run, $flight, $this->classified( $thrown ), $result );
		}

		$archive = [];

		foreach ( $flight as $link_id => $pair ) {
			if ( $this->settle( $run, $pair, $answers[ $link_id ] ?? null, $result ) && self::archives( $pair['payload'] ) ) {
				$archive[] = $pair;
			}
		}

		// The archive part of these intents stays pending: when the budget is spent the next run archives them without a push.
		$this->archive( $run, $archive, $result );

		return true;
	}

	/**
	 * Freezes the newest pending payload of each pair as in flight.
	 *
	 * @param Account_Run                    $run   The run.
	 * @param array<int,array<string,mixed>> $pairs Pairs with `key` and `link`.
	 * @return array<int,array<string,mixed>> By link id: the pair plus `payload` and `rev`.
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function freeze( Account_Run $run, array $pairs ): array {
		$frozen = [];

		foreach ( $pairs as $pair ) {
			$taken = $this->queue->take( $pair['key'], $run->token() );

			if ( null === $taken ) {
				$run->guard(); // Nothing pending any more, or the claim is gone: only the second stops the run.
				continue;
			}

			$frozen[ (int) $pair['link']->link_id() ] = $pair + $taken;
		}

		return $frozen;
	}

	/**
	 * Settles one pair after the marketplace's per-item answer.
	 *
	 * @param Account_Run         $run    The run.
	 * @param array<string,mixed> $pair   Pair: `key`, `link`, `payload`, `rev`.
	 * @param Item_Result|null    $answer The marketplace's answer for it.
	 * @param Stock_Flush_Result  $result Result.
	 * @return bool True when the marketplace accepted the value.
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function settle( Account_Run $run, array $pair, ?Item_Result $answer, Stock_Flush_Result $result ): bool {
		/**
		 * The pair's link.
		 *
		 * @var Link_Record $link
		 */
		$link    = $pair['link'];
		$link_id = (int) $link->link_id();
		$stock   = (int) ( $pair['payload']['stock'] ?? 0 );

		if ( null !== $answer && $answer->is_ok() ) {
			$sent = self::archives( $pair['payload'] ) ? [ 'stock' => $stock ] : null;
			$run->write( $this->queue->acknowledge( $pair['key'], (int) $pair['rev'], $run->token(), $sent ) );
			$run->write( $this->links->record_stock_outcome( $link_id, $stock, null, null, $run->fence() ) );
			++$result->sent;

			return true;
		}

		$code = null === $answer ? self::CODE_NO_ANSWER : $answer->code();
		$text = null === $answer ? __( 'Маркетплейс не ответил по этому товару.', 'woodev-plugin-framework' ) : $answer->message();
		$wait = null === $answer ? null : $answer->retry_after_seconds();

		switch ( null === $answer ? Stock_Rules::FAILED : $this->rules->verdict( $answer ) ) {
			case Stock_Rules::WAIT:
				$run->write( $this->queue->defer( $pair['key'], max( 1, $wait ?? self::THROTTLE_SECONDS ), '', false, $run->token() ) );
				++$result->waiting;
				break;
			case Stock_Rules::NOT_READY:
				$run->write( $this->queue->defer( $pair['key'], max( 1, $wait ?? self::NOT_READY_SECONDS ), $code . ': ' . $text, false, $run->token() ) );
				++$result->waiting;
				break;
			case Stock_Rules::ARCHIVED:
				$run->write( $this->links->mark_archived( $link_id, $text, $run->fence() ) );
				$run->write( $this->queue->drop( $pair['key'], $code . ': ' . $text, $run->token() ) );
				$result->error( $text );
				break;
			default:
				$this->fail( $run, $pair['key'], $code . ': ' . $text, $result );
				$run->write( $this->links->record_stock_outcome( $link_id, null, $code, $text, $run->fence() ) );
				$result->error( $text );
		}

		return false;
	}

	/**
	 * Applies a refusal of a whole request to its pairs.
	 *
	 * @param Account_Run                    $run    The run.
	 * @param array<int,array<string,mixed>> $flight Pairs in the request.
	 * @param Remote_Error                   $error  Refusal.
	 * @param Stock_Flush_Result             $result Result.
	 * @return bool False when the run must stop.
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function after_failed_request( Account_Run $run, array $flight, Remote_Error $error, Stock_Flush_Result $result ): bool {
		if ( $error->is( Remote_Error::PAUSE_ACCOUNT ) ) {
			$this->pause->apply( $run->account(), $error );
		}

		foreach ( $flight as $pair ) {
			if ( $error->is( Remote_Error::THROTTLE ) ) {
				$run->write( $this->queue->defer( $pair['key'], max( 1, (int) ( $error->retry_after() ?? self::THROTTLE_SECONDS ) ), '', false, $run->token() ) );
			} elseif ( $error->is( Remote_Error::PAUSE_ACCOUNT ) ) {
				$run->write( $this->queue->defer( $pair['key'], Backoff::seconds_after( 1 ), $error->describe(), false, $run->token() ) );
			} else {
				// An uncertain outcome keeps the in-flight snapshot; the next run sends the newest pending payload.
				$this->fail( $run, $pair['key'], $error->describe(), $result );
			}
		}

		if ( $error->is( Remote_Error::THROTTLE ) ) {
			$result->waiting += count( $flight );

			return false;
		}

		$result->error( $error->message() );

		return $error->is( Remote_Error::RETRY ) || $error->is( Remote_Error::FAIL );
	}

	/**
	 * Archives the cards of archive pairs within the budget; a refusal is a counted failure of the pair.
	 *
	 * @param Account_Run                    $run    The run.
	 * @param array<int,array<string,mixed>> $pairs  Pairs with `key` and `link`.
	 * @param Stock_Flush_Result             $result Result.
	 * @return void
	 * @throws Claim_Lost When the claim was taken over.
	 * @throws \Throwable What the archive call threw, when it is not a marketplace answer.
	 */
	private function archive( Account_Run $run, array $pairs, Stock_Flush_Result $result ): void {
		if ( [] === $pairs || ! $run->can_request() ) {
			return; // The archive stays pending in the row; the next run resumes it.
		}

		$pairs = $this->freeze( $run, $pairs );

		if ( [] === $pairs ) {
			return;
		}

		$cards = [];

		foreach ( $pairs as $pair ) {
			$cards[] = $this->links->to_product_link( $pair['link'] );
		}

		$run->spend_request();

		try {
			$archived = $this->rules->archive( $run->account(), $cards );
			$text     = __( 'Маркетплейс не подтвердил отправку карточки в архив.', 'woodev-plugin-framework' );
		} catch ( \Throwable $thrown ) {
			$archived = false;
			$text     = $this->classified( $thrown )->message();
		}

		foreach ( $pairs as $pair ) {
			if ( ! $archived ) {
				$this->fail( $run, $pair['key'], $text, $result );
				$result->error( $text );
				continue;
			}

			$run->write( $this->links->mark_archived( (int) $pair['link']->link_id(), null, $run->fence() ) );
			$run->write( $this->queue->acknowledge( $pair['key'], (int) $pair['rev'], $run->token() ) );
			++$result->archived;
		}
	}

	/**
	 * Counts a failure of a pair: it backs off by {@see Backoff}, and the last failure leaves it failed with the text.
	 *
	 * @param Account_Run         $run    The run.
	 * @param array<string,mixed> $key    Row key.
	 * @param string              $text   Reason.
	 * @param Stock_Flush_Result  $result Result.
	 * @return void
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function fail( Account_Run $run, array $key, string $text, Stock_Flush_Result $result ): void {
		$attempts = (int) ( $this->queue->get( $key )['attempts'] ?? 0 );

		$run->write( $this->queue->defer( $key, Backoff::seconds_after( $attempts + 1 ), $text, true, $run->token() ) );

		if ( $attempts + 1 >= $this->queue->cadence()->max_attempts() ) {
			++$result->failed;
		}
	}

	/**
	 * Asks the adapter what a thrown error means; what is not a marketplace answer goes on up.
	 *
	 * @param \Throwable $thrown What a marketplace call threw.
	 * @return Remote_Error
	 * @throws \Throwable The same error, when the adapter does not know it.
	 */
	private function classified( \Throwable $thrown ): Remote_Error {
		$error = $this->classifier->classify( $thrown );

		if ( null === $error ) {
			throw $thrown;
		}

		return $error;
	}

	/**
	 * Tells whether a payload archives the card.
	 *
	 * @param mixed $payload Payload.
	 * @return bool
	 */
	private static function archives( $payload ): bool {
		return is_array( $payload ) && true === ( $payload[ Stock_Intents::ARCHIVE_FLAG ] ?? false );
	}

	/**
	 * Tells whether the zero of an archive intent was already accepted.
	 *
	 * @param mixed $sent Sent payload.
	 * @return bool
	 */
	private static function zero_sent( $sent ): bool {
		return is_array( $sent ) && ! self::archives( $sent ) && array_key_exists( 'stock', $sent ) && 0 === (int) $sent['stock'];
	}

	/**
	 * Tells whether a row has no send unresolved beyond its accepted revision: nothing is in flight, or the snapshot is the
	 * accepted revision itself (an archive call that was taken and refused).
	 *
	 * @param array<string,mixed> $row Due row.
	 * @return bool
	 */
	private static function settled_at_sent( array $row ): bool {
		return null === $row['in_flight_rev'] || (int) $row['in_flight_rev'] === (int) $row['sent_rev'];
	}
}
