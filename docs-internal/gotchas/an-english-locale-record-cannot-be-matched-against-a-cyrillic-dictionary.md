# An English-locale record cannot be matched against a Cyrillic carrier dictionary — ask for the Russian spelling, explicitly

**Topic:** `[shipping/location]`
**Discovered:** s159 (07.10.2026), card #1152

## The trap

`Dadata_Api_Client::with_language()` derives the answer language from `get_user_locale()`, so under any non-`ru` locale every `name` / `region` / `label` of a DaData record is a **transliteration** («Khimki», «Moskovskaya»). The identity (`fias_id`, KLADR, coordinates, `cdek_id`) is byte-identical, but a carrier adapter that verifies a candidate by NAME against the carrier's Cyrillic dictionary («Химки» ≠ «Khimki») throws away even a correct id. Measured on the rig: under `en` no Russian city except Moscow and Saint-Petersburg resolved to a CDEK code.

```php
❌ $name = $record->settlement()['name'];                 // "Khimki" under en — never matches the dictionary
❌ // forcing language=ru globally                          // puts Cyrillic suggestions back into an English form
✅ $ru   = $service->get_record_in_language( $record );    // same key, Russian text; null → carry on with $record
```

## What the accessor does, and its three small traps

- **An explicit `language` bypasses BOTH the locale and the `woodev_location_dadata_language` filter** — `with_language()` returns early for a body that already has the field. That is the point; do not "also" route it through the filter.
- **A cached miss must be `[]`, not `false`**: `get_transient()` answers `false` for «not stored», so a miss is stored as an empty array and read with `is_array()`. A transport failure or an unmappable 200 THROWS `Location_Provider_Exception` and stores nothing — an outage must not calcify into a day-long «unknown».
- **The id asked is the record's own key** (`dadata:{fias}`), not the city/settlement id `delivery_ids()` uses — the answer has to be the record itself. A derived key has no FIAS id to ask about and answers `null`.

## Related

- [a-locality-display-name-is-not-an-identifier](a-locality-display-name-is-not-an-identifier.md)
