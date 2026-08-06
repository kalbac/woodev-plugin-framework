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

## s51 addendum — the guard has a cost of its own: it also beats plain `[hidden]`

**Discovered:** s51 (2026-08-05), rig verification: the search field's clear (✕) button stayed visible
on an empty field.

`.woodev-pickup-search__reset[hidden] { display: none }` had no effect. Measured: `hidden === true`
on the element while `getComputedStyle().display` still read `'flex'`. The cause is this very fix —
the reset's `display: inline-flex !important` (present twice: `.woodev-pickup-stage :where(button)` and
`.woodev-modal :where(button)`) beats a plain, non-`!important` `[hidden]` rule on the same property,
for the same reason a theme's own `!important` had to be beaten in the first place.

```css
/* ❌ Loses to the reset's display:inline-flex !important */
.woodev-pickup-search__reset[hidden] { display: none; }

/* ✅ Matches force with force */
.woodev-pickup-search__reset[hidden] { display: none !important; }
```

The sibling `.woodev-pickup-search__results[hidden]` needed no such flag — it's a `<div>`, and the div
reset in this file was never promoted to `!important` in the first place.

**The rule to add:** once a property is defended with `!important` for one purpose (surviving a hostile
theme), every LATER rule that touches that same property on the same elements — even our own, even for
an unrelated purpose like a `[hidden]` toggle — must also be `!important`, or it silently loses to our
own guard.

## s54 addendum — the THIRD occurrence, and why it is now a rule and not an anecdote

Session 54 (2026-08-07) hit it again, in a third place with a third purpose. #168's mobile work
needed the sidebar toggle hidden while the point CARD is open:

```css
/* ❌ Reads correct, does nothing */
.woodev-pickup-stage.is-open.is-card .woodev-pickup-list__toggle { display: none; }

/* ✅ */
.woodev-pickup-stage.is-open.is-card .woodev-pickup-list__toggle { display: none !important; }
```

Specificity here is (0,4,0) against the reset's (0,2,0), so every instinct says it wins. It does
not: the reset carries `!important`, and nothing but `!important` beats `!important`, whatever the
specificity. Measured on the rig — the toggle stayed at computed `flex` and
`document.elementFromPoint()` over the card's CTA kept returning the toggle, which is exactly the
defect the rule was written to fix.

**What generalises after three hits:** the failure is invisible to review, because the CSS reads
correct and the selector genuinely matches. Both times it was caught only by asking the browser
what is actually on top (`getComputedStyle().display`, `document.elementFromPoint()`), never by
reading the rule. In this file, treat `display` on any element inside `.woodev-pickup-stage` as an
`!important`-only property, and verify by measurement rather than by re-reading the selector.

## Related

- `docs-internal/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — V-14
- [[mobile-inline-min-width-and-floating-control-stacking]] — same session, same "only a live
  browser check surfaces this" verification lesson, different cause
- [[css-hidden-attribute-needs-explicit-override]] — the s50 root cause this addendum's symptom
  is actually a variant of: an author `display` rule beating the `[hidden]` UA rule, except this
  time the author rule carries `!important` too
