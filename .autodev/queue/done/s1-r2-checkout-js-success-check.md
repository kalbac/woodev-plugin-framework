---
id: s1-r2-checkout-js-success-check
title: Pickup map JS must honor the AJAX success flag (no false selection)
phase: P6 Wiring + Gate (remediation)
type: build
touches_contract_zone: false
writes_guard: false
file_set:
  - woodev/shipping-method/assets/js/frontend/pickup-map.js
  - woodev/shipping-method/assets/js/frontend/checkout.js
depends_on: []
needs_guard: no
acceptance:
  - selecting a point only commits (updates the hidden field + closes the modal + notifies onSelect) when the set-point AJAX actually SUCCEEDED
  - a wp_send_json_error() response (HTTP 200) is treated as a FAILURE, not a success
  - search treats a wp_send_json_error() response as an error, not an empty result set
---

# Task

Remediation from the 2026-06-07 holistic GPT-5.5 integration review
(`docs-internal/reviews/s1-holistic-integration-review-2026-06-07.md`).

`wp_send_json_success()` / `wp_send_json_error()` BOTH return HTTP 200, so jQuery `$.post()` resolves
in both cases. The pickup map JS currently treats any resolved response as success:
- `persistSelection()` / `handleSelect()` (pickup-map.js ~L199-232): on a `wp_send_json_error()`
  from `Shipping_AJAX::handle_set()`, the code still updates the hidden field, closes the modal, and
  notifies `onSelect` -- committing a selection the server rejected and stored nothing for.
- search (`fetchPoints`/`normalizeResponse`): a `wp_send_json_error()` is silently coerced to an
  empty point list instead of surfacing the error.

## Fix
Inspect the resolved response envelope and branch on `response.success` (the WP AJAX shape is
`{ success: bool, data: ... }`):
- set-point: only commit (hidden field + close modal + onSelect/select handlers) when `success === true`;
  otherwise route through the existing error handler (`handleError`) and do NOT mutate selection state.
- search: when `success === false`, treat it as an error (handleError), not as an empty result set.
Keep it framework-default-safe: if a response lacks the envelope (bare array, already handled by
`normalizeResponse`) keep current behavior. JS only; no PHP, no contract strings.

<!-- committed: 07fa015 -->
