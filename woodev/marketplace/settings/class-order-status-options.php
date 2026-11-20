<?php
/**
 * The choice of the WooCommerce status a marketplace order is created with.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The «Статус нового заказа» field of an account: which statuses the merchant may choose, and which one is in force while the account
 * holds none.
 *
 * The value is stored in the account's settings under {@see self::FIELD}, with the `wc-` prefix, because that is the key and the form
 * plugins already read (`$account->setting( 'default_order_status' )`) and the v1 migration already writes. Statuses that mean an order
 * is over (completed, cancelled, refunded, failed) or not an order yet (a checkout draft) are not offered: a freshly imported order is
 * either waiting for payment, on hold or in work, and the marketplace's own status moves it on from there.
 *
 * @since 2.1.13
 */
final class Order_Status_Options {
	public const FIELD = 'default_order_status';

	/**
	 * The status of a new order when nothing else is set.
	 */
	public const FALLBACK = 'wc-processing';

	/**
	 * Statuses an imported order is never created with.
	 */
	private const EXCLUDED = [ 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-checkout-draft' ];

	/**
	 * The statuses on offer as `{value, label}` pairs, in the order WooCommerce lists them.
	 *
	 * @since 2.1.13
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	public static function choices(): array {
		$choices = [];

		foreach ( (array) wc_get_order_statuses() as $status => $label ) {
			if ( ! in_array( $status, self::EXCLUDED, true ) ) {
				$choices[] = [
					'value' => (string) $status,
					'label' => (string) $label,
				];
			}
		}

		return $choices;
	}

	/**
	 * Tells whether a status may be chosen.
	 *
	 * @since 2.1.13
	 *
	 * @param string $status Status with the `wc-` prefix.
	 * @return bool
	 */
	public static function accepts( string $status ): bool {
		foreach ( self::choices() as $choice ) {
			if ( $choice['value'] === $status ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The status in force while an account holds none: the one a migration left in `woodev_{plugin id}_default_order_status`, else
	 * {@see self::FALLBACK}. A stored value that cannot be chosen (a custom status that has since been removed) counts as none.
	 *
	 * @since 2.1.13
	 *
	 * @param string $plugin_id Plugin id.
	 * @return string
	 */
	public static function shop_default( string $plugin_id ): string {
		$migrated = self::with_prefix( (string) get_option( 'woodev_' . $plugin_id . '_' . self::FIELD, '' ) );

		return self::accepts( $migrated ) ? $migrated : self::FALLBACK;
	}

	/**
	 * Adds the `wc-` prefix to a status that lacks it.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function with_prefix( string $status ): string {
		return '' === $status || 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
	}
}
