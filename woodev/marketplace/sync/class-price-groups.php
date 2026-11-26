<?php
/**
 * Price groups of one price run.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Shared\Sync_Reason_Text;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Diagnostics\Sync_Reason;
use Woodev\Framework\Marketplace\Jobs\Price_Poll_Runner;
use Woodev\Framework\Marketplace\Price\Flush_Result;
use Woodev\Framework\Marketplace\Price\Price_Grouping;
use Woodev\Framework\Marketplace\Price\Price_Intent;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Price\Price_Refusal;
use Woodev\Framework\Marketplace\Price\Price_Sync_Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the due prices of a marketplace with {@see Price_Grouping} into whole groups, for
 * {@see \Woodev\Framework\Marketplace\Jobs\Price_Flush_Runner}: the links a due price shares its group with are loaded and
 * sent with it. Every queue write is fenced by the account claim.
 *
 * What happens to a group with a due price, in this order:
 *
 * 1. its prices differ and the marketplace keeps one price for it — the group is **held** ({@see Price_Sync_Queue::hold()});
 * 2. one of its prices waits for a price task — the due ones are put off until the task is read again;
 * 3. the marketplace keeps one price for it and already refused this price for one of its links (a refusal, a quarantine, a
 *    second expiry) — the due ones are turned down too, with the same reason, and nothing is sent;
 * 4. one of the prices to send has a deadline or the least time between two sends ahead — the due ones are put off until then;
 * 5. otherwise it is sent. Where the group takes different prices, a price that was refused, or whose sends failed as often
 *    as the price cadence allows, is left out; where it keeps one price, such a failed one goes with the group again.
 *
 * @since 2.1.25
 */
final class Price_Groups {
	/**
	 * The marketplace's grouping.
	 *
	 * @var Price_Grouping
	 */
	private $grouping;

	/**
	 * Price queue.
	 *
	 * @var Price_Sync_Queue
	 */
	private $queue;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Seconds a due price is put off while another price of its group waits for a price task.
	 *
	 * @var int
	 */
	private $task_wait;

	/**
	 * Creates the groups.
	 *
	 * @since 2.1.25
	 *
	 * @param Price_Grouping   $grouping  The marketplace's grouping.
	 * @param Price_Sync_Queue $queue     Price queue.
	 * @param Wpdb_Link_Store  $links     Links.
	 * @param int              $task_wait Seconds between two reads of a price task.
	 */
	public function __construct( Price_Grouping $grouping, Price_Sync_Queue $queue, Wpdb_Link_Store $links, int $task_wait ) {
		$this->grouping  = $grouping;
		$this->queue     = $queue;
		$this->links     = $links;
		$this->task_wait = $task_wait;
	}

	/**
	 * Looks at every held price again and frees those that no longer conflict: the prices of the group agree now, the
	 * marketplace takes different prices now, or the link left the group (price synchronisation off, card detached). A link
	 * that joined a group that still conflicts is held with it. The prices that stay held are counted as failed.
	 *
	 * @since 2.1.25
	 *
	 * @param Account      $account Account.
	 * @param string       $token   Account claim token.
	 * @param Flush_Result $result  Tally.
	 * @return void
	 * @throws \RuntimeException When the database refuses a statement.
	 */
	public function release_resolved( Account $account, string $token, Flush_Result $result ): void {
		$held = $this->queue->held( $account->id() );

		if ( [] === $held ) {
			return;
		}

		$groups      = $this->groups_of( $account, $held );
		$member_ids  = [] === $groups ? [] : array_merge( ...array_values( array_map( 'array_keys', $groups ) ) );
		$states      = $this->queue->states( $account->id(), $member_ids );
		$conflicting = [];

		foreach ( $groups as $members ) {
			$group = array_intersect_key( $states, $members );

			if ( self::differ( $group ) && ! $this->allows_member_prices( $account, array_intersect_key( $members, $group ) ) ) {
				$conflicting = array_merge( $conflicting, array_keys( $group ) );
			}
		}

		$this->queue->hold( $account->id(), array_values( array_diff( $conflicting, $held ) ), self::differs_sentence(), $token );
		$this->queue->unhold( $account->id(), array_values( array_diff( $held, $conflicting ) ), self::turned_down_sentence(), Price_Poll_Runner::resend_sentence(), $token );

		$still_held = count( $this->queue->held( $account->id() ) );

		if ( $still_held > 0 ) {
			$result->add_failed( self::differs_sentence(), $still_held );
		}
	}

