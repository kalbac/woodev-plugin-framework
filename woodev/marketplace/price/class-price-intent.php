<?php
/**
 * One price a link wants on the marketplace.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

defined( 'ABSPATH' ) || exit;

/**
 * A `sync_state(kind = price)` row taken for sending: the link, the revision of the payload and the payload itself
 * (`{price, old_price (0 = clear), min_price (null = absent), currency_code}`, spec 3.1).
 *
 * @since 2.0.29
 */
final class Price_Intent {
	/**
	 * Link id.
	 *
	 * @var int
	 */
	private $link_id;

	/**
	 * Revision of the payload.
	 *
	 * @var int
	 */
	private $revision;

	/**
	 * Payload.
	 *
	 * @var array<string,string|null>
	 */
	private $payload;

	/**
	 * Failed attempts of this revision so far.
	 *
	 * @var int
	 */
	private $attempts;

	/**
	 * Creates the intent.
	 *
	 * @since 2.0.29
	 *
	 * @param int                       $link_id  Link id.
	 * @param int                       $revision Revision.
	 * @param array<string,string|null> $payload  Payload.
	 * @param int                       $attempts Failed attempts so far.
	 */
	public function __construct( int $link_id, int $revision, array $payload, int $attempts = 0 ) {
		$this->link_id  = $link_id;
		$this->revision = $revision;
		$this->payload  = $payload;
		$this->attempts = $attempts;
	}

	/**
	 * Returns the link id.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function link_id(): int {
		return $this->link_id;
	}

	/**
	 * Returns the revision.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function revision(): int {
		return $this->revision;
	}

	/**
	 * Returns the payload.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,string|null>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * Returns the failed attempts so far.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function attempts(): int {
		return $this->attempts;
	}

	/**
	 * Returns the payload as a price pair; an old price of 0 is none.
	 *
	 * @since 2.0.29
	 *
	 * @return Price_Pair
	 * @throws \InvalidArgumentException When the payload holds no readable price.
	 */
	public function pair(): Price_Pair {
		$currency = (string) ( $this->payload['currency_code'] ?? '' );
		$old      = Money::from_string( (string) ( $this->payload['old_price'] ?? '0' ), $currency );
		$min      = $this->payload['min_price'] ?? null;

		return new Price_Pair(
			Money::from_string( (string) ( $this->payload['price'] ?? '' ), $currency ),
			$old->is_positive() ? $old : null,
			null === $min ? null : Money::from_string( (string) $min, $currency )
		);
	}
}
