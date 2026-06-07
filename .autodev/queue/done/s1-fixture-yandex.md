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
  - s1-test-scaffold-extract
contract_zones_touched: [shipping_method_id, option_keys, rest, order_session_meta]
needs_guard: yes
acceptance:
  - fixture loads end-to-end through the new shipping module via the v2 load path
  - test asserts yandex contract strings preserved (method ids, REST ns, warehouse table, meta prefix, session key)
  - composer test green; CONSUMES the shared scaffold from s1-test-scaffold-extract (no copy-paste)
---

# Task

The S1 analog of `tests/unit/EdostavkaPilotFixtureTest.php` (spec §7) — the **validation gate**
that proves the new abstraction actually fits the #1 reference plugin.

Build a yandex-shaped pilot fixture that extends the new module: a `Shipping_Method_Pickup`
subclass, a `Warehouse_Store` over a yandex-shaped table, a yandex `Map_Provider`, a
`Pickup_Point_Source`. `YandexPilotFixtureTest` asserts the fixture loads through the v2 path
AND that the yandex installed-site contract strings are preserved.

## Re-scope note (operator, 2026-06-07) — approved TOO_BIG split into Task A + Task B
This is **Task B**. The shared test scaffold is now extracted by the precursor
`s1-test-scaffold-extract` (Task A, in `depends_on`). **CONSUME the shared scaffold**
(`tests/unit/Support/Pilot_Testable_Framework_Resolver` + the `Pilot_Fixture_WP_Stubs` trait,
namespace `Woodev\Tests\Unit\Support`) — do NOT copy-paste the resolver/WP-stubs into the yandex
test, and do NOT add scaffold files to this file_set (they were committed by Task A).

## Contract strings to assert (all already mutation-verified + blessed in `.autodev/GUARDS.md`)
- `shipping_method_id` → `yandex_delivery_express`, `yandex_delivery_other_day`
- `option_keys` → `woocommerce_yandex_delivery_settings`
- `rest` namespace → **`yandex-delivery`** — NOTE: the original prose said `wc-yandex-delivery`,
  which is STALE/WRONG. Per `.autodev/GUARDS.md` ("Yandex REST namespace contract — RESOLVED"),
  the live value is `yandex-delivery` = `$plugin->get_id_dasherized()` of id `yandex_delivery`.
  Assert `yandex-delivery`.
- `order_session_meta` → `_yandex_delivery_`, `chosen_yandex_pickup_point`
- `db_schema` (table NAME only) → `wc_yandex_delivery_warehouses`

In-repo fixture (program-tracker "Validation deviation") — proves architecture, not live data.
Mirror the edostavka fixture's loader-definition + include-callback shape, but the yandex plugin
exposes TWO method ids plus the warehouse-store / map-provider / pickup-source wiring.

<!-- committed: 7a21e7d (operator one-glance; critic over-strict on test-fixture fidelity) -->
