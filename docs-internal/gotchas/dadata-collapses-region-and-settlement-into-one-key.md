# gotcha: DaData gives a city of federal significance ONE key on both levels, and no ancestors at all

**Namespace:** `[shipping/location]`
**Discovered:** s111 (2026-09-01), card #707

## What happened

The popular-settlements list stopped narrowing under DaData. The operator saw it as a granularity
difference against the CDEK fixture:

> Granularity works, but not as sharply as with CDEK. The popular-cities list shows for every
> region if the region was filled in **automatically**. If I pick the region **by hand**, the
> granularity starts working.

The stored customer record explains it — one `fias_guid` standing at two different levels:

```text
region      key=dadata:0c5b2444-70a0-4932-980c-b4dc0d3f02b5  level=region      label=Moscow city
settlement  key=dadata:0c5b2444-70a0-4932-980c-b4dc0d3f02b5  level=settlement  label=Moscow city
```

## Root cause: the provider drops a self-referential ancestor, on purpose

`Dadata_Provider::ancestor_keys_from_dadata_fields()` skips any upstream id equal to the row's own:

```php
if ( '' === $native_id || $native_id === $fias_id ) {
    continue;
}
```

Moscow **is** its own region, so `region_fias_id === fias_id`, the ancestor is dropped, and the
record publishes `ancestors: []`. The skip is correct and deliberate — the docblock says that
identity "is answered by `Location_Record::is_within()` separately, never carried twice over".
The bug was in the CONSUMERS, which tested `ancestors()` raw instead of asking `is_within()`.

## Measured spread — it is not two cities, it is three countries

Live `suggest()` across all nine countries DaData serves (s111):

| | colliding: region key == own key, `ancestors` empty |
|---|---|
| **RU** | Moscow, St Petersburg, **Sevastopol**, **Baikonur** |
| **BY** | **5 of 8 sampled** — Minsk, Brest, Gomel, Vitebsk, Mogilev (Grodno does NOT) |
| **KZ** | **5 of 8** — Almaty, Astana, Shymkent, Pavlodar, Kostanay |
| **UZ** | **3 of 7** — Tashkent, Samarkand, Namangan |
| KG, AM, AZ, GE, MD, TJ, TM | none |

For BY/KZ/UZ the id is not FIAS at all but an OSM relation (`relation:59195`), and DaData puts the
same one in `fias_id` and `region_fias_id`. Grodno behaving unlike Brest means DaData's own data is
not internally consistent here, so the ancestor chain cannot be relied on in those countries.

## ✅ The rule

**Never test `ancestors()` directly. Ask `Location_Record::is_within()`,** which is reflexive:

```php
return $key === $this->data['key'] || in_array( $key, $this->data['ancestors'], true );
```

Fixed in s111 for `Location_Service::region_ancestor_of()` (a settlement with no ancestors now
offers its OWN key as the region candidate) and for `location-cascade.js`'s scoped popular filter.

## ⚠ The half that is NOT fixed, and why

`location-cascade.js`'s #538 sibling-intersection branch still fails OPEN on an empty ancestor set.
From the data alone, **"this provider publishes no ancestry" and "this record is its own region"
are the same empty array** — and #538 deliberately decided the first must never hide entries, a
decision pinned by its own test. Telling the two apart needs a decision, not a patch. Card #707
stays open for it.

## Related

- [a-derived-ancestor-is-not-the-one-the-customer-picked](a-derived-ancestor-is-not-the-one-the-customer-picked.md) — the other ancestry trap in this layer
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — why this needed a LIVE measurement, not a fixture
- [a-hand-typed-format-table-drifts-from-the-real-spec](a-hand-typed-format-table-drifts-from-the-real-spec.md) — the same lesson about someone else's data
