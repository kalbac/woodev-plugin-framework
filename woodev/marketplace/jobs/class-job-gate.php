<?php
/**
 * The checks every remote job makes before it calls the marketplace.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Three checks, in this order: the account is re-read and a disabled or deleted one ends the job silently; a lapsed licence
 * waits an hour without calling the marketplace; an account paused after an authorisation refusal waits out the pause
 * ({@see Account_Pause}).
 *
 * Two more shared checks:
 *
 * - **Writes may wait for the plugin** ({@see self::allows_writes()}). A plugin that must hold an account's remote writes back
 *   — a data migration that is not validated yet — supplies the write gate; reads and polls never ask it. With no gate
 *   supplied every account may write.
 * - **One run per account.** A run takes the account row's claim through the account store — the claim the per-account sender
 *   and every other writer of the account take — and carries it as a {@see Claim_Fence} into every fenced write.
 *
 * @since 2.1.0
 */
final class Job_Gate {
	public const LICENCE_DELAY = 3600;
	public const WRITE_DELAY   = 3600;
	public const BUSY_DELAY    = 30;
	public const CLAIM_TTL     = 120;

	private const PAUSE_RECHECK_MINIMUM = 60;

	/**
	 * Accounts.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * Licence gate.
	 *
	 * @var License_Gate
	 */
	private $license;

	/**
	 * Account pause.
	 *
	 * @var Account_Pause
	 */
	private $pause;

	/**
	 * The plugin's write gate: `function ( Account $account ): bool`, or null when the plugin holds nothing back.
	 *
	 * @var callable|null
	 */
	private $write_gate;

	/**
	 * Creates the gate.
	 *
	 * @since 2.1.0
	 *
	 * @param Wpdb_Account_Store $accounts   Accounts.
	 * @param License_Gate       $license    Licence gate.
	 * @param Account_Pause      $pause      Account pause.
	 * @param callable|null      $write_gate `function ( Account $account ): bool`: whether the account may write to the
	 *                                       marketplace now; null lets every account write.
	 */
	public function __construct( Wpdb_Account_Store $accounts, License_Gate $license, Account_Pause $pause, ?callable $write_gate = null ) {
		$this->accounts   = $accounts;
		$this->license    = $license;
		$this->pause      = $pause;
		$this->write_gate = $write_gate;
	}

	/**
	 * Checks an account.
	 *
	 * @since 2.1.0
	 *
	 * @param int $account_id Account id.
	 * @return array{0:Account|null,1:int} The account (null: stop silently) and the seconds to wait first (0: go).
	 */
	public function check( int $account_id ): array {
		$account = $this->accounts->get( $account_id );

		if ( null === $account || ! $account->is_enabled() ) {
			return [ null, 0 ];
		}

		if ( ! $this->license->is_in_force() ) {
			return [ $account, self::LICENCE_DELAY ];
		}

		$paused_for = $this->pause->remaining( $account );

		return [ $account, $paused_for > 0 ? max( self::PAUSE_RECHECK_MINIMUM, $paused_for ) : 0 ];
	}

	/**
	 * Tells whether the account may write to the marketplace: the plugin's write gate lets it, or there is none. Only an
	 * explicit `true` of the gate allows a write, so a gate that fails to answer holds the account back.
	 *
	 * @since 2.1.0
	 *
	 * @param Account $account Account.
	 * @return bool
	 */
	public function allows_writes( Account $account ): bool {
		return null === $this->write_gate || true === call_user_func( $this->write_gate, $account );
	}

	/**
	 * Takes the account row's claim for a run.
	 *
	 * @since 2.1.0
	 *
	 * @param int $account_id Account id.
	 * @return Claim_Fence|null Null when another run holds it.
	 */
	public function claim( int $account_id ): ?Claim_Fence {
		$token = $this->accounts->claim( $account_id, self::CLAIM_TTL );

		return null === $token ? null : new Claim_Fence( $this->accounts->table(), $token );
	}

	/**
	 * Tells whether a run still holds its claim, and extends it.
	 *
	 * @since 2.1.0
	 *
	 * @param int         $account_id Account id.
	 * @param Claim_Fence $fence      The run's claim.
	 * @return bool
	 */
	public function still_holds( int $account_id, Claim_Fence $fence ): bool {
		return $this->accounts->renew_claim( $account_id, $fence->token(), self::CLAIM_TTL );
	}

	/**
	 * Releases a run's claim; a run that lost it changes nothing.
	 *
	 * @since 2.1.0
	 *
	 * @param int         $account_id Account id.
	 * @param Claim_Fence $fence      The run's claim.
	 * @return void
	 */
	public function release( int $account_id, Claim_Fence $fence ): void {
		$this->accounts->release_claim( $account_id, $fence->token() );
	}

	/**
	 * Returns the account pause, for a runner that must apply one.
	 *
	 * @since 2.1.0
	 *
	 * @return Account_Pause
	 */
	public function pause(): Account_Pause {
		return $this->pause;
	}
}
