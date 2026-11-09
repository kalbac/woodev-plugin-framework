<?php
/**
 * Optional marketplace features.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Names of the features a marketplace may or may not offer. The admin draws its buttons from
 * `Marketplace_Adapter::supports()`, never from the marketplace's name.
 *
 * @since 2.0.29
 */
final class Capability {
	/**
	 * The marketplace pushes notifications (Ozon yes, Wildberries no).
	 */
	public const PUSH_NOTIFICATIONS = 'push_notifications';

	/**
	 * Stock changes arrive as notifications (Ozon `STOCKS_CHANGED`, Wildberries no).
	 */
	public const INBOUND_STOCK = 'inbound_stock';

	/**
	 * An order can be cancelled from the shop (both).
	 */
	public const CANCEL_ORDER = 'cancel_order';

	/**
	 * A shipping label can be fetched (both; Ozon asynchronously, Wildberries at once).
	 */
	public const GET_LABEL = 'get_label';

	/**
	 * The shop can confirm a shipment (Ozon ship, Wildberries supply); neither is in v2.0.
	 */
	public const CONFIRM_SHIPMENT = 'confirm_shipment';

	/**
	 * Cards can be imported into WooCommerce (Ozon yes; Wildberries: decide later).
	 */
	public const IMPORT_PRODUCTS = 'import_products';

	/**
	 * The seller article can be renamed after export (Ozon offer-id update; Wildberries `vendorCode` is fixed).
	 * ASSUMPTION for Wildberries.
	 */
	public const RENAME_SKU = 'rename_sku';

	/**
	 * Lists every capability name.
	 *
	 * @since 2.0.29
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return [
			self::PUSH_NOTIFICATIONS,
			self::INBOUND_STOCK,
			self::CANCEL_ORDER,
			self::GET_LABEL,
			self::CONFIRM_SHIPMENT,
			self::IMPORT_PRODUCTS,
			self::RENAME_SKU,
		];
	}
}
