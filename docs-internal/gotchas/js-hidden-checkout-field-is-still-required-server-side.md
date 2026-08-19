# Gotcha: [shipping/checkout] — Hiding a checkout field in JS does not stop WooCommerce requiring it
> Tags: checkout, validation, hide_for_pickup, woocommerce | Session: s81

## What happens

A field hidden by our own classic-checkout JS still posts an empty input, and WooCommerce still
validates it. The customer presses «Заказать» and gets an error naming a field that is not on the
screen — with no way to fix it.

Measured on the rig 2026-08-19 with `address_field = hide_for_pickup`,
`postcode_field = hide_for_pickup`, `pickup_replace_address = 0` and a pickup shipping method
chosen. `POST /?wc-ajax=checkout` answered `{"result":"failure"}` with four errors:

```
Billing Street address is a required field.
Billing Postcode / ZIP is a required field.
Shipping Адрес (Location Provider) is a required field.
Shipping Postcode / ZIP is a required field.
```

All four rows carried `woodev-field--hidden-for-pickup` and were `offsetParent === null` at the
moment of submission.

## Root cause

Two independent facts that only bite together.

1. **WooCommerce validates presence, not visibility.** `WC_Checkout::validate_posted_data()`
   (`class-wc-checkout.php:992` on WC 10.x) is:

   ```php
   if ( $validate_fieldset && $required && '' === $data[ $key ] ) {
       $errors->add( $key . '_required', ... );
   }
   ```

   The loop just above only skips a key that is ABSENT from the posted data
   (`if ( ! isset( $data[ $key ] ) ) { continue; }`). A CSS-hidden row still posts its input, so
   the key is present, empty, and required — an error. Note this is the opposite of WooCommerce's
   own CLIENT-side rule, which checks `.validate-required:visible` and therefore *does* skip a
   hidden row. Client and server disagree by design, and only the server blocks the order.

2. **The framework's JS-driven field options deliberately did not touch the server.**
   `Checkout_Field_Policy` treated `hide_for_pickup` / `country=hide` as browser-only and published
   them to the client without changing the field definitions.

Normally invisible because `pickup-mount.js::applyAddressReplacement()` writes the chosen point's
`address` / `locality` / `postal_code` into the fields, so they are non-empty by the time the order
is placed. The hole is `hide_for_pickup` **plus** either `pickup_replace_address = false` (a
documented store option) or a carrier point that carries no `postal_code`.

## Fix

The rule: **whatever the browser hides, the server must stop requiring — in the same condition.**
Relax `required`, never `unset()`: unsetting drops the posted value and changes the DOM, and the
map still needs to write the point's address into that very field.

❌ Wrong — hide in JS only, leave the field definition alone:

```php
// Checkout_Field_Policy: "these are classic-only, JS-driven — this class never acts on them"
// …and WC_Checkout then rejects the order on a field the customer cannot see.
```

❌ Also wrong — remove the field instead of relaxing it:

```php
unset( $fields[ $section ][ $section . '_postcode' ] ); // value never posts; the map cannot fill it
```

✅ Correct — same condition as the JS, `required` only:

```php
if ( $pickup_chosen && 'hide_for_pickup' === ( $settings['postcode_field'] ?? 'show' )
	&& isset( $fields[ $section ][ $section . '_postcode' ] ) ) {
	$fields[ $section ][ $section . '_postcode' ]['required'] = false;
}
```

Whoever adds the NEXT JS-driven field option inherits this obligation. Before shipping one, ask:
can this field end up empty while hidden? If yes, the server side is part of the feature, not a
follow-up.

## Related

- [block-checkout-reads-country-locale-not-checkout-fields](block-checkout-reads-country-locale-not-checkout-fields.md) — the other half of the same map: which seam reaches which checkout
- [wc-renders-a-label-for-hidden-fields](wc-renders-a-label-for-hidden-fields.md) — hiding a field on the classic form leaves more behind than it looks
- [custom-checkout-field-is-empty-on-reload-by-construction](custom-checkout-field-is-empty-on-reload-by-construction.md) — the same family: what the DOM shows is not what the server received
- `docs-internal/wiki/architecture.md` → «Доставка» tab, "The two-instrument rule" — where the JS-only options sit in the design