	/**
	 * Takes the next request's worth of due prices as whole groups, oldest due price first.
	 *
	 * A group that would take the request over the limit is left for the next one; a group larger than the limit is taken
	 * alone. A group that is not sent leaves no due price behind, so the queue is read again until it gives something to send.
	 *
	 * @since 2.1.25
	 *
	 * @param Account      $account Account.
	 * @param int          $limit   Most prices of one request.
	 * @param string       $token   Account claim token.
	 * @param Flush_Result $result  Tally of the prices that are not sent.
	 * @return array<int,Price_Intent[]> The groups to send.
	 * @throws \RuntimeException When the database refuses a statement.
	 */
	public function next_batch( Account $account, int $limit, string $token, Flush_Result $result ): array {
		do {
			$due     = $this->queue->due( $account->id(), $limit );
			$batch   = [];
			$taken   = 0;
			$changed = 0;

			foreach ( $this->split( $account, $due ) as list( $members, $due_prices ) ) {
				if ( [] === $members ) {
					$send = $due_prices; // A price that stands alone.
				} else {
					list( $send, $rows ) = $this->decide( $account, $members, $due_prices, $token, $result );
					$changed            += $rows;
				}

				if ( [] === $send ) {
					continue;
				}

				if ( $taken > 0 && $taken + count( $send ) > $limit ) {
					break;
				}

				$batch[] = $send;
				$taken  += count( $send );
			}

			// A round that changed nothing (the claim is gone, or nothing is due) would read the same rows again.
		} while ( [] === $batch && $changed > 0 );

		return $batch;
	}

	/**
	 * Decides what a group with a due price sends now, in the order the class describes.
	 *
	 * @param Account                $account    Account.
	 * @param array<int,Link_Record> $members    Members by link id.
	 * @param Price_Intent[]         $due_prices The due prices of the group.
	 * @param string                 $token      Account claim token.
	 * @param Flush_Result           $result     Tally.
	 * @return array{0:Price_Intent[],1:int} The prices to send, none when the group stays; and the rows changed instead.
	 * @throws \RuntimeException When the database refuses a statement.
	 */
	private function decide( Account $account, array $members, array $due_prices, string $token, Flush_Result $result ): array {
		$states    = $this->queue->states( $account->id(), array_keys( $members ) );
		$refused   = array_filter( $states, static fn( array $state ): bool => $state['refused'] );
		$exhausted = array_filter( $states, static fn( array $state ): bool => $state['exhausted'] );
		$differ    = self::differ( $states );

		// The marketplace is asked only when the answer changes something.
		$one_price = ( $differ || [] !== $refused || [] !== $exhausted ) && count( $states ) > 1
			&& ! $this->allows_member_prices( $account, array_intersect_key( $members, $states ) );

		if ( $one_price && $differ ) {
			$rows = $this->queue->hold( $account->id(), array_keys( $states ), self::differs_sentence(), $token );

			if ( $rows > 0 ) {
				$result->add_failed( self::differs_sentence(), $rows );
			}

			return [ [], $rows ];
		}

		if ( in_array( true, array_column( $states, 'awaiting' ), true ) ) {
			return [ [], $this->put_off( $account, $due_prices, $this->task_wait, $token, $result ) ];
		}

		if ( $one_price && [] !== $refused ) {
			return [ [], $this->turn_down( $account, $due_prices, reset( $refused ), $token, $result ) ];
		}

		$send = $one_price ? $states : array_diff_key( $states, $refused, $exhausted );
		$wait = [] === $send ? 0 : max( array_column( $send, 'wait' ) );

		if ( $wait > 0 ) {
			return [ [], $this->put_off( $account, $due_prices, $wait, $token, $result ) ];
		}

		return [ array_values( array_column( $send, 'intent' ) ), 0 ];
	}

	/**
	 * Puts the due prices of a group off.
	 *
	 * @param Account        $account    Account.
	 * @param Price_Intent[] $due_prices Due prices.
	 * @param int            $delay      Seconds to wait.
	 * @param string         $token      Account claim token.
	 * @param Flush_Result   $result     Tally.
	 * @return int Rows changed.
	 */
	private function put_off( Account $account, array $due_prices, int $delay, string $token, Flush_Result $result ): int {
		$rows = 0;

		foreach ( $due_prices as $intent ) {
			$rows += $this->queue->put_off( $account->id(), $intent, max( 1, $delay ), $token ) ? 1 : 0;
		}

		if ( $rows > 0 ) {
			$result->add_waiting( $rows );
		}

		return $rows;
	}

