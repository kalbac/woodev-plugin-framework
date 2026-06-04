---
id: s1-p4-status-view
title: Complete the shipping-method system-status view (existing stub)
phase: P4 Admin/REST
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/admin/views/html-admin-shipping-method-status.php
depends_on: []
contract_zones_touched: [shipping_method_id]
needs_guard: no
acceptance:
  - the placeholder "developer did not complete this block" text is gone
  - status rows render env / debug / configured state via the method + integration
---

# Task

The skeleton ships `html-admin-shipping-method-status.php` as a placeholder that literally says
"the developer did not complete this block". Complete it: render real WC system-status rows
(environment, debug, configured/credentials state, API reachability if available) for a
`Shipping_Method`, keeping the existing `woodev_shipping_method_{id}_system_status_*` action
hook points. Single existing-file edit.
