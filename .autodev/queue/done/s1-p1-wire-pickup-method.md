---
id: s1-p1-wire-pickup-method
title: Wire PVZ selection into Shipping_Method_Pickup (extend existing marker)
phase: P1 PVZ-map
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/class-shipping-method-pickup.php
depends_on: [s1-p1-pickup-selection, s1-p1-pickup-source]
contract_zones_touched: [shipping_method_id]
needs_guard: no
acceptance:
  - composer phpstan green
  - existing shipping skeleton tests stay green
  - pickup method exposes a pickup-source + selection seam; remains abstract (no concrete id)
---

# Task

The skeleton's `Shipping_Method_Pickup` is an empty type marker whose docblock says it
"requires the customer to select a pickup point" but does nothing. Extend it (single-file edit,
file-disjoint) to wire the PVZ pieces from P1:

- declare the seam to a `Pickup_Point_Source` and `Pickup_Selection`,
- require a selected point for availability/validation at checkout,
- keep it ABSTRACT — concrete method id and source stay in the plugin.

Only edits `class-shipping-method-pickup.php`. Path-zone match only; introduces no concrete
`$this->id` literal → no guard.

<!-- committed: 94b39ed -->
