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
 * The time of the last poll that read an account's postings to the end, per account, in one option of the plugin's family (so the
 * uninstaller takes it with the rest). The «Заказы маркетплейсов» page shows it as «Обновлено»: a poll that was skipped (no licence,
 * a paused account) or failed records nothing, so the time never claims a read that did not happen.
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
	 * Records a finished poll of an account.
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
	 * The last finished poll of an account.
	 *
	 * @since 2.1.13
	 *
	 * @param int $account_id Account id.
	 * @return int|null Unix time; null when no poll has finished since the record began.
	 */
	public function last( int $account_id ): ?int {
		$time = $this->all()[ $account_id ] ?? null;

		return is_numeric( $time ) && (int) $time > 0 ? (int) $time : null;
	}

	/**
	 * @return array<int,int|string>
	 */
	private function all(): array {
		$times = get_option( $this->option, [] );

		return is_array( $times ) ? $times : [];
	}
}
