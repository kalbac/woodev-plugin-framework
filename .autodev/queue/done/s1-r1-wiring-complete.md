---
id: s1-r1-wiring-complete
title: Complete Shipping_Plugin wiring + session->order pickup handoff
phase: P6 Wiring + Gate (remediation)
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/class-shipping-plugin.php
  - woodev/shipping-method/checkout/class-checkout-handler.php
  - woodev/shipping-method/checkout/class-pickup-checkout-handler.php
  - woodev/shipping-method/order/class-shipping-order-handler.php
depends_on: []
contract_zones_touched: [shipping_method_id, hooks, order_session_meta]
needs_guard: no
acceptance:
  - composer check green; existing Shipping_Plugin + pilot-fixture tests stay green
  - Shipping_Plugin::includes() loads EVERY committed S1 subsystem class file (so host plugins + the base can reference them without fataling)
  - the null-guarded subsystem accessors are actually CALLED in the lifecycle (ajax/checkout/admin/webhook registered when the host provides them)
  - a pickup order's chosen point flows from WC session (Pickup_Selection) to order meta via Shipping_Order_Handler
---

# Task

Remediation from the 2026-06-07 holistic GPT-5.5 integration review
(`docs-internal/reviews/s1-holistic-integration-review-2026-06-07.md`). p6-plugin-wiring (105c19f)
added the accessors but did NOT finish the wiring: `includes()` omits most subsystem files and
`add_hooks()`/constructor never call `get_checkout_handler()` / `get_ajax_handler()` /
`get_shipping_admin()`, so checkout/ajax/admin/webhook/shipment/tracking are INERT, and the chosen
pickup point never reaches order meta. Complete the wiring. Keep the base platform-neutral and the
base-vs-concrete contract intact (accessors return null in the base; host plugins override them).

## 1. includes() completeness (class-shipping-plugin.php)
Add `require_once` for EVERY committed S1 subsystem class file that `includes()` currently omits:
pickup models/source/selection (`pickup/`), AJAX base (`ajax/`), checkout fields + handler +
pickup-checkout-handler (`checkout/`), order handler + abstract shipment/tracking/webhook handlers
(`order/`), admin bootstrap + admin-order + warehouse-admin (`admin/`), the pickup-points REST
controller (`rest-api/abstract-pickup-points-controller.php`), and the warehouse store interface +
abstract + `Warehouse` model (`pickup/`). Mirror the existing require_once block style.
**EXCLUDE `rest-api/abstract-warehouses-controller.php`** — that task is DEFERRED (React rework) and
is NOT committed; requiring it would fatal. (Verify against the committed tree before adding a require.)

## 2. Lifecycle registration (class-shipping-plugin.php)
In the constructor/`add_hooks()` flow, call the EXISTING null-guarded accessors and register each
subsystem the host supplies (each accessor returns null in the base, so null-guard every call):
- `$this->get_ajax_handler()?->register();`
- checkout: register the checkout handler's WC hooks (see step 3 for its new `register()`):
  `$this->get_checkout_handler()?->register();`
- admin, only in admin context: `$admin = $this->get_shipping_admin(); if ( null !== $admin ) { $admin->register_handlers(); $admin->register_pages(); }`
- webhook: add a null-default accessor `get_webhook_handler(): ?Order\Abstract_Webhook_Handler { return null; }`
  and call `$this->get_webhook_handler()?->register();` (its `register()` wires `register_route()` etc.)
- REST: confirm `init_rest_api_handler()` is actually invoked by the base lifecycle; if not, wire it.
Shipment/tracking handlers are dependencies of the admin-order handler (constructed by the host's
Shipping_Admin), not independently registered — do NOT force-register them.

## 3. Checkout handler self-registration (class-checkout-handler.php + class-pickup-checkout-handler.php)
`Checkout_Handler` exposes `inject()/sanitize_posted_data()/validate()/save()/process()/` and the
pickup subclass adds `enqueue()/render()`, but nothing hooks them. Add a `public function register(): void`
(base wires field injection + posted-data processing/save into the WC checkout hooks; the pickup
subclass extends it to also wire `enqueue` + `render`). Use the SAME plugin-supplied hook prefix /
field contracts already in those classes; introduce NO new installed-site string.

## 4. session -> order handoff (class-pickup-checkout-handler.php + class-shipping-order-handler.php)
On checkout/order save for a pickup order, read the chosen point from `Pickup_Selection` (WC session)
and persist it to order meta THROUGH `Shipping_Order_Handler` (the plugin-supplied logical-field ->
real-meta-key map) -- do NOT write raw under the field id, and do NOT derive any meta key. If
`Shipping_Order_Handler` lacks a suitable "store chosen point" method, add one that uses its existing
plugin-supplied key map. The session key + meta prefix are blessed contracts (`order_session_meta`);
preserve them byte-for-byte.

## Notes
- `order_session_meta` + `shipping_method_id` zones are blessed/autonomous; `hooks` trips on new
  additive forward hooks (one-glance). Introduce NO derived/hardcoded installed-site contract string.
- If completing this needs >1 logical change beyond this file_set, STOP and report TOO_BIG with a split.

<!-- committed: 93a5be5 (operator fix-via-loop; 4 critic-caught bugs fixed, 5th pass clean) -->
