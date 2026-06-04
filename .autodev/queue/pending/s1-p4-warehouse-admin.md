---
id: s1-p4-warehouse-admin
title: Warehouse_Admin — warehouse CRUD admin UI
phase: P4 Admin/REST
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/admin/class-warehouse-admin.php
  - woodev/shipping-method/assets/js/admin/warehouse-admin.js
depends_on: [s1-p1-warehouse-store, s1-p4-admin-bootstrap]
contract_zones_touched: [shipping_method_id]
needs_guard: no
acceptance:
  - composer phpstan green
  - CRUD UI lists/creates/edits/deletes via the Warehouse_Store interface (storage-agnostic)
---

# Task

Spec §4.4. Admin UI for managing warehouses through the `Warehouse_Store` interface — works over
ANY store implementation (yandex's existing table or a plugin's `Abstract_Warehouse_Store`).
Does not assume a schema. New files; disjoint.
