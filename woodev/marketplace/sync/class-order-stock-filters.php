<?php
/**
 * Who takes and gives back the stock of a marketplace order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's `woocommerce_can_reduce_order_stock` / `woocommerce_can_restore_order_stock` filters for the orders a
 * marketplace plugin creates, by the stamp written once when the order is first written:
 *
 * | Stamp | Reduce | Restore |
 * |---|---|---|
 * | `site` | WooCommerce's own rule | WooCommerce's own rule |
 * | `marketplace` | no: the marketplace's stock notification or the readback moves WC stock, an order would subtract the sale twice | no |
 * | `legacy` | no: an earlier generation of the plugin already took the sale from WC stock | only while the account is in `site` mode |
 *
 * One exception keeps a `legacy` order honest when its lines change: the order writer gives back what the old lines took and
 * marks the order ({@see self::META_STOCK_PENDING}) before the new lines take theirs. That reduction is this generation's own
 * accounting of the new lines, so it is allowed in `site` mode; otherwise a legacy order whose line was replaced would give
 * stock back and never take it again.
 *
 * Where the stamp is kept and how an order names its account is the plugin's order meta, so both are asked through callables.
 * An order without a stamp is not ours and is left to WooCommerce.
 *
 * @since 2.0.30
 */
final class Order_Stock_Filters {
	public const STAMP_SITE        = 'site';
	public const STAMP_MARKETPLACE = 'marketplace';
	public const STAMP_LEGACY      = 'legacy';

	/**
	 * Order meta the order writer sets to `1` while it swaps the lines of an order.
	 */
	public const META_STOCK_PENDING = '_woodev_mp_stock_pending';

	/**
	 * Reads an order's stamp: `function ( \WC_Order $order ): string`, one of the `STAMP_*` values or '' for an order that is
	 * not the plugin's.
	 *
	 * @var callable
	 */
	private $stamp_of;

	/**
	 * Finds the account of an order: `function ( \WC_Order $order ): ?Account`.
	 *
	 * @var callable
	 */
	private $account_of;

	/**
	 * Creates the filters.
	 *
	 * @since 2.0.30
	 *
	 * @param callable $stamp_of   `function ( \WC_Order $order ): string`, a `STAMP_*` value or ''.
	 * @param callable $account_of `function ( \WC_Order $order ): ?Account`.
	 */
	public function __construct( callable $stamp_of, callable $account_of ) {
		$this->stamp_of   = $stamp_of;
		$this->account_of = $account_of;
	}

	/**
	 * Hooks both filters.
	 *
	 * @since 2.0.30
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_can_reduce_order_stock', [ $this, 'can_reduce' ], 10, 2 );
		add_filter( 'woocommerce_can_restore_order_stock', [ $this, 'can_restore' ], 10, 2 );
	}

	/**
	 * Decides whether WooCommerce may take an order's stock.
	 *
	 * @since 2.0.30
	 *
	 * @param mixed $allowed WooCommerce's answer so far.
	 * @param mixed $order   The order.
	 * @return bool
	 */
	public function can_reduce( $allowed, $order ): bool {
		if ( ! $order instanceof \WC_Order ) {
			return (bool) $allowed;
		}

		switch ( $this->stamp( $order ) ) {
			case self::STAMP_MARKETPLACE:
				return false;
			case self::STAMP_LEGACY:
				return (bool) $allowed && self::swapping_lines( $order ) && $this->site_mode( $order );
		}

		return (bool) $allowed;
	}

	/**
	 * Decides whether WooCommerce may give an order's stock back.
	 *
	 * @since 2.0.30
	 *
	 * @param mixed $allowed WooCommerce's answer so far.
	 * @param mixed $order   The order.
	 * @return bool
	 */
	public function can_restore( $allowed, $order ): bool {
		if ( ! $order instanceof \WC_Order ) {
			return (bool) $allowed;
		}

		switch ( $this->stamp( $order ) ) {
			case self::STAMP_MARKETPLACE:
				return false;
			case self::STAMP_LEGACY:
				return (bool) $allowed && $this->site_mode( $order );
		}

		return (bool) $allowed;
	}

	/**
	 * Reads the order's stamp.
	 *
	 * @param \WC_Order $order The order.
	 * @return string
	 */
	private function stamp( \WC_Order $order ): string {
		return (string) call_user_func( $this->stamp_of, $order );
	}

	/**
	 * Tells whether the order writer is in the middle of swapping the order's lines.
	 *
	 * @param \WC_Order $order The order.
	 * @return bool
	 */
	private static function swapping_lines( \WC_Order $order ): bool {
		return '1' === (string) $order->get_meta( self::META_STOCK_PENDING );
	}

	/**
	 * Tells whether the order's account is in `site` mode now. An order whose account is gone keeps WooCommerce's answer.
	 *
	 * @param \WC_Order $order The order.
	 * @return bool
	 */
	private function site_mode( \WC_Order $order ): bool {
		$account = call_user_func( $this->account_of, $order );

		return ! $account instanceof Account || Stock_Mode::is_site( $account );
	}
}
