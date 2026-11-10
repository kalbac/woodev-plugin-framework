<?php
/**
 * Token-bucket governor driven by Bucket_Spec data.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Governor\Bucket_Spec;
use Woodev\Framework\Marketplace\Governor\Request_Governor;

/**
 * An in-memory governor that proves the contract is data-driven: all numbers come from `Bucket_Spec`, none from the
 * governor. A failed (4xx) request is charged by acquiring `cost_4xx` tokens after the fact.
 */
final class Fake_Request_Governor implements Request_Governor {
	/**
	 * Clock.
	 *
	 * @var Fake_Clock
	 */
	private $clock;

	/**
	 * Specs by bucket key.
	 *
	 * @var array<string,Bucket_Spec>
	 */
	private $specs = array();

	/**
	 * Bucket state by "account|bucket".
	 *
	 * @var array<string,array{tokens:float,at:int,blocked_until:int,disabled:bool}>
	 */
	private $state = array();

	/**
	 * Creates the governor.
	 *
	 * @param Fake_Clock    $clock Clock.
	 * @param Bucket_Spec[] $specs Bucket descriptions.
	 */
	public function __construct( Fake_Clock $clock, array $specs ) {
		$this->clock = $clock;
		foreach ( $specs as $spec ) {
			$this->specs[ $spec->key() ] = $spec;
		}
	}

	/**
	 * Takes tokens, or says how long to wait.
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket.
	 * @param int    $cost        Tokens.
	 * @return int
	 */
	public function acquire( string $account_key, string $bucket, int $cost = 1 ): int {
		$spec  = $this->specs[ $bucket ];
		$now   = $this->clock->now()->getTimestamp();
		$state = $this->state_of( $account_key, $bucket, $spec );
		if ( $state['blocked_until'] > $now ) {
			return $state['blocked_until'] - $now;
		}

		$rate            = $spec->limit() / $spec->period_seconds();
		$state['tokens'] = min( (float) $spec->burst(), $state['tokens'] + ( $now - $state['at'] ) * $rate );
		$state['at']     = $now;
		$wait            = 0;
		if ( $state['tokens'] >= $cost ) {
			$state['tokens'] -= $cost;
		} else {
			$wait = (int) ceil( ( $cost - $state['tokens'] ) / $rate );
		}

		$this->state[ $account_key . '|' . $bucket ] = $state;

		return $wait;
	}

	/**
	 * Empties a bucket for a while.
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket.
	 * @param int    $retry_after Seconds.
	 * @return void
	 */
	public function report_rate_limited( string $account_key, string $bucket, int $retry_after ): void {
		$state                                       = $this->state_of( $account_key, $bucket, $this->specs[ $bucket ] );
		$state['tokens']                             = 0.0;
		$state['blocked_until']                      = $this->clock->now()->getTimestamp() + $retry_after;
		$this->state[ $account_key . '|' . $bucket ] = $state;
	}

	/**
	 * Switches a bucket's method off.
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket.
	 * @param string $reason      Reason.
	 * @return void
	 */
	public function report_disabled( string $account_key, string $bucket, string $reason ): void {
		$state                                       = $this->state_of( $account_key, $bucket, $this->specs[ $bucket ] );
		$state['disabled']                           = true;
		$this->state[ $account_key . '|' . $bucket ] = $state;
	}

	/**
	 * Tells whether a bucket's method is switched off.
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket.
	 * @return bool
	 */
	public function is_disabled( string $account_key, string $bucket ): bool {
		return $this->state[ $account_key . '|' . $bucket ]['disabled'] ?? false;
	}

	/**
	 * Reads or creates the state of a bucket.
	 *
	 * @param string      $account_key Account.
	 * @param string      $bucket      Bucket.
	 * @param Bucket_Spec $spec        Bucket description.
	 * @return array{tokens:float,at:int,blocked_until:int,disabled:bool}
	 */
	private function state_of( string $account_key, string $bucket, Bucket_Spec $spec ): array {
		return $this->state[ $account_key . '|' . $bucket ] ?? array(
			'tokens'        => (float) $spec->burst(),
			'at'            => $this->clock->now()->getTimestamp(),
			'blocked_until' => 0,
			'disabled'      => false,
		);
	}
}
