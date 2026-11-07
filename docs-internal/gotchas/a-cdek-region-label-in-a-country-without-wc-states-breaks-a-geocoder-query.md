# Gotcha: [shipping/location] — a CDEK region label written as the free-text State breaks a geocoder query in a country WooCommerce has no state list for

> Tags: cdek, dadata, geocode, region, kazakhstan, city-mode | Session: s220 (07.11.2026)

## What happens

City mode, KZ with one listed place (Алматы, a CDEK record). WooCommerce has no state list for KZ, so the checkout's
State is free text, and city mode wrote CDEK's region label there: «городской округ Алматы». The range plugin built its
DaData geocode query as «State, City» = «городской округ Алматы, Алматы» → DaData returned **0** results (probed;
«Алматы» alone returns 3). No rate, «Enter your address to view shipping options». The framework side had the same
mismatch: CDEK «городской округ Алматы» vs DaData «Алматы г» did not fold to one region, so a picked house did not price.

## Root cause

A provider's region LABEL is not a geocoder-friendly region name; for a city of republican/federal significance it only
restates the city. Countries with a WC state list never expose it, because the State is a code there.

## Fix

- Framework (#1645): region folding treats «городской округ X» and «X г» as one region.
- Plugin (range `kalbac/1642-fix2-range`, `Package_Address::to_string()`): drop a State whose normalised name equals the
  normalised city before building the free-text query.
- Any plugin that geocodes from the checkout address: never send a State that only names the city; test with a KZ/BY
  CDEK place, not only RU.

## Related

- [dadata-filters-a-region-only-by-its-own-spelling](dadata-filters-a-region-only-by-its-own-spelling.md) — DaData's own spelling rule
- [Session s220](../sessions/s220.md) — where it was hit
