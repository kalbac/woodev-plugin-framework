# Geo services for Russian shops — routing and geocoding (05.11.2026)

> s217 research worker report. ⚠ Correction by the coordinator (re-read from docs.2gis.com 05.11.2026): the 2GIS «6 700 ₽ / 10 000 units» row is the price of **Routing API and Distance Matrix API** too (identical package table), and Yandex Distance Matrix is 20 800 ₽/month for 1 000 req/day or 195 000 ₽/year (226 200 extended). Design: [the v2 spec](../specs/2026-11-05-range-shipping-v2-design.md).

---

## 0. Key findings (read this first)

1. **2GIS free API no longer exists**: 2GIS stopped free API since 01.08.2021 (law.2gis.ru/api-rules [P], 05.11.2026). Now: 1-month demo key (1000 requests per service) then a paid subscription in RUB (docs.2gis.com pricing [P]).
2. **Yandex Router / Distance Matrix have NO free tier** (yandex.com/dev/commercial/doc/en/concepts/distance_matrix.md [P], 05.11.2026). Cheapest is 20 800 ₽/month (1 000 req/day, basic licence). Yandex **Geocoder** is free to 1 000 req/day but only under the "show it on a Yandex JS-API map" rule — it cannot legally be used for a server-side distance calculator with no Yandex map.
3. **DaData free tier (10 000 req/day) DOES return `geo_lat`/`geo_lon`/`qc_geo`** in the Suggestions response, marked "Все тарифы" (dadata.ru/api/suggest/address/ [P], 05.11.2026). This is the best free geocoder for a Russian shop. Caveat: fields are filled when a concrete suggestion (final address) is returned.
4. **OpenRouteService Standard (free): 2 000 directions/day, 500 matrix/day, 40/min** (openrouteservice.org/plans/ via search [S] + account.heigit.org [S]; the ORS FAQ [P] confirms the 2 000/day + 40/min directions limit and that a key belongs to one person and must be used server-side). It is the only free real-road router found; each merchant registers their own key.
5. **Nominatim public server: 1 req/s absolute max, limits apply per application (sum of all users)** — a plugin installed on many sites is one "application"; periodic app requests = bulk, "strongly discouraged" (operations.osmfoundation.org/policies/nominatim/ [P], 05.11.2026). Not safe as the plugin's default.

---

## A. Routing / distance providers

