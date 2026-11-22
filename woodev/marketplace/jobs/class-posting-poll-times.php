<?php
/**
 * When each account's postings were last read from the marketplace.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Data\Uninstaller;

defined( 'ABSPATH' ) || exit;

/**
 * The time of the last poll attempt that ended without an error, per account, in one option of the plugin's family (so the
 * uninstaller takes it with the rest). The «Заказы маркетплейсов» page shows it as «Последний опрос». A poll that was skipped (no
 * licence, a paused account) or failed records nothing; a poll that stopped within the handler's time budget and left the rest for
 * the next one IS recorded — `Posting_Handler::catch_up()` returns nothing to tell the two apart — so the time says when the site
 * last asked, never that every change was read.
 *
 * @since 2.1.13
 */
final class Posting_Poll_Times {
	/**
	 * Name of the option after the plugin's option prefix.
	 */
	private const OPTION = 'postings_polled';

	/**
	 * Full option name.
	 *
	 * @var string
	 */
	private $option;

	/**
	 * Creates the store of one plugin.
	 *
	 * @since 2.1.13
	 *
	 * @param string $plugin_id Plugin id, e.g. `ozon-seller`.
	 */
	public function __construct( string $plugin_id ) {
		$this->option = Uninstaller::option_prefix( $plugin_id ) . self::OPTION;
	}

	/**
	 * Records a poll attempt of an account that ended without an error.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @param int $time       Unix time the poll finished.
	 * @return void
	 */
	public function record( int $account_id, int $time ): void {
		$times                = $this->all();
		$times[ $account_id ] = $time;

		update_option( $this->option, $times, false );
	}

	/**
	 * The last poll attempt of an account that ended without an error.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @return int|null Unix time; null when no poll has ended without an error since the record began.
	 */
	public function last( int $account_id ): ?int {
		$time = $this->all()[ $account_id ] ?? null;

		return is_numeric( $time ) && (int) $time > 0 ? (int) $time : null;
	}

	/**
	 * Forgets the polls of an account that was deleted, so an account added later under the same id is polled for the first time
	 * ({@see Posting_Jobs} reads a recorded poll as «this account has no history to protect»). The other accounts' times stay.
	 *
	 * @since 2.1.15
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	public function forget( int $account_id ): void {
		$times = $this->all();

		if ( ! isset( $times[ $account_id ] ) ) {
			return;
		}

		unset( $times[ $account_id ] );

		update_option( $this->option, $times, false );
	}

	/**
	 * @return array<int,int|string>
	 */
	private function all(): array {
		$times = get_option( $this->option, [] );

		return is_array( $times ) ? $times : [];
	}
}
