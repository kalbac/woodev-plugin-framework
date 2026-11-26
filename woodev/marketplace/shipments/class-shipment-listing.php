<?php
/**
 * Marketplace domain — one page of the shipments list.
 *
 * @package Woodev\Framework\Marketplace\Shipments
 */

namespace Woodev\Framework\Marketplace\Shipments;

defined( 'ABSPATH' ) || exit;

/**
 * What {@see Shipment_Batches::list_shipments()} answers: the shipments of one page and where the next page starts.
 *
 * @since 2.1.28
 */
final class Shipment_Listing {
	/**
	 * @var Shipment[]
	 */
	private $shipments;

	/**
	 * @var string
	 */
	private $next_cursor;

	/**
	 * @since 2.1.28
	 *
	 * @param Shipment[] $shipments   Shipments of the page.
	 * @param string     $next_cursor Cursor of the next page; empty after the last one.
	 */
	public function __construct( array $shipments, string $next_cursor = '' ) {
		$this->shipments   = array_values( $shipments );
		$this->next_cursor = $next_cursor;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return Shipment[]
	 */
	public function shipments(): array {
		return $this->shipments;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Empty after the last page.
	 */
	public function next_cursor(): string {
		return $this->next_cursor;
	}
}
