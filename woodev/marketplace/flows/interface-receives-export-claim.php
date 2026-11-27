<?php
/**
 * An export gateway that is handed the account claim of the run that builds and sends.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Optional: an {@see Export_Gateway} implements it when its own work must stop once another run took the account — reads, waits or
 * several requests inside {@see Export_Gateway::submit()} that may outlast the claim
 * ({@see \Woodev\Framework\Marketplace\Jobs\Job_Gate::CLAIM_TTL} seconds).
 *
 * The `export_batch` job calls it right after it took the claim, before it builds or sends anything of the run, so the gateway
 * holds this run's claim from the first card on. Reading the claim from the account row later is not the same: by then the row may
 * hold another run's claim.
 *
 * Without it nothing changes: the job still checks its claim before it calls {@see Export_Gateway::submit()}.
 *
 * @since 2.1.28
 */
interface Receives_Export_Claim {
	/**
	 * Receives the claim of the run that starts. Each run hands over its own, replacing the one before: one gateway object may serve
	 * several runs in one request. No request to the marketplace belongs here.
	 *
	 * `$hold` extends the claim, so a gateway calls it between its remote calls and right before each write. It throws
	 * {@see \Woodev\Framework\Marketplace\Jobs\Claim_Lost} once the claim is no longer this run's: another run took it, or this run
	 * ended and released it. The gateway lets that exception through, and its {@see \Woodev\Framework\Marketplace\Jobs\Error_Classifier}
	 * does not classify it: the job logs it and leaves its task and links as they are, for the recovery sweep.
	 *
	 * @since 2.1.28
	 *
	 * @param Account  $account Account the cards of the run go to.
	 * @param callable $hold    `function (): void`.
	 * @return void
	 */
	public function receive_export_claim( Account $account, callable $hold ): void;
}
