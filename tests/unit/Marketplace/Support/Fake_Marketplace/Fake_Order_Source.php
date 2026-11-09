<?php
/**
 * Poll-only order source.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Source;
use Woodev\Framework\Marketplace\Orders\Pull_Result;

/**
 * Wildberries has no push, so orders only arrive by polling. Pages hold two orders to make cursor handling visible.
 */
final class Fake_Order_Source implements Order_Source {
	private const PAGE_SIZE = 2;

	/**
	 * Latest snapshot of each order, by external id.
	 *
	 * @var array<string,Order_DTO>
	 */
	private $orders = array();

	/**
	 * Adds or replaces an order as the marketplace now shows it.
	 *
	 * @param Order_DTO $order Order snapshot.
	 * @return void
	 */
	public function publish( Order_DTO $order ): void {
		$this->orders[ $order->external_id() ] = $order;
	}

	/**
	 * Lists orders changed since a moment, two per page.
	 *
	 * @param Account            $account Account.
	 * @param \DateTimeImmutable $since   Start of the window.
	 * @param string|null        $cursor  Offset of the page, null for the first.
	 * @return Pull_Result
	 */
	public function pull_changed( Account $account, \DateTimeImmutable $since, ?string $cursor ): Pull_Result {
		$changed = array();
		foreach ( $this->orders as $order ) {
			if ( $order->account_id() === $account->id() && self::changed_since( $order, $since ) ) {
				$changed[] = $order;
			}
		}

		$offset = null === $cursor ? 0 : (int) $cursor;
		$page   = array_slice( $changed, $offset, self::PAGE_SIZE );
		$next   = $offset + self::PAGE_SIZE < count( $changed ) ? (string) ( $offset + self::PAGE_SIZE ) : null;

		return new Pull_Result( $page, $next );
	}

	/**
	 * Tells whether an order changed at or after a moment, comparing instants (offsets, `Z` and fractions all count).
	 *
	 * An order without a change time cannot be placed in the window, so it is left out.
	 *
	 * @param Order_DTO          $order Order snapshot.
	 * @param \DateTimeImmutable $since Start of the window.
	 * @return bool
	 */
	private static function changed_since( Order_DTO $order, \DateTimeImmutable $since ): bool {
		$changed_at = $order->updated_at_instant();

		return null !== $changed_at && $changed_at >= $since;
	}

	/**
	 * Fetches one order.
	 *
	 * @param Account $account     Account.
	 * @param string  $external_id Order id.
	 * @return Order_DTO|null
	 */
	public function pull_one( Account $account, string $external_id ): ?Order_DTO {
		$order = $this->orders[ $external_id ] ?? null;

		return null !== $order && $order->account_id() === $account->id() ? $order : null;
	}
}
