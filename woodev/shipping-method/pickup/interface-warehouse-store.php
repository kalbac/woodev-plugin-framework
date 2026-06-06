<?php
/**
 * Woodev Warehouse Store Interface
 *
 * The persistence seam for shipment-origin {@see Warehouse} value objects
 * (decision §6b). The framework deliberately mints **no canonical shared
 * table**: each plugin owns its storage and implements this contract over it
 * (yandex, for instance, keeps `wc_yandex_delivery_warehouses` byte-for-byte).
 *
 * Pure contract — no WooCommerce assumptions. See
 * docs-internal/platform-v2-s1-shipping-spec.md §6b.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Warehouse_Store' ) ) :

	/**
	 * Warehouse persistence contract.
	 *
	 * A minimal CRUD seam keyed by a storage row id — distinct from the carrier's
	 * own {@see Warehouse::get_id()}. Implementations decide where the data lives
	 * (a custom table, an option, an in-memory map for tests); callers depend only
	 * on this contract.
	 *
	 * @since 1.5.0
	 */
	interface Warehouse_Store {

		/**
		 * Gets a single warehouse by its storage row id.
		 *
		 * @since 1.5.0
		 *
		 * @param int $id storage row id
		 *
		 * @return Warehouse|null the warehouse, or null when no row matches
		 */
		public function get( int $id ): ?Warehouse;

		/**
		 * Gets every stored warehouse.
		 *
		 * @since 1.5.0
		 *
		 * @return Warehouse[] all stored warehouses (possibly empty)
		 */
		public function all(): array;

		/**
		 * Persists a warehouse, inserting or updating as needed.
		 *
		 * @since 1.5.0
		 *
		 * @param Warehouse $warehouse warehouse to store
		 *
		 * @return int storage row id of the saved warehouse
		 */
		public function save( Warehouse $warehouse ): int;

		/**
		 * Deletes a warehouse by its storage row id.
		 *
		 * @since 1.5.0
		 *
		 * @param int $id storage row id
		 *
		 * @return bool true when a row was deleted, false otherwise
		 */
		public function delete( int $id ): bool;
	}

endif;
