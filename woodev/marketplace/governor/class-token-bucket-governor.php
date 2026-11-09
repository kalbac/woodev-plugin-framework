<?php
/**
 * Token-bucket request governor.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Governor;

defined( 'ABSPATH' ) || exit;

/**
 * A `Request_Governor` driven entirely by `Bucket_Spec` data and persisted through a `Throttle_Store`.
 *
 * Every state change is a compare-and-swap on the row's version: the governor reads, computes, and swaps; when the swap
 * is lost it re-reads and computes again, at most `MAX_ATTEMPTS` times. If the contention never ends it answers "wait
 * `CONTENTION_WAIT` second", so a busy store slows a runner down and never lets a request through unchecked.
 *
 * Keys that have no `Bucket_Spec` (a single retired method) can still be switched off and asked about; they only cannot
 * be acquired from.
 *
 * @since 2.0.29
 */
final class Token_Bucket_Governor implements Request_Governor {
	public const MAX_ATTEMPTS     = 3;
	public const CONTENTION_WAIT  = 1;
	public const DISABLED_SECONDS = 86400;
	public const COOLDOWN_PREFIX  = 'c:';

	/**
	 * Bucket state keys.
	 */
	private const TOKENS       = 'tokens';
	private const REFILLED_AT  = 'refilled_at';
	private const REMAINING    = 'remaining';
	private const REMAINING_AT = 'remaining_at';
	private const ANSWERED     = 'answered';

	/**
	 * Storage.
	 *
	 * @var Throttle_Store
	 */
	private $store;

	/**
	 * Bucket descriptions by key.
	 *
	 * @var array<string,Bucket_Spec>
	 */
	private $specs = [];

	/**
	 * Clock: Unix time in seconds with a fraction.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the governor.
	 *
	 * @since 2.0.29
	 *
	 * @param Throttle_Store $store Storage.
	 * @param Bucket_Spec[]  $specs Bucket descriptions.
	 * @param callable|null  $clock Returns the Unix time as a float; the system clock when null.
	 */
	public function __construct( Throttle_Store $store, array $specs, ?callable $clock = null ) {
		$this->store = $store;
		$this->clock = $clock ?? static function (): float {
			return microtime( true );
		};

		foreach ( $specs as $spec ) {
			$this->specs[ $spec->key() ] = $spec;
		}
	}

	/**
	 * Asks permission for a request.
	 *
	 * A cost above the bucket's capacity is lowered to the capacity, so a big batch goes out when the bucket is full
	 * instead of waiting for a level it can never reach.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @param int    $cost        Tokens the request costs.
	 * @return int 0 to go ahead, otherwise the seconds to wait.
	 * @throws \InvalidArgumentException When the bucket has no description.
	 */
	public function acquire( string $account_key, string $bucket, int $cost = 1 ): int {
		$spec = $this->spec( $bucket );
		$cost = max( 1, min( $cost, $spec->burst() ) );

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$now = $this->now();
			$row = $this->load( $account_key, $bucket, $spec, $now );

			$wait = max( self::disabled_wait( $row, $now ), $this->cooldown_wait( $account_key, $bucket, $now ) );
			if ( $wait > 0 ) {
				return $wait;
			}

			$state = self::refilled( $row->state(), $spec, $now );
			if ( $state[ self::TOKENS ] < $cost ) {
				return self::seconds_for( ( $cost - $state[ self::TOKENS ] ) * $spec->period_seconds() / $spec->limit() );
			}

			$state[ self::TOKENS ] -= $cost;
			if ( $this->store->swap( $account_key, $bucket, $row->version(), $state, $row->disabled_until(), $row->disabled_reason() ) ) {
				return 0;
			}
		}

