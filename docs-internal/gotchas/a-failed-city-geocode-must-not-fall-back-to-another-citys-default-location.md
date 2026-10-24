# A failed city geocode must not fall back to the default location — it shows another city's points as the buyer's

**Namespace:** `[shipping/pickup]` · **Discovered:** s195 (24.10.2026), card #1432, measured on the rig (#1425 investigation).

## What happens

The Russian Post map (`viewport` strategy) centres itself by geocoding the city text. When the geocoder fails (a Yandex key
without the Geocoder/JavaScript API entitlement, network, an ad blocker on `api-maps.yandex.ru/services/search`) or finds
nothing, `_resolveInitialViewport()` used to apply the plugin's `defaultLocation` — Moscow. The first `boundsChange` then
fetched Moscow's offices: a Vladivostok buyer saw another city's points, with no message.

## Root cause

One shared fallback served two different situations: «the buyer has no city yet» (the default location is the right answer) and
«the buyer HAS a city but we could not place it» (any default is a wrong answer shown as a right one).

## Fix

❌ geocode fails → `map.setCenter( defaultLocation )` and fetch there.

✅ geocode fails → centre on the customer location record's own `[lat, lon]` (`config.localityCenter`, city zoom 11); with none,
HOLD the viewport (`_viewportHeld`: no points fetched for the placeholder camera, the first camera action of the customer's own
releases it), emit `cityUnresolved` and show the panel message «Не удалось определить город на карте — введите адрес в
поиске». The default location stays only for an EMPTY city field.

How the coordinates reach the browser: the wire `chain` node is `{ key, level }` plus `lat`/`lon` when the record has both
(`Location_Record::to_chain_node()`, one builder for `/select`, the checkout config and the pickup config's
`location.settlementCenter`). The client's `entry.records[level]` DROPS every field it does not name, so `adoptChain()` copies
`lat`/`lon` explicitly and `woodev_location_applied` publishes `detail.settlementCenter` beside `settlementKey` (absent
otherwise). Any new provider event must also be added to the `handlers` map in `map-provider-yandex.js` (constructor AND
`destroy()`), or `on()` silently ignores the listener.

Not covered: the Checkout block host has no `getLocalityCenter` — a failed geocode there shows the message, not the centre.

## Related

- [a-bare-city-name-geocodes-to-a-namesake-send-the-region-too](a-bare-city-name-geocodes-to-a-namesake-send-the-region-too.md) — the geocode query this fallback backs up
