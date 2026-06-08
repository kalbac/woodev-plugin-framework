---
id: s1-p1-pickup-selection
title: Pickup_Selection — chosen-point WC session persistence (session-only, plugin-supplied key)
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
  - session key is the plugin-supplied session key — NO hardcoded key string, NO order-meta writes
---

# Task

Spec §4.1.v (CORRECTED 2026-06-06 — see below). Persist the chosen `Pickup_Point` in the
**WC session** during checkout. **Session-only.** Surface: `set(Pickup_Point)`,
`get(): ?Pickup_Point`, `clear()`.

## Session-only — do NOT persist to order meta here

> A prior attempt was rejected by the adversarial critic (`critic-s1-p1-pickup-selection`,
> 2026-06-06) and the operator. The original spec conflated two DISTINCT installed-site
> contracts that do **not** share a namespace:
>
> | Store | Yandex installed key | Source |
> |-------|---------------------|--------|
> | WC session (checkout) | `chosen_yandex_pickup_point` / `chosen_yandex_pickup_point_test` | `functions.php:316-323` |
> | Order meta | prefix `_yandex_delivery_` (decomposed `_destination_station_id`/`_address`/…) | `class-order.php:45-100` |
>
> A single composed key (`$prefix . $key`) written to BOTH stores cannot satisfy both — it was
> a real contract break. **Order-meta persistence of the chosen point is owned by the Phase-3
> order handler** (`class-shipping-order-handler.php`, spec §4.3), under the plugin's order-meta
> prefix. This task is the SESSION half only.

CRITICAL (platform-neutral rule, spec §3.2): the **session key** is supplied by the plugin —
the framework hardcodes NO contract string. The constructor takes the plugin's session key
(e.g. yandex passes `chosen_yandex_pickup_point`); store `Pickup_Point::to_array()` under it
and rebuild via `Pickup_Point::from_array()` on read. No `update_post_meta`, no
`persist_to_order`, no `restore_from_order`, no order object at all.

`order_session_meta` zone is tripped because the session key lives in that zone, but the diff
introduces no installed-site key literal (the plugin supplies it) → no guard; human one-glance
pass expected.

<!-- committed: af28c89 -->
