# A custom checkout field is empty after a reload BY CONSTRUCTION

**Namespace:** `[shipping/checkout]`
**Discovered:** 2026-08-11 (s65, issue #176)

## The trap

Add a field through `woocommerce_checkout_fields`, let the customer fill it, reload the page —
the value is gone. It reads like broken restore logic. It is not: WooCommerce never had anywhere
to read it from.

WooCommerce renders every checkout field as
`woocommerce_form_field( $key, $field, $checkout->get_value( $key ) )` (`form-billing.php:39`,
`form-shipping.php:39` and `:63` — the last one is the `order` section). `WC_Checkout::get_value()`
(WooCommerce 11.0.0, `class-wc-checkout.php:1480-1523`) resolves in exactly four steps:

```php
if ( ! empty( $_POST[ $input ] ) ) { return wc_clean( wp_unslash( $_POST[ $input ] ) ); }

$value = apply_filters( 'woocommerce_checkout_get_value', null, $input );
if ( ! is_null( $value ) ) { return $value; }

// …then a WC_Customer getter `get_{$input}()`, or `$customer->get_meta( $input )`

return apply_filters( 'default_checkout_' . $input, $value, $input );
```

For a key that is neither `billing_*` nor `shipping_*`, and that nobody wrote to customer meta,
**every one of those four steps yields nothing on a GET request**. There is no bug to find. A
value that is not written somewhere WooCommerce reads simply does not survive the request.

## Correct pattern

`woocommerce_checkout_get_value` is the sanctioned restore seam, and it is the SECOND step — it
runs after `$_POST`, so a failed checkout submit still re-renders what the customer actually
posted rather than a stale stored value. Answer only for your own field id and return the
incoming `$value` untouched for everything else, or the filter short-circuits every other field
on the checkout:

```php
public function restore_selection( $value, string $key ) {
    if ( $this->field_id !== $key ) {
        return $value;      // ← not `return null`, not `return ''`
    }
    // … resolve, and still return $value when there is nothing to restore
}
```

Restoring server-side this way needs no JavaScript at all: the value is in the rendered `value`
attribute, so every consumer that reads the field on mount already works.

## Why it matters

The half-fixed state is worse than the plain bug. In SP-5 the picker also writes the chosen
point's address into `billing_*`/`shipping_*` — native fields WooCommerce DOES persist. After a
reload the customer therefore saw the pickup point's address still filled in, while the hidden id
field was empty and the order was blocked. The page looked like the selection was intact.

## How to tell this case from a broken restore

Read the rendered HTML, not the DOM after scripts run. If the `value` attribute is present on
page load, the server restored it; if it appears only later, some JS put it there. That one
distinction is what makes the verification a positive artifact rather than a timing guess.

## Related
- [[an-empty-domain-key-is-not-a-key]]
- [[guest-session-write-needs-the-cart-cookie]]
- `docs-internal/specs/2026-08-11-sp5-pickup-selection-persistence-design.md`
