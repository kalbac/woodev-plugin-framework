---
id: guard-yandex-contracts
title: Write mutation-verified yandex contract guards (second pilot)
phase: P6 Wiring + Gate
type: guard
touches_contract_zone: true
writes_guard: true
file_set:
  - tests/unit/Contract/YandexShippingMethodIdContractTest.php
  - tests/unit/Contract/YandexSettingsOptionKeyContractTest.php
  - tests/unit/Contract/YandexRestNamespaceContractTest.php
  - tests/unit/Contract/YandexWarehouseTableContractTest.php
  - tests/unit/Contract/YandexOrderMetaPrefixContractTest.php
  - tests/unit/Contract/recipes/yandex-shipping-method-id.recipe.json
  - tests/unit/Contract/recipes/yandex-settings-option-key.recipe.json
  - tests/unit/Contract/recipes/yandex-rest-namespace.recipe.json
  - tests/unit/Contract/recipes/yandex-warehouse-table.recipe.json
  - tests/unit/Contract/recipes/yandex-order-meta-prefix.recipe.json
  - .autodev/GUARDS.md
depends_on: []
contract_zones_touched: [shipping_method_id, option_keys, rest, db_schema, order_session_meta]
needs_guard: yes
acceptance:
  - each guard asserts the exact yandex contract string at its canonical source
  - each emits a mutation-recipe.json proving the guard goes RED on a contract flip
  - GUARDS.md rows added with mutation_verified + blessed_by pending-operator
  - mutation-check.ps1 proves RED-on-flip / GREEN-on-revert for each auto_guardable guard
---

# Task

Mirror `guard-edostavka-contracts` (done) for the **yandex** second pilot. Write mutation-verified
unit guards for the release-blocking yandex installed-site contracts from
`docs-internal/migration/yandex-data-preservation-checklist.md`:

1. Shipping method IDs `yandex_delivery_express` + `yandex_delivery_other_day`.
2. Settings option key `woocommerce_yandex_delivery_settings`.
3. REST namespace `wc-yandex-delivery`.
4. Warehouse table name `wc_yandex_delivery_warehouses` (assert name only; **schema is
   `auto_guardable:false` → human-only**, do not claim the schema is guarded).
5. Order-meta prefix `_yandex_delivery_`.

For each auto-guardable contract emit a `mutation-recipe.json`. Record each in `.autodev/GUARDS.md`
with `blessed_by: pending-operator`.

NOTE: editing `.autodev/GUARDS.md` is a **constitution** path → the conductor escalates for the
operator's one-time blessing (by design — this is how a guard becomes autonomous). Contract-zone
+ guard-writing task → worker pinned to Opus. Touches only NEW test files + GUARDS.md; no
production code.

PENDING-OPERATOR inputs (checklist §): EDD download id, exact warehouse table name/columns, and
the `wc_yandex_update_order` cron payload — if not yet supplied, write the four guards that do
not need them and leave the EDD/cron guards as TODO (they are human-only anyway).
