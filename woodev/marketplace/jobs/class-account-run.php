<?php
/**
 * One run of an account under its claim.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Claim_Fence;

defined( 'ABSPATH' ) || exit;

/**
 * The one claim check a sender's writes go through, and its one request and time budget.
 *
 * - {@see self::spend_request()} comes right before every marketplace request: it renews the claim and counts the request.
 * - {@see self::guard()} comes right before every side effect outside our tables (a WooCommerce stock write, an intent).
 * - {@see self::write()} wraps every fenced write (sync state, link, ledger): a write the fence refused means the claim is lost.
 *
 * Each of them throws {@see Claim_Lost} when another run owns the account now; the run stops there.
 *
 * @since 2.0.30
 */
final class Account_Run {
	/**
	 * Account.
	 *
	 * @var Account
	 */
	private $account;

	/**
	 * The run's claim.
	 *
	 * @var Claim_Fence
	 */
	private $fence;

	/**
	 * Renews the claim: `function (): bool`, false when it is lost.
	 *
	 * @var callable
	 */
	private $renew;

	/**
	 * Requests the run may send.
	 *
	 * @var int
	 */
	private $budget;

	/**
	 * When the run stops starting requests (seconds with fractions).
	 *
	 * @var float
	 */
	private $deadline;

	/**
	 * Clock: `function (): float`.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Requests sent so far.
	 *
	 * @var int
	 */
	private $requests = 0;

	/**
	 * Starts a run.
	 *
	 * @since 2.0.30
	 *
	 * @param Account       $account Account.
	 * @param Claim_Fence   $fence   The run's claim.
	 * @param callable      $renew   `function (): bool`, renews the claim.
	 * @param int           $budget  Requests the run may send.
	 * @param float         $seconds Seconds the run may start requests in.
	 * @param callable|null $clock   `function (): float`; `microtime( true )` when null.
	 */
	public function __construct( Account $account, Claim_Fence $fence, callable $renew, int $budget, float $seconds, ?callable $clock = null ) {
		$this->account  = $account;
		$this->fence    = $fence;
		$this->renew    = $renew;
		$this->budget   = max( 0, $budget );
		$this->clock    = $clock ?? static function (): float {
			return microtime( true );
		};
		$this->deadline = $this->now() + $seconds;
	}

	/**
	 * Returns the account.
	 *
	 * @since 2.0.30
	 *
	 * @return Account
	 */
	public function account(): Account {
		return $this->account;
	}

	/**
	 * Returns the run's claim.
	 *
	 * @since 2.0.30
	 *
	 * @return Claim_Fence
	 */
	public function fence(): Claim_Fence {
		return $this->fence;
	}

	/**
	 * Returns the claim token.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function token(): string {
		return $this->fence->token();
	}

	/**
	 * Tells whether the budget allows one more request now.
	 *
	 * @since 2.0.30
	 *
	 * @return bool
	 */
	public function can_request(): bool {
		return $this->requests < $this->budget && $this->now() < $this->deadline;
	}

	/**
	 * Renews the claim right before a request and counts it.
	 *
	 * @since 2.0.30
	 *
	 * @return void
	 * @throws Claim_Lost When another run owns the account now.
	 */
	public function spend_request(): void {
		$this->guard();
		++$this->requests;
	}

	/**
	 * Renews the claim right before a side effect.
	 *
	 * @since 2.0.30
	 *
	 * @return void
	 * @throws Claim_Lost When another run owns the account now.
	 */
	public function guard(): void {
		if ( ! call_user_func( $this->renew ) ) {
			throw new Claim_Lost( 'The account claim of account ' . $this->account->id() . ' was taken over.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Checks the result of a fenced write.
	 *
	 * @since 2.0.30
	 *
	 * @param bool $written What the fenced write returned.
	 * @return void
	 * @throws Claim_Lost When the fence refused the write.
	 */
	public function write( bool $written ): void {
		if ( ! $written ) {
			throw new Claim_Lost( 'A fenced write of account ' . $this->account->id() . ' changed nothing: the claim was taken over.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Returns the requests sent.
	 *
	 * @since 2.0.30
	 *
	 * @return int
	 */
	public function requests(): int {
		return $this->requests;
	}

	/**
	 * Returns the seconds left before the run's deadline (0 once it has passed).
	 *
	 * @since 2.0.30
	 *
	 * @return float
	 */
	public function remaining_seconds(): float {
		return max( 0.0, $this->deadline - $this->now() );
	}

	/**
	 * Returns the requests left in the budget.
	 *
	 * @since 2.0.30
	 *
	 * @return int
	 */
	public function remaining(): int {
		return max( 0, $this->budget - $this->requests );
	}

	/**
	 * Reads the clock.
	 *
	 * @return float
	 */
	private function now(): float {
		return (float) call_user_func( $this->clock );
	}
}
