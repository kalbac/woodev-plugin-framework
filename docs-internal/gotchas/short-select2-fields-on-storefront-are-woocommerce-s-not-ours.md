---
id: short-select2-fields-on-storefront-are-woocommerce-s-not-ours
namespace: rig
tags: [rig, css, select2, storefront, woocommerce, theme]
session: 2026-10-02
---

# Short select2 fields on Storefront are WooCommerce's, not ours

**Namespace:** `[rig/*]` · **Measured:** s147 (02.10.2026), rig on Storefront 4.6.2 + WooCommerce 11.1.0.

## The trap

After the rig switched to Storefront, every select on `/classic-checkout/` looked short next to the
text inputs, our location fields included. The operator's demo sites (also Storefront) show selects as
tall as inputs. The obvious reading is "our location CSS shrinks the select". It does not.

| Field | Height |
|---|---|
| text inputs (`#billing_first_name`, …) | 46px |
| WooCommerce's own `#billing_country` / `#billing_state` select2 | **28px** |
| our `#shipping_city` select2 | 28px |
| all of the above with every `woodev-*` stylesheet disabled | **unchanged** |

The 28px comes from `.select2-container .select2-selection--single { height: 28px }` in
`woocommerce/assets/css/select2.css`, select2's own default. WooCommerce enqueues that file on the cart,
checkout and account pages (`WC_Frontend_Scripts::load_scripts()`, `enqueue_style( 'select2' )`).
Storefront's `woocommerce.css` only recolours the border of an invalid select2, and does not size it.

## ❌ Wrong

```css
/* "Fix" our location fields so they match the inputs */
.woodev-location-field .select2-selection--single { height: 46px; }
```

This makes OUR fields disagree with WooCommerce's own country/state fields on the same form, and it puts
the framework in charge of a theme decision.

## ✅ Correct

Leave it alone. **The framework does not restyle WordPress/WooCommerce defaults** (operator, 02.10.2026).
Our fields look like WooCommerce's fields, and the theme (or the site's Customizer CSS) decides both.
To diagnose a "small field" report, disable the `woodev-*` sheets in the page and re-measure before
touching anything:

```js
for ( const sh of document.styleSheets ) { if ( sh.href && /woodev/.test( sh.href ) ) sh.disabled = true; }
```

## Related

- [rig-checkout-url-is-the-block-checkout](rig-checkout-url-is-the-block-checkout.md) — which checkout the rig serves where
- [../gotcha-index/rig.md](../gotcha-index/rig.md) — topic index
