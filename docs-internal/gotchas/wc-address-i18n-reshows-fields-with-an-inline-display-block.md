# Gotcha: [shipping/checkout] — WooCommerce re-shows every locale field with an INLINE `display:block`, so a class-based hide must be `!important`
> Tags: checkout, css, address-i18n, woocommerce | Session: s81

## What happens

You add a class to a `.form-row` and a stylesheet rule `display: none` for it. It works — until the
customer changes country, or anything calls `update_checkout`. Then the row is back, and nothing in
your own code put it back.

Measured on the rig 2026-08-19: with a pickup method chosen, `#shipping_postcode_field`,
`#shipping_address_1_field`, `#billing_postcode_field` and `#shipping_country_field` all carried the
policy class as intended, and all four had computed `display: block`, height 115px. The only
matching stylesheet rule was ours. The override was an **inline** `style="display: block;"`.

The dangerous part is the timing: right after the class is applied it DOES hide, so a quick check
passes. The row only comes back on the next locale pass, which is a different interaction.

## Root cause

`woocommerce/assets/js/frontend/address-i18n.js`, in the handler for `country_to_state_changed` —
which fires on page load, on every country change, and after every `update_checkout`:

```js
// Hidden fields. State visibility (show) is managed by country-select.js,
// but locale can still hide it.
if ( true === fieldLocale.hidden ) {
    field.hide().find( ':input' ).val( '' );
} else if ( 'state' !== key ) {
    field.show();
}
```

jQuery's `.show()` writes an inline `display: block`. Inline styles beat any class-based rule that
is not `!important`, so WooCommerce silently un-hides the row on its own schedule. **The adversary
here is WooCommerce itself, not a hostile theme** — this is not a defensive-`!important` habit, it
is the only thing that works.

Note the `if` branch too: a field WooCommerce considers locale-`hidden` also has its **value
cleared**. So "hide it through the country locale instead" is not an alternative whenever the value
still has to post.

## Fix

❌ Wrong — a plain class rule, defeated the next time the locale pass runs:

```css
.woodev-field--hidden-for-pickup,
.woodev-field--hidden {
	display: none;
}
```

❌ Also wrong — moving the hide into the locale as `hidden: true`: WooCommerce then blanks the
input, and these classes exist precisely to hide a row WITHOUT touching what it submits.

✅ Correct:

```css
.woodev-field--hidden-for-pickup,
.woodev-field--hidden {
	display: none !important;
}
```

Verified live on the same page: all four rows went to computed `display: none`, height 0, while the
inline `display: block` was still present on each of them.

**How to check it properly:** after applying the class, fire the locale pass before you measure —
`document.getElementById('shipping_country').dispatchEvent(new Event('change', {bubbles:true}))` —
and read `getComputedStyle(row).display`, not `offsetParent`. Measuring straight after the class is
added tests nothing.

## Related

- [js-hidden-checkout-field-is-still-required-server-side](js-hidden-checkout-field-is-still-required-server-side.md) — the other half of the same feature: hiding the row in the browser is not enough on the server either
- [wc-renders-a-label-for-hidden-fields](wc-renders-a-label-for-hidden-fields.md) — more of what WooCommerce keeps doing to a field you thought you had hidden
- [block-checkout-reads-country-locale-not-checkout-fields](block-checkout-reads-country-locale-not-checkout-fields.md) — what the locale instrument does reach, and why it is not the tool for a value-preserving hide
- `docs-internal/wiki/architecture.md` → «Доставка» tab, "The two-instrument rule"
