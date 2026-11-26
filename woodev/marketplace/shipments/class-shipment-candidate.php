<?php
/**
 * Marketplace domain — a unit of an order that could join a shipment.
 *
 * @package Woodev\Framework\Marketplace\Shipments
 */

namespace Woodev\Framework\Marketplace\Shipments;

defined( 'ABSPATH' ) || exit;

/**
 * A unit of a WooCommerce order that the merchant may put into a shipment, or the reason why it cannot go
 * ({@see Shipment_Batches::shipment_candidates()}).
 *
 * @since 2.1.28
 */
final class Shipment_Candidate {
	/**
	 * @var int
	 */
	private $order_id;

	/**
	 * @var string
	 */
	private $unit_id;

	/**
	 * @var string
	 */
	private $label;

	/**
	 * @var string
	 */
	private $refusal;

	/**
	 * @since 2.1.28
	 *
	 * @param int    $order_id WooCommerce order of the unit.
	 * @param string $unit_id  The marketplace's id of the unit.
	 * @param string $label    The goods and their size.
	 * @param string $refusal  Why it cannot join the shipment, in Russian; empty when it can.
	 * @throws \InvalidArgumentException When the order or the unit id is missing.
	 */
	public function __construct( int $order_id, string $unit_id, string $label, string $refusal = '' ) {
		if ( $order_id < 1 || '' === $unit_id ) {
			throw new \InvalidArgumentException( 'A shipment candidate needs an order and a unit id.' );
		}

		$this->order_id = $order_id;
		$this->unit_id  = $unit_id;
		$this->label    = trim( $label );
		$this->refusal  = trim( $refusal );
	}

	/**
	 * @since 2.1.28
	 *
	 * @return int
	 */
	public function order_id(): int {
		return $this->order_id;
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
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Empty when the unit can join the shipment.
	 */
	public function refusal(): string {
		return $this->refusal;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return bool
	 */
	public function is_eligible(): bool {
		return '' === $this->refusal;
	}
}