| Provider | Endpoint / version | Free tier | Signup requirement | Pay in ₽ (IP/ООО/individual)? | Road network RU/CIS | Terms that matter | Traffic-aware | Price above free | Source (date) |
|---|---|---|---|---|---|---|---|---|---|
| **Yandex Distance Matrix** + «Получение деталей маршрута» (Router) | HTTP API in Yandex Maps Platform; covers RU, BY, KZ, KG, TJ, UZ, GE, AM, AZ, MD, TR | **None.** "There is no free version… need to purchase a commercial licence" | Developer cabinet + buy licence by invoice/offer (how-to-buy page not read) | Yes, prices in ₽ on yandex.ru/maps-api/tariffs; USD offer for non-RU (yandex.com/dev/tariffs/.../matrix/prices: $2 000/yr min for 1 000/day) | Best in RU/CIS (own map, own traffic) — vendor claim + common knowledge; no independent benchmark found | Standard licence: results may be cached **≤30 days**; saving longer needs "extended" licence (1.5×). Responses for "display and use in the Platform" — server-side OK. 1 matrix element = 1 request | Yes (Router: jams + weather forecast per product page) | Monthly, basic licence: **20 800 ₽/mo** (1 000/day), **58 500 ₽/mo** (10 000/day), overage 390/195 ₽ per 1 000. Yearly: 195 000 / 585 000 ₽ (basic), 226 200 / 678 600 ₽ (extended) | yandex.com/dev/commercial/doc/en/concepts/distance_matrix.md [P]; yandex.ru/maps-api/tariffs [P]; yandex.com/dev/tariffs/doc/en/matrix/terms/index.md [P] (05.11.2026) |
| **2GIS Routing API 7.0.0** + **Distance Matrix API** | `/routing/7.0.0/global` (car, taxi, bicycle, scooter, motorcycle, truck, pedestrian, PT); Distance Matrix sync ≤25 points per side | Demo key only: 1 month, 1 000 requests per service, one demo key per account | Account in «Менеджер Платформы» (docs.2gis.com) | Yes, prices in ₽; card payment gives a receipt link; invoice/ИП/ООО flow and individual signup **not verified** | Own map data of RU cities + KZ/BY etc. (2GIS covers CIS cities); rural coverage weaker than Yandex — judgement, not cited | Billing per route / per matrix cell (1 unit each). Rate limit 600/min. **Terms of use for the paid API not found** (cache/commercial) — the old free-API terms (law.2gis.ru) forbid storing geocode results except temporary caching; paid terms must be read before launch | Yes (routing page: "taking traffic into account") | Monthly packages: **10k units 6 700 ₽; 30k 15 000; 50k 20 000; 100k 30 000; 300k 46 700; 500k 63 400; 1M 79 200**; 10% off yearly | docs.2gis.com/en/platform-manager/subscription/pricing [P]; docs.2gis.com/en/api/navigation/routing/overview [P]; .../distance-matrix/overview [P]; law.2gis.ru/api-rules [P] (05.11.2026) |
| **OpenRouteService (HeiGIT)** | Directions v2, Matrix v2 on api.openrouteservice.org (a [S] connector page claims the old host is being shut down in Nov 2026 — not confirmed, re-check the base URL before building) | Standard plan: **Directions 2 000/day, 40/min; Matrix 500/day, 40/min** (matrix element limits per request not read) | Email account on account.heigit.org; no card mentioned | Free — nothing to pay. Paid commercial plans exist but pricing not read | OSM data. Good on main roads and cities, weaker on rural/unpaved/new roads; **no traffic** — judgement, not cited | ToS: key belongs to one person; must NOT be exposed client-side; server-side use or user's own key is the documented pattern (ORS FAQ [P]). Attribution "© openrouteservice.org by HeiGIT / © OpenStreetMap contributors" required. Collaborative plan (10 000/day) only for humanitarian/academic/government/non-profit. **Whether commercial use of Standard is allowed is NOT stated in anything I read** | No | Not read (contact HeiGIT) | giscience.github.io/openrouteservice/frequently-asked-questions.html [P]; openrouteservice.org/plans/ [S]; openrouteservice.org/?p=940 (ToS) [S] (05.11.2026) |
| **GraphHopper Directions API** | Cloud Directions/Matrix | Free plan **non-commercial only**; 500 credits/day shared across APIs; Matrix cost = origins×destinations/2 credits | Email | Not for RU (EUR pricing) — card in EUR not verified | OSM | Free is non-commercial → unusable for a sold plugin | Paid only | Basic **5 000 credits/day ≈ €56/mo**, Standard 15 000 ≈ €160 (figures disagree across sources, [S]) | graphhopper.com/pricing/ [S via search] (05.11.2026) |
| **Mapbox Directions / Matrix** | Directions v5, Matrix v1 | **100 000 requests/mo (Directions); 100 000 matrix elements/mo** | Account + card (brief says foreign card required; my sources conflict; not verified) | Not for RU (USD, foreign card) | OSM-based; as ORS; has live traffic profile | Standard Mapbox ToS; results usually have to be used with Mapbox maps (not verified for Directions) | Yes (`driving-traffic`) | **$2.00 / 1 000** (100k–500k), $1.60 (500k–1M), $1.20 (>1M) | mapbox.com/pricing [P] (05.11.2026) |
| **Google Routes API** (Compute Route Matrix) | Routes API (replaces legacy Distance Matrix) | **10 000 free events/mo** per SKU (Essentials) | Card required | Not for RU in practice | Strong in RU historically, but traffic/updates limited — not verified | Google Maps Platform ToS: results to be shown on Google map; caching of lat/lng limited — not re-read now | Yes (Pro SKU) | Essentials Route/Matrix **$5.00 / 1 000** (10k–100k), then $4/$3/$1.5; Pro **$10 / 1 000** | developers.google.com/maps/billing-and-pricing/pricing [P] (05.11.2026) |
| **HERE Routing v8 / Matrix v8** | Routing v8 | [S] ~5 000 routing and 2 500 matrix transactions/mo free (conflicting sources) | Account + card | Not for RU | Good, own data | Matrix transaction weighting: ×(larger of origins/destinations)×factor — free quota covers few matrices | Yes | Matrix $5.50/1 000 for 2 501–200 000 [S] | here.com supplemental pricing [S] (05.11.2026) |
| **TomTom Routing / Matrix** | Routing API, Matrix Routing API | [P] page parse: Routing "20K monthly", **Matrix 2.5K monthly** free ("2.5K" may mean per day — page ambiguous) | Account, "no credit card" for free tier | Not verified for RU | OSM-based/own; coverage in RU not cited | Not read | Yes | Pay-as-you-grow, not read | developer.tomtom.com/pricing [P, parsed text, ambiguous] (05.11.2026) |
| **Geoapify** | Routing, Route Matrix | **3 000 credits/day**, 5 req/s; Route Matrix 5×5 costs 25 credits | Email | USD card (RU payment not verified) | OSM | Free: "limited commercial use", attribution (sources disagree [S]); caching terms unknown | Partly (via mode) | **$59/mo** for 10 000 credits/day | geoapify.com/pricing [S] (05.11.2026) |
| **LocationIQ** | Directions/Matrix (OSM/Valhalla-like) | **5 000 req/day**, 2/s (official page lists these [S]) | Email | USD card; RU not verified | OSM | Free tier "limited commercial use", attribution | No | ~$45–49/mo paid [S] | locationiq.com/pricing [S] (05.11.2026) |
| **Stadia Maps (Valhalla)** | Matrix routing, traffic-influenced | Page parsed but numbers not extractable | — | — | OSM | — | Optional | — | stadiamaps.com/pricing [P — unreadable] (05.11.2026) |
| **VK Карты** | maps.vk.com — dev docs page needs JS; **nothing found on a public routing/geocoder API or tariffs** | unknown | unknown | unknown | unknown | unknown | unknown | unknown | maps.vk.com, dev.vk.com/ru/maps [P — empty shell] (05.11.2026) |
| **Sputnik / Maps.me** | **Not researched** (no evidence of a public server-side routing API for third parties) | — | — | — | — | — | — | — | — |

