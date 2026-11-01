# Gotcha: [shipping/location] DaData filters a region only by its own spelling

> Tags: dadata, locations, cross-provider, cdek | Session: s209 (#1534)

## What happens

On a shop with the CDEK location provider, the «Адрес» field offers no house at all in Екатеринбург,
Краснодар and most other cities. Москва and Казань work. The `/location/suggest` answer is
`{"suggestions":[], "scope_narrowing":"degraded"}`, so the Ozon courier, which needs a house, is
never offered there.

## Root cause

Under a foreign parent `Dadata_Provider::build_locations_constraint()` has only names. It used to send
both the CDEK region name and the city name in DaData's `locations` filter. DaData matches `region`
against its OWN spelling of the region, case-insensitively. Measured on the live API (01.11.2026),
«Ленина, 50»:

| `locations` | suggestions |
|---|---|
| `region: «Свердловская область»`, `city: «Екатеринбург»` | 0 |
| `region: «Свердловская»`, `city: «Екатеринбург»` | 5 |
| `city: «Екатеринбург»` alone | 5 |
| `region: «Краснодарский край»`, `city: «Краснодар»` | 0 |
| `region: «Ханты-Мансийский»`, `city: «Сургут»` | 0 |
| `region: «Ханты-Мансийский автономный округ - Югра»`, `city: «Сургут»` | 5 |

CDEK writes «Свердловская область». DaData writes «Свердловская», but it keeps the type words in the
Югра name. No type-word strip rebuilds DaData's spelling.

## Fix

Send a foreign parent's city alone. Keep the region only when there is no city. A same-named city in
another region is still refused: a house enters the chain only when its region does not disagree with
the confirmed one (`Location_Record::is_same_locality_as()`, `isSameLocality()` in the cascade).

```php
// ❌ 0 suggestions whenever the providers spell the region differently
$location = [ 'region' => 'Свердловская область', 'city' => 'Екатеринбург' ];

// ✅ the city alone; the region is checked on the pick, not in the filter
$location = [ 'city' => 'Екатеринбург' ];
```

## Related

- [the-cascade-keeps-only-the-key-of-a-confirmed-chain-record.md](the-cascade-keeps-only-the-key-of-a-confirmed-chain-record.md) — the client half of the same CDEK + DaData house path
- [a-locality-display-name-is-not-an-identifier.md](a-locality-display-name-is-not-an-identifier.md) — names from two providers differ in other ways too
