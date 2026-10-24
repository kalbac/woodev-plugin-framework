# A bare city name geocodes to a namesake — send the region too

**Namespace:** `[shipping/pickup]` · **Discovered:** s195 (24.10.2026), card #1425, measured on the rig.

## What happens

A viewport-strategy carrier (Russian Post) centres its pickup map by geocoding the checkout's city field. The field holds
only the settlement name. For «Берёзовка, Ачинский район, Красноярский край» the geocoder was asked for `Берёзовка` and
answered with a Берёзовка at 53.7 N, 25.5 E (Belarus): the map opened there, the bbox points request came back empty and the
shop heard «Почта открывает не тот город».

## Root cause

`pickup-mount.js` `resolveLocality()` returns the city field's value, and `pickup-session.js` `buildProviderConfig()` handed it
to `map-provider-yandex.js` `_resolveInitialViewport()` as the whole geocode query. The region sits in the state field, which
nothing read. The same bare name is also what the bulk carriers' name-addressing fallback sends, but there the SERVER's record
outranks it — only a viewport map has no other anchor.

## Fix

❌ `ymaps.geocode( cityField.value )`

✅ `ymaps.geocode( 'Берёзовка, Красноярский край' )` — `host.getGeocodeQuery()` (city + the state field's VISIBLE label, never
its code; a region that repeats the city is dropped). `config.locality` stays the bare city: `trimAddressValue()` strips it
from address suggestions and a longer string would stop matching.

Not covered: the Checkout block host (`src/checkout-blocks/pickup-host.ts`) has no `getGeocodeQuery` and keeps the bare city;
a geocoder FAILURE (no Geocoder entitlement on the key, network) was a silent fall-back to `defaultLocation` (Moscow) —
fixed in #1432, see [a-failed-city-geocode-must-not-fall-back-to-another-citys-default-location](a-failed-city-geocode-must-not-fall-back-to-another-citys-default-location.md).

## Related

- [ymaps-suggest-not-geocode-for-address-lists](ymaps-suggest-not-geocode-for-address-lists.md) — the other place a free-text geocode ranked the wrong thing first
- [the-pickup-modal-s-locality-comes-from-the-resolved-record-not-the-city-field](the-pickup-modal-s-locality-comes-from-the-resolved-record-not-the-city-field.md) — the points query's addressing, which this does not change