		return self::CONTENTION_WAIT;
	}

	/**
	 * Holds a bucket closed for the time the marketplace asked for.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @param int    $retry_after Seconds to wait, at least 1.
	 * @return void
	 */
	public function report_rate_limited( string $account_key, string $bucket, int $retry_after ): void {
		$this->apply_rate_limit( $account_key, $bucket, $retry_after );
	}

	/**
	 * Holds a bucket closed and says whether the hold was stored.
	 *
	 * The hold lives in a row of its own (`c:` and the bucket key) and is written by one atomic maximum
	 * ({@see Throttle_Store::raise_until()}): it cannot lose to a competing 429 report or to token traffic, and a later,
	 * shorter answer never shortens it.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @param int    $retry_after Seconds to wait, at least 1.
	 * @return bool False only when the database refused the write; the caller should record that.
	 */
	public function apply_rate_limit( string $account_key, string $bucket, int $retry_after ): bool {
		$this->empty_tokens( $account_key, $bucket );

		return $this->store->raise_until( $account_key, self::COOLDOWN_PREFIX . $bucket, (int) ceil( $this->now() + max( 1, $retry_after ) ) );
	}

	/**
	 * Switches a bucket (or a single method's key) off for 24 hours.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @param string $reason      Short machine-readable reason, stored up to 64 characters.
	 * @return void
	 */
	public function report_disabled( string $account_key, string $bucket, string $reason ): void {
		$this->update(
			$account_key,
			$bucket,
			function ( Throttle_Row $row, float $now ) use ( $reason ): array {
				return [ $row->state(), (int) ceil( $now ) + self::DISABLED_SECONDS, substr( $reason, 0, 64 ) ];
			}
		);
	}

	/**
	 * Tells whether a bucket is switched off.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @return bool
	 */
	public function is_disabled( string $account_key, string $bucket ): bool {
		$row = $this->store->read( $account_key, $bucket );

		return null !== $row && self::disabled_wait( $row, $this->now() ) > 0;
	}

	/**
	 * Returns how long a switched-off bucket stays off, for a message to the merchant.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @return int Seconds, 0 when the bucket is not switched off.
	 */
	public function disabled_for( string $account_key, string $bucket ): int {
		$row = $this->store->read( $account_key, $bucket );

		return null === $row ? 0 : self::disabled_wait( $row, $this->now() );
	}

	/**
	 * Records what a successful answer showed: the quota the marketplace reported and that this method works.
	 *
	 * The quota is kept for the diagnostics panel only. The method tag is the memory behind "this method answered 200
	 * before in this install", which separates a retired method from one that was never valid.
	 *
	 * @since 2.0.29
	 *
	 * @param string   $account_key Account.
	 * @param string   $bucket      Bucket key.
	 * @param int|null $remaining   Requests left in the bucket per the marketplace's header, null when it sent none.
	 * @param string   $method_tag  Short tag of the method that answered, '' to record only the quota.
	 * @return void
	 */
	public function record_success( string $account_key, string $bucket, ?int $remaining, string $method_tag = '' ): void {
		$this->update(
			$account_key,
			$bucket,
			function ( Throttle_Row $row, float $now ) use ( $remaining, $method_tag ): array {
				$state                       = $row->state();
				$state[ self::REMAINING ]    = $remaining;
				$state[ self::REMAINING_AT ] = $now;
				if ( '' !== $method_tag && ! self::knows( $state, $method_tag ) ) {
					$state[ self::ANSWERED ][] = $method_tag;
				}

				return [ $state, $row->disabled_until(), $row->disabled_reason() ];
			}
		);
	}

	/**
	 * Tells whether a method has answered successfully before in this install.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @param string $method_tag  Tag given to `record_success()`.
	 * @return bool
	 */
	public function has_succeeded( string $account_key, string $bucket, string $method_tag ): bool {
		$row = $this->store->read( $account_key, $bucket );

		return null !== $row && self::knows( $row->state(), $method_tag );
	}

	/**
	 * Returns the quota the marketplace last reported for a bucket.
	 *
	 * @since 2.0.29
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @return int|null Null when none was recorded.
	 */
	public function remaining( string $account_key, string $bucket ): ?int {
		$row = $this->store->read( $account_key, $bucket );

		return null === $row || ! isset( $row->state()[ self::REMAINING ] ) ? null : (int) $row->state()[ self::REMAINING ];
	}

	/**
	 * Applies a change to a stored bucket with the compare-and-swap loop.
	 *
	 * @param string   $account_key Account.
	 * @param string   $bucket      Bucket key.
	 * @param callable $change      `fn( Throttle_Row $row, float $now ): array{0:array,1:?int,2:string}` returning the new state,
	 *                              switch-off time and reason.
	 * @return bool False when the swap was lost on every attempt.
	 */
	private function update( string $account_key, string $bucket, callable $change ): bool {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$now = $this->now();
			$row = $this->load( $account_key, $bucket, $this->specs[ $bucket ] ?? null, $now );

			list( $state, $disabled_until, $reason ) = $change( $row, $now );
			if ( $this->store->swap( $account_key, $bucket, $row->version(), $state, $disabled_until, $reason ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads a bucket, creating it full when it does not exist yet.
	 *
	 * @param string           $account_key Account.
	 * @param string           $bucket      Bucket key.
	 * @param Bucket_Spec|null $spec        Description, null for a key that is only switched off.
	 * @param float            $now         Current time.
	 * @return Throttle_Row
	 */
	private function load( string $account_key, string $bucket, ?Bucket_Spec $spec, float $now ): Throttle_Row {
		$row = $this->store->read( $account_key, $bucket );
		if ( null !== $row ) {
			return $row;
		}

		$fresh = [
			self::TOKENS      => null === $spec ? 0.0 : (float) $spec->burst(),
			self::REFILLED_AT => $now,
		];
		$this->store->create( $account_key, $bucket, $fresh );

		// If the store is unreachable the swap below fails and the caller waits; it is never let through unchecked.
		return $this->store->read( $account_key, $bucket ) ?? new Throttle_Row( $fresh, 0, null, '' );
	}

	/**
	 * Seconds a bucket stays switched off.
	 *
	 * @param Throttle_Row $row Stored bucket.
	 * @param float        $now Current time.
	 * @return int 0 when the bucket is not switched off.
	 */
	private static function disabled_wait( Throttle_Row $row, float $now ): int {
		$until = (float) ( $row->disabled_until() ?? 0 );

		return $until > $now ? self::seconds_for( $until - $now ) : 0;
	}

	/**
	 * Seconds a bucket is held closed by a 429.
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @param float  $now         Current time.
	 * @return int 0 when no hold is active.
	 */
	private function cooldown_wait( string $account_key, string $bucket, float $now ): int {
		$row = $this->store->read( $account_key, self::COOLDOWN_PREFIX . $bucket );

		return null === $row ? 0 : self::disabled_wait( $row, $now );
	}

	/**
	 * Empties the tokens of a bucket after a 429. Best effort: the hold, not the tokens, is what stops requests.
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket key.
	 * @return void
	 */
	private function empty_tokens( string $account_key, string $bucket ): void {
		$this->update(
			$account_key,
			$bucket,
			function ( Throttle_Row $row, float $now ): array {
				$state                      = $row->state();
				$state[ self::TOKENS ]      = 0.0;
				$state[ self::REFILLED_AT ] = $now;

				return [ $state, $row->disabled_until(), $row->disabled_reason() ];
			}
		);
	}

	/**
	 * Adds the tokens earned since the last refill, up to the capacity.
	 *
	 * @param array<string,mixed> $state State read from the store.
	 * @param Bucket_Spec         $spec  Description.
	 * @param float               $now   Current time.
	 * @return array<string,mixed> State with `tokens` and `refilled_at` set.
	 */
	private static function refilled( array $state, Bucket_Spec $spec, float $now ): array {
		$tokens = isset( $state[ self::TOKENS ] ) ? (float) $state[ self::TOKENS ] : (float) $spec->burst();
		$since  = isset( $state[ self::REFILLED_AT ] ) ? (float) $state[ self::REFILLED_AT ] : $now;
		$earned = max( 0.0, $now - $since ) * $spec->limit() / $spec->period_seconds();

		$state[ self::TOKENS ]      = min( (float) $spec->burst(), $tokens + $earned );
		$state[ self::REFILLED_AT ] = $now;

		return $state;
	}

	/**
	 * Rounds a wait up to whole seconds, never to zero.
	 *
	 * @param float $seconds Wait.
	 * @return int
	 */
	private static function seconds_for( float $seconds ): int {
		return max( 1, (int) ceil( $seconds ) );
	}

	/**
	 * Tells whether the state lists a method tag.
	 *
	 * @param array<string,mixed> $state      Bucket state.
	 * @param string              $method_tag Tag.
	 * @return bool
	 */
	private static function knows( array $state, string $method_tag ): bool {
		return in_array( $method_tag, (array) ( $state[ self::ANSWERED ] ?? [] ), true );
	}

	/**
	 * Returns the description of a bucket.
	 *
	 * @param string $bucket Bucket key.
	 * @return Bucket_Spec
	 * @throws \InvalidArgumentException When the bucket has no description.
	 */
	private function spec( string $bucket ): Bucket_Spec {
		if ( ! isset( $this->specs[ $bucket ] ) ) {
			throw new \InvalidArgumentException( 'No description for bucket ' . $bucket . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A developer message, never printed as HTML.
		}

		return $this->specs[ $bucket ];
	}

	/**
	 * Returns the current time.
	 *
	 * @return float
	 */
	private function now(): float {
		return (float) call_user_func( $this->clock );
	}
}
