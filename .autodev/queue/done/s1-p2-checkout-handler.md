---
id: s1-p2-checkout-handler
title: Checkout_Handler — field injection, posted-data, validation orchestration
phase: P2 Checkout
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/checkout/class-checkout-handler.php
depends_on: [s1-p2-checkout-fields]
contract_zones_touched: [shipping_method_id, order_session_meta, hooks]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test (Brain Monkey): posted data flows through sanitize→validate→save; invalid input blocks
  - HPOS-safe save via Woodev_Order_Compatibility
---

# Task

Spec §4.2 — the checkout orchestration backbone. Injects `Checkout_Fields` into the WC checkout,
handles posted data (sanitize + validate), and saves to the order (HPOS-safe). Fires the
module's `handle_*` hook callbacks. New framework hooks introduced here are FORWARD contracts
(not renames) — `hooks`/`order_session_meta` zones trip the grep but break no existing
installed-site string → no guard; human one-glance pass to bless new public hook names.
