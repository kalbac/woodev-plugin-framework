<?php
/**
 * The declaration of one seller action.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * What the adapter says about an action once ({@see Seller_Actions::seller_actions()}) and every screen reads: its name, where it
 * is offered, the statuses it is offered in, what the merchant is asked and what he confirms.
 *
 * Where it is offered ({@see self::UNIT}, {@see self::ORDER}, {@see self::BULK}, in any combination):
 *
 * - a unit: a button beside the unit, on the order screen;
 * - an order: a button of the order's row in the «Заказы» table and on the order screen; it applies to every unit of the order
 *   that takes it;
 * - bulk: an entry of the table's «Массовые действия»; it applies to every unit of the ticked orders that takes it.
 *
 * @since 2.1.27
 */
final class Seller_Action {
	/**
	 * Offered on one unit.
	 */
	public const UNIT = 'unit';

	/**
	 * Offered on one order.
	 */
	public const ORDER = 'order';

	/**
	 * Offered on the ticked orders of the table.
	 */
	public const BULK = 'bulk';

	/**
	 * Most fields an action may ask for.
	 */
	public const MAX_FIELDS = 10;

	private const TARGETS = [ self::UNIT, self::ORDER, self::BULK ];

	private const TONES = [ 'neutral', 'primary', 'danger' ];

	private const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/D';

	/**
	 * Icon of an action that names none: the neutral «run» glyph of «Заказы доставки».
	 */
	private const DEFAULT_ICON = 'controls-play';

	/**
	 * @var string
	 */
	private $id;

	/**
	 * @var string
	 */
	private $label;

	/**
	 * @var string[]
	 */
	private $targets;

	/**
	 * @var string[]
	 */
	private $statuses;

	/**
	 * @var Seller_Action_Field[]
	 */
	private $fields;

	/**
	 * @var string
	 */
	private $confirmation;

	/**
	 * @var string
	 */
	private $tone;

	/**
	 * @var string
	 */
	private $icon;

	/**
	 * @var string
	 */
	private $short_label;

	/**
	 * Declares an action.
	 *
	 * @since 2.1.27
	 *
	 * @param string              $id      Action id: lower-case letters, digits, `_` and `-`, at most 32.
	 * @param string              $label   Name of the action, translated: «Принять в работу».
	 * @param string[]            $targets Where it is offered: {@see self::UNIT}, {@see self::ORDER}, {@see self::BULK}.
	 * @param array<string,mixed> $args    Optional: `statuses` (string[], the unit statuses it is offered in; empty or absent for
	 *                                     any), `fields` ({@see Seller_Action_Field}[]), `confirmation` (the sentence the
	 *                                     merchant reads before confirming), `tone` (`neutral`, `primary` or `danger`),
	 *                                     `icon` (a dashicon name), `short_label` (for a narrow button).
	 * @throws \InvalidArgumentException When the declaration cannot work.
	 */
	public function __construct( string $id, string $label, array $targets, array $args = [] ) {
		$fields = array_values( (array) ( $args['fields'] ?? [] ) );
		$tone   = (string) ( $args['tone'] ?? 'neutral' );

		if ( 1 !== preg_match( self::ID_PATTERN, $id ) || '' === trim( $label ) ) {
			throw new \InvalidArgumentException( 'A seller action needs a slug id and a label.' );
		}

		if ( [] === $targets || [] !== array_diff( $targets, self::TARGETS ) || ! in_array( $tone, self::TONES, true ) ) {
			throw new \InvalidArgumentException( 'A seller action needs known targets and a known tone.' );
		}

		if ( count( $fields ) > self::MAX_FIELDS ) {
			throw new \InvalidArgumentException( 'A seller action asks for too many fields.' );
		}

		foreach ( $fields as $field ) {
			if ( ! $field instanceof Seller_Action_Field ) {
				throw new \InvalidArgumentException( 'A seller action field must be a Seller_Action_Field.' );
			}
		}

		$this->id           = $id;
		$this->label        = $label;
		$this->targets      = array_values( $targets );
		$this->statuses     = array_values( array_map( 'strval', (array) ( $args['statuses'] ?? [] ) ) );
		$this->fields       = $fields;
		$this->confirmation = (string) ( $args['confirmation'] ?? '' );
		$this->tone         = $tone;
		$this->icon         = (string) ( $args['icon'] ?? self::DEFAULT_ICON );
		$this->short_label  = (string) ( $args['short_label'] ?? '' );
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Tells whether the action is offered in a place.
	 *
	 * @since 2.1.27
	 *
	 * @param string $target One of {@see self::UNIT}, {@see self::ORDER}, {@see self::BULK}.
	 * @return bool
	 */
	public function is_for( string $target ): bool {
		return in_array( $target, $this->targets, true );
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string[] Unit statuses the action is offered in; empty for any.
	 */
	public function statuses(): array {
		return $this->statuses;
	}

	/**
	 * Tells whether a unit in this status can take the action.
	 *
	 * @since 2.1.27
	 *
	 * @param string $status Unit status.
	 * @return bool
	 */
	public function is_offered_in( string $status ): bool {
		return [] === $this->statuses || in_array( $status, $this->statuses, true );
	}

	/**
	 * @since 2.1.27
	 *
	 * @return Seller_Action_Field[]
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string The sentence the merchant confirms; empty for none.
	 */
	public function confirmation(): string {
		return $this->confirmation;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string `neutral`, `primary` or `danger`.
	 */
	public function tone(): string {
		return $this->tone;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string Dashicon name.
	 */
	public function icon(): string {
		return $this->icon;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string Empty when the label is used everywhere.
	 */
	public function short_label(): string {
		return $this->short_label;
	}
}
