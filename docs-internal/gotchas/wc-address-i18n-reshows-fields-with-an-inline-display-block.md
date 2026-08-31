# Gotcha: [shipping/checkout] — `address-i18n.js` re-writes your field on its own schedule: an INLINE `display:block` over a class-based hide, and the LABEL over anything you set
> Tags: checkout, css, address-i18n, woocommerce, labels | Session: s81, extended s109

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

## The same handler also overwrites the LABEL (s109, #483)

A few lines above the `hidden` branch, in the same `$.each` over `locale_fields`:

```js
// Labels.
if ( typeof fieldLocale.label !== 'undefined' ) {
    field.find( 'label' ).html( fieldLocale.label );
}
```

`fieldLocale` is `locale['default'][key]` merged with `locale[country][key]`, and the **`default`
entry carries a label for every native address field**. Measured on the rig 31.08.2026 through
`WC()->countries->get_country_locale()`:

```
default.state     label=State / County
default.city      label=Town / City
default.address_1 label=Street address
default.postcode  label=Postcode / ZIP
RU.state / RU.city / RU.address_1 / RU.postcode  → no label key at all
```

So the country locale never has to define anything: the `default` entry alone is enough to
overwrite the label of `billing_city`, `billing_state`, `billing_address_1`, `billing_postcode` and
their `shipping_*` twins on **every** locale pass.

**Consequence for this framework.** `Field::set_label()` reaches
`WC()->checkout()->get_checkout_fields()` — that half was measured and is not in doubt (#483) — but
for a native WooCommerce field the customer never sees it. The framework label loses to
WooCommerce's own, client-side, after render.

⚠ Do not read this as "the label never reaches the markup". The card that opened #483 diagnosed it
that way from the final DOM alone, which cannot distinguish "never rendered" from "rendered and
then replaced". The server side is fine; the overwrite is a later client-side pass.

**Operator decision 31.08.2026: leave it.** A framework-supplied label would compete not only with
WooCommerce's locale but with the shop's own rename — shops routinely rename address labels from
their own code or a third-party plugin. `set_label()` is documented as applying only to fields
WooCommerce does not define itself; the docblock on `Field::set_label()` carries the rule.


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
