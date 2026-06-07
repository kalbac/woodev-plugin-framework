---
id: s1-p4-rest-warehouses
title: Abstract_Warehouses_Controller — warehouses CRUD REST controller base
phase: P4 Admin/REST
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/rest-api/abstract-warehouses-controller.php
depends_on: [s1-p1-warehouse-store, s1-p4-rest-bootstrap]
contract_zones_touched: [shipping_method_id, rest]
needs_guard: no
acceptance:
  - composer phpstan green
  - GET/POST /{rest_base} and GET/PUT/DELETE /{rest_base}/(?P<id>[\w-]+) over Warehouse_Store
---

# Task

Spec §4.4 — mirror yandex `Warehouses_Rest_Api`. Abstract controller exposing warehouse CRUD
over the `Warehouse_Store` interface. Routes `/{rest_base}` and `/{rest_base}/(?P<id>[\w-]+)`
(yandex `warehouses`). `rest_base` + namespace supplied by the concrete controller/plugin.
