# Flat `:where()` isolation loses to an ordinary longer theme selector — no `!important` required

**Namespace:** `[admin-ui/*]` / frontend CSS
**Discovered:** 2026-08-12 (s69), rig hostile-theme pass on the location typeahead

## The trap

The style-isolation contract this repo uses (`pickup.css`, `woodev-modal.css`) writes its reset with
`:where( … )`, which carries **zero specificity by spec**. That is deliberate: a theme may restyle
decoration without a specificity war, and our real component rules (one class, `(0,1,0)`) still beat a
theme's bare element selectors (`(0,0,1)`).

The sibling gotcha [[hostile-theme-button-display-none-needs-important]] documents the case where a
theme shouts — `button { display: none !important }` — and concludes that `display` on a control
gets `!important`, because nothing but `!important` beats `!important`.

**There is a third case, and neither rule covers it: a theme selector that is simply LONGER.**

```css
/* Theme. No !important anywhere. Specificity (0,3,2). */
.woocommerce form .form-row ul li { display: none; }

/* Ours. Specificity (0,1,0). Loses, honestly, by the cascade. */
.woodev-location-option { display: block; }
```

Measured on the rig: every suggestion vanished while the dropdown panel itself stayed open — ten
children at computed `display: none`, the box collapsed to 10px, and `document.elementFromPoint()`
over an option's own centre returned the page's `<main>`. Flat isolation cannot defend against this
**by construction**: zero specificity is the whole point of `:where()`.

## `:has()` inherits its argument's specificity — so it ties, and source order decides

The same pass found the positioning half:

```css
/* Theme */
.woocommerce-input-wrapper { position: static !important; }   /* (0,1,0) */

/* Ours — `:has()` takes the specificity of its most specific argument → also (0,1,0) */
:has( > .woodev-location-listbox ) { position: relative !important; }
```

Both carry `!important` and both are `(0,1,0)`, so the **later stylesheet wins** — and we do not
control whether a theme's CSS is enqueued after ours. Measured: the panel rendered at full height
with every option visible, and `elementFromPoint()` at an option's centre returned nothing, because
an absolutely-positioned box whose containing block moved up the tree lands somewhere else entirely.
Visible but not where the customer is looking is the same class of broken as invisible.

## ✅ The rule

For any property whose loss removes the control from the screen (or moves it off its anchor):

1. give the rule **real specificity** — the component class plus its container — for the ordinary
   long-selector theme;
2. **and** `!important` for the theme that also shouts;
3. when the selector uses `:has()`, add the **compound form** so it does not tie:

```css
.woodev-location-listbox .woodev-location-option { display: block !important; }
.woocommerce-input-wrapper:has( > .woodev-location-listbox ) { position: relative !important; }
```

Everything else on the same rules stays plain and may lose to a theme gracefully — that is still the
contract, and it is still correct for font, colour, border and spacing.

## How to verify — and the control that saves you from a false alarm

Only a live browser resolves the cascade; no unit test does. Inject the rules, then **measure**
(`getComputedStyle()`, `elementFromPoint()`), never re-read the selector.

Run a **control probe with no injection at all** in the same sequence. The first attempt at this
check reported all four hostile rules "breaking" the widget — the control proved the listbox simply
had not opened, because the helper cleared the input below `minChars` and the widget legitimately
closed. Without the control that was one keystroke away from a fabricated four-defect report.

The strongest single probe is **all hostile rules applied simultaneously**: it subsumes the
individual ones, so a flaky per-probe setup cannot leave a gap in the conclusion.

## Related

- [[hostile-theme-button-display-none-needs-important]] — the `!important`-vs-`!important` case; this
  file is its third sibling: specificity-vs-specificity
- [[css-hidden-attribute-needs-explicit-override]]
- [[mobile-inline-min-width-and-floating-control-stacking]] — same "only a live browser surfaces it"
