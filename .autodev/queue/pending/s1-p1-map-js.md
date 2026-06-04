---
id: s1-p1-map-js
title: pickup-map.js core (MapAdapter contract) + Leaflet adapter + css
phase: P1 PVZ-map
type: build
touches_contract_zone: false
writes_guard: false
file_set:
  - woodev/shipping-method/assets/js/frontend/pickup-map.js
  - woodev/shipping-method/assets/js/frontend/map-adapter-leaflet.js
  - woodev/shipping-method/assets/css/frontend/pickup-map.css
depends_on: []
contract_zones_touched: []
needs_guard: no
acceptance:
  - eslint/jshint clean if configured; otherwise syntactically valid ES5/ES6 per repo convention
  - pickup-map.js defines and documents the MapAdapter contract; Leaflet adapter implements it
---

# Task

Decision §6a — the JS side is the actual provider boundary. Provider-agnostic core +
the default Leaflet adapter (one logical change: "ship the JS map core with its default adapter").

`pickup-map.js` (core): orchestrates fetch-points (AJAX) → hand to the active adapter →
relay `select` → call back into the selection AJAX. Documents the **MapAdapter contract**:
`init(containerEl, config) -> Promise`, `setPoints(points)`, `on('select', cb)`,
`filter(predicateFn)`, `destroy()`.

`map-adapter-leaflet.js`: implements `MapAdapter` over Leaflet (markers + popup balloon).
A Yandex adapter implementing the same contract ships in the yandex plugin (not here).

`pickup-map.css`: modal/map layout. No contract zones (assets only).
