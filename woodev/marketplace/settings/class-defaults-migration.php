<?php
/**
 * One-time fill of the «Общие» defaults on a shop that already had accounts.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Before the defaults existed every account held its own price rule and exchange switches. On the first run after the upgrade the
 * defaults are filled from the **first** account (the lowest id), so a merchant who switches an account to «как в Общих» gets the
 * values the shop already worked with, not factory ones.
 *
 * Nothing is written to the accounts: an account without the `overrides` flag already counts as «свои» ({@see Effective_Settings}),
 * so every existing account keeps behaving as before. The run is marked in the defaults option and never repeats.
 *
 * A shop without accounts is **not** marked: on an upgrade from v1 the settings handler can be built (the plugin-list «Настройки» link,
 * the not-configured notice) before the plugin's own migration has created the accounts, and the mark would keep the built-in defaults
 * for good. The mark is set by the run that finds an account, or by the first default the merchant saves on «Общие»
 * ({@see Defaults_Section::write()}), whichever comes first.
 *
 * @since 2.1.5
 */
final class Defaults_Migration {
	/**
	 * Accounts.
	 *
	 * @var Account_Store
	 */
	private $accounts;

	/**
	 * Adapter id.
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * Creates the migration.
	 *
	 * @since 2.1.5
	 *
	 * @param Account_Store $accounts   Account store.
	 * @param string        $adapter_id Adapter id.
	 */
	public function __construct( Account_Store $accounts, string $adapter_id ) {
		$this->accounts   = $accounts;
		$this->adapter_id = $adapter_id;
	}

	/**
	 * Fills the defaults from the first account, once.
	 *
	 * @since 2.1.5
	 *
	 * @return bool True when this call did the fill.
	 */
	public function run(): bool {
		$resolver = Effective_Settings::for_adapter( $this->adapter_id );

		if ( $resolver->is_seeded() ) {
			return false;
		}

		$first = $this->first_account();

		if ( null === $first ) {
			return false;
		}

		$defaults = $this->values_of( $first );

		$defaults[ Effective_Settings::SEEDED_KEY ] = 1;

		update_option( Effective_Settings::option_name( $this->adapter_id ), $defaults );

		return true;
	}

	/**
	 * The account with the lowest id, or null when the shop has none.
	 *
	 * @return Account|null
	 */
	private function first_account(): ?Account {
		$accounts = $this->accounts->all( $this->adapter_id, false );

		if ( [] === $accounts ) {
			return null;
		}

		usort(
			$accounts,
			static function ( Account $a, Account $b ): int {
				return $a->id() <=> $b->id();
			}
		);

		return $accounts[0];
	}

	/**
	 * The account's own price rule and exchange switches, in the shape the defaults option stores.
	 *
	 * @param Account $account Account.
	 * @return array<string,mixed>
	 */
	private function values_of( Account $account ): array {
		$builtin = Effective_Settings::builtin_defaults();
		$stored  = $account->setting( 'price_rule' );
		$stored  = is_array( $stored ) ? array_filter( $stored, 'is_scalar' ) : []; // A plugin's own `markup: { kind, value }` shape is not a flat rule: its keys fall back to the built-in ones.
		$rule    = array_intersect_key( array_merge( $builtin, $stored ), array_flip( Effective_Settings::PRICE_KEYS ) );

		return array_merge(
			$rule,
			[
				'orders_enabled' => (bool) $account->setting( 'orders_enabled', $builtin['orders_enabled'] ),
				'import_enabled' => (bool) $account->setting( 'import_enabled', $builtin['import_enabled'] ),
			]
		);
	}
}
