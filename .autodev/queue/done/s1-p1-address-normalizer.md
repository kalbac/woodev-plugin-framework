---
id: s1-p1-address-normalizer
title: Address_Normalizer interface + Null default (DaData pluggable)
phase: P1 PVZ-map
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/address/interface-address-normalizer.php
  - woodev/shipping-method/address/class-null-address-normalizer.php
depends_on: []
contract_zones_touched: [shipping_method_id]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test: Null_Address_Normalizer returns input unchanged / empty suggestions
---

# Task

Spec §4.1.vii. Address normalization is pluggable, never baked into the framework.

`interface Address_Normalizer`: `suggest(string $query): array`, `normalize(string $address): array`.
`class Null_Address_Normalizer implements Address_Normalizer`: no-op default.

A DaData-backed normalizer ships in the plugin that holds a DaData token (yandex stores it in
`woocommerce_yandex_delivery_settings` / `wc_woodev_shared_settings`) — NOT in the framework.

<!-- committed: 778eae3 (worker), verified post-hoc: critic clean 0.90 + gate COMMIT -->
