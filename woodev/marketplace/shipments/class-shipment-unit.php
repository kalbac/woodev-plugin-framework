<?php
/**
 * Marketplace domain — a unit of a shipment.
 *
 * @package Woodev\Framework\Marketplace\Shipments
 */

namespace Woodev\Framework\Marketplace\Shipments;

defined( 'ABSPATH' ) || exit;

/**
 * One piece of goods, or a whole posting where the marketplace ships postings, inside a shipment. The posting key is the one
 * the order registry holds the WooCommerce order under, so the screens can show the order.
 *
 * @since 2.1.28
 */
final class Shipment_Unit {
	/**
	 * Longest id, in characters.
	 */
	public const MAX_ID_LENGTH = 191;

	/**
	 * @var string
	 */
	private $id;

	/**
	 * @var string
	 */
	private $posting_key;

	/**
	 * @var string
	 */
	private $label;

	/**
	 * @var string
	 */
	private $status_label;

	/**
	 * @var bool
	 */
	private $movable;

	/**
	 * @var Shipment_Requirement[]
	 */
	private $requirements;

	/**
	 * @var array<string,string>
	 */
	private $facts;

	/**
	 * Describes a unit.
	 *
	 * @since 2.1.28
	 *
	 * @param string              $id          The marketplace's id of the unit, the one {@see Shipment_Candidate} and the seller
	 *                                         actions of the order use; at most 191 characters.
	 * @param string              $posting_key Key of its order in the order registry; empty when it has none in the shop.
	 * @param string              $label       The goods and their size, in the merchant's words.
	 * @param array<string,mixed> $args        Optional: `status_label` (Russian), `movable` (bool: it may be moved into another
	 *                                         open shipment), `requirements` ({@see Shipment_Requirement}[]), `facts` (name =>
	 *                                         string).
	 * @throws \InvalidArgumentException When the id is empty or too long.
	 */
	public function __construct( string $id, string $posting_key, string $label, array $args = [] ) {
		if ( '' === $id || mb_strlen( $id ) > self::MAX_ID_LENGTH ) {
			throw new \InvalidArgumentException( 'A shipment unit needs an id of at most ' . self::MAX_ID_LENGTH . ' characters.' );
		}

		$this->id           = $id;
		$this->posting_key  = $posting_key;
		$this->label        = trim( $label );
		$this->status_label = trim( (string) ( $args['status_label'] ?? '' ) );
		$this->movable      = true === ( $args['movable'] ?? false );
		$this->requirements = array_values( (array) ( $args['requirements'] ?? [] ) );
		$this->facts        = array_map( 'strval', (array) ( $args['facts'] ?? [] ) );
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
	 * @return string Empty when the unit has no order in the shop.
	 */
	public function posting_key(): string {
		return $this->posting_key;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Empty when the marketplace gave none.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Empty when the marketplace gave none.
	 */
	public function status_label(): string {
		return $this->status_label;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return bool Whether the marketplace lets it be moved into another open shipment.
	 */
	public function is_movable(): bool {
		return $this->movable;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return Shipment_Requirement[] What the marketplace asks for on the unit, met or not.
	 */
	public function requirements(): array {
		return $this->requirements;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return array<string,string> The adapter's notes.
	 */
	public function facts(): array {
		return $this->facts;
	}

	/**
	 * @since 2.1.28
	 *
	 * @param string $name Name of a note.
	 * @return string The note; empty when there is none.
	 */
	public function fact( string $name ): string {
		return $this->facts[ $name ] ?? '';
	}
}
