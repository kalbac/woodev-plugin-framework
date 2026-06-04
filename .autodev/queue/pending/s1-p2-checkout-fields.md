---
id: s1-p2-checkout-fields
title: Checkout_Fields — custom checkout field definitions (pure)
phase: P2 Checkout
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/checkout/class-checkout-fields.php
depends_on: []
contract_zones_touched: [shipping_method_id]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test: field definitions are returned as a normalized array; no WC I/O in the definer
---

# Task

Spec §4.2. Declarative custom checkout-field definitions (id, type, label, required,
sanitize callback, validate callback). Pure definition object consumed by the checkout handler.
No hardcoded field-name contract strings (plugin supplies its field names, cf. yandex
`yandex_pickup_point` etc.).
