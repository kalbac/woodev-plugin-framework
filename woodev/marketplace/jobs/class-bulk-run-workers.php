<?php
/**
 * Picks the worker of a bulk run by the run's action.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Bulk_Run;

defined( 'ABSPATH' ) || exit;

/**
 * One account has one run row whichever table started it (#1795, #1796), and {@see Bulk_Run_Runner} has one {@see Bulk_Run_Worker}. The
 * «Товары сайта» worker reads parents under a filter; the «Карточки на маркетплейсе» worker feeds a frozen list. This hands each run to
 * the one whose action it is, and the products worker takes every action nobody else claims.
 *
 * @since 2.1.10
 */
final class Bulk_Run_Workers implements Bulk_Run_Worker {
	/**
	 * @var Bulk_Run_Worker
	 */
	private $default;

	/**
	 * Workers of the actions that are not the default's, by action id.
	 *
	 * @var array<string,Bulk_Run_Worker>
	 */
	private $by_action;

	/**
	 * Creates the router.
	 *
	 * @since 2.1.10
	 *
	 * @param Bulk_Run_Worker               $default   The worker of every action not listed in `$by_action`.
	 * @param array<string,Bulk_Run_Worker> $by_action The worker of an action, by action id.
	 */
	public function __construct( Bulk_Run_Worker $default, array $by_action ) {
		$this->default   = $default;
		$this->by_action = $by_action;
	}

	/**
	 * @since 2.1.10
	 *
	 * @param Account  $account Account.
	 * @param Bulk_Run $run     The run.
	 * @return string|null
	 */
	public function refusal( Account $account, Bulk_Run $run ): ?string {
		return $this->worker_of( $run )->refusal( $account, $run );
	}

	/**
	 * @since 2.1.10
	 *
	 * @param Account             $account Account.
	 * @param Bulk_Run            $run     The run.
	 * @param array<string,mixed> $cursor  Where the previous part stopped.
	 * @param callable            $owns    `function (): bool`.
	 * @return array{cursor:array<string,mixed>,finished:bool,counts:array<string,int>,groups:array<int,array<string,mixed>>,busy:int}
	 */
	public function part( Account $account, Bulk_Run $run, array $cursor, callable $owns ): array {
		return $this->worker_of( $run )->part( $account, $run, $cursor, $owns );
	}

	/**
	 * @param Bulk_Run $run The run.
	 * @return Bulk_Run_Worker
	 */
	private function worker_of( Bulk_Run $run ): Bulk_Run_Worker {
		return $this->by_action[ $run->action() ] ?? $this->default;
	}
}
