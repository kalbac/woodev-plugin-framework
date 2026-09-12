# Gotcha: [compat/hpos] — a row rebuilt from the same `WC_Order` after an action is stale ONLY on the legacy CPT store
> Tags: hpos, woocommerce, rest, measurement | Session: s134

## What happens

A REST route performs an action on an order, then rebuilds the row from the SAME `WC_Order`
instance and returns it so the client can swap it in place:

```php
$handler->export( $order );          // writes carrier_order_id through the order handler
return [ 'row' => $this->build_row( $order, $provider ) ];   // ← stale on CPT
```

On **HPOS** this is correct. On the **legacy CPT store** the row comes back describing the order as
it was BEFORE the action — for #824 that meant a just-exported order returned `is_exported: false`
and its pre-action buttons, so the merchant was invited to export it a second time.

## Root cause

`Woodev_Order_Compatibility::update_order_meta()` branches on the datastore:

```php
if ( Woodev_Plugin_Compatibility::is_hpos_enabled() ) {
    $order->update_meta_data( $meta_key, $meta_value );   // the instance you hold IS updated
    $order->save_meta_data();
} else {
    update_post_meta( $order_id, $meta_key, $meta_value ); // written AROUND that instance
}
```

`update_post_meta()` writes the row and invalidates WordPress's own meta cache, but the `WC_Order`
object already in memory keeps the meta it read at construction.

## Why nothing catches it

- **A mocked unit test cannot**: the mock returns whatever the test told it to, so the read after
  the write is whatever you staged.
- **The rig cannot**: it runs HPOS, which is the half that works.
- **A green REST response cannot**: the request succeeds, the action really happened, and only the
  echoed row is wrong.

That combination is why this survived a Codex review, 3800 unit tests and a rig pass.

## ✅ Correct

Force a re-read before rebuilding anything from an order you just wrote to:

```php
$order->read_meta_data( true );   // true = bypass the cache
```

Right on both stores: under HPOS it re-reads what was just saved, on the CPT store it picks up the
write that went around the object.

## Related

- [wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore](wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore.md)
  — the same split, in the query layer rather than the write one.
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md)
- `woodev/shipping-method/rest-api/class-orders-controller.php` → `reread_order()`.
