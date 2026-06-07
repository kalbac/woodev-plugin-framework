<?php
/**
 * Yandex-shaped pilot fixture warehouse store.
 *
 * Proves the optional table-backed {@see Abstract_Warehouse_Store} fits the yandex
 * reference plugin: it owns its table NAME — the installed-site contract
 * `wc_yandex_delivery_warehouses` — and the row<->object mapping, while reusing the
 * framework CRUD plumbing. The framework mints no shared table (decision §6b).
 *
 * @package Woodev_Yandex_Pilot_Fixture
 */

defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Shipping\Pickup\Abstract_Warehouse_Store;
use Woodev\Framework\Shipping\Pickup\Warehouse;

/**
 * Yandex-shaped fixture warehouse store over the yandex warehouse table.
 */
final class Woodev_Yandex_Pilot_Warehouse_Store extends Abstract_Warehouse_Store {

	/** Unprefixed warehouse table name — installed-site contract preserved by the rewrite. */
	const TABLE_NAME = 'wc_yandex_delivery_warehouses';

	/**
	 * Gets the fully-qualified (prefixed) warehouse table name.
	 *
	 * @return string
	 */
	protected function get_table_name(): string {
		return $this->wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Gets the `CREATE TABLE` statement for the yandex warehouse table.
	 *
	 * The table NAME is the installed-site contract; the schema's stability is the
	 * subclass's responsibility (the framework introduces no shared schema).
	 *
	 * @return string
	 */
	protected function get_schema(): string {
		$table = $this->get_table_name();

		return "CREATE TABLE `{$table}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			external_id VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			address VARCHAR(255) NOT NULL DEFAULT '',
			lat DECIMAL(10,7) NOT NULL DEFAULT 0,
			lng DECIMAL(10,7) NOT NULL DEFAULT 0,
			PRIMARY KEY (id)
		);";
	}

	/**
	 * Maps a warehouse to a yandex table row.
	 *
	 * @param Warehouse $warehouse Warehouse to serialize.
	 * @return array<string,mixed>
	 */
	protected function to_row( Warehouse $warehouse ): array {
		return [
			'external_id' => $warehouse->get_id(),
			'name'        => $warehouse->get_name(),
			'address'     => $warehouse->get_address(),
			'lat'         => $warehouse->get_lat(),
			'lng'         => $warehouse->get_lng(),
		];
	}

	/**
	 * Maps a yandex table row back into a warehouse value object.
	 *
	 * @param array<string,mixed> $row Raw database row.
	 * @return Warehouse
	 */
	protected function from_row( array $row ): Warehouse {
		return Warehouse::from_array(
			[
				'id'      => $row['external_id'] ?? '',
				'name'    => $row['name'] ?? '',
				'address' => $row['address'] ?? '',
				'lat'     => $row['lat'] ?? 0.0,
				'lng'     => $row['lng'] ?? 0.0,
				'raw'     => $row,
			]
		);
	}
}
