---
id: s1-p4-admin-bootstrap
title: Shipping_Admin — admin suite bootstrap
phase: P4 Admin/REST
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/admin/class-shipping-admin.php
depends_on: []
contract_zones_touched: [shipping_method_id, admin_page_slugs]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test (Brain Monkey): admin handlers registered on admin_init; page slug from plugin id
---

# Task

Spec §4.4 — mirror payment-gateway's admin bootstrap (`Woodev_Payment_Gateway_Admin_*` wiring).
Instantiates and registers the shipping admin handlers (order, warehouse) and the settings/
status surface. Admin page slugs are installed-site contracts SUPPLIED BY THE PLUGIN as explicit
values; the framework hardcodes/derives none. `admin_page_slugs` grep match only, no guard.

## Re-scope note (operator, 2026-06-06) — sent back from critic-s1-p4-admin-bootstrap (critic CORRECT)
The prior attempt DERIVED the slug by dasherizing the plugin id -> `wc-edostavka-orders`. That is
wrong: the live slugs are NOT one convention — edostavka uses `wc_edostavka_orders` (underscores),
yandex uses `wc-yandex-orders` (dashes) (.autodev/INVARIANTS.md admin_page_slugs). A derived
`wc-edostavka-orders` breaks edostavka's bookmarked admin URL. FIX (same root as order-handler /
ajax-base): the plugin SUPPLIES its exact admin slug(s) as explicit values; derive nothing.

<!-- committed: 4f52e66 -->
