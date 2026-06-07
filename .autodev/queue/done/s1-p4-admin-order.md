---
id: s1-p4-admin-order
title: Shipping_Admin_Order — order-list column + order metabox
phase: P4 Admin/REST
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/admin/class-shipping-admin-order.php
  - woodev/shipping-method/admin/views/html-admin-order-metabox.php
depends_on: [s1-p3-order-handler, s1-p4-admin-bootstrap]
contract_zones_touched: [shipping_method_id, hooks]
needs_guard: no
acceptance:
  - composer phpstan green
  - metabox shows tracking/carrier-id/chosen-point (read via Shipping_Order_Handler)
  - export/track/cancel buttons present (wired to Abstract_Shipment_Handler)
---

# Task

Spec §4.4 — mirror `Woodev_Payment_Gateway_Admin_Order`. Order-list column + order-edit metabox:
display carrier id / tracking / chosen pickup point (from `Shipping_Order_Handler`), and
export/track/cancel actions (calling the shipment handler). New files; disjoint.

<!-- committed: 47b5e1c (operator fix A/C) -->