	/**
	 * Turns the due prices of a one-price group down with the reason one of its links already carries for the same price.
	 *
	 * @param Account                           $account    Account.
	 * @param Price_Intent[]                    $due_prices Due prices.
	 * @param array{code:string,message:string} $source     The state of the link whose price was turned down.
	 * @param string                            $token      Account claim token.
	 * @param Flush_Result                      $result     Tally.
	 * @return int Rows changed.
	 */
	private function turn_down( Account $account, array $due_prices, array $source, string $token, Flush_Result $result ): int {
		$code    = '' !== $source['code'] ? $source['code'] : Price_Refusal::REFUSED;
		$message = '' !== $source['message'] ? $source['message'] : self::turned_down_sentence();
		$rows    = 0;

		foreach ( $due_prices as $intent ) {
			$rows += $this->queue->refuse_unsent( $account->id(), $intent, $code, $message, $token ) ? 1 : 0;
		}

		if ( $rows > 0 ) {
			$result->add_failed( $message, $rows );
		}

		return $rows;
	}

	/**
	 * Splits due prices by group, in the order the queue gave them.
	 *
	 * @param Account        $account Account.
	 * @param Price_Intent[] $due     Due prices.
	 * @return array<int,array{0:array<int,Link_Record>,1:Price_Intent[]}> Per group: its members by link id (none for a price
	 *                                                                     that stands alone) and its due prices.
	 */
	private function split( Account $account, array $due ): array {
		$link_ids = [];

		foreach ( $due as $intent ) {
			$link_ids[] = $intent->link_id();
		}

		$group_of = [];

		foreach ( $this->groups_of( $account, $link_ids ) as $key => $members ) {
			foreach ( array_keys( $members ) as $link_id ) {
				$group_of[ $link_id ] = [ $key, $members ];
			}
		}

		$groups = [];

		foreach ( $due as $intent ) {
			list( $key, $members ) = $group_of[ $intent->link_id() ] ?? [ 'alone ' . $intent->link_id(), [] ];

			$groups[ $key ]      = $groups[ $key ] ?? [ $members, [] ];
			$groups[ $key ][1][] = $intent;
		}

		return array_values( $groups );
	}

	/**
	 * Reads the groups some links belong to.
	 *
	 * @param Account $account  Account.
	 * @param int[]   $link_ids Link ids.
	 * @return array<string,array<int,Link_Record>> Members by link id, per group.
	 * @throws \RuntimeException When the groups cannot be read.
	 */
	private function groups_of( Account $account, array $link_ids ): array {
		$name   = $this->grouping->price_group_id_name();
		$groups = [];

		foreach ( $this->links->price_group_members( $account->id(), $name, $link_ids ) as $record ) {
			$groups[ 'group ' . $record->remote_ids()[ $name ] ][ (int) $record->link_id() ] = $record;
		}

		return $groups;
	}

	/**
	 * Asks the marketplace whether the links of a group may carry different prices.
	 *
	 * @param Account                $account Account.
	 * @param array<int,Link_Record> $members Members by link id.
	 * @return bool
	 */
	private function allows_member_prices( Account $account, array $members ): bool {
		return $this->grouping->allows_member_prices( $account, array_values( array_map( [ $this->links, 'to_product_link' ], $members ) ) );
	}

	/**
	 * Tells whether the wanted prices of a group are not all the same. A payload that holds no readable price is not compared:
	 * the sender refuses it by itself.
	 *
	 * @param array<int,array{intent:Price_Intent}> $states States of the group's links.
	 * @return bool
	 */
	private static function differ( array $states ): bool {
		$seen = [];

		foreach ( $states as $state ) {
			try {
				$seen[ self::fingerprint( $state['intent']->pair() ) ] = true;
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return count( $seen ) > 1;
	}

	/**
	 * Writes a price triple as text, so that equal prices give equal text whatever the payload wrote («100» and «100.00»).
	 *
	 * @param Price_Pair $pair Prices.
	 * @return string
	 */
	private static function fingerprint( Price_Pair $pair ): string {
		$parts = [ $pair->price()->currency() ];

		foreach ( [ $pair->price(), $pair->old_price(), $pair->min_price() ] as $money ) {
			$parts[] = null === $money ? '-' : (string) $money->minor();
		}

		return implode( ' ', $parts );
	}

	/**
	 * Returns the sentence of a held price.
	 *
	 * @return string
	 */
	private static function differs_sentence(): string {
		return Sync_Reason_Text::sentence( new Sync_Reason( Sync_Reason::PRICE_GROUP_DIFFERS ) );
	}

	/**
	 * Returns the words of a refused price that carries no text of the marketplace.
	 *
	 * @return string
	 */
	private static function turned_down_sentence(): string {
		return __( 'чтобы отправить её снова, измените цену', 'woodev-plugin-framework' );
	}
}
