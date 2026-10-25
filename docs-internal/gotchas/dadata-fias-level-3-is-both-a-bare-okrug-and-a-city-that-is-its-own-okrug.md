# gotcha: DaData `fias_level 3` is a bare district AND a city that is its own okrug — filter by `city_fias_id === area_fias_id`, not by the level

**Namespace:** `[shipping/location]`
**Discovered:** s197 (2026-10-25), card #1459 (FreeScout #17095)

## What happened

The settlement filter accepted only `fias_level` `4`, `6` (and `1` for federal cities). Дмитров, Коломна, Клин, Шатура and
Сергиев Посад — Moscow-oblast cities that are their own urban okrug — come back from `suggest/address`
(`from_bound=city`, `to_bound=settlement`) at **`fias_level 3`**, so they vanished from the «Город» suggestions and the
carriers could not resolve a free-typed «Дмитров» (`no_geo_id`). Химки, Подольск, Красногорск, Сочи, Новый Уренгой are
level 4 and were never affected.

## Root cause

ФИАС level 3 means «area» (district/okrug). DaData fills it for a city whose okrug has the same name, and then
`city`, `area` and `fias_id` all carry the SAME id (measured 25.10.2026: `city_fias_id === area_fias_id === fias_id`,
`area_with_type === city_with_type === "г Дмитров"`). The level number alone cannot tell this row from a bare district.

## Fix

Level 3 is accepted only structurally, the way the federal-city carve-out at level 1 is:

```php
// ❌ the level decides
return ! in_array( $fias_level, [ '3', '4', '6' ], true );

// ✅ the city must BE the area
if ( self::RU_FIAS_LEVEL_AREA === $fias_level ) {
    return ! ( '' !== $city_fias_id && $city_fias_id === $area_fias_id );
}
```

Pinned by the verbatim captures `tests/_fixtures/dadata/ru-settlement-{dmitrov-level-3,kolomna-level-3,khimki-level-4}.json`.
Measured at a city-bounded query, DaData did not return a bare level-3 district at all (the Moscow «Дмитровский р-н» row is
level 1 with `city_district`, already rejected); the `city_fias_id === area_fias_id` guard is kept so a district without a
city can never slip in. Note the level-65 planning rows of the same cities (СНТ, городок) also carry
`city_fias_id === area_fias_id` — they stay rejected because their LEVEL is not 3.

## Related

- [../gotcha-index/shipping-location.md](../gotcha-index/shipping-location.md) — the topic index
- [dadata-collapses-region-and-settlement-into-one-key.md](dadata-collapses-region-and-settlement-into-one-key.md) — the sibling federal-city (level 1) case
