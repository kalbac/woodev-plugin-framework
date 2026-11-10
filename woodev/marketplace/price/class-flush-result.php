<?php
/**
 * What one price flush did.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

defined( 'ABSPATH' ) || exit;

/**
 * The tally of a flush run, and the merchant's one-line message for it (spec 5.3 «Messages distinguish»): «Отправлено: 12»,
 * «Нечего отправлять (значения совпадают)» and «Ошибка: …». A counter object the runner fills while it works.
 *
 * @since 2.0.29
 */
final class Flush_Result {
	/**
	 * Prices the marketplace accepted.
	 *
	 * @var int
	 */
	private $sent = 0;

	/**
	 * Prices the marketplace refused or that could not be sent.
	 *
	 * @var int
	 */
	private $failed = 0;

	/**
	 * Prices put off (a pause was asked for, or the call was held back).
	 *
	 * @var int
	 */
	private $waiting = 0;

	/**
	 * Text of the first failure, verbatim.
	 *
	 * @var string
	 */
	private $error = '';

	/**
	 * Why nothing was sent when the account does not push prices (`from_marketplace`, `off`) or may not write yet; empty when it does.
	 *
	 * @var string
	 */
	private $disabled = '';

	/**
	 * Counts accepted prices.
	 *
	 * @since 2.0.29
	 *
	 * @param int $count How many.
	 * @return void
	 */
	public function add_sent( int $count = 1 ): void {
		$this->sent += $count;
	}

	/**
	 * Counts a refused price and keeps the first text.
	 *
	 * @since 2.0.29
	 *
	 * @param string $message The marketplace's text, verbatim.
	 * @param int    $count   How many prices it covers.
	 * @return void
	 */
	public function add_failed( string $message, int $count = 1 ): void {
		$this->failed += $count;
		$this->error   = '' === $this->error ? $message : $this->error;
	}

	/**
	 * Counts prices put off.
	 *
	 * @since 2.0.29
	 *
	 * @param int $count How many.
	 * @return void
	 */
	public function add_waiting( int $count = 1 ): void {
		$this->waiting += $count;
	}

	/**
	 * Marks the run as skipped because the account does not push prices now.
	 *
	 * @since 2.0.29
	 *
	 * @param string $reason Russian reason for the merchant.
	 * @return void
	 */
	public function mark_disabled( string $reason = 'Отправка цен сейчас выключена в настройках аккаунта.' ): void {
		$this->disabled = $reason;
	}

	/**
	 * Returns the accepted count.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function sent(): int {
		return $this->sent;
	}

	/**
	 * Returns the failed count.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function failed(): int {
		return $this->failed;
	}

	/**
	 * Returns the put-off count.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function waiting(): int {
		return $this->waiting;
	}

	/**
	 * Returns the first failure text.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * Returns the one-line message for the merchant.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function message(): string {
		if ( '' !== $this->disabled ) {
			return $this->disabled;
		}

		if ( $this->failed > 0 ) {
			return 'Ошибка: ' . $this->error . ( $this->sent > 0 ? ' (отправлено: ' . $this->sent . ', с ошибкой: ' . $this->failed . ')' : '' );
		}

		if ( $this->sent > 0 ) {
			return 'Отправлено: ' . $this->sent;
		}

		return $this->waiting > 0 ? 'Отправка отложена: ' . $this->waiting . '.' : 'Нечего отправлять (значения совпадают)';
	}
}
