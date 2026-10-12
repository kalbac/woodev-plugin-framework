<?php
/**
 * Yandex-shaped pilot fixture warehouse table-name holder.
 *
 * The framework's warehouse-store scaffold (Abstract_Warehouse_Store, Warehouse,
 * Warehouse_Store) was removed by #141 — decision §17 dropped the warehouse subsystem
 * from the framework; a carrier plugin owning its own warehouse persistence code,
 * with no framework base class, is exactly the end state that decision intends. This
 * fixture keeps only what {@see \Woodev\Tests\Unit\YandexPilotFixtureTest} actually
 * asserts: the installed-site table-name contract `wc_yandex_delivery_warehouses`
 * (schema is human-only; the table is out of the v2 contract since D10, see
 * docs-internal/specs/2026-10-12-yandex-v2-plugin-design.md §11).
 *
 * @package Woodev_Yandex_Pilot_Fixture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Yandex-shaped fixture warehouse table-name holder.
 */
final class Woodev_Yandex_Pilot_Warehouse_Store {

	/** Unprefixed warehouse table name — installed-site contract preserved by the rewrite. */
	const TABLE_NAME = 'wc_yandex_delivery_warehouses';
}
