<?php
/**
 * One page of pulled orders.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * A page of changed orders and the cursor to resume from. The poller advances its watermark only after the last page
 * of a window was fully processed.
 *
 * @since 2.0.29
 */
final class Pull_Result {
	/**
	 * Orders on this page.
	 *
	 * @var Order_DTO[]
	 */
	private $orders;

	/**
	 * Cursor for the next page, null on the last page.
	 *
	 * @var string|null
	 */
	private $next_cursor;

	/**
	 * Creates a page.
	 *
	 * @since 2.0.29
	 *
	 * @param Order_DTO[] $orders      Orders on this page.
	 * @param string|null $next_cursor Cursor for the next page, null on the last page.
	 */
	public function __construct( array $orders, ?string $next_cursor = null ) {
		$this->orders      = $orders;
		$this->next_cursor = $next_cursor;
	}

	/**
	 * Returns the orders on this page.
	 *
	 * @since 2.0.29
	 *
	 * @return Order_DTO[]
	 */
	public function orders(): array {
		return $this->orders;
	}

	/**
	 * Returns the cursor for the next page, or null on the last page.
	 *
	 * @since 2.0.29
	 *
	 * @return string|null
	 */
	public function next_cursor(): ?string {
		return $this->next_cursor;
	}

	/**
	 * Tells whether another page follows.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function has_more(): bool {
		return null !== $this->next_cursor;
	}
}
