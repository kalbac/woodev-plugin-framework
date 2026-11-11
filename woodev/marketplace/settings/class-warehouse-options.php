<?php
/**
 * Cached warehouse choices per marketplace account.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Reads cached seller warehouses of an account from {@see Wpdb_Catalog_Cache} (`warehouses:{account_id}`) without
 * making remote API calls during settings or diagnostics rendering.
 *
 * Supports both `['id' => ..., 'name' => ...]` and `['warehouse_id' => ..., 'name' => ...]` list payloads, as well as
 * direct `['warehouses' => [...]]` wrappers.
 *
 * @since 2.1.2
 */
final class Warehouse_Options {
	public const KEY_PREFIX = 'warehouses:';

	/**
	 * Catalogue cache reader.
	 *
	 * @var Wpdb_Catalog_Cache
	 */
	private $cache;

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.2
	 *
	 * @param Wpdb_Catalog_Cache $cache Catalogue cache.
	 */
	public function __construct( Wpdb_Catalog_Cache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Builds the cache key for one account's warehouses.
	 *
	 * @since 2.1.2
	 *
	 * @param int $account_id Account id.
	 * @return string
	 */
	public static function key_for( int $account_id ): string {
		return self::KEY_PREFIX . $account_id;
	}

	/**
	 * Returns cached warehouse options (`warehouse_id_string => label`) for one account, or `[]` when not cached yet.
	 *
	 * @since 2.1.2
	 *
	 * @param int $account_id Account id.
	 * @return array<string,string>
	 */
	public function for_account( int $account_id ): array {
		if ( $account_id <= 0 ) {
			return [];
		}

		$payload = $this->cache->get( self::key_for( $account_id ) );
		if ( ! is_array( $payload ) ) {
			return [];
		}

		$items = isset( $payload['warehouses'] ) && is_array( $payload['warehouses'] )
			? $payload['warehouses']
			: $payload;

		$options = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$raw_id = $item['warehouse_id'] ?? ( $item['id'] ?? null );
			if ( null === $raw_id || ! is_scalar( $raw_id ) ) {
				continue;
			}

			$id = trim( (string) $raw_id );
			if ( '' === $id || '0' === $id ) {
				continue;
			}

			$name           = isset( $item['name'] ) ? trim( (string) $item['name'] ) : '';
			$options[ $id ] = '' !== $name ? $name : ( '#' . $id );
		}

		return $options;
	}
}
