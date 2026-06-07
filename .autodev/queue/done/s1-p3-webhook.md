---
id: s1-p3-webhook
title: Abstract_Webhook_Handler — generic inbound receiver (scaffolding only)
phase: P3 Order/Tracking/Webhook
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/order/abstract-webhook-handler.php
depends_on: []
contract_zones_touched: [shipping_method_id, hooks, rest]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test: signature-verification seam rejects an unsigned/forged payload
  - documented as SCAFFOLDING — not validated against yandex (yandex is outbound-only)
---

# Task

Spec §4.3 + decision §6d. Generic inbound-webhook receiver base + signature-verification seam,
so carrier→order status sync has a home. **Scaffolding only:** yandex has NO inbound webhook
(outbound-only — see checklist §Operational Surface), so this base is NOT exercised by the
yandex fixture. Real validation happens later via edostavka's rewrite. Keep it minimal; do not
gold-plate. New endpoint/hook names are forward contracts (no existing string touched).
