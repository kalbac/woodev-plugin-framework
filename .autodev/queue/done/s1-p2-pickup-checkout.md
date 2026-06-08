---
id: s1-p2-pickup-checkout
title: Pickup checkout handler + modal/balloon views + checkout.js
phase: P2 Checkout
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/checkout/class-pickup-checkout-handler.php
  - woodev/shipping-method/checkout/views/html-pickup-modal.php
  - woodev/shipping-method/checkout/views/html-pickup-balloon.php
  - woodev/shipping-method/assets/js/frontend/checkout.js
depends_on: [s1-p2-checkout-handler, s1-p1-pickup-selection, s1-p1-map-js, s1-p1-ajax-base]
contract_zones_touched: [shipping_method_id, hooks]
needs_guard: no
acceptance:
  - composer phpstan green
  - modal shell + balloon templates render; checkout.js opens modal on pickup-method select
  - selected point posts hidden fields and persists via Pickup_Selection
---

# Task

Spec §4.1.vi + §4.2. Specialization that wires the PVZ modal into checkout: the modal shell
(`html-pickup-modal.php`, mirrors yandex `html-modal-map.php`), the balloon template
(`html-pickup-balloon.php`), and `checkout.js` (binds the pickup-method radio → opens modal →
boots `pickup-map.js` with the active `Map_Provider` config → on select, writes hidden fields
and calls the set-point AJAX). Cohesive single change; all four files are new and disjoint.

<!-- committed: 8887ce0 (operator fix, continue-loop) -->
