---
id: s1-p3-order-handler
title: Shipping_Order_Handler — HPOS-safe order-meta read/write (plugin-supplied key map)
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
  - unit test (Brain Monkey) - read/write round-trip driven by a plugin-supplied key map
  - NO hardcoded meta key or suffix at all - every key comes from the plugin's map
---

# Task

Spec §4.3 (CORRECTED 2026-06-06 — see below). Central HPOS-safe order-meta accessor for
shipping plugins.

## No hardcoded meta-key suffixes — plugin supplies the FULL key map

> A prior attempt was rejected by the adversarial critic (`critic-s1-p3-order-handler`,
> 2026-06-06) and the operator. It hardcoded neutral suffixes (`chosen_point`,
> `carrier_order_id`, `tracking_number`, `carrier_status`) and composed them onto the plugin
> prefix. But every carrier's order-meta keys are DISTINCT installed-site contracts and do not
> follow one neutral scheme:
>
> | Plugin | Real order-meta keys |
> |--------|----------------------|
> | edostavka | `cdek_order_id`, `tracking_code`, `status` (under `_wc_edostavka_`) |
> | yandex | decomposed: `_yandex_delivery_destination_station_id`, `_…_address`, `_…_request_id`, … |
>
> A neutral `_yandex_delivery_chosen_point` / `_wc_edostavka_carrier_order_id` matches NEITHER
> and would orphan live order data on installed sites.

**Design:** the handler takes an explicit **map** from the plugin — logical field → real meta
key — and reads/writes ONLY those keys via `Woodev_Order_Compatibility` (HPOS-safe). It
hardcodes no prefix, no suffix, no key. Example a plugin passes:
`['carrier_order_id' => 'cdek_order_id', 'tracking_number' => 'tracking_code', 'status' => 'status']`.
Surface (suggested): `get(WC_Order, $logical): mixed`, `set(WC_Order, $logical, $value): void`,
constructed with the key map. Do NOT add a chosen-point/session concern here (that is
`Pickup_Selection`, session-only, §4.1.v).

`order_session_meta` zone is a grep match only; the diff introduces no installed-site key
literal (the plugin supplies the map) → no guard; human one-glance pass expected.
