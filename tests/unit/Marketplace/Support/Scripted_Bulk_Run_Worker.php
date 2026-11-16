<?php
/**
 * The work of a bulk run, scripted.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Bulk_Run;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Worker;

/**
 * Answers parts from a list, and lets a test do something in the middle of one (a stop arriving, another tick taking the run).
 */
final class Scripted_Bulk_Run_Worker implements Bulk_Run_Worker {
	/**
	 * The parts to answer, in order: the keys of {@see Bulk_Run_Worker::part()}'s answer, any of them left out.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $parts = [];

	/**
	 * What `refusal()` says; null lets the run go on.
	 *
	 * @var string|null
	 */
	public $refusal = null;

	/**
	 * Runs inside a part, before it answers: `function ( int $call ): void`.
	 *
	 * @var callable|null
	 */
	public $during = null;

	/**
	 * A throwable a part throws.
	 *
	 * @var \Throwable|null
	 */
	public $throws = null;

	/**
	 * The call that throws; 0 throws on every call.
	 *
	 * @var int
	 */
	public $throws_at = 0;

	/**
	 * The cursors the parts were asked with.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $cursors = [];

	/**
	 * @param Account  $account Account.
	 * @param Bulk_Run $run     Run.
	 * @return string|null
	 */
	public function refusal( Account $account, Bulk_Run $run ): ?string {
		return $this->refusal;
	}

	/**
	 * @param Account              $account Account.
	 * @param Bulk_Run             $run     Run.
	 * @param array<string,mixed>  $cursor  Cursor.
	 * @param callable             $owns    Ownership.
	 * @return array<string,mixed>
	 */
	public function part( Account $account, Bulk_Run $run, array $cursor, callable $owns ): array {
		$this->cursors[] = $cursor;
		$call            = count( $this->cursors );

		if ( null !== $this->during ) {
			call_user_func( $this->during, $call );
		}

		if ( null !== $this->throws && ( 0 === $this->throws_at || $call === $this->throws_at ) ) {
			throw $this->throws;
		}

		if ( ! $owns() ) {
			throw new \Woodev\Framework\Marketplace\Jobs\Bulk_Run_Lost();
		}

		$part = array_shift( $this->parts ) ?? [ 'finished' => true ];

		return array_merge(
			[
				'cursor'   => [ 'parent' => $call * 10, 'child' => 0 ],
				'finished' => false,
				'counts'   => [ 'products_done' => 2, 'offers_accepted' => 3, 'offers_skipped' => 1, 'offers_failed' => 0 ],
				'groups'   => [],
				'busy'     => 0,
			],
			$part
		);
	}
}
