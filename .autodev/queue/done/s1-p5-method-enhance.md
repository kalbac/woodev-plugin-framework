---
id: s1-p5-method-enhance
title: Minimal Shipping_Method enhancement (rate-cache hook + pickup seam)
phase: P5 Rate/API bases
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/class-shipping-method.php
depends_on: []
contract_zones_touched: [shipping_method_id, hooks]
needs_guard: no
acceptance:
  - composer phpstan green
  - existing shipping-method tests stay green
  - change is additive (a rate-cache filter + accessor seam); no signature break to calculate_rate
---

# Task

Spec §4.5 — KEEP MINIMAL. Single-file additive edit to the existing `Shipping_Method` base:
add a rate-cache filter hook around `calculate_shipping()`'s result, and an accessor seam so a
pickup method can reach its `Pickup_Point_Source`. Do NOT refactor the existing 85%-complete
base; only add. If this turns out to need more than one logical change → report TOO_BIG.
