<?php
/**
 * Asynchronous price upload with quarantine.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Price\Price_Line;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Price\Price_Pusher;
use Woodev\Framework\Marketplace\Price\Push_Receipt;

/**
 * Wildberries prices: `POST /api/v2/upload/task` takes `nmID`, an integer `price` and an integer `discount` percent and
 * answers with a task id (a second identical upload while the first is queued is HTTP 208 and reuses that task). The
 * result is learned later: a price whose discounted value falls below a third of the current one is put in quarantine
 * and the old remote price stays.
 */
final class Fake_Price_Pusher implements Price_Pusher {
	/**
	 * A discounted price below this share of the current one is quarantined.
	 */
	private const QUARANTINE_DIVISOR = 3;

	/**
	 * Clock.
	 *
	 * @var Fake_Clock
	 */
	private $clock;

	/**
	 * Remote final price in kopecks, by nmID.
	 *
	 * @var array<string,int>
	 */
	private $remote_prices = array();

	/**
	 * Queued tasks by remote task id.
	 *
	 * @var array<string,array{hash:string,items:array<int,array{nm:string,price:int,discount:int}>,task:Async_Task}>
	 */
	private $tasks = array();

	/**
	 * Tasks created so far.
	 *
	 * @var int
	 */
	private $created = 0;

	/**
	 * Creates the pusher.
	 *
	 * @param Fake_Clock $clock Clock.
	 */
	public function __construct( Fake_Clock $clock ) {
		$this->clock = $clock;
	}

	/**
	 * Sets the price Wildberries currently shows for a card.
	 *
	 * @param string $nm_id        nmID.
	 * @param int    $final_kopeck Final price in kopecks.
	 * @return void
	 */
	public function seed_remote_price( string $nm_id, int $final_kopeck ): void {
		$this->remote_prices[ $nm_id ] = $final_kopeck;
	}

	/**
	 * Returns the price Wildberries shows for a card, in kopecks.
	 *
	 * @param string $nm_id nmID.
	 * @return int|null
	 */
	public function remote_price( string $nm_id ): ?int {
		return $this->remote_prices[ $nm_id ] ?? null;
	}

	/**
	 * Returns how many distinct tasks were created.
	 *
	 * @return int
	 */
	public function created_task_count(): int {
		return $this->created;
	}

	/**
	 * Submits prices; the answer is a task, never a result.
	 *
	 * @param Account      $account Account.
	 * @param Price_Line[] $lines   Prices.
	 * @return Push_Receipt
	 */
	public function submit( Account $account, array $lines ): Push_Receipt {
		$items = array();
		foreach ( $lines as $line ) {
			$wb                                      = self::to_wb( $line->pair() );
			$items[ (int) $line->link()->link_id() ] = array(
				'nm'       => (string) $line->link()->remote_id( 'nmID' ),
				'price'    => $wb['price'],
				'discount' => $wb['discount'],
			);
		}

		$hash = md5( (string) json_encode( $items ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
		foreach ( $this->tasks as $remote_id => $queued ) {
			if ( $queued['hash'] === $hash ) {
				return Push_Receipt::duplicate( (string) $remote_id, $queued['task'] );
			}
		}

		++$this->created;
		$remote_id                 = 'price-task-' . $this->created;
		$task                      = new Async_Task( $account->id(), Async_Task::KIND_PRICE, $remote_id, Async_Task::STATUS_POLLING, count( $items ), $this->clock->now() );
		$this->tasks[ $remote_id ] = array(
			'hash'  => $hash,
			'items' => $items,
			'task'  => $task,
		);

		return Push_Receipt::pending( $task );
	}

	/**
	 * Decides on the queued items: accepted, or quarantined with the old price kept.
	 *
	 * @param Account    $account Account.
	 * @param Async_Task $task    Pending task.
	 * @return Item_Result[]
	 */
	public function settle( Account $account, Async_Task $task ): array {
		$remote_id = (string) $task->remote_task_id();
		if ( ! isset( $this->tasks[ $remote_id ] ) ) {
			return array();
		}

		$results = array();
		foreach ( $this->tasks[ $remote_id ]['items'] as $link_id => $item ) {
			$final   = $item['price'] * ( 100 - $item['discount'] );
			$current = $this->remote_prices[ $item['nm'] ] ?? null;
			if ( null !== $current && $final * self::QUARANTINE_DIVISOR < $current ) {
				$results[ $link_id ] = Item_Result::failure( $link_id, 'quarantine', 'Цена слишком сильно снижена и попала в карантин; прежняя цена сохранена.' );
				continue;
			}

			$this->remote_prices[ $item['nm'] ] = $final;
			$results[ $link_id ]                = Item_Result::success( $link_id );
		}

		unset( $this->tasks[ $remote_id ] );

		return $results;
	}

	/**
	 * Maps the price triple to Wildberries' integer price and whole-percent discount.
	 *
	 * @param Price_Pair $pair Calculated prices.
	 * @return array{price:int,discount:int} Price in whole currency units before discount.
	 */
	public static function to_wb( Price_Pair $pair ): array {
		$old = $pair->old_price();
		if ( null === $old ) {
			return array(
				'price'    => intdiv( $pair->price()->round_to( 1 )->minor(), 100 ),
				'discount' => 0,
			);
		}

		$old_minor = $old->minor();
		$discount  = intdiv( ( $old_minor - $pair->price()->minor() ) * 100 + intdiv( $old_minor, 2 ), $old_minor );

		return array(
			'price'    => intdiv( $old->round_to( 1 )->minor(), 100 ),
			'discount' => $discount,
		);
	}
}
