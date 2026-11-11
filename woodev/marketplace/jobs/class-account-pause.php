<?php
/**
 * The pause of an account whose credentials the marketplace refuses.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;

defined( 'ABSPATH' ) || exit;

/**
 * A refused key updates the account's health, the account stays enabled, and every job of the account pauses until the next
 * successful connection test or six hours. The pause is the stored health itself: a refused key leaves a failed status and a
 * check time, a successful connection test overwrites it with `ok`, and six hours after the check the pause lapses and the next
 * job tries the key again.
 *
 * Which answers are a refusal is the adapter's knowledge ({@see Error_Classifier}); this class only keeps the time.
 *
 * @since 2.1.0
 */
final class Account_Pause {
	public const SECONDS = 21600;

	private const PAUSING = [ Health_Result::AUTH_FAILED, Health_Result::ROLE_MISSING, Health_Result::KEY_EXPIRED ];

	/**
	 * Maps a refusal to a health result.
	 *
	 * @var Error_Classifier
	 */
	private $classifier;

	/**
	 * Reads the stored health.
	 *
	 * @var callable
	 */
	private $read;

	/**
	 * Writes health fenced by the credentials that were used.
	 *
	 * @var callable
	 */
	private $write;

	/**
	 * Clock returning a Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the pause.
	 *
	 * @since 2.1.0
	 *
	 * @param Error_Classifier $classifier Maps a refusal to a health result.
	 * @param callable         $read       `function ( int $account_id ): ?Health_Result`.
	 * @param callable         $write      `function ( Account, Health_Result ): bool`, the credential-fenced writer.
	 * @param callable|null    $clock      `function (): int`; the system clock when null.
	 */
	public function __construct( Error_Classifier $classifier, callable $read, callable $write, ?callable $clock = null ) {
		$this->classifier = $classifier;
		$this->read       = $read;
		$this->write      = $write;
		$this->clock      = $clock ?? 'time';
	}

	/**
	 * Builds the pause over the account store: the health is read from the account row and written only while the row still
	 * holds the credentials the refused call used, so a key the merchant replaced meanwhile is not marked refused.
	 *
	 * @since 2.1.0
	 *
	 * @param Wpdb_Account_Store $accounts   Accounts.
	 * @param Error_Classifier   $classifier Maps a refusal to a health result.
	 * @param callable|null      $clock      `function (): int`; the system clock when null.
	 * @return self
	 */
	public static function over( Wpdb_Account_Store $accounts, Error_Classifier $classifier, ?callable $clock = null ): self {
		return new self(
			$classifier,
			[ $accounts, 'health' ],
			static function ( Account $account, Health_Result $result ) use ( $accounts ): bool {
				return $accounts->set_health_for_credentials( $account->id(), $account->credentials(), $result );
			},
			$clock
		);
	}

	/**
	 * Records that the marketplace refused the account's credentials.
	 *
	 * @since 2.1.0
	 *
	 * @param Account      $account Account.
	 * @param Remote_Error $error   The refusal.
	 * @return void
	 */
	public function apply( Account $account, Remote_Error $error ): void {
		call_user_func( $this->write, $account, $this->classifier->health_for( $error, $account ) );
	}

	/**
	 * Tells how many seconds the account's jobs still wait.
	 *
	 * @since 2.1.0
	 *
	 * @param Account $account Account.
	 * @return int 0 when the account is not paused.
	 */
	public function remaining( Account $account ): int {
		$health = call_user_func( $this->read, $account->id() );

		if ( ! $health instanceof Health_Result || ! in_array( $health->status(), self::PAUSING, true ) ) {
			return 0;
		}

		$checked_at = $health->extra()['checked_at'] ?? null;

		if ( ! $checked_at instanceof \DateTimeInterface ) {
			return 0;
		}

		return max( 0, self::SECONDS - ( (int) call_user_func( $this->clock ) - $checked_at->getTimestamp() ) );
	}
}