---

## B. Geocoding (address → coordinates)

| Provider | Free tier | Returns coords? | Signup | RUB payment | Terms that matter | Price | Source (date) |
|---|---|---|---|---|---|---|---|
| **DaData Suggestions (address)** | **Free tariff: 10 000 req/day**, same data as «Лёгкий»; logo must be shown in the dropdown (widget) | **Yes: `data.geo_lat`, `data.geo_lon`, `data.qc_geo` — doc header "Все тарифы"**; "additional fields are filled when a concrete suggestion is chosen — before that they're empty". Foreign addresses: limited fields but coordinates present | Registration → free tariff is auto-enabled | Paid tariffs by invoice (min 1 year) | Free tier: no uptime guarantee [S]; **storage/caching terms of DaData not read** | Paid yearly: **Лёгкий 14 000 ₽/yr** (50k/day), Расширенный 28 000, Максимальный 56 000. Out-of-subscription: **Геокодирование 20 коп./адрес**, Стандартизация 20 коп./адрес | dadata.ru/pricing/ [P]; dadata.ru/api/suggest/address/ [P] (05.11.2026) |
| **`qc_geo` values** (Clean/Suggest) | — | 0 exact coordinates, 1 nearest house, 2 street, 3 settlement, 4 city, 5 not determined | — | — | — | — | dadata.ru/api/clean/address/ [P] (05.11.2026) |
| **DaData Clean (Стандартизация)** | not in free tariff | Yes (`qc_geo`) | — | ₽ | — | **0.20 ₽ per record** (20 коп.) | dadata.ru/pricing/ [P] (05.11.2026) |
| **Yandex HTTP Geocoder** | **Free: 1 000 req/day total** (geocoder + others on the key); more only on paid licence (yandex.ru/legal/maps_api §6.6.2) | Yes | API key from Developer cabinet; key required | Paid licence in ₽ (226 200 ₽/yr for 1 000/day, 390 ₽ per 1 000 over) | **§6.6.1: the free Geocoder may be used only on sites/apps that use the Yandex JavaScript API map; results only for display on that map; no showing on third-party maps. §5.1.6: no storing except cache ≤30 days. 25 000/day figure in old forum posts is obsolete.** Paid terms (1.1.1): requests "for display on the Platform"; 30-day cache on standard licence | see left | yandex.ru/legal/maps_api/ [P]; yandex.ru/maps-api/tariffs [P] (05.11.2026) |
| **2GIS Geocoder API** | demo key 1 000 requests only | Yes | Platform Manager | ₽ | free API discontinued 01.08.2021; old terms forbade storing except temporary cache | paid package from 6 700 ₽/mo (Geocoder billed per successful request); exact Geocoder package price not extracted | docs.2gis.com [P]; law.2gis.ru/api-rules [P] (05.11.2026) |
| **Nominatim (public)** | free, ≤ **1 req/s absolute**, per application incl. all users | Yes | none, but valid UA/Referer identifying app; stock library UAs forbidden | free | "Periodic requests from apps are considered bulk… strongly discouraged"; must be able to switch service at their request without a software update; autocomplete forbidden; results must be cached; ODbL attribution; policy may change without notice; reselling/geocoding-centric apps must run own service | free | operations.osmfoundation.org/policies/nominatim/ [P] (05.11.2026) |
| **Geoapify Geocoding** | 3 000 credits/day (~1 credit/req) | Yes | email | USD | see A | $59/mo | [S] (05.11.2026) |
| **LocationIQ Geocoding** | 5 000/day free key; keyless 1 000/day/IP [S] | Yes | email | USD | attribution | ~$45+/mo | [S] (05.11.2026) |
| **OpenCage** | Free trial **2 500 req/day, 1 req/s**, "testing only", "no credit card"; **cannot store data permanently on trial** | Yes | email | EUR | trial not for production | €45/mo = 10 000/day (storage allowed) | opencagedata.com/pricing [P] (05.11.2026) |

