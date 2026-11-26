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
	 * The marketplace has a catalogue of cards: shop products are exported to it and linked with its cards, the cabinet's cards are
	 * listed, and the catalogue cache is refreshed. An adapter that reports it answers `export_gateway()`, `card_lifecycle()` and
	 * `link_resolver()`. {@see self::has_catalog()} is the question the framework asks.
	 *
	 * @since 2.1.23
	 */
	public const CATALOG = 'catalog';

	/**
	 * Cards can be imported into WooCommerce, by a {@see \Woodev\Framework\Marketplace\Flows\Card_Importer}. An adapter that
	 * reports it has a catalogue too, with or without {@see self::CATALOG}: released adapters report only this one.
	 */
	public const IMPORT_PRODUCTS = 'import_products';

	/**
	 * The seller article can be renamed after export (Ozon offer-id update; Wildberries `vendorCode` is fixed).
	 * ASSUMPTION for Wildberries.
	 */
	public const RENAME_SKU = 'rename_sku';

	/**
	 * Oversize goods go to a warehouse of their own (Ozon КГТ): the account settings offer an oversize warehouse and an oversize
	 * product without a warehouse on its link takes it.
	 */
	public const OVERSIZE_WAREHOUSE = 'oversize_warehouse';

	/**
	 * Tells whether a marketplace has a catalogue of cards.
	 *
	 * @since 2.1.23
	 *
	 * @param Marketplace_Adapter $adapter The marketplace's adapter.
	 * @return bool
	 */
	public static function has_catalog( Marketplace_Adapter $adapter ): bool {
		return $adapter->supports( self::CATALOG ) || $adapter->supports( self::IMPORT_PRODUCTS );
	}

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
			self::CATALOG,
			self::IMPORT_PRODUCTS,
			self::RENAME_SKU,
			self::OVERSIZE_WAREHOUSE,
		];
	}
}
