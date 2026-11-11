<?php
/**
 * What one stock flush did.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Counts of one run and the merchant line: «Отправлено: 12», «Нечего отправлять (значения совпадают)» or «Ошибка: …» — never
 * «готово» whatever happened.
 *
 * @since 2.0.30
 */
final class Stock_Flush_Result {
	/**
	 * Pairs the marketplace accepted.
	 *
	 * @var int
	 */
	public $sent = 0;

	/**
	 * Requests the stock half sent, archive calls included.
	 *
	 * @var int
	 */
	public $requests = 0;

	/**
	 * Requests the price half of the same `flush_sync` run sent ({@see \Woodev\Framework\Marketplace\Jobs\Flush_Sync_Runner}).
	 *
	 * @var int
	 */
	public $price_requests = 0;

	/**
	 * Cards archived.
	 *
	 * @var int
	 */
	public $archived = 0;

	/**
	 * Pairs that wait (rate limit, card not created yet, backoff after an error).
	 *
	 * @var int
	 */
	public $waiting = 0;

	/**
	 * Pairs that reached their last counted failure in this run: kept with their text, no longer sent.
	 *
	 * @var int
	 */
	public $failed = 0;

	/**
	 * Error texts, the marketplace's verbatim.
	 *
	 * @var string[]
	 */
	public $errors = [];

	/**
	 * Whether the run stopped because another run took the account claim.
	 *
	 * @var bool
	 */
	public $claim_lost = false;

	/**
	 * Records an error text once.
	 *
	 * @since 2.0.30
	 *
	 * @param string $text Text.
	 * @return void
	 */
	public function error( string $text ): void {
		if ( '' !== $text && ! in_array( $text, $this->errors, true ) ) {
			$this->errors[] = $text;
		}
	}

	/**
	 * Returns the merchant line.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function message(): string {
		$parts = [];

		if ( $this->sent > 0 ) {
			/* translators: %d: number of products whose stock the marketplace accepted */
			$parts[] = sprintf( __( 'Отправлено: %d', 'woodev-plugin-framework' ), $this->sent );
		}

		if ( [] !== $this->errors ) {
			/* translators: %s: the marketplace's error texts */
			$parts[] = sprintf( __( 'Ошибка: %s', 'woodev-plugin-framework' ), implode( '; ', $this->errors ) );
		}

		if ( [] !== $parts ) {
			return implode( '. ', $parts );
		}

		return 0 === $this->waiting
			? __( 'Нечего отправлять (значения совпадают)', 'woodev-plugin-framework' )
			: __( 'Ожидает отправки: маркетплейс просит подождать', 'woodev-plugin-framework' );
	}
}
