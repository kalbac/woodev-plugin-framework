<?php
/**
 * A marketplace that changes articles as the test tells it to.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\Offer_Id_Rename_Outcome;
use Woodev\Framework\Marketplace\Flows\Offer_Id_Renamer;

/**
 * Renames every pair unless the test says otherwise: `$refuse` (current article => answer), `$omit` (current articles it gives no answer for) or
 * `$throw` (the whole request fails).
 */
final class Scripted_Offer_Id_Renamer implements Offer_Id_Renamer {
	/**
	 * The requests it received: each a list of current => new.
	 *
	 * @var array<int,array<string,string>>
	 */
	public $calls = [];

	/**
	 * Current article => the answer of a refusal.
	 *
	 * @var array<string,string>
	 */
	public $refuse = [];

	/**
	 * Current articles it gives no answer for.
	 *
	 * @var string[]
	 */
	public $omit = [];

	/**
	 * What the whole request throws, when set.
	 *
	 * @var \Throwable|null
	 */
	public $throw;

	/**
	 * Most pairs per request.
	 *
	 * @var int
	 */
	public $limit = 25;

	/**
	 * Returns the limit.
	 *
	 * @return int
	 */
	public function max_renames_per_request(): int {
		return $this->limit;
	}

	/**
	 * Answers a request.
	 *
	 * @param Account              $account Account.
	 * @param array<string,string> $pairs   Current => new.
	 * @param string               $context Calling job.
	 * @return array<string,Offer_Id_Rename_Outcome>
	 * @throws \Throwable What the test scripted.
	 */
	public function rename_offer_ids( Account $account, array $pairs, string $context ): array {
		$this->calls[] = $pairs;

		if ( null !== $this->throw ) {
			throw $this->throw;
		}

		$answers = [];

		foreach ( array_keys( $pairs ) as $old ) {
			if ( in_array( $old, $this->omit, true ) ) {
				continue;
			}

			$answers[ $old ] = isset( $this->refuse[ $old ] ) ? Offer_Id_Rename_Outcome::refused( $this->refuse[ $old ] ) : Offer_Id_Rename_Outcome::renamed();
		}

		return $answers;
	}
}
