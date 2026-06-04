---
id: s1-p3-shipment
title: Abstract_Shipment_Handler — create/cancel/export with retry
phase: P3 Order/Tracking/Webhook
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/order/abstract-shipment-handler.php
depends_on: [s1-p3-order-handler]
contract_zones_touched: [shipping_method_id, hooks, background_jobs]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test (Brain Monkey): export calls Shipping_API::create_order, persists carrier id via order-handler
  - failed export retried via Woodev_Background_Job_Handler
---

# Task

Spec §4.3. Abstract shipment base over `Shipping_API::create_order()/cancel_order()`: export an
order to the carrier, store the returned carrier id (via `Shipping_Order_Handler`), cancel,
retry on failure through `Woodev_Background_Job_Handler`. `background_jobs` zone trips the grep;
job ID is built from the plugin id (no live literal) → no guard.
