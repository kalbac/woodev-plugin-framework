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

## Locale is part of a name-scoped request (s219 F4)

DaData's client normally derives `language` from WordPress. On an `en_US` site, a stored
Cyrillic parent (CDEK Москва, DaData Ташкент, CDEK Алматы) receives transliterated child rows.
The locality post-filter then rejects every row even though the upstream search succeeded.
For the NAME path only, `build_suggest_body()` explicitly requests `ru` when the parent's
own locality name contains Cyrillic, otherwise `en`. The existing client preserves an explicit
language, including for the settlement retry. FIAS/native-id requests keep their locale behaviour.
This retains the homonym guard without another remote translation lookup.

```php
// ❌ A Cyrillic parent compared to locale-driven transliterated rows.
$records = $provider->suggest( $query, $scope ); // language=en → Moscow ≠ Москва

// ✅ The request asks for the stored locality name's script before the same post-filter runs.
$body['language'] = 1 === preg_match( '/\p{Cyrillic}/u', $parent->own_locality_name() ) ? 'ru' : 'en';
```

Reference: [DaData address request parameters](https://dadata.ru/api/suggest/address/),
`Dadata_Api_Client::with_language()`. Regression: `DadataProviderTest::test_name_scope_requests_the_parent_language`.
A name-scoped request therefore carries an explicit `language`, which `with_language()` keeps — so the
`woodev_location_dadata_language` filter is NOT consulted for it. That bypass is deliberate: removing it re-opens
the zero-row case above.

## A region-name post-filter can empty a correct request too (s219 F7, #1632)

The CDEK pick `cdek:224` has locality «Ижевск» and region «Удмуртия», without a FIAS id.
Its exact first request is already correct in both ru_RU and en_US:

```json
{"query":"Ленина","count":20,"from_bound":{"value":"street"},"to_bound":{"value":"house"},"language":"ru","locations":[{"country_iso_code":"RU","city":"Ижевск"}],"restrict_value":true}
```

DaData names that region «Удмуртская»; the shared `regions_disagree()` post-filter rejected
all matching streets, then the settlement retry also returned nothing. Type-word removal
cannot equate «Удмуртия» / «Удмуртская», «Кабардино-Балкария» / «Кабардино-Балкарская»,
«Карачаево-Черкесия» / «Карачаево-Черкесская» or «Чечня» / «Чеченская».

```php
// ❌ Different names alone are treated as proof of different regions.
$disagrees = $normalized_cdek_name !== $normalized_dadata_name;

// ✅ Only two identified, different RU regions prove disagreement.
$aliases = Location_Record::russian_region_aliases();
$one = $aliases[ Location_Record::normalize_region_name( $cdek_name ) ] ?? '';
$other = $aliases[ Location_Record::normalize_region_name( $dadata_name ) ] ?? '';
$disagrees = '' !== $one && '' !== $other && $one !== $other;
```

`Location_Record::RU_REGION_LABELS` now owns the existing CDEK RU list formerly in the
registry (#1618); zone-state keys stay byte-for-byte unchanged. `russian_region_aliases()`
adds known DaData spellings and short region aliases; `Checkout_Config` sends that same map
as `regionNameAliases` to the classic pick guard. Unknown spellings fall back to the city
match. Do not guess by a five-letter stem: Республика Алтай and Алтайский край differ.
Known different regions still reject homonyms (Благовещенск in Амурская / Башкортостан).

Stable locality ids remain preferable: native RU DaData ids, then a canonical top-level
FIAS id, already bypass this name path. A foreign provider's opaque `raw`/numeric region
code or a translated WC state label is not a shared region identifier; do not inspect or
send it as one. Regression cases include the labels above, Чувашия, Северная Осетия,
Саха (Якутия), Ханты-Мансийский АО, Москва, Санкт-Петербург, Тула and Казань. These tests
use reduced DaData-shaped responses and prove the post-filter defect, not a fresh live API run.

## Related

- [the-cascade-keeps-only-the-key-of-a-confirmed-chain-record.md](the-cascade-keeps-only-the-key-of-a-confirmed-chain-record.md) — the client half of the same CDEK + DaData house path
- [a-locality-display-name-is-not-an-identifier.md](a-locality-display-name-is-not-an-identifier.md) — names from two providers differ in other ways too
