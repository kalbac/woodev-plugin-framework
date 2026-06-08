---
id: s1-p1-pickup-source
title: Pickup_Point_Source interface + Pickup_Point_Filter (sourcing axis)
phase: P1 PVZ-map
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/pickup/interface-pickup-point-source.php
  - woodev/shipping-method/pickup/class-pickup-point-filter.php
depends_on: [s1-p1-pickup-models]
contract_zones_touched: [shipping_method_id]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test: filter narrows a Pickup_Point[] by type, payment_method (COD), and max_weight
  - Pickup_Point_Filter is pure (no WC, no I/O)
---

# Task

The **second axis** of decision §6a (sourcing ≠ rendering). The existing
`api/interface-shipping-api.php::get_pickup_points()` is the carrier-API source; this adds the
normalizing seam above it.

`interface Pickup_Point_Source`: `search(array $params): Pickup_Point[]` (params: `city`,
`postal_code`, `lat`, `lng`, `limit`). A carrier maps its API payload into `Pickup_Point`
objects (raw blob carries carrier specifics).

`Pickup_Point_Filter` (pure, static-friendly): filter `Pickup_Point[]` by `type`,
`payment_method` (COD support — the exact filter yandex applies), and
`max_weight`/`max_dimensions`. These are the three filters the yandex reference performs.

<!-- committed: d8026c7 -->
