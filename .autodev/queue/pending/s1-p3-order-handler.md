---
id: s1-p3-order-handler
title: Shipping_Order_Handler — HPOS-safe order-meta read/write (prefix-driven)
phase: P3 Order/Tracking/Webhook
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/order/class-shipping-order-handler.php
depends_on: []
contract_zones_touched: [shipping_method_id, order_session_meta]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test (Brain Monkey): carrier-order-id / tracking-number / chosen-point round-trip
  - all keys built from get_order_meta_prefix(); no hardcoded meta literal
---

# Task

Spec §4.3. Central HPOS-safe order-meta accessor for shipping plugins: carrier order id,
tracking number, chosen point, carrier status. Keys composed from the plugin's
`get_order_meta_prefix()` (yandex `_yandex_delivery_*` is supplied by the plugin) — framework
adds no literal. `order_session_meta` zone is a grep match only; no guard.
