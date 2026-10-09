# `woodev-modal.css` resets every `div` / `form` to `display: block` at class specificity — a single-class form inside the shell loses to it and renders as unstyled blocks

**Namespace:** `[admin-ui/modal]`
**Found:** s164 (09.10.2026), rig-checking the order-edit metabox of a CDEK order (#1180 follow-up).

## The trap

`WoodevModal` (the framework's vanilla dialog shell, written for the storefront pickup map) ships a
"style isolation" block in `woodev/assets/css/frontend/woodev-modal.css`:

```css
.woodev-modal :where( div, p, form, details, ul ) { display: block; }
.woodev-modal :where( div, span, p, … ) { margin: 0; padding: 0; border: 0; background: none; … }
```

`:where()` adds zero specificity, so each rule is `(0,1,0)` — **the same as a single-class rule of ours**. On
a tie the later stylesheet wins, and on an admin screen `woodev-modal-css` is printed AFTER
`woodev-shipping-orders-page-css`. So `.woodev-action-form { display: flex; gap: 12px }` lost, and so did every other
single-class rule for a `div` / `form` inside the dialog: the order-action form on the order-edit screen came out as
stacked blocks with labels glued to their fields, the buttons glued together, and no padding at all — while the SAME
component on the orders page (inside `@wordpress/components`' `Modal`, which has no `.woodev-modal` ancestor) was fine.

Measured on the rig with `getComputedStyle()`: `.woodev-action-form` was `display: block` (`flex-direction` set,
useless), `__field` `block`, `__buttons` `block`; the stylesheet itself was loaded and contained the rules.

## ❌ Wrong

```scss
.woodev-action-form { display: flex; flex-direction: column; gap: 12px; }   // (0,1,0) — loses to the shell's reset
```

## ✅ Correct

Scope the component under the shell, so it is `(0,2,0)` and wins whatever the load order:

```scss
.woodev-action-form,
.woodev-modal .woodev-action-form { display: flex; … }   // nested &__x selectors follow both
```

And remember the shell's body has no padding of its own — a form inside it brings its own.

## How to notice

- A component that is fine on one surface and "bare" on another, with the stylesheet demonstrably loaded: dump
  `getComputedStyle()` of the container — a `display: block` where your CSS says `flex` is this trap.
- jsdom does not run the cascade; a jest/PHP test cannot see it. `tests/js/order-metabox-styles.test.js` pins the
  selector in the source, the proof is a rig measurement.

## Related

- [a-grep-of-a-vendor-stylesheet-is-an-incomplete-measurement](a-grep-of-a-vendor-stylesheet-is-an-incomplete-measurement.md) — same lesson: read the computed style, not the stylesheet
- [the-modal-shell-handles-are-registered-on-the-storefront-hook-only](the-modal-shell-handles-are-registered-on-the-storefront-hook-only.md) — the other way the shell bites on an admin screen
