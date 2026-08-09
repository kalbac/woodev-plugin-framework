# ymaps' copyright strip ignores `margin.addArea()` and sits in a stacking context the sidebar's z-index can't reach

**Namespace:** `[shipping/pickup]` · **Discovered:** s51 (2026-08-05), rig-verifying that the
[[ymaps-margin-area-needs-explicit-width]] fix actually restored the copyright strip's visibility.

## What happened

Yandex's ToS forbids covering the copyright strip. The sidebar is an absolutely-positioned DOM sibling
of the map, and with the panel open it covered the strip completely.

The margin-area gotcha (same session) fixed `setMargin()`'s zero-width reservation on the theory that,
once the reservation was real, ymaps would keep its own copyright strip clear of it. **It doesn't.**
Measured A/B live, with the width bug already fixed: the strip sat at exactly `x 807…1097, y 806…830` —
identically — with the sidebar open, with it closed, and with the map element narrowed to 600px.
`map.margin.addArea()` moves the **camera** (confirmed separately: `getBounds({ useMapMargin: true })`
does return a genuinely narrower box) and does not reposition that strip at all. The margin-area file's
assumption that a correct reservation would pull the copyright pane along with it does not hold.

## The z-index numbers actively mislead

```
.woodev-pickup-list           z-index 2
.woodev-pickup-map            z-index auto — creates NO stacking context
.ymaps-2-1-79-copyrights-pane z-index 5002
```

By these numbers the copyright pane should paint comfortably above the sidebar. It doesn't —
`document.elementFromPoint()` at the strip's own centre returns `woodev-pickup-list__body`, not the
copyright pane. The pane is nested inside an intermediate ymaps-owned container element, and **that**
container is what actually competes with the sidebar for stacking order — its own z-index, not the
5002 on the pane three levels further down. A z-index only outranks siblings within the SAME stacking
context; the copyright pane's 5002 never gets compared against the sidebar's 2 at all.

## The shipped fix

The sidebar panel stops 32px short of the map's bottom edge, leaving the strip visible full-width in
the gap. Holds at ≤500px viewport widths too (checked on the same rig pass as the mobile fixes in
[[mobile-inline-min-width-and-floating-control-stacking]]).

## The alternative, not shipped

The operator's cosmetic preference is a full-height panel with the copyright painted *over* it. Doing
that would require raising the stacking of an ymaps-owned element — which means selecting a class whose
name carries the loaded API version (`ymaps-2-1-79-…`), something `pickup.css` deliberately never does
anywhere else in this feature (a version bump silently breaks the selector, with no warning). Cheap to
write once, expensive to own across every future ymaps update. Was tracked as issue #168 — closed in
s54 (floating sidebar card; see the [[hostile-theme-button-display-none-needs-important]] s54 addendum).

## Related

- [[ymaps-margin-area-needs-explicit-width]] — the reservation bug this file's investigation started
  from; that fix was necessary but not sufficient for the copyright strip specifically
- [[ymaps-html-icon-layout-anchors-at-its-top-left]] — same session, same shape: an ymaps-owned element
  positions itself by rules the framework's CSS/JS does not control and can only work around
