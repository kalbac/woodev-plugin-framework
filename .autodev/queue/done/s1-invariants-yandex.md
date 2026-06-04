---
id: s1-invariants-yandex
title: Register yandex exact-strings in INVARIANTS.md (constitution — human-only)
phase: P6 Wiring + Gate
type: invariants
touches_contract_zone: true
writes_guard: false
file_set:
  - .autodev/INVARIANTS.md
depends_on: [guard-yandex-contracts]
contract_zones_touched: [constitution]
needs_guard: human-only
acceptance:
  - yandex exact_strings added to the matching contract_zones, MACHINE-INVARIANTS JSON stays valid
  - provenance table updated; cron-payload + DB-schema remain auto_guardable:false
---

# Task

Add the yandex installed-site contract strings (from the yandex data-preservation checklist) to
the `exact_strings` arrays of the matching `contract_zones` in `.autodev/INVARIANTS.md`:
`yandex_delivery_express`, `yandex_delivery_other_day` (shipping_method_id);
`woocommerce_yandex_delivery_settings` (option_keys); `wc-yandex-delivery` (rest);
`_yandex_delivery_` + `chosen_yandex_pickup_point` (order_session_meta);
`wc_yandex_update_order`/`wc_yandex_orders_update` (cron, auto_guardable:false);
`wc_yandex_delivery_warehouses` table (db_schema, auto_guardable:false).

`.autodev/INVARIANTS.md` is a **constitution** path → the conductor NEVER auto-commits this.
A worker may draft the diff, but the **operator commits it** (human-only). Keep the
MACHINE-INVARIANTS JSON valid; update the provenance table.
