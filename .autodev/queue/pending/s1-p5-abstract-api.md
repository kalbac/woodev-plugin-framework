---
id: s1-p5-abstract-api
title: Abstract_Shipping_API — base implementing Shipping_API over Woodev_API_Base
phase: P5 Rate/API bases
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/api/class-abstract-shipping-api.php
depends_on: [s1-p1-pickup-models]
contract_zones_touched: [shipping_method_id]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test (Brain Monkey): get_request/get_response plumbing; get_pickup_points maps to Pickup_Point[]
---

# Task

Spec §4.5. Abstract base implementing the existing `Shipping_API` interface on top of
`Woodev_API_Base` HTTP plumbing (request/response wiring, logging via the
`woodev_{plugin_id}_api_request_performed` action). Provides a default `get_pickup_points()`
that returns `Pickup_Point[]`; carriers implement thin subclasses with their endpoint mapping.
Extends the skeleton — does not replace `interface-shipping-api.php`.
