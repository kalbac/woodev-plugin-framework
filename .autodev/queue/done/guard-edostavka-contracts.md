---
id: guard-edostavka-contracts
title: Write mutation-verified edostavka contract guards (shipping method id + settings option key)
type: guard
touches_contract_zone: true
writes_guard: true
file_set:
  - tests/unit/Contract/ShippingMethodIdContractTest.php
  - tests/unit/Contract/SettingsOptionKeyContractTest.php
  - tests/unit/Contract/recipes/shipping-method-id-edostavka.recipe.json
  - tests/unit/Contract/recipes/settings-option-key-edostavka.recipe.json
  - .autodev/GUARDS.md
---

# Task: first mutation-verified contract guards

Bootstrap the GUARDS registry by writing the first two mutation-verified guards for
release-blocking installed-site data contracts (edostavka data-preservation checklist):

1. Shipping method ID is exactly `edostavka`.
2. Settings option key is exactly `woocommerce_edostavka_settings`.

For each: write a unit guard under `tests/unit/Contract/` asserting the exact contract
string at its canonical source, AND emit a `mutation-recipe.json` so the conductor can
prove the guard goes RED on a contract flip. Record each in `.autodev/GUARDS.md` with
`mutation_verified: yes` and `blessed_by: pending-operator`.

Constraints: touches only NEW test files + the GUARDS registry; no production code edited.
This is a contract-zone, guard-writing task -> worker pinned to Opus.

<!-- committed: 6147853 -->
