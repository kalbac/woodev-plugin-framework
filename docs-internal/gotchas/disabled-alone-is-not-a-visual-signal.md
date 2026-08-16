# Gotcha: [admin-ui/css] — `disabled` alone is not a visual signal; a theme's own `input` rule erases it
> Tags: css, storefront, checkout, themes | Session: s76

## What happens

A checkout field is blocked by setting `el.disabled = true`. It really is blocked — it takes no
typing, it is out of the tab order — and on the rig it looked **exactly like the editable field
next to it**. Nothing about it read as "blocked": it read as broken.

Measured on the rig (16.08.2026, #337): the shipping "Street address" input with
`disabled === true` rendered with the same background, border and text colour as its billing twin.

## Root cause

The browser's default greying of a disabled control is a *UA stylesheet* rule, and it only survives
while nothing else sets those properties. Any storefront theme that styles `input` — i.e. nearly
every one — states its own `background`, `border` and `color`, which beat the UA default. The
`:disabled` state was never mentioned by the theme, so the field simply keeps the enabled look.

This is the same class of assumption as expecting an unstyled `ul` to look like a dropdown: the
UA default is a floor only where the page has not already built a floor of its own.

The framework's own precedent (`checkout-field-classic.js`'s A2 gate, #274) is a disabled
`<button>` — and there the UA default *does* usually survive, because themes style buttons through
their own classes rather than the bare element. A field is not a button; the precedent does not
transfer.

## Fix

Mark the element and style the mark. Not with an explanation — the standing rule is that a blocked
control is blocked and says nothing — only with the two signals a disabled control conventionally
carries.

❌ Wrong — the attribute alone, trusting the UA:

```js
el.disabled = locked;   // invisible under any theme that styles `input`
```

✅ Correct — the attribute plus a class the stylesheet can see:

```js
el.disabled = locked;
el.classList.toggle( 'woodev-location-locked', locked );
```

```css
.woodev-location-locked {
	cursor: not-allowed;
	opacity: 0.55;
}
```

`opacity` rather than a colour of our own, deliberately: it composes with whatever background,
border and text colour the theme already gave the field, on a light or dark palette, so the rule
needs no palette of its own and cannot clash. Specificity stays flat (one class) so a theme can
still take a stronger treatment.

## Related

- [flat-where-isolation-loses-to-a-longer-theme-selector](flat-where-isolation-loses-to-a-longer-theme-selector.md) — the same lesson from the other end: a theme's ordinary selector beats our isolation
- [hostile-theme-button-display-none-needs-important](hostile-theme-button-display-none-needs-important.md) — a theme overriding a control's `display` outright
- [css-hidden-attribute-needs-explicit-override](css-hidden-attribute-needs-explicit-override.md) — an author `display` rule beating the UA default for `[hidden]`
