# `is_checkout()` is FALSE on a page that only carries the Checkout block

**Namespace:** `[woocommerce/blocks]`
**Discovered:** 2026-10-04 (s152, #1089)

## The trap

WooCommerce's `is_checkout()` answers for three things only (WC 11.1,
`CartCheckoutUtils::is_page_type()`):

1. the store's CONFIGURED checkout page (`woocommerce_checkout_page_id`);
2. a page whose content holds the `[woocommerce_checkout]` shortcode;
3. a page that holds the `woocommerce/classic-shortcode` block with `shortcode: checkout`.

A page that holds the **Checkout block** and is not the configured checkout page is none of them.
WooCommerce still renders the block there, runs every registered integration and takes orders
through the Store API — so anything of ours that rides on the block's integration works, and
anything gated on `is_checkout()` silently does not.

That is exactly the rig: the configured checkout page is `/classic-checkout/` (id 13), and
`/checkout/` (id 7) carries the block. #1089's browser acceptance found the pickup button block
registered and rendering nothing: `Pickup_Handler::enqueue_assets()` returned at its
`is_checkout()` gate, so neither `pickup-session.js` nor the `woodev_pickup_config_*` global the
button reads its labels from ever reached the page. The server side answered correctly the whole
time, which made it look like a client bug.

## Wrong

```php
public function enqueue_assets(): void {
	if ( ! is_checkout() ) {
		return; // also returns on a page that renders the Checkout block
	}
```

## Correct

Ask what the page actually contains, and let that answer open the gate too:

```php
$block_only = Checkout_Surface::is_block_only(); // has_block( 'woocommerce/checkout' ) on the page

if ( ! $block_only && ! is_checkout() ) {
	return;
}
```

Data the block needs at render time is better published through the integration's
`get_script_data()` — WooCommerce reads it only where it renders the block, with no page check at
all. That is why the locality chooser (C-1) worked on the same page while the pickup button did not.

## How to see it

A probe on the page itself, not a guess from the URL:

```php
var_dump( is_checkout(), has_block( 'woocommerce/checkout' ), wc_get_page_id( 'checkout' ), get_the_ID() );
```

## Related

- [rig-checkout-url-is-the-block-checkout](rig-checkout-url-is-the-block-checkout.md)
- [the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks](the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md)
- [a-pickup-handler-without-a-selection-scope-has-no-store-api-transport](a-pickup-handler-without-a-selection-scope-has-no-store-api-transport.md)
