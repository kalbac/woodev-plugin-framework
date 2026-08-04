# A theme's `button { display: none !important }` hid every button inside the modal, close included

**Discovered:** s50 (2026-08-04), rig verification (Task 20) — the T20 checklist's own hostile-theme
injection step (`button{display:none!important}` in the browser console).

## What happened

The style-isolation reset (spec V-14) declares `display: inline-flex` on every `button` inside the
modal, deliberately **without** `!important` — the file's stated philosophy is "specificity stays
flat... no `!important`... so a theme CAN restyle decoration without a specificity war". That
philosophy holds for font size, colour, border — but not for `display`, and the checklist's own
test proves it: a theme rule using `!important` on `button { display: none }` (unusual, but not
hypothetical — some page builders and utility-CSS resets do exactly this) beat our plain
`display: inline-flex` regardless of specificity, because **`!important` only ever loses to another
`!important`**. Every button inside the dialog vanished, including the close (×) button — leaving
the customer with no way to dismiss it at all.

## The fix

`display` is the one property promoted to `!important`, in both files that declare the rule:

```css
/* woodev-modal.css and pickup.css, in the :where( button ) reset */
display: inline-flex !important;
```

Nothing else in the reset needed it: re-testing the SAME injected hostile rule confirmed the modal
title (`h1,h2 { font-size: 34px; text-transform: uppercase }`, no `!important`) and every input
(`input { border: 4px solid red; height: 80px }`, no `!important`) still resolved correctly through
ordinary specificity — `.woodev-modal__title`/`:where(input)` (class selector, 0-1-0) already beats
a bare element selector (0-0-1) without needing the nuclear option. Only a theme's own `!important`
forces ours in kind, and only for the property whose loss breaks the feature outright rather than
just its decoration.

## Why this needed a live browser, not a test

No unit test renders CSS cascade resolution. This is exactly why the T20 verification checklist
carries a dedicated hostile-theme injection step — it is the one way to catch a `!important` fight
before a real merchant's theme does.

## The wider lesson

"No `!important`, ever" is a good default, not an absolute rule. The one property worth breaking it
for is whichever one determines whether the control **exists on screen at all** — losing a
custom `display` to a theme is decoration; losing whether a button renders at all is a broken
feature. Everything else on the isolation reset can afford to lose a specificity fight to a theme
gracefully; `display` on an interactive control cannot.

## Related

- `docs-internal/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — V-14
- [[mobile-inline-min-width-and-floating-control-stacking]] — same session, same "only a live
  browser check surfaces this" verification lesson, different cause
