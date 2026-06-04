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
status surface. Admin page slugs are **built from the plugin id** (yandex `wc-yandex-orders` is
the plugin's, not the framework's) → `admin_page_slugs` grep match only, no guard.
