<?php
/**
 * One unit of a marketplace order that a seller action works on.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * What the marketplace answers for, one by one ({@see Seller_Actions::posting_units()}): a piece of goods, or the whole posting
 * when the marketplace acts on postings. The id is the marketplace's and is never shown; the merchant reads the label and the
 * status label.
 *
 * @since 2.1.27
 */
final class Posting_Unit {
	/**
	 * The marketplace's id of the unit.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * What the merchant reads: the goods and their size.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * The status code the actions are offered by.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * The status as the merchant reads it.
	 *
	 * @var string
	 */
	private $status_label;

	/**
	 * Ids of the actions that exist for the unit; null for all declared ones.
	 *
	 * @var string[]|null
	 */
	private $actions;

	/**
	 * Describes a unit.
	 *
	 * @since 2.1.27
	 *
	 * @param string        $id           The marketplace's id, unique within the order.
	 * @param string        $label        The goods and their size, in the merchant's words.
	 * @param string        $status       The status code compared with {@see Seller_Action::statuses()}.
	 * @param string        $status_label The status in Russian; shown when an action is not offered in it.
	 * @param string[]|null $actions      Ids of the actions that exist for this unit (a delivery action on a unit the seller
	 *                                    delivers); an action outside the list is not shown for it at all. Null for all.
	 * @throws \InvalidArgumentException When the id is empty.
	 */
	public function __construct( string $id, string $label, string $status = '', string $status_label = '', ?array $actions = null ) {
		if ( '' === $id ) {
			throw new \InvalidArgumentException( 'A posting unit needs an id.' );
		}

		$this->id           = $id;
		$this->label        = trim( $label );
		$this->status       = $status;
		$this->status_label = trim( $status_label );
		$this->actions      = $actions;
	}

	/**
	 * Returns the marketplace's id of the unit.
	 *
	 * @since 2.1.27
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Returns what the merchant reads.
	 *
	 * @since 2.1.27
	 *
	 * @return string
	 */
	public function label(): string {
		return '' === $this->label ? __( 'Позиция', 'woodev-plugin-framework' ) : $this->label;
	}

	/**
	 * Returns the unit under another label.
	 *
	 * @since 2.1.27
	 *
	 * @param string $label Label.
	 * @return self
	 */
	public function with_label( string $label ): self {
		return new self( $this->id, $label, $this->status, $this->status_label, $this->actions );
	}

	/**
	 * Returns the status code.
	 *
	 * @since 2.1.27
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Returns the status as the merchant reads it.
	 *
	 * @since 2.1.27
	 *
	 * @return string Empty when the marketplace gave none.
	 */
	public function status_label(): string {
		return $this->status_label;
	}

	/**
	 * Tells whether an action exists for this unit.
	 *
	 * @since 2.1.27
	 *
	 * @param string $action_id Action id.
	 * @return bool
	 */
	public function has_action( string $action_id ): bool {
		return null === $this->actions || in_array( $action_id, $this->actions, true );
	}
}
