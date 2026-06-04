---
id: s1-p1-pickup-models
title: Pickup_Point + Warehouse value objects (pure, immutable)
phase: P1 PVZ-map
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/pickup/class-pickup-point.php
  - woodev/shipping-method/pickup/class-warehouse.php
depends_on: []
contract_zones_touched: [shipping_method_id]
needs_guard: no
acceptance:
  - composer phpstan green
  - new unit test asserts Pickup_Point::from_array/to_array round-trip incl. raw escape-hatch
  - new unit test asserts Warehouse getters; both classes are WC-free (Brain Monkey only)
---

# Task

Create the two pure, immutable value objects for the PVZ abstraction (spec §4.1.i, §4.1.iii).
Namespace `Woodev\Framework\Shipping`, PSR-4, no WooCommerce calls.

`Pickup_Point`: fixed core schema (`code, type, name, address_full, address[], lat, lng,
work_hours[], payment_methods[], max_weight, max_dimensions, phone`) + `raw[]` escape hatch
(decision §6b). Typed getters, `from_array()`, `to_array()`, `JsonSerializable`.

`Warehouse`: `id, name, address, lat, lng, contact_{name,phone,email}, work_hours[], raw[]`.
Typed getters, `from_array()`, `to_array()`.

Contract zone is a **path-glob match only** (`woodev/shipping-method/**`) — these files
introduce NO installed-site method-id string. Expect a one-glance human pass, no guard needed.
