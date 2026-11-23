<?php
/**
 * The «Источник» choice of the orders list, remembered per user.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Which orders a manager's WooCommerce orders list shows: everything, the shop's own orders, every marketplace's, one
 * marketplace's (the choice is then that marketplace's `created_via` value) or one account's of a marketplace that has several
 * (`{created_via}:{account id}`, see {@see self::account()}).
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
	 * The choices that pick one account, of the marketplaces that offer it.
	 *
	 * @var string[]
	 */
	private $account_choices;

	/**
	 * Creates the choice.
	 *
	 * @since 2.1.13
	 *
	 * @param string[] $created_vias    The `created_via` values of the registered marketplaces.
	 * @param string[] $account_choices The {@see self::account()} choices on offer; since 2.1.16.
	 */
	public function __construct( array $created_vias, array $account_choices = [] ) {
		$this->created_vias    = array_values( $created_vias );
		$this->account_choices = array_values( $account_choices );
	}

	/**
	 * Builds the choice that picks one account of a marketplace.
	 *
	 * @since 2.1.16
	 *
	 * @param string $created_via The marketplace's `created_via`.
	 * @param int    $account_id  Account id.
	 * @return string
	 */
	public static function account( string $created_via, int $account_id ): string {
		return $created_via . ':' . $account_id;
	}

	/**
	 * Splits a choice that picks one account.
	 *
	 * @since 2.1.16
	 *
	 * @param string $choice A choice.
	 * @return array{0:string,1:int}|null The marketplace's `created_via` and the account id; null for any other choice.
	 */
	public static function parse_account( string $choice ): ?array {
		return 1 === preg_match( '/^(.+):(\d+)$/', $choice, $match ) ? [ $match[1], (int) $match[2] ] : null;
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
		return in_array( $value, [ self::ALL, self::SHOP, self::MARKETPLACES ], true ) || in_array( $value, $this->created_vias, true ) || in_array( $value, $this->account_choices, true );
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
