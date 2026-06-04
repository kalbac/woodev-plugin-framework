---
id: s1-p1-pickup-selection
title: Pickup_Selection — session + order-meta persistence (prefix-driven)
phase: P1 PVZ-map
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/pickup/class-pickup-selection.php
depends_on: [s1-p1-pickup-models]
contract_zones_touched: [shipping_method_id, order_session_meta]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test (Brain Monkey): set/get/clear round-trip via WC session mock
  - meta key is built from the plugin's get_order_meta_prefix() — NO hardcoded key string
---

# Task

Spec §4.1.v. Persist the chosen `Pickup_Point` in **WC session** during checkout and **order
meta** after order. Surface: `set(Pickup_Point)`, `get(): ?Pickup_Point`, `clear()`,
`persist_to_order(WC_Order)`, `restore_from_order(WC_Order): ?Pickup_Point`.

CRITICAL (platform-neutral rule, spec §3.2): the meta/session key is composed from the
**plugin-supplied** `get_order_meta_prefix()` — the framework hardcodes NO contract string
(yandex's `_yandex_delivery_destination_station_*` comes from the yandex plugin's prefix).
HPOS-safe via `Woodev_Order_Compatibility`.

`order_session_meta` zone is tripped by the `update_post_meta`/session grep, but the diff
introduces no installed-site key literal → no guard; human one-glance pass expected.
