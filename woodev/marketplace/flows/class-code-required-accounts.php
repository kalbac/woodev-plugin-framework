<?php
/**
 * The accounts whose cabinet confirms a delivery only with the buyer's code.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Uninstaller;

defined( 'ABSPATH' ) || exit;

/**
 * Once a marketplace asked for the buyer's confirmation code, the framework stops confirming deliveries of that account by
 * itself ({@see Carrier_Bridge}): the refused request costs and would only be repeated. Kept in one option of the plugin's family,
 * so the uninstaller takes it with the rest; an account's deletion forgets it.
 *
 * @since 2.1.29
 */
final class Code_Required_Accounts {
	/**
	 * Name of the option after the plugin's option prefix.
	 */
	private const OPTION = 'confirmation_code_accounts';

	/**
	 * Full option name.
	 *
	 * @var string
	 */
	private $option;

	/**
	 * @since 2.1.29
	 *
	 * @param string $plugin_id Plugin id.
	 */
	public function __construct( string $plugin_id ) {
		$this->option = Uninstaller::option_prefix( $plugin_id ) . self::OPTION;
	}

	/**
	 * @since 2.1.29
	 *
	 * @param int $account_id Account id.
	 * @return bool Whether the account's cabinet asked for a code.
	 */
	public function has( int $account_id ): bool {
		return in_array( $account_id, $this->all(), true );
	}

	/**
	 * Records that an account's cabinet asked for a code.
	 *
	 * @since 2.1.29
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	public function remember( int $account_id ): void {
		if ( ! $this->has( $account_id ) ) {
			update_option( $this->option, array_merge( $this->all(), [ $account_id ] ), false );
		}
	}

	/**
	 * Forgets an account that was deleted.
	 *
	 * @since 2.1.29
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	public function forget( int $account_id ): void {
		if ( $this->has( $account_id ) ) {
			update_option( $this->option, array_values( array_diff( $this->all(), [ $account_id ] ) ), false );
		}
	}

	/**
	 * @return int[]
	 */
	private function all(): array {
		$ids = get_option( $this->option, [] );

		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : [];
	}
}