---

## C. Recommendation — bundles for v2 (ranked for a Russian small shop)

Assumptions: one geocode + one route per quote; quotes cached by (origin, destination-coord-rounded). "Per month" = 30 days.

### Bundle 1 — cheapest, straight-line mode

**DaData Suggestions (free) for the buyer's address → haversine to the warehouse; warehouse coordinates typed/picked once by the merchant (no API for that).**

| | |
|---|---|
| Merchant registers | DaData (email) → free API key. No card. |
| Cost @ ~100 quotes/day | 0 ₽ (100 req/day ≪ 10 000) |
| Cost @ ~3 000 quotes/day | 0 ₽ if ONE Suggest request per quote (3 000 < 10 000); would break if the buyer's browser calls Suggest per keystroke (10–30 req/address = 30k–90k/day) — server-side single call only, plus a cache |
| Risks | Free tariff: no uptime guarantee [S]; DaData terms on storing/caching results **not read** — check before shipping; the dropdown-logo rule applies only to a widget, not to a server call (not confirmed in writing). Coordinates accuracy per `qc_geo`; reject `qc_geo ≥ 3` for a distance price (city/settlement centroid). |
| Fallback | Yandex Geocoder 1 000/day is **not** usable here (must display on Yandex map); Nominatim only as a clearly-labelled optional fallback with the merchant's own contact in the User-Agent and caching — still policy-fragile (per-application cap shared by all installs). |

### Bundle 2 — best road distance payable from Russia

