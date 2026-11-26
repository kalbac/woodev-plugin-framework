<?php
/**
 * Marketplace domain — the marketplace's answer for one unit.
 *
 * @package Woodev\Framework\Marketplace\Shipments
 */

namespace Woodev\Framework\Marketplace\Shipments;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the marketplace added or moved a unit, and why not.
 *
 * @since 2.1.28
 */
final class Shipment_Result {
	/**
	 * @var string
	 */
	private $unit_id;

	/**
	 * @var bool
	 */
	private $done;

	/**
	 * @var string
	 */
	private $reason;

	/**
	 * @param string $unit_id Unit.
	 * @param bool   $done    Whether it was done.
	 * @param string $reason  Refusal.
	 */
	private function __construct( string $unit_id, bool $done, string $reason ) {
		$this->unit_id = $unit_id;
		$this->done    = $done;
		$this->reason  = $reason;
	}

	/**
	 * @since 2.1.28
	 *
	 * @param string $unit_id The marketplace's id of the unit.
	 * @return self
	 */
	public static function done( string $unit_id ): self {
		return new self( $unit_id, true, '' );
	}

	/**
	 * @since 2.1.28
	 *
	 * @param string $unit_id The marketplace's id of the unit.
	 * @param string $reason  The sentence for the merchant, in Russian.
	 * @return self
	 * @throws \InvalidArgumentException When no reason is given.
	 */
	public static function refused( string $unit_id, string $reason ): self {
		if ( '' === trim( $reason ) ) {
			throw new \InvalidArgumentException( 'A refused unit needs a reason.' );
		}

		return new self( $unit_id, false, trim( $reason ) );
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function unit_id(): string {
		return $this->unit_id;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return $this->done;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Empty for a unit that was done.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
