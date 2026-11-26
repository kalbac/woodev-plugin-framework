# gotcha: a stock row keyed by warehouse outlives the warehouse choice

**Namespace:** `[framework/marketplace-stock]`
**Discovered:** 26.11.2026, #2034 (found on the Wildberries sandbox; Ozon Seller 2.0.0 behaves the same)

## What happened

`sync_state` is keyed by (account, link, **warehouse**, kind). When a product moved to another warehouse the flusher retired the
old pair — and only when it happened to be due. A marketplace keeps the last quantity it was sent, so it went on selling the same
units from the old warehouse, while the shop showed only the new one. Nothing recorded a first value for the new warehouse either.

## ❌ Wrong

```php
// The shop stops writing to the old warehouse, so the marketplace "forgets" it.
$this->queue->drop( $old_key, 'warehouse changed', $token );
```

## ✅ Correct

```php
// The old warehouse is sent zero while the marketplace may hold units there.
if ( Sync_State_Queue::may_hold_stock( $row ) ) {
	$this->intents->record_vacate( $account_id, $link_id, $old_warehouse );
}
```

Six traps come with that zero:

- **«No warehouse» and «switched off» are different answers.** `Stock_Warehouse::for_link()` returns null for both, so an intent
  gated on it is lost while the warehouse is off and nothing is left to send when it returns (#2042). Record for `chosen()` and
  let the sender wait.
- **Only when something replaces it.** If the chosen warehouse cannot be written to (switched off) or the stock is not managed,
  zeroing the old one stops the sales altogether — for the whole account when it is the default warehouse.
- **Order it explicitly.** Equal `pending_since` and `link_id` leave the warehouse id to break the tie: a test passed only
  because the left id was the lower one.
- **It is not the product's stock.** Do not write it to the link's last pushed stock, and clear the row's readback:
  `Sync_State_Store::overview()` takes a link's newest readback across warehouses (Ozon's storefront buy button reads it).
- **The due list is a snapshot.** While one pair is settled, a value may be moved onto another row already read. Cancel a zero
  with a conditional statement (`Sync_State_Queue::cancel_vacate()`), never by what the snapshot said.
- **A zero never changes**, so "a new value is a new try" never re-arms a refused one — `retry_failed()` does it at the next
  record.

## Related

- [framework-classmap-autoload-vendored-boot](framework-classmap-autoload-vendored-boot.md)
- [../wiki/marketplace-admin.md](../wiki/marketplace-admin.md)