**DaData Suggestions (free) for geocoding + ORS Standard key (free, merchant's own) for road distance, with 2GIS paid as the upgrade path.**

| | |
|---|---|
| Merchant registers | DaData (email); HeiGIT/ORS account (email) → own API key (ORS ToS: one key per person, server-side) |
| Cost @ ~100/day | 0 ₽ (ORS 100 ≪ 2 000 directions/day) |
| Cost @ ~3 000/day | ORS free quota exhausted (2 000 directions/day, 40/min) → need cache + straight-line fallback beyond quota, **or** switch to 2GIS: ~90 000 units/month ≈ **30 000 ₽/month** (100k package; 27 000 ₽ with annual discount) |
| Cost @ 100/day on 2GIS (alternative) | ~3 000 units/mo → 10k package **6 700 ₽/month** (~72 000 ₽/yr with annual discount) — expensive for a small shop |
| Risks | ORS: commercial use of the free Standard key **not stated** in what I read; "Single End User" wording in ToS [S]; no traffic; base host reportedly changing Nov 2026 [S]. 2GIS: paid terms (cache/commercial) not read; sign-up route for ИП/individual not verified. ORS availability from RU IP not verified. |

### Bundle 3 — best quality regardless

**DaData (paid Лёгкий if needed) + Yandex Router/Distance Matrix (traffic-aware) — or Google Routes if the merchant can pay in USD.**

| | |
|---|---|
| Merchant registers | Yandex Developer cabinet; buys a licence (invoice in ₽); DaData free tier or paid |
| Cost @ ~100/day | **20 800 ₽/month** (Distance Matrix, 1 000/day, basic licence, monthly) — or 195 000 ₽/yr |
| Cost @ ~3 000/day | **58 500 ₽/month** (10 000/day tier); 585 000 ₽/yr |
| Risks | Price is prohibitive for a typical small shop (only for large shops or a plugin-vendor-paid plan). Standard licence: cache ≤30 days only; extended licence (1.15–1.2× price) to store longer. Geocoder inside Yandex stays under map-display rule — keep DaData for geocoding. Google/Mapbox/HERE: foreign card/sanctions risk for RU merchants, not for the plugin buyer. |

### Overall ranking

1. **Bundle 1** as default in v2 (zero cost, zero friction).
2. **Bundle 2** as the optional "real road distance" setting with ORS free key; show the quota and fall back to straight-line×coefficient when exhausted.
3. **Bundle 3** only as "pro" preset (Yandex or 2GIS), documented as paid by the merchant.

---

## Not verified

- Terms of use of 2GIS **paid** API (cache/commercial) and the ИП/ООО/individual onboarding; 2GIS Geocoder package price; VK Карты API (no public documentation found); Sputnik/Maps.me (not researched); ORS commercial-use clause for Standard plan and RU-IP availability; Mapbox/Google/HERE card requirement and RU availability; DaData storage/caching terms; Yandex "how-to-buy" requirements for individuals; Stadia/TomTom/Geoapify/LocationIQ exact terms (aggregator figures only); independent road-network quality benchmarks for RU/CIS.
- Pages that failed or were unreadable: openrouteservice.org/plans (404 to curl; figures via search), account.heigit.org (JS shell), HERE pricing (JS shell), Stadia pricing (JS), VK dev docs (JS shell), docs.2gis.com/ru/platform-manager/keys (404 to curl).

## Risks

- **Yandex Geocoder free terms are a legal trap** for this plugin (display only on Yandex JS map, ≤1 000/day, ≤30-day cache); do not ship it as the default.
- **Nominatim public server is per-application**: a plugin sold to many sites shares one quota/UA — likely to get the whole plugin blocked; do not use as default.
- **Free tiers can change** (DaData uptime, ORS host move in Nov 2026, Yandex/2GIS prices); build all providers behind an adapter with backoff + cap + cache (consistent with the licence-server rule).

## Related

- [v2 design spec](../specs/2026-11-05-range-shipping-v2-design.md)
- [v1 recon](2026-11-05-range-shipping-v1-recon.md)
