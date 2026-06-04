---
id: s1-p4-rest-bootstrap
title: Shipping_REST_API — REST extension bootstrap
phase: P4 Admin/REST
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/rest-api/class-shipping-rest-api.php
depends_on: []
contract_zones_touched: [shipping_method_id, rest]
needs_guard: no
acceptance:
  - composer phpstan green
  - extends Woodev_REST_API; registers the controllers; namespace from plugin id_dasherized
---

# Task

Spec §4.4 — mirror `Woodev_Payment_Gateway_REST_API`. Bootstrap that wires the warehouses +
pickup-points controllers. REST **namespace is `$plugin->get_id_dasherized()`** (yandex
`wc-yandex-delivery` is the plugin's) — the framework introduces no live namespace literal;
`rest` grep match only, no guard.
