<?php
/**
 * The «Источник» choice of the orders list, remembered per user.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Which orders a manager's WooCommerce orders list shows: everything, the shop's own orders, every marketplace's, or one
 * marketplace's (the choice is then that marketplace's `created_via` value).
 *
 * The last choice a user made is kept in their user meta, so the list opens the way they left it; a user who never chose gets
 * {@see self::SHOP}, so marketplace orders do not mix with the shop's until they ask for them.
 *
 * @since 2.1.13
 */
final class Order_Source_Choice {
	/**
	 * Every order.
	 */
	public const ALL = 'all';

	/**
	 * The shop's own orders: those no marketplace created.
	 */
	public const SHOP = 'shop';

	/**
	 * The orders of every marketplace.
	 */
	public const MARKETPLACES = 'marketplace';

	/**
	 * What a user who never chose gets.
	 */
	public const DEFAULT_CHOICE = self::SHOP;

	/**
	 * User meta key of the remembered choice.
	 */
	public const META_KEY = 'woodev_marketplace_orders_source';

	/**
	 * Query argument the choice travels in.
	 */
	public const QUERY_ARG = 'woodev_order_source';

	/**
	 * The `created_via` values of the registered marketplaces.
	 *
	 * @var string[]
	 */
	private $created_vias;

	/**
	 * Creates the choice.
	 *
	 * @since 2.1.13
	 *
	 * @param string[] $created_vias The `created_via` values of the registered marketplaces.
	 */
	public function __construct( array $created_vias ) {
		$this->created_vias = array_values( $created_vias );
	}

	/**
	 * Returns the choice that applies to this request and remembers a new one.
	 *
	 * A requested value that is not a known choice is ignored, and so is a remembered one whose marketplace is gone (its plugin was
	 * deactivated): the list falls back to the default instead of showing nothing.
	 *
	 * @since 2.1.13
	 *
	 * @param int    $user_id   The current user.
	 * @param string $requested The value the request carries; empty when it carries none.
	 * @return string
	 */
	public function resolve( int $user_id, string $requested ): string {
		$stored = $this->stored( $user_id );

		if ( $this->is_known( $requested ) ) {
			if ( $user_id > 0 && $requested !== $stored ) {
				update_user_meta( $user_id, self::META_KEY, $requested );
			}

			return $requested;
		}

		return $this->is_known( $stored ) ? $stored : self::DEFAULT_CHOICE;
	}

	/**
	 * Tells whether a value is a choice a user can make.
	 *
	 * @since 2.1.13
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public function is_known( string $value ): bool {
		return in_array( $value, [ self::ALL, self::SHOP, self::MARKETPLACES ], true ) || in_array( $value, $this->created_vias, true );
	}

	/**
	 * Reads the remembered choice.
	 *
	 * @param int $user_id User id.
	 * @return string Empty when the user never chose.
	 */
	private function stored( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$value = get_user_meta( $user_id, self::META_KEY, true );

		return is_string( $value ) ? $value : '';
	}
}
