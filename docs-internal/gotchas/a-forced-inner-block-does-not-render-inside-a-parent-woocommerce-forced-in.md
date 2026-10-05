# A forced inner block does not render inside a parent WooCommerce itself forced in

**Namespace:** `[woocommerce/blocks]`
**Discovered:** 2026-10-05 (s153, #1091 — SP-11 C-4, WooCommerce 11.1)

## What happens

`registerCheckoutBlock( { force: true } )` is read as «WooCommerce renders my block on every
checkout, whatever the saved page holds». It does — **as long as the block's PARENT is in the saved
page content**.

When the saved checkout page lacks the parent itself (measured with
`woocommerce/checkout-shipping-address-block` and `woocommerce/checkout-shipping-methods-block`
removed from the markup), core still renders those parents — they are forced too, appended after the
saved steps — but renders them **without their forced children**. The locality chooser and the
pickup-point button are simply absent. A pickup rate then requires a point with no control to choose
one, and the order is refused by the server: «You have not chosen a pickup point. Please choose a
pickup point on the checkout page before paying.» Nothing logs and nothing warns the merchant.

## Root cause

Forced children are added while core walks a parent's SAVED DOM node: «the registered forced blocks
of this area that are not among the node's children». A parent that was force-rendered has no saved
node, so nothing walks it and its area is never completed
(`wc-cart-checkout-base-frontend.js`, search `_forced_`).

The same walk explains two neighbours, both reachable only by hand-editing the markup (our blocks
have `inserter: false` and no editor script, so a merchant cannot place them):

- a child saved TWICE under its parent renders twice — `supports.multiple: false` is an editor
  rule, not a frontend one;
- a child saved under a WRONG parent renders there AND, forced, under the right one — the
  de-duplication is per parent.

A child saved once under its own parent renders once, and a page whose steps were reordered keeps
each child with its parent.

## Fix

There is none in the framework yet — a design question, filed from the C-4 report (detect the
missing parent and tell the merchant; D-2 A already names «an actionable compatibility notice»).
Until then:

❌ Assuming a forced block is on the page because it is forced.

✅ When a store reports «no pickup button on the block checkout», look at the saved page first:

```bash
wp post get "$(wp option get woocommerce_checkout_page_id)" --field=post_content \
  | grep -c 'wp:woocommerce/checkout-shipping-methods-block'   # 0 → the parent is not in the page
```

A core parent can only go missing through hand-edited or very old markup: both blocks are locked in
the editor.

## Related

- [a-pickup-handler-without-a-selection-scope-has-no-store-api-transport](a-pickup-handler-without-a-selection-scope-has-no-store-api-transport.md) — the other way a pickup rate ends up with no button, and what «fail loud» looked like there (#1100)
- [core-local-pickup-exists-only-when-the-store-s-checkout-page-holds-the-checkout-block](core-local-pickup-exists-only-when-the-store-s-checkout-page-holds-the-checkout-block.md) — another «it depends on what the page holds» rule of the block checkout
- [../specs/2026-10-04-sp11-block-checkout-design.md](../specs/2026-10-04-sp11-block-checkout-design.md) — D-2 and the C-4 measured surface
