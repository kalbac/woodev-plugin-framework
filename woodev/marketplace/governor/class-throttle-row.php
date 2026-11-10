<?php
/**
 * One stored governor bucket.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Governor;

defined( 'ABSPATH' ) || exit;

/**
 * What the throttle store holds for one `(account, bucket)` pair: the bucket state, the version counter that makes a
 * compare-and-swap possible, and the "switched off until" mark.
 *
 * @since 2.0.29
 */
final class Throttle_Row {
	/**
	 * Bucket state: `tokens`, `refilled_at` and the other keys the governor keeps.
	 *
	 * @var array<string,mixed>
	 */
	private $state;

	/**
	 * Version counter; every successful swap adds one.
	 *
	 * @var int
	 */
	private $version;

	/**
	 * Unix time until which the bucket's method is switched off, null when it is not.
	 *
	 * @var int|null
	 */
	private $disabled_until;

	/**
	 * Short machine-readable reason for the switch-off.
	 *
	 * @var string
	 */
	private $disabled_reason;

	/**
	 * Creates a row.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $state           Bucket state.
	 * @param int                 $version         Version counter.
	 * @param int|null            $disabled_until  Unix time, null when the bucket is not switched off.
	 * @param string              $disabled_reason Reason of the switch-off, '' for none.
	 */
	public function __construct( array $state, int $version, ?int $disabled_until, string $disabled_reason ) {
		$this->state           = $state;
		$this->version         = $version;
		$this->disabled_until  = $disabled_until;
		$this->disabled_reason = $disabled_reason;
	}

	/**
	 * Returns the bucket state.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function state(): array {
		return $this->state;
	}

	/**
	 * Returns the version counter.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Returns the Unix time the bucket's method is switched off until.
	 *
	 * @since 2.0.29
	 *
	 * @return int|null
	 */
	public function disabled_until(): ?int {
		return $this->disabled_until;
	}

	/**
	 * Returns the reason of the switch-off.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function disabled_reason(): string {
		return $this->disabled_reason;
	}
}
