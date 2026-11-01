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

Send a foreign parent's locality name WITHOUT the region, as DaData's `city`. A foreign settlement is
not always DaData's city: CDEK's Черкизово (Пушкинский городской округ) is DaData's `settlement` inside
«г Пушкино». Measured live, «Главная 1»: `city: «Черкизово»` → 0, `settlement: «Черкизово»` → 10. So when
the city answer is empty after the filter below, `Dadata_Provider::suggest()` sends a SECOND request by
`settlement`. Keep the region only when there is no locality.

Do not put both names in one `locations` list, though it is a documented OR. Measured on the same day:
with two entries DaData stops cutting the locality out of the labels under `restrict_value`, and every
CDEK city's list reads «г Москва, ул Тверская, д 1» instead of «ул Тверская, д 1».

Without the region, a same-named city in another region answers too, and `restrict_value` makes the
labels identical: under CDEK's Благовещенск (Башкортостан) DaData lists «ул Ленина, д 1» of Амурская
область first. Picking it would write postcode 675002 into the form before the chain guard refuses it.
So `Dadata_Provider::suggest()` drops every result that fails `Location_Record::is_same_locality_as()`
against the foreign parent BEFORE the widget sees it, and asks DaData for 20 results (its cap) so
homonyms do not use up the ten the buyer sees. The pick-time guard (`isSameLocality()` in the
cascade) stays as the second line.

```php
// ❌ 0 suggestions whenever the providers spell the region differently
$locations = [ [ 'region' => 'Свердловская область', 'city' => 'Екатеринбург' ] ];

// ❌ finds Черкизово, but every label grows the locality prefix
$locations = [ [ 'city' => 'Черкизово' ], [ 'settlement' => 'Черкизово' ] ];

// ✅ the city alone; a second request by settlement only when the filtered answer is empty
$locations = [ [ 'country_iso_code' => 'RU', 'city' => 'Черкизово' ] ];
```

## Related

- [the-cascade-keeps-only-the-key-of-a-confirmed-chain-record.md](the-cascade-keeps-only-the-key-of-a-confirmed-chain-record.md) — the client half of the same CDEK + DaData house path
- [a-locality-display-name-is-not-an-identifier.md](a-locality-display-name-is-not-an-identifier.md) — names from two providers differ in other ways too
