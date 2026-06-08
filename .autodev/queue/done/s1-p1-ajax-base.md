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

CRITICAL: AJAX action names are installed-site contracts and are NOT mechanically derivable.
The PLUGIN SUPPLIES the exact action-name map (logical endpoint -> real action string); the
framework hardcodes/derives none. The `ajax_actions` zone is tripped by the `wp_ajax_` grep but
no installed-site action string is added by the framework -> no guard; human one-glance pass.

## Re-scope note (operator, 2026-06-06) — sent back from critic-s1-p1-ajax-base (critic CORRECT)
The prior attempt DERIVED action names as `{plugin_id}_get_pickup_points` etc. That is wrong:
yandex's live contract actions are `get_yandex_delivery_shipment_points`,
`set_yandex_delivery_pickup_point`, `get_yandex_delivery_location_detect`,
`set_yandex_delivery_time_interval` (.autodev/INVARIANTS.md ajax_actions); the method ids are
`yandex_delivery_express`/`_other_day` — a derived `yandex_delivery_get_pickup_points` matches
NONE of them, so every live request 404s. FIX (same root as order-handler): take the action
names as a plugin-supplied map; derive nothing. SECOND bug: the set-point handler required
`$_POST['point']` as an array, but the shipped JS (assets pickup-map.js ~L226-227) posts
`point_id` + flattened `point.meta`. Align the handler to the EXISTING shipped-JS payload shape;
do not invent a new one.

<!-- committed: 85a99cc (operator fix A) -->
