---
id: s1-p4-rest-pickup
title: Abstract_Pickup_Points_Controller — pickup-search REST controller base
phase: P4 Admin/REST
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/rest-api/abstract-pickup-points-controller.php
depends_on: [s1-p1-pickup-source, s1-p4-rest-bootstrap]
contract_zones_touched: [shipping_method_id, rest]
needs_guard: no
acceptance:
  - composer phpstan green
  - GET pickup-search route returns Pickup_Point[] via Pickup_Point_Source + Pickup_Point_Filter
---

# Task

Spec §4.4. Abstract REST controller for pickup-point search (read-only): query a
`Pickup_Point_Source`, apply `Pickup_Point_Filter`, return points. Namespace/rest_base from the
concrete controller/plugin; no live string in the framework.
