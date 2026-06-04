---
id: s1-p1-map-provider-php
title: Map_Provider interface + registry + Leaflet default (PHP descriptor)
phase: P1 PVZ-map
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/map/interface-map-provider.php
  - woodev/shipping-method/map/class-map-provider-registry.php
  - woodev/shipping-method/map/class-leaflet-map-provider.php
depends_on: []
contract_zones_touched: [shipping_method_id]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test: registry register/get/get_default; Leaflet provider returns no API-key field
  - PHP side only describes/enqueues — no markup generation (rendering is JS, decision §6a)
---

# Task

Decision §6a (rendering axis). Thin PHP descriptor; the real interface boundary is the JS
adapter contract (see `s1-p1-map-js`).

`interface Map_Provider`: `get_id(): string`, `enqueue_assets(): void`,
`get_settings_fields(): array`, `get_js_adapter_handle(): string`, `get_localized_config(): array`.

`class Map_Provider_Registry`: `register(Map_Provider)`, `get($id): ?Map_Provider`,
`get_default(): Map_Provider`.

`class Leaflet_Map_Provider implements Map_Provider`: the framework's default, no-API-key
provider. **The Yandex provider is NOT in the framework** — it ships in the yandex plugin and
self-registers; the framework only guarantees the seam + the Leaflet fallback.
