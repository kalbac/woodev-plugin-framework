---
id: s1-p1-warehouse-store
title: Warehouse_Store interface + abstract table-backed default
phase: P1 PVZ-map
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/pickup/interface-warehouse-store.php
  - woodev/shipping-method/pickup/class-abstract-warehouse-store.php
depends_on: [s1-p1-pickup-models]
contract_zones_touched: [shipping_method_id, db_schema]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test: a fake in-memory store implementing the interface satisfies get/all/save/delete
  - Abstract_Warehouse_Store table name is supplied by the subclass (NOT a fixed framework table)
---

# Task

Decision §6b: framework provides the `Warehouse_Store` interface + an abstract default a plugin
MAY extend — but **no canonical shared table**. Each plugin owns its storage (yandex keeps
`wc_yandex_delivery_warehouses` byte-for-byte and implements this interface over it).

`interface Warehouse_Store`: `get($id): ?Warehouse`, `all(): Warehouse[]`, `save(Warehouse): int`,
`delete($id): bool`.

`abstract Abstract_Warehouse_Store implements Warehouse_Store`: CRUD against a `$wpdb` table
whose **name + schema the concrete subclass provides** (abstract `get_table_name()` /
`get_schema()`); the framework does NOT mint a permanent table. Any `dbDelta`/`CREATE TABLE`
helper here is a NEW framework mechanism, not an existing contract → `db_schema` zone is
`auto_guardable:false` (human one-glance pass at the gate; no auto-guard).
