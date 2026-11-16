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
 * direct `['warehouses' => [...]]` wrappers. An item may carry a neutral `oversize` flag (the adapter maps its marketplace's own
 * word, e.g. Ozon `is_kgt`, when it fills the cache); the oversize warehouse select lists such warehouses first.
 *
 * @since 2.1.2
 */
final class Warehouse_Options {
	public const KEY_PREFIX = 'warehouses:';

	/**
	 * How long a cached list is taken for the current one: the weekly catalogue refresh ({@see \Woodev\Framework\Marketplace\Jobs\Refresh_Catalog_Scheduler::WEEK})
	 * plus a day of slack. A list older than this (the refresh keeps failing) says nothing about what the marketplace offers now.
	 *
	 * @since 2.1.8
	 */
	public const FRESH_FOR = 691200;

	/**
	 * Catalogue cache reader.
	 *
	 * @var Wpdb_Catalog_Cache
	 */
	private $cache;

	/**
	 * Clock returning a Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * The current lists kept for this request: `table|account id` to the cache write count and the ids (null when the list is
	 * not a current one). A write to any catalogue cache moves {@see Wpdb_Catalog_Cache::writes()} and drops the kept value.
	 *
	 * @var array<string,array{writes:int,ids:string[]|null}>
	 */
	private static $current = [];

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.2
	 *
	 * @param Wpdb_Catalog_Cache $cache Catalogue cache.
	 * @param callable|null      $clock `function (): int`, defaults to `time` (@since 2.1.8).
	 */
	public function __construct( Wpdb_Catalog_Cache $cache, ?callable $clock = null ) {
		$this->cache = $cache;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Forgets the lists kept for the request.
	 *
	 * @since 2.1.8
	 *
	 * @return void
	 */
	public static function forget_current(): void {
		self::$current = [];
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
	 * @param int  $account_id     Account id.
	 * @param bool $oversize_first Whether to list the warehouses flagged `oversize` first (the order is otherwise kept) and mark
	 *                             their labels, for the select of the oversize warehouse.
	 * @return array<string,string>
	 */
	public function for_account( int $account_id, bool $oversize_first = false ): array {
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
		$flagged = [];
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

			if ( ! empty( $item['oversize'] ) ) {
				$flagged[] = $id;
			}
		}

		return $oversize_first ? self::oversize_first( $options, $flagged ) : $options;
	}

	/**
	 * Tells whether a warehouse is known to be unavailable: the list is a current one and the id is not in it.
	 *
	 * A list counts only when it came from a successful refresh within {@see self::FRESH_FOR}. An absent or empty list and a stale
	 * one (the refresh keeps failing; the old rows stay readable) prove nothing, so they never count as «unavailable» and nothing is
	 * blocked or flagged because of them.
	 *
	 * @since 2.1.8
	 *
	 * @param int $account_id   Account id.
	 * @param int $warehouse_id Warehouse id, as saved.
	 * @return bool
	 */
	public function is_unavailable( int $account_id, int $warehouse_id ): bool {
		if ( $warehouse_id <= 0 ) {
			return false;
		}

		$ids = $this->current_ids( $account_id );

		return null !== $ids && ! in_array( (string) $warehouse_id, $ids, true );
	}

	/**
	 * The ids of the account's current warehouse list, read once per request until a catalogue cache write.
	 *
	 * @param int $account_id Account id.
	 * @return string[]|null Null when the list is absent, empty or stale.
	 */
	private function current_ids( int $account_id ): ?array {
		$slot   = $this->cache->table() . '|' . $account_id;
		$writes = Wpdb_Catalog_Cache::writes();

		if ( ! isset( self::$current[ $slot ] ) || self::$current[ $slot ]['writes'] !== $writes ) {
			self::$current[ $slot ] = [
				'writes' => $writes,
				'ids'    => $this->read_current_ids( $account_id ),
			];
		}

		return self::$current[ $slot ]['ids'];
	}

	/**
	 * Reads the account's warehouse list when it is a current one.
	 *
	 * @param int $account_id Account id.
	 * @return string[]|null
	 */
	private function read_current_ids( int $account_id ): ?array {
		$fetched_at = $this->cache->fetched_at( self::key_for( $account_id ) );

		if ( null === $fetched_at || (int) call_user_func( $this->clock ) - $fetched_at > self::FRESH_FOR ) {
			return null;
		}

		$ids = array_map( 'strval', array_keys( $this->for_account( $account_id ) ) );

		return [] === $ids ? null : $ids;
	}

	/**
	 * The note the account settings give a saved warehouse that the marketplace no longer offers.
	 *
	 * @since 2.1.8
	 *
	 * @return string
	 */
	public static function unavailable_note(): string {
		return __( 'Склад отключён в маркетплейсе — выберите другой', 'woodev-plugin-framework' );
	}

	/**
	 * Moves the flagged warehouses to the front, keeping the order inside both groups, and marks their labels.
	 *
	 * @param array<string,string> $options Options by warehouse id.
	 * @param string[]             $flagged Ids of the oversize warehouses.
	 * @return array<string,string>
	 */
	private static function oversize_first( array $options, array $flagged ): array {
		$first = [];
		$rest  = [];

		foreach ( $options as $id => $label ) {
			if ( in_array( (string) $id, $flagged, true ) ) {
				/* translators: %s: warehouse name. */
				$first[ $id ] = sprintf( __( '%s (КГТ)', 'woodev-plugin-framework' ), $label );
			} else {
				$rest[ $id ] = $label;
			}
		}

		return $first + $rest;
	}
}
