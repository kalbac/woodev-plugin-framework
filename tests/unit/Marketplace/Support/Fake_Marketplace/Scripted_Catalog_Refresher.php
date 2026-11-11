<?php
/**
 * The catalogue refresh of the marketplace, scripted.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\Catalog_Refresher;
use Woodev\Framework\Marketplace\Flows\Refresh_Result;

/**
 * Answers with {@see self::$result} (a complete pass that wrote nothing unless a test says otherwise) and keeps what it was asked.
 * A `\Throwable` in {@see self::$failure} is thrown by the next pass, once.
 */
final class Scripted_Catalog_Refresher implements Catalog_Refresher {
	/**
	 * The answer.
	 *
	 * @var Refresh_Result|null
	 */
	public $result;

	/**
	 * Thrown by the next pass when set.
	 *
	 * @var \Throwable|null
	 */
	public $failure;

	/**
	 * Passes: `[ account id, context, since, deadline ]`.
	 *
	 * @var array<int,array{0:int,1:string,2:int,3:float}>
	 */
	public $calls = [];

	/**
	 * Refreshes the catalogue.
	 *
	 * @param Account $account  Account.
	 * @param string  $context  Job.
	 * @param int     $since    Freshness limit.
	 * @param float   $deadline Stop time.
	 * @return Refresh_Result
	 * @throws \Throwable The scripted failure.
	 */
	public function refresh( Account $account, string $context, int $since, float $deadline ): Refresh_Result {
		$this->calls[] = [ $account->id(), $context, $since, $deadline ];

		if ( null !== $this->failure ) {
			$failure       = $this->failure;
			$this->failure = null;

			throw $failure;
		}

		return $this->result ?? new Refresh_Result( 0, [], true );
	}
}
