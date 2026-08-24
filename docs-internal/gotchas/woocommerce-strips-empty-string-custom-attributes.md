# WooCommerce strips empty-string `custom_attributes`, so an empty attribute cannot be declared through them

**Namespace:** `[shipping/checkout]` · **Discovered:** s89 (2026-08-24), browser measurement on the
rig while closing #469

## The trap

`woocommerce_form_field()` filters the attribute map before it renders anything:

```php
// wc-template-functions.php:3367 (WooCommerce 11.0.1)
$args['custom_attributes'] = array_filter( (array) $args['custom_attributes'], 'strlen' );
```

`strlen( '' )` is `0`, so **any attribute whose value is the empty string is dropped outright** and
never reaches the markup. There is no hook to get around it: `woocommerce_form_field_args` fires
*before* this line, and the only other filter (`woocommerce_form_field`) hands you finished HTML.

This is easy to miss because WooCommerce itself emits empty attributes all the time — for example
its own `billing_state` renders `data-input-classes=""`. It can, because that branch writes the
attribute **literally**, outside `custom_attributes`. A plugin declaring the same thing through
`custom_attributes` gets silence.

## Why it bites here

`Checkout_Handler::inject()` overrides `type` on a field WooCommerce would have rendered through
its `state` branch. That branch is the **only** one that emits `data-input-classes`, so the
override removes the attribute. `country-select.js:103` then reads it back off the statebox as
`undefined` and concatenates it into a class list at line 120
(`.addClass( 'state_select ' + input_classes )`) — the rig's `class="state_select undefined"`
fingerprint.

Re-declaring the attribute through `custom_attributes` is the right seam, but the natural value —
the empty string, matching what WooCommerce writes — is exactly the one the filter throws away.
WooCommerce core sets no `input_class` on any address field, so the empty case is the **only** one
a stock install ever hits: a fix that skips it fixes nothing.

## ❌ Wrong

```php
// Silently dropped by array_filter( …, 'strlen' ) — the markup is unchanged and the defect stays.
$attributes['data-input-classes'] = implode( ' ', $existing_wc_args['input_class'] ?? [] ); // ''
```

## ✅ Correct

```php
$input_classes = implode( ' ', (array) ( $existing_wc_args['input_class'] ?? [] ) );

// A single space is the minimal value that survives `strlen`, and it is equivalent to the empty
// string for every consumer of a class-list attribute.
$attributes['data-input-classes'] = '' === $input_classes ? ' ' : $input_classes;
```

Measured on the rig rather than reasoned about — with `data-input-classes=" "` WooCommerce's own
rebuild produced `class="state_select"`, byte-identical to the `""` control, and round-tripped the
attribute onto the element it built.

## How it was caught

By measuring the **screen**, not the payload. A probe through `wp eval-file` showed
`custom_attributes => [ 'data-input-classes' => '' ]` sitting in the checkout-fields array — the
change looked landed. The rendered markup for the same field, fetched over HTTP in the same
minute, carried no such attribute. Same lesson as
[narrowing-a-server-option-list-has-a-client-half](narrowing-a-server-option-list-has-a-client-half.md):
the array a filter returns is not the HTML a renderer emits.

## Related

- [narrowing-a-server-option-list-has-a-client-half](narrowing-a-server-option-list-has-a-client-half.md) — the same measure-the-screen lesson
- [the-classic-adapter-reverts-a-select-the-location-cascade-owns](the-classic-adapter-reverts-a-select-the-location-cascade-owns.md) — the other half of who owns a checkout node
- [a-dom-attribute-is-the-wrong-seam-on-a-woocommerce-checkout](a-dom-attribute-is-the-wrong-seam-on-a-woocommerce-checkout.md) — when the node is not yours
- `woodev/shipping-method/checkout/class-checkout-handler.php` → `inject()`
- `woodev/shipping-method/assets/js/frontend/location-select-modes.js` → `buildSelectField()`
