# gotcha: WooCommerce's `#variable_product_options .notice` rule strips the box of any notice inside the variations panel

**Namespace:** `[woocommerce/admin-css]` · **Discovered:** s238 (19.11.2026, #1859, rig look of PR #1870)

## What happens

A notice printed into the Variations panel (`#variable_product_options`) with WordPress' own classes
(`notice notice-warning inline is-dismissible`) renders as plain text: no coloured left edge, no border. WooCommerce's admin
CSS has `#variable_product_options .notice { … border: none … }` (an id selector), which beats any class-only rule of ours,
including a plugin stylesheet that restates `.notice` — the computed `border-left-width` was `0px` on the rig.

## ❌ Wrong

```css
.woodev-mp-variation-notice.notice { border-left-width: 4px; }
```

## ✅ Correct

```css
#variable_product_options .woodev-mp-variation-notice.notice {
	border: 1px solid #c3c4c7;
	border-left-width: 4px;
	border-style: solid;
}
```

Scope our rules with the same id WooCommerce uses. Prove a CSS claim on the rig with `getComputedStyle`, not by reading the stylesheet.

## Related

- [../gotcha-index/woocommerce.md](../gotcha-index/woocommerce.md)
