<?php
/**
 * The order writer did not own the registry row.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the order writer when it does not finish the write itself (spec 5.5 step 3):
 *
 * - `busy`: another writer holds the row's claim; the caller retries the refresh later ({@see self::retry_after()});
 * - `lost`: the claim expired and was taken over during the write, or another writer registered the order first. Whatever this
 *   writer created was discarded before it touched WooCommerce; the current holder completes the work, so the caller retries
 *   nothing;
 * - `stale`: the claim was lost **after** this writer had written the WooCommerce order, so the order may now hold this writer's
 *   older view over the holder's newer one. The caller retries like `busy`; the retry re-reads the remote order and repairs it.
 *
 * @since 2.0.29
 */
final class Order_Claim_Exception extends \RuntimeException {
	public const BUSY  = 'busy';
	public const LOST  = 'lost';
	public const STALE = 'stale';

	/**
	 * Seconds after which a busy refresh is tried again (spec 5.5 step 3: «rescheduled in 2 min»).
	 */
	public const RETRY_SECONDS = 120;

	/**
	 * `busy`, `lost` or `stale`.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Creates the exception.
	 *
	 * @param string $reason `busy`, `lost` or `stale`.
	 * @param string $key    Group key.
	 */
	private function __construct( string $reason, string $key ) {
		parent::__construct( self::BUSY === $reason ? 'Another writer holds the order ' . $key . '.' : 'The claim on the order ' . $key . ' was lost (' . $reason . ').' );

		$this->reason = $reason;
	}

	/**
	 * Another writer holds the row.
	 *
	 * @since 2.0.29
	 *
	 * @param string $key Group key.
	 * @return self
	 */
	public static function busy( string $key ): self {
		return new self( self::BUSY, $key );
	}

	/**
	 * The claim was lost during the write.
	 *
	 * @since 2.0.29
	 *
	 * @param string $key Group key.
	 * @return self
	 */
	public static function lost( string $key ): self {
		return new self( self::LOST, $key );
	}

	/**
	 * The claim was lost after the WooCommerce order was written.
	 *
	 * @since 2.0.29
	 *
	 * @param string $key Group key.
	 * @return self
	 */
	public static function stale( string $key ): self {
		return new self( self::STALE, $key );
	}

	/**
	 * Returns why the write did not finish.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Tells whether the caller should try again later.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_busy(): bool {
		return self::BUSY === $this->reason;
	}

	/**
	 * Tells whether the caller must refresh again: the row was busy, or the order was written from a view that lost.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function needs_retry(): bool {
		return self::LOST !== $this->reason;
	}
}
