<?php
/**
 * The marketplace's answer to a list request cannot be trusted.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a {@see Link_Resolver} when the marketplace answers a restricted question with cards nobody asked about, or pages in a way
 * that never ends. The message is for the merchant (Russian); linking stops, nothing is written.
 *
 * @since 2.0.30
 */
final class Link_Resolve_Exception extends \RuntimeException {
	/**
	 * The marketplace ignored the filter and returned cards outside the requested list.
	 */
	public const FOREIGN_ITEMS = 'foreign_items';

	/**
	 * The cursor did not move, or the pages never ended.
	 */
	public const BROKEN_CURSOR = 'broken_cursor';

	/**
	 * Reason code.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Creates the exception.
	 *
	 * @since 2.0.30
	 *
	 * @param string $reason  One of the class constants.
	 * @param string $message Text for the merchant.
	 */
	public function __construct( string $reason, string $message ) {
		parent::__construct( $message );

		$this->reason = $reason;
	}

	/**
	 * Builds the refusal for a restricted query that came back with foreign cards.
	 *
	 * @since 2.0.30
	 *
	 * @return self
	 */
	public static function foreign_items(): self {
		return new self( self::FOREIGN_ITEMS, __( 'Маркетплейс вернул товары вне запрошенного списка', 'woodev-plugin-framework' ) );
	}

	/**
	 * Builds the refusal for a cursor that does not advance.
	 *
	 * @since 2.0.30
	 *
	 * @return self
	 */
	public static function broken_cursor(): self {
		return new self( self::BROKEN_CURSOR, __( 'Маркетплейс не закончил выдачу товаров: список повторяется. Попробуйте позже.', 'woodev-plugin-framework' ) );
	}

	/**
	 * Returns the reason code.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}
