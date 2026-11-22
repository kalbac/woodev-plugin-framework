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
 * The moment the first poll of an account began, per account, in one option of the plugin's family (so the uninstaller takes it
 * with the rest). Orders the marketplace created before it are the account's history: {@see \Woodev\Framework\Marketplace\Flows\History_Import}
 * writes them without a WooCommerce e-mail, however many polls the import takes. An account that was already polled when this
 * record began has no history to protect and stores 0.
 *
 * @since 2.1.15
 */
final class Posting_History_Cutoffs {
	/**
	 * Name of the option after the plugin's option prefix.
	 */
	private const OPTION = 'postings_history_cutoff';

	/**
	 * Full option name.
	 *
	 * @var string
	 */
	private $option;

	/**
	 * Creates the store of one plugin.
	 *
	 * @since 2.1.15
	 *
	 * @param string $plugin_id Plugin id, e.g. `ozon-seller`.
	 */
	public function __construct( string $plugin_id ) {
		$this->option = Uninstaller::option_prefix( $plugin_id ) . self::OPTION;
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
		$time = $this->all()[ $account_id ] ?? null;

		return is_numeric( $time ) && (int) $time >= 0 ? (int) $time : null;
	}

	/**
	 * Records the cutoff of an account. The first record stays: a poll that began later does not move it.
	 *
	 * @since 2.1.15
	 *
	 * @param int $account_id Account id.
	 * @param int $time       Unix time the first poll began; 0 for an account with no history.
	 * @return void
	 */
	public function remember( int $account_id, int $time ): void {
		$cutoffs = $this->all();

		if ( isset( $cutoffs[ $account_id ] ) ) {
			return;
		}

		$cutoffs[ $account_id ] = max( 0, $time );

		update_option( $this->option, $cutoffs, false );
	}

	/**
	 * @return array<int,int|string>
	 */
	private function all(): array {
		$cutoffs = get_option( $this->option, [] );

		return is_array( $cutoffs ) ? $cutoffs : [];
	}
}
