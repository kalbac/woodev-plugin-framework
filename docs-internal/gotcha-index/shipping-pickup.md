# Gotcha index — [shipping/pickup] Pickup point picker / ymaps

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [shipping/pickup] **A `Pickup_Handler` built without its plugin (the 14th positional arg) SILENTLY addresses the carrier by a DOM-read place name instead of the locality key — and gives the source no record and no resolved identity.** → [a-pickup-handler-built-without-its-plugin-silently-addresses-by-name](../gotchas/a-pickup-handler-built-without-its-plugin-silently-addresses-by-name.md) (s113)
- [shipping/pickup] **A restore tied to a server confirmation looks like a render artefact — settle "who did this" with a timestamped ledger AND a control, never by watching.** → [a-restore-tied-to-a-server-confirmation-looks-like-a-render-artefact](../gotchas/a-restore-tied-to-a-server-confirmation-looks-like-a-render-artefact.md) (s77)
- [shipping/pickup] **Two hook registrations in a reference can mean two OPTIONS, not two outputs.** → [two-hook-registrations-can-mean-two-options-not-two-outputs](../gotchas/two-hook-registrations-can-mean-two-options-not-two-outputs.md) (s73)
- [shipping/pickup] **A capability flag that removes a whole UI layer silences every branch that REPORTED through it.** → [a-capability-flag-that-removes-a-ui-layer-silences-every-branch-that-reported-through-it](../gotchas/a-capability-flag-that-removes-a-ui-layer-silences-every-branch-that-reported-through-it.md) (s66)
- [shipping/pickup] **ymaps camera moves are asynchronous — losing the `setBounds()` promise breaks two different things.** → [ymaps-camera-moves-are-async](../gotchas/ymaps-camera-moves-are-async.md) (s46, extended s47)
- [shipping/pickup] **Draw-then-move parks a ymaps overlay off screen — and `setBounds()` starts its camera action LATE.** → [ymaps-draw-then-move-parks-the-overlay](../gotchas/ymaps-draw-then-move-parks-the-overlay.md) (s52)
- [shipping/pickup] **an ObjectManager layout gets PLAIN properties, a Placemark layout gets a data manager.** → [ymaps-objectmanager-properties-are-plain](../gotchas/ymaps-objectmanager-properties-are-plain.md) (s49)
- [shipping/pickup] **A theme's `button { display: none !important }` hid every button inside the modal, close included.** → [hostile-theme-button-display-none-needs-important](../gotchas/hostile-theme-button-display-none-needs-important.md) (s50, extended s51, s54)
- [shipping/pickup] **Two mobile-only defects, both invisible without an actual narrow viewport.** → [mobile-inline-min-width-and-floating-control-stacking](../gotchas/mobile-inline-min-width-and-floating-control-stacking.md) (s50)
- [shipping/pickup] **`setAnchor()` re-sorts the list body but never opens it — a stale card survived an address pick.** → [setanchor-resorts-but-never-shows-the-sidebar](../gotchas/setanchor-resorts-but-never-shows-the-sidebar.md) (s50)
- [shipping/pickup] **`ObjectManager.setFilter()`'s callback takes ONE argument, not `(objectId, object)` — selecting any specific type hid every marker.** → [ymaps-objectmanager-setfilter-single-argument](../gotchas/ymaps-objectmanager-setfilter-single-argument.md) (s50)
- [shipping/pickup] **`focusGroup()` only recentred the camera for clustered points — a plain marker click did nothing.** → [focusgroup-only-moved-for-clustered-points](../gotchas/focusgroup-only-moved-for-clustered-points.md) (s50)
- [shipping/pickup] **A `hidden`-attribute element needs its own `[hidden] { display: none }` — an author `display` rule at equal specificity beats the UA default.** → [css-hidden-attribute-needs-explicit-override](../gotchas/css-hidden-attribute-needs-explicit-override.md) (s50)
- [shipping/pickup] **ymaps control options must be nested under `options` — a flat object is silently ignored.** → [ymaps-control-options-must-be-nested](../gotchas/ymaps-control-options-must-be-nested.md) (s50)
- [shipping/pickup] **An HTML icon layout has no hit area without `iconShape` — clicks fall through to Yandex's POI layer.** → [ymaps-html-icon-layout-needs-iconshape](../gotchas/ymaps-html-icon-layout-needs-iconshape.md) (s50)
- [shipping/pickup] **The ymaps `lang` parameter picks units, not just labels — `en_US` gives miles.** → [ymaps-locale-region-drives-units](../gotchas/ymaps-locale-region-drives-units.md) (s47)
- [shipping/pickup] **`map.margin.addArea()` needs an EXPLICIT `width` — `right` is an offset, not a size.** → [ymaps-margin-area-needs-explicit-width](../gotchas/ymaps-margin-area-needs-explicit-width.md)
- [shipping/pickup] **A custom HTML icon layout draws with its top-left corner AT the anchor — `iconShape` is centred, the artwork isn't.** → [ymaps-html-icon-layout-anchors-at-its-top-left](../gotchas/ymaps-html-icon-layout-anchors-at-its-top-left.md) (s51)
- [shipping/pickup] **Address lookup needs `ymaps.suggest()`, not `geocode()` — and `value`, not `displayName`.** → [ymaps-suggest-not-geocode-for-address-lists](../gotchas/ymaps-suggest-not-geocode-for-address-lists.md) (s51)
- [shipping/pickup] **Bounding the address geocode by the pickup-point area breaks the normal case — self-inflicted, s51.** → [bounding-the-address-resolve-breaks-the-normal-case](../gotchas/bounding-the-address-resolve-breaks-the-normal-case.md) (s51)
- [shipping/pickup] **ymaps' copyright strip ignores `margin.addArea()` and sits in a stacking context the sidebar's z-index can't reach.** → [ymaps-copyright-pane-is-trapped-in-a-stacking-context](../gotchas/ymaps-copyright-pane-is-trapped-in-a-stacking-context.md) (s51)
- [shipping/pickup] **The card renders from a snapshot the writers never touch.** → [card-renders-from-a-snapshot-the-writers-never-touch](../gotchas/card-renders-from-a-snapshot-the-writers-never-touch.md) (s57)
- [shipping/pickup] **A per-cycle memo is not in-flight de-duplication.** → [a-per-cycle-memo-is-not-in-flight-deduplication](../gotchas/a-per-cycle-memo-is-not-in-flight-deduplication.md) (s57)
- [shipping/pickup] **A field that never varies cannot be a verdict.** → [a-constant-field-cannot-be-a-verdict](../gotchas/a-constant-field-cannot-be-a-verdict.md) (s58)
- [shipping/pickup] **A control that changes WHAT a surface is about must emit the same event every other route to that state emits.** → [a-control-that-changes-the-subject-must-announce-it](../gotchas/a-control-that-changes-the-subject-must-announce-it.md) (s58)
- [shipping/pickup] **A per-viewport cache is unbounded by construction.** → [per-viewport-cache-is-unbounded-by-construction](../gotchas/per-viewport-cache-is-unbounded-by-construction.md) (s58)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
