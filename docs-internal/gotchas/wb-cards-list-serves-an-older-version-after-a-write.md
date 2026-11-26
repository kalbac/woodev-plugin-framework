# Gotcha: [api/wb] — Wildberries `cards/list` can serve an older version of a card after a newer one was already read

> Tags: api/wb, read-after-write, marketplace | Card: #1995 (M0a, 26.11.2026)

## What happens

After `POST /content/v2/cards/update` the cards list (`POST /content/v2/get/cards/list`) showed the change, and four
seconds later returned the **previous** version of the same card, with the older `updatedAt`. A second update built
from that read changed only `vendorCode` — and silently put the old description back. Both requests answered 200 and
the error list stayed unchanged. `cards/update` overwrites the whole card, so every field travels back with it.

## Root cause

Reads are not read-after-write consistent: consecutive reads, even within one second, can come from versions of
different age. The OpenAPI text speaks of a synchronisation of up to 30 minutes and promises no ordering.

## Fix

❌ Wrong — read, change one field, write; or trust one read that shows the previous write:

```php
$card = $client->get_card( $nm_id );              // may predate our own last update
$card['vendorCode'] = $new_code;
$client->update_card( $card );                    // restores every older field
```

✅ Correct — serialise writes per `nmID`, remember when and what was last sent, and refuse a read older than that:

```php
$card = $client->get_card( $nm_id );

if ( $card['updatedAt'] < $link->last_write_confirmed_at() ) {
    return Result::retry_later();                 // a stale version: do not merge onto it
}
```

`last_write_confirmed_at()` is the `updatedAt` of the first read that showed the write. Until one has been seen, the
next update of that card waits. Measurement and timeline:
[measurement.md](../research/2026-11-26-wb-seller/measurement.md#5-card-update--own-field-only-d2).

## Related

- [WB Seller measurement log](../research/2026-11-26-wb-seller/measurement.md) — M0a item 5, fixtures `w06-*` and `w07-*`
- [Wildberries Seller 1.0 design](../specs/2026-11-26-wildberries-seller-plugin-design.md) — §5.5 create and update, card WP4
