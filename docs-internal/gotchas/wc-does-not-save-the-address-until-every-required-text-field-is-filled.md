# WooCommerce saves no address until every required TEXT field in the block is filled — the gate is in the JS

**Namespace:** `[woocommerce/*]`
**Discovered:** 2026-08-12 (s65, operator's correction on #272)

## The trap

`WC_AJAX::update_order_review()` reads `$_POST['city']`, `$_POST['postcode']` and friends
individually, each with a `null` fallback, and then calls `WC()->customer->save()`
**unconditionally**. Read the server alone and you conclude WooCommerce happily persists a
partial address. It does — but it is almost never asked to.

The gate lives in the client, `assets/js/frontend/checkout.js`:

```js
maybe_update_checkout: function () {
    var update_totals = true;

    if ( $( wc_checkout_form.dirtyInput ).length ) {
        var $required_inputs = $( wc_checkout_form.dirtyInput )
            .closest( 'div' )
            .find( '.address-field.validate-required' );

        if ( $required_inputs.length ) {
            $required_inputs.each( function () {
                if ( $( this ).find( 'input.input-text' ).val() === '' ) {
                    update_totals = false;
                }
            } );
        }
    }

    if ( update_totals ) { wc_checkout_form.trigger_update_checkout(); }
},
```

While ANY required address field in the same block is empty, `update_checkout` never fires, so
`update_order_review()` never runs and nothing is saved. A customer who picks a city and stops —
because the carrier needs nothing else — has their choice discarded on reload.

## Two details that decide where a fix goes

1. **Only text inputs count.** The check is `$( this ).find( 'input.input-text' ).val() === ''`; a
   required `<select>` contains no `input.input-text`, so `.val()` is `undefined`, not `''`, and it
   does **not** block. `billing_state`, and any takeover field converted to a select, are invisible
   to this gate. What blocks is the required TEXT fields — typically «Адрес» and «Индекс».
2. **Scope is `.closest('div')`** from the changed field — the surrounding block, not the whole
   form.

## Why it matters here

Sites running these shipping plugins routinely disable the address fields a carrier does not need.
That is precisely the configuration in which this gate never opens. Any design that persists
customer location data by relying on `updated_checkout` is therefore built on an event that, for a
large share of real installs, does not arrive.

The reference implementations do not rely on it: СДЭК saves on its own AJAX the moment the city
changes (`WC_Edostavka_Ajax::set_customer_location_model()` → `$customer_location->save()`), which
also solves the second, independent problem — WooCommerce has nowhere to put a carrier's own
`city_id` / `fias_guid` / `city_uuid` anyway.

## How this was got wrong once

Measured on the rig, where the test customer already had «Адрес» and «Индекс» filled: the city
persisted, and that was read as "no gate exists". The observation was consistent with both
hypotheses and was resolved in favour of the wrong one. **A measurement taken in a state where the
suspected gate is already open proves nothing about the gate** — vary the condition, or read the
code that would implement it.

## Related
- [[guest-session-write-needs-the-cart-cookie]] — the other half of "the session write you think
  happened may not have"
- [[custom-checkout-field-is-empty-on-reload-by-construction]]
