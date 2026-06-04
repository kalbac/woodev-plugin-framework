<?php
/**
 * Contract guard: the yandex warehouse custom table is named exactly
 * '{$wpdb->prefix}wc_yandex_delivery_warehouses'.
 *
 * @package Woodev\Tests\Unit\Contract
 */

namespace Woodev\Tests\Unit\Contract;

use Woodev\Tests\Unit\TestCase;

/**
 * Guards the installed-site yandex warehouse TABLE-NAME data contract (second pilot).
 *
 * SCOPE: this guard asserts the table NAME only. The warehouse table SCHEMA (its 15-column
 * DDL) is `auto_guardable: false` in .autodev/INVARIANTS.md (db_schema zone) -- a column
 * diff is not mechanically mutatable, so the schema stays human-only and is NOT claimed
 * guarded here. Renaming the table, however, orphans every stored warehouse row, and the
 * name IS a literal in the canonical CREATE TABLE statement, so it is mutation-verifiable.
 *
 * Mutation-recipe: tests/unit/Contract/recipes/yandex-warehouse-table.recipe.json
 */
final class YandexWarehouseTableContractTest extends TestCase {

	/**
	 * Canonical read-only reference source declaring the warehouse table DDL.
	 *
	 * @return string
	 */
	private static function source_file(): string {
		return dirname( __DIR__, 3 )
			. '/plugins-reference/woocommerce-yandex-delivery/includes/class-lifecycle.php';
	}

	/**
	 * The warehouse table base name must be exactly 'wc_yandex_delivery_warehouses'.
	 *
	 * Decision §6b keeps this as a per-plugin table with no migration; the eventual rewrite
	 * must preserve the name byte-for-byte. Release-blocking installed-site data contract
	 * (yandex data-preservation checklist; db_schema zone, name-only guard).
	 *
	 * @return void
	 */
	public function test_warehouse_table_name_is_exactly_wc_yandex_delivery_warehouses(): void {
		$file = self::source_file();
		$this->assertFileExists( $file, 'Canonical warehouse-DDL source must exist.' );

		$source = (string) file_get_contents( $file );

		$this->assertSame(
			1,
			preg_match( "/CREATE TABLE \\{\\\$wpdb->prefix\\}(wc_yandex_delivery_warehouses)\\b/", $source, $matches ),
			'A CREATE TABLE statement for the yandex warehouse table must exist in the canonical source.'
		);
		$this->assertSame(
			'wc_yandex_delivery_warehouses',
			$matches[1],
			'Installed-site contract: warehouse table name must be exactly "wc_yandex_delivery_warehouses".'
		);
	}
}
