# Gotcha: [shipping/checkout] — The block checkout never sees `woocommerce_checkout_fields`, but it DOES honour the country locale (order, hidden, required)
> Tags: blocks, checkout, country-locale, field-order, woocommerce | Session: s79

## What happens

Two opposite wrong assumptions were both live before s79:

1. «Removing a field with `unset()` in `woocommerce_checkout_fields` removes it everywhere.» It
   does not: the block checkout's core address fields are **hard-coded** in
   `CheckoutFields::get_core_fields()` (`src/Blocks/Domain/Services/CheckoutFields.php`, WC 11.0.1)
   and that filter is never applied to the block form.
2. «Field ORDER cannot be customised on the block checkout, so a field-order preset is
   classic-only.» Also wrong — the input document for #362 asserted it in its first edition and
   the operator asked for it to be checked rather than stated.

## Root cause (measured on the rig, WC 11.0.1)

`CartCheckoutUtils::get_country_data()` (`src/Blocks/Utils/CartCheckoutUtils.php:356–370`) maps
every country's `WC()->countries->get_country_locale()` entry into the client settings, renaming
`priority` → `index` and dropping `class`. The shared bundle
(`assets/client/blocks/wc-cart-checkout-base-frontend.js`) merges the per-country locale into the
field list and sorts `(e, t) => e.index - t.index`; a `hidden: true` field gets `required = false`
and is not rendered.

So `woocommerce_get_country_locale` is the ONE instrument that reaches **both** checkouts for
`priority`, `hidden`, `required` and `label`; `woocommerce_checkout_fields` reaches the classic
checkout only.

## Fix

❌ Wrong — one seam for everything, then wonder why the block form ignores it:

```php
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	unset( $fields['shipping']['shipping_state'] );          // classic only
	$fields['shipping']['shipping_state']['priority'] = 20;  // classic only, and contradicts the unset anyway
	return $fields;
} );
```

✅ Correct — locale for what must reach both, the checkout-fields filter for classic-only removal:

```php
add_filter( 'woocommerce_get_country_locale', function ( $locale ) {
	foreach ( array_keys( WC()->countries->get_shipping_countries() ) as $cc ) {
		$locale[ $cc ]['state']['priority'] = 20;   // order — both checkouts
		$locale[ $cc ]['state']['hidden']   = true; // "remove" on the block form — not rendered
		$locale[ $cc ]['state']['required'] = false;
	}
	return $locale;
}, PHP_INT_MAX - 10 );

add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	unset( $fields['billing']['billing_state'], $fields['shipping']['shipping_state'] ); // out of the classic DOM
	return $fields;
}, PHP_INT_MAX - 10 );
```

Anything JS-driven on the classic form (hide-for-pickup, CSS-hide of the country row) still does
not reach the block checkout until the SP-11 adapter exists — those admin options are rendered
`disabled` with the reason (design S3/D11 of `specs/2026-08-18-shipping-settings-v2-design.md`).

## Related

- [rig-checkout-url-is-the-block-checkout](rig-checkout-url-is-the-block-checkout.md) — the rig's `/checkout/` IS the block checkout; measure there
- [wc-renders-a-label-for-hidden-fields](wc-renders-a-label-for-hidden-fields.md) — what `hidden` does on the CLASSIC form (a label still renders)
- `docs-internal/specs/2026-08-18-shipping-settings-v2-design.md` §5.3–5.4 — the measurement this gotcha records
- `docs-internal/wiki/architecture.md` → «Доставка» tab, "The two-instrument rule" — where this
  measurement ended up as a design rule: the locale is Instrument A (reaches both checkouts), the
  late `woocommerce_checkout_fields` filter is Instrument B (classic only), and which one a setting
  uses is what decides its reach
