# A single-place key can have no customer-chain record

> Tags: shipping/location, city-mode, blocks, scope | Session: s219 F4

## What happens

Blocks' single-place component prefills and locks City and publishes the listed record's key,
but it has no chooser and makes no `/location/select` call. Outside the default country, a
`within` lookup against only the customer chain misses and addresses become country-wide.
The same failure is possible for a non-default hidden RU place; a fixed default can mask it.

## Fix

The shared REST scope builder checks the customer chain first, then the merchant's city-mode
records. It hands the matching record unchanged to `Location_Scope::within()`, retaining the
cross-country and parent-level guards. It performs no provider lookup or customer write, and
an enriched chain record takes precedence over the saved settings record.

```php
// ❌ Only a shopper's previous /select can supply the parent.
$records = null !== $chain ? $chain['records'] : [];

// ✅ A merchant-listed record can scope a single prefilled place too.
$records = array_merge( array_values( $records ), $service->get_settlement_choices()['records'] );
```

Regression: `LocationControllerTest::test_suggest_scopes_a_listed_place_without_a_customer_chain`
(DaData UZ, CDEK KZ, non-default RU), plus cross-country and enriched-chain precedence tests.
A saved row without FIAS is still scoped by name; this read does not call selection-time enrichment.

## Related

- [within-applied-reports-the-scope-builder-not-the-provider.md](within-applied-reports-the-scope-builder-not-the-provider.md)
- [dadata-filters-a-region-only-by-its-own-spelling.md](dadata-filters-a-region-only-by-its-own-spelling.md)
