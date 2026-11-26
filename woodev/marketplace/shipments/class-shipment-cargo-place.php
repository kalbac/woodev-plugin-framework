<?php
/**
 * Marketplace domain — a cargo place of a shipment.
 *
 * @package Woodev\Framework\Marketplace\Shipments
 */

namespace Woodev\Framework\Marketplace\Shipments;

defined( 'ABSPATH' ) || exit;

/**
 * A box or another cargo place of a shipment.
 *
 * @since 2.1.28
 */
final class Shipment_Cargo_Place {
	/**
	 * @var string
	 */
	private $id;

	/**
	 * @var string
	 */
	private $label;

	/**
	 * @since 2.1.28
	 *
	 * @param string $id    The marketplace's id.
	 * @param string $label What the merchant reads; the id when empty.
	 * @throws \InvalidArgumentException When the id is empty.
	 */
	public function __construct( string $id, string $label = '' ) {
		if ( '' === $id ) {
			throw new \InvalidArgumentException( 'A cargo place needs an id.' );
		}

		$this->id    = $id;
		$this->label = trim( $label );
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function label(): string {
		return '' === $this->label ? $this->id : $this->label;
	}
}
