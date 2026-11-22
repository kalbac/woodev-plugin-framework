<?php
/**
 * Where each account's order history ends.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Data\Uninstaller;

defined( 'ABSPATH' ) || exit;

/**
 * The moment the first poll of an account began, one option per account in the plugin's family (so the uninstaller takes it with
 * the rest). Orders the marketplace created before it are the account's history: {@see \Woodev\Framework\Marketplace\Flows\History_Import}
 * writes them without a WooCommerce e-mail, however many polls the import takes. An account that was already polled when this
 * record began has no history to protect and stores 0.
 *
 * One option per account, written with `add_option()`: the first poll of two accounts may begin in two requests at once, and a
 * shared option read and written back whole would lose one account's record. `add_option()` stores nothing when the option exists,
 * so the first record of an account wins and no write touches another account's.
 *
 * @since 2.1.15
 */
final class Posting_History_Cutoffs {
	/**
	 * Name of the option after the plugin's option prefix; the account id follows.
	 */
	private const OPTION = 'postings_history_cutoff_';

	/**
	 * Prefix of the option names, up to the account id.
	 *
	 * @var string
	 */
	private $option_prefix;

	/**
	 * Creates the store of one plugin.
	 *
	 * @since 2.1.15
	 *
	 * @param string $plugin_id Plugin id, e.g. `ozon-seller`.
	 */
	public function __construct( string $plugin_id ) {
		$this->option_prefix = Uninstaller::option_prefix( $plugin_id ) . self::OPTION;
	}

	/**
	 * The cutoff of an account.
	 *
	 * @since 2.1.15
	 *
	 * @param int $account_id Account id.
	 * @return int|null Unix time, 0 for an account with no history; null when none was recorded yet.
	 */
	public function get( int $account_id ): ?int {
		$time = get_option( $this->option( $account_id ), false );

		return is_numeric( $time ) && (int) $time >= 0 ? (int) $time : null;
	}

	/**
	 * Records the cutoff of an account. The first record stays: a poll that began later, or at the same moment in another request,
	 * does not move it.
	 *
	 * @since 2.1.15
	 *
	 * @param int $account_id Account id.
	 * @param int $time       Unix time the first poll began; 0 for an account with no history.
	 * @return void
	 */
	public function remember( int $account_id, int $time ): void {
		add_option( $this->option( $account_id ), max( 0, $time ), '', false );
	}

	/**
	 * Forgets the cutoff of an account that was deleted, so an account that is added later under the same id starts a history of its own.
	 *
	 * @since 2.1.15
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	public function forget( int $account_id ): void {
		delete_option( $this->option( $account_id ) );
	}

	/**
	 * Name of the option of an account.
	 *
	 * @param int $account_id Account id.
	 * @return string
	 */
	private function option( int $account_id ): string {
		return $this->option_prefix . $account_id;
	}
}
