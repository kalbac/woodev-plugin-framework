<?php
/**
 * In-memory account store.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Account\Health_Result;

/**
 * Keeps accounts and their last health in memory. `set_health()` never touches the merchant's switch.
 */
final class Fake_Account_Store implements Account_Store {
	/**
	 * Accounts by id.
	 *
	 * @var array<int,Account>
	 */
	private $accounts = array();

	/**
	 * Last health by account id.
	 *
	 * @var array<int,Health_Result>
	 */
	private $health = array();

	/**
	 * Loads an account.
	 *
	 * @param int $id Account id.
	 * @return Account|null
	 */
	public function get( int $id ): ?Account {
		return $this->accounts[ $id ] ?? null;
	}

	/**
	 * Lists the accounts of an adapter.
	 *
	 * @param string $adapter_id   Adapter id.
	 * @param bool   $enabled_only Skip switched-off accounts.
	 * @return Account[]
	 */
	public function all( string $adapter_id, bool $enabled_only ): array {
		$found = array();
		foreach ( $this->accounts as $account ) {
			if ( $account->adapter_id() === $adapter_id && ( ! $enabled_only || $account->is_enabled() ) ) {
				$found[] = $account;
			}
		}

		return $found;
	}

	/**
	 * Saves an account; id 0 gets the next free id.
	 *
	 * @param Account $account Account.
	 * @return int
	 */
	public function save( Account $account ): int {
		$id = $account->id();
		if ( 0 === $id ) {
			$id      = count( $this->accounts ) + 1;
			$account = new Account( $id, $account->adapter_id(), $account->name(), $account->is_enabled(), $account->credentials(), $account->expires_at(), $account->settings() );
		}

		$this->accounts[ $id ] = $account;

		return $id;
	}

	/**
	 * Records a health result.
	 *
	 * @param int           $id     Account id.
	 * @param Health_Result $result Result.
	 * @return void
	 */
	public function set_health( int $id, Health_Result $result ): void {
		$this->health[ $id ] = $result;
	}

	/**
	 * Returns the last recorded health.
	 *
	 * @param int $id Account id.
	 * @return Health_Result|null
	 */
	public function health_of( int $id ): ?Health_Result {
		return $this->health[ $id ] ?? null;
	}
}
