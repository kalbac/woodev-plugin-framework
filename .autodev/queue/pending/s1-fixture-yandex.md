---
id: s1-fixture-yandex
title: Yandex-shaped fixture + validation gate (mirrors edostavka pilot)
phase: P6 Wiring + Gate
type: fixture
touches_contract_zone: true
writes_guard: false
file_set:
  - tests/_fixtures/woodev-yandex-pilot-plugin/woodev-yandex-pilot-plugin.php
  - tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-shipping-plugin.php
  - tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-pickup-method.php
  - tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-warehouse-store.php
  - tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-map-provider.php
  - tests/unit/YandexPilotFixtureTest.php
depends_on:
  - s1-p6-plugin-wiring
  - s1-p1-wire-pickup-method
  - s1-p1-warehouse-store
  - s1-p1-map-provider-php
  - s1-p1-pickup-source
  - guard-yandex-contracts
contract_zones_touched: [shipping_method_id, option_keys, rest, order_session_meta]
needs_guard: yes
acceptance:
  - fixture loads end-to-end through the new shipping module via the v2 load path
  - test asserts yandex contract strings preserved (method ids, REST ns, warehouse table, meta prefix, session key)
  - composer test green; reuses the shared testable-resolver/WP-stub helper (no copy-paste)
---

# Task

The S1 analog of `tests/unit/EdostavkaPilotFixtureTest.php` (spec §7) — the **validation gate**
that proves the new abstraction actually fits the #1 reference plugin.

Build a yandex-shaped pilot fixture that extends the new module: a `Shipping_Method_Pickup`
subclass, a `Warehouse_Store` over a yandex-shaped table, a yandex `Map_Provider`, a
`Pickup_Point_Source`. `YandexPilotFixtureTest` asserts the fixture loads through the v2 path
AND that the yandex installed-site contract strings are preserved (`yandex_delivery_express` /
`yandex_delivery_other_day`, REST ns `wc-yandex-delivery`, warehouse table
`wc_yandex_delivery_warehouses`, order-meta prefix `_yandex_delivery_`, session key
`chosen_yandex_pickup_point`).

In-repo fixture (program-tracker "Validation deviation") — proves architecture, not live data.
REUSE: extract the shared testable-resolver + WP-stub helper the program-tracker flagged
(this is the 3rd fixture — do NOT copy the edostavka scaffolding again).
