<?php
/**
 * Marketplace domain — one shipment.
 *
 * @package Woodev\Framework\Marketplace\Shipments
 */

namespace Woodev\Framework\Marketplace\Shipments;

defined( 'ABSPATH' ) || exit;

/**
 * A shipment of the seller as the marketplace describes it: open (goods can be added) or handed over. The texts are for the
 * merchant, in Russian; the facts are the adapter's own small notes (the kind of cargo), kept with the shipment so that its
 * answers from stored data can use them.
 *
 * @since 2.1.28
 */
final class Shipment {
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
	private $name;

	/**
	 * @var bool
	 */
	private $is_open;

	/**
	 * @var string
	 */
	private $status_label;

	/**
	 * @var string
	 */
	private $kind_label;

	/**
	 * @var string
	 */
	private $shipping_label;

	/**
	 * Unix time; 0 when unknown.
	 *
	 * @var int
	 */
	private $created_at;

	/**
	 * Unix time; 0 when it was not handed over or the time is unknown.
	 *
	 * @var int
	 */
	private $closed_at;

	/**
	 * @var array<string,string>
	 */
	private $facts;

	/**
	 * @var bool
	 */
	private $still_changing;

	/**
	 * Describes a shipment.
	 *
	 * @since 2.1.28
	 *
	 * @param string              $id      The marketplace's id: shown to the merchant, at most 191 characters.
	 * @param string              $name    Name of the shipment; the id is shown when empty.
	 * @param bool                $is_open Whether goods can still be added.
	 * @param array<string,mixed> $args    Optional: `status_label` (the marketplace's status in Russian), `kind_label` (the kind
	 *                                     of cargo), `shipping_label` (pickup point and date), `created_at` and `closed_at`
	 *                                     (Unix times), `facts` (name => string), `still_changing` (bool: a handed-over shipment
	 *                                     whose units may still change, so every refresh reads it again; an open one is always
	 *                                     read again).
	 * @throws \InvalidArgumentException When the id is empty or too long.
	 */
	public function __construct( string $id, string $name, bool $is_open, array $args = [] ) {
		if ( '' === $id || mb_strlen( $id ) > self::MAX_ID_LENGTH ) {
			throw new \InvalidArgumentException( 'A shipment needs an id of at most ' . self::MAX_ID_LENGTH . ' characters.' );
		}

		$this->id             = $id;
		$this->name           = trim( $name );
		$this->is_open        = $is_open;
		$this->status_label   = trim( (string) ( $args['status_label'] ?? '' ) );
		$this->kind_label     = trim( (string) ( $args['kind_label'] ?? '' ) );
		$this->shipping_label = trim( (string) ( $args['shipping_label'] ?? '' ) );
		$this->created_at     = max( 0, (int) ( $args['created_at'] ?? 0 ) );
		$this->closed_at      = max( 0, (int) ( $args['closed_at'] ?? 0 ) );
		$this->facts          = array_map( 'strval', (array) ( $args['facts'] ?? [] ) );
		$this->still_changing = true === ( $args['still_changing'] ?? false );
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string The marketplace's id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string The name; empty when it has none.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string What the merchant reads for this shipment: the name, or the id.
	 */
	public function title(): string {
		return '' === $this->name ? $this->id : $this->name;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return bool Whether goods can still be added.
	 */
	public function is_open(): bool {
		return $this->is_open;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return bool Whether its units have to be read again at every refresh: it is open, or the marketplace is still working on it.
	 */
	public function is_still_changing(): bool {
		return $this->is_open || $this->still_changing;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string The marketplace's status in Russian; empty when it gave none.
	 */
	public function status_label(): string {
		return $this->status_label;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string The kind of cargo; empty when the marketplace has none.
	 */
	public function kind_label(): string {
		return $this->kind_label;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string The chosen pickup point and date; empty when nothing is chosen.
	 */
	public function shipping_label(): string {
		return $this->shipping_label;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return int Unix time of creation; 0 when unknown.
	 */
	public function created_at(): int {
		return $this->created_at;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return int Unix time of the hand-over; 0 when unknown or still open.
	 */
	public function closed_at(): int {
		return $this->closed_at;
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
