<?php
/**
 * Marketplace domain — something a unit needs before it ships.
 *
 * @package Woodev\Framework\Marketplace\Shipments
 */

namespace Woodev\Framework\Marketplace\Shipments;

defined( 'ABSPATH' ) || exit;

/**
 * One line of the «what is missing» list: an identifier or another thing the marketplace asks for on a unit, whether it is
 * there and accepted, and whether the shipment can go without it. A blocking requirement that is not met keeps the shipment
 * from being handed over; a non-blocking one only warns.
 *
 * @since 2.1.28
 */
final class Shipment_Requirement {
	/**
	 * Filled and accepted.
	 */
	public const MET = 'met';

	/**
	 * Filled, the marketplace is checking it.
	 */
	public const WAITING = 'waiting';

	/**
	 * Filled, the marketplace refused it.
	 */
	public const REFUSED = 'refused';

	/**
	 * Not filled.
	 */
	public const MISSING = 'missing';

	private const STATES = [ self::MET, self::WAITING, self::REFUSED, self::MISSING ];

	/**
	 * @var string
	 */
	private $label;

	/**
	 * @var string
	 */
	private $state;

	/**
	 * @var bool
	 */
	private $blocking;

	/**
	 * @var string
	 */
	private $note;

	/**
	 * Describes a requirement.
	 *
	 * @since 2.1.28
	 *
	 * @param string $label    What is asked for, in Russian: «Код «Честный знак»».
	 * @param string $state    One of {@see self::MET}, {@see self::WAITING}, {@see self::REFUSED}, {@see self::MISSING}.
	 * @param bool   $blocking Whether the shipment cannot be handed over until it is met.
	 * @param string $note     Why it was refused, or where to fill it in; Russian.
	 * @throws \InvalidArgumentException When the label is empty or the state unknown.
	 */
	public function __construct( string $label, string $state, bool $blocking = true, string $note = '' ) {
		if ( '' === trim( $label ) || ! in_array( $state, self::STATES, true ) ) {
			throw new \InvalidArgumentException( 'A shipment requirement needs a label and a known state.' );
		}

		$this->label    = trim( $label );
		$this->state    = $state;
		$this->blocking = $blocking;
		$this->note     = trim( $note );
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
	 * @return string One of the state constants.
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Empty when there is nothing to add.
	 */
	public function note(): string {
		return $this->note;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return bool Whether the requirement is of the blocking level, met or not.
	 */
	public function is_blocking(): bool {
		return $this->blocking;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return bool Whether it is filled and accepted.
	 */
	public function is_met(): bool {
		return self::MET === $this->state;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return bool Whether it keeps the shipment from being handed over now.
	 */
	public function blocks(): bool {
		return $this->blocking && ! $this->is_met();
	}
}
