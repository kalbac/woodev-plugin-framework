---
id: s1-p6-plugin-wiring
title: Register new shipping subsystems in Shipping_Plugin (integration)
phase: P6 Wiring + Gate
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/class-shipping-plugin.php
depends_on:
  - s1-p2-checkout-handler
  - s1-p2-pickup-checkout
  - s1-p1-map-provider-php
  - s1-p1-address-normalizer
  - s1-p1-ajax-base
  - s1-p4-admin-bootstrap
  - s1-p4-rest-bootstrap
  - s1-p3-order-handler
contract_zones_touched: [shipping_method_id, hooks]
needs_guard: no
acceptance:
  - composer phpstan green
  - existing Shipping_Plugin tests stay green
  - new accessors (map registry, address normalizer, checkout handler, admin, rest, ajax) wired
---

# Task

The integration task — single-file edit to the existing `Shipping_Plugin` to construct/register
the new subsystems (checkout handler, admin bootstrap, REST bootstrap, AJAX base, map-provider
registry with the Leaflet default registered, address normalizer). Mirrors how
`Woodev_Payment_Gateway_Plugin` instantiates its admin/handlers in its constructor.

This is the one task that edits `class-shipping-plugin.php`; it depends on the subsystems
existing so wiring compiles. Keep additive — do not disturb the existing registry methods.

<!-- committed: 105c19f -->
