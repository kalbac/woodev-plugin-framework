---
id: s1-p3-tracking
title: Abstract_Tracking_Handler — tracking model + display hooks
phase: P3 Order/Tracking/Webhook
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/order/abstract-tracking-handler.php
depends_on: [s1-p3-order-handler]
contract_zones_touched: [shipping_method_id, hooks]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test: tracking events normalized into a typed structure from a Shipping_API response
---

# Task

Spec §4.3. Abstract tracking base over `Shipping_API::get_tracking()`: normalizes carrier
tracking history into a typed structure, exposes frontend/admin display hooks. Concrete carriers
implement the carrier-specific mapping. New forward hooks only — no existing contract touched.
