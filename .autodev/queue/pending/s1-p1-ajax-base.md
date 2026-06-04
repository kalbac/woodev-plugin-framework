---
id: s1-p1-ajax-base
title: Shipping AJAX base (pickup search + set-point endpoints)
phase: P1 PVZ-map
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/ajax/class-shipping-ajax.php
depends_on: [s1-p1-pickup-source, s1-p1-pickup-selection]
contract_zones_touched: [shipping_method_id, ajax_actions]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test (Brain Monkey): action names are derived from the plugin id, not hardcoded
  - nonce-checked handlers; nopriv + auth registered for the search/set endpoints
---

# Task

Spec §4.1 — the AJAX surface behind the map. Abstract base that registers nonce-protected
handlers for: pickup-point search (calls `Pickup_Point_Source::search`), set-selected-point
(calls `Pickup_Selection::set`). Mirrors the yandex `set_yandex_delivery_pickup_point` /
`get_yandex_delivery_shipment_points` family.

CRITICAL: AJAX action names are **built from the plugin id** (`wp_ajax_{plugin}_…`), so the
framework introduces NO live action-name literal. The `ajax_actions` zone is tripped by the
`wp_ajax_` grep but no installed-site action string is added → no guard; human one-glance pass.
