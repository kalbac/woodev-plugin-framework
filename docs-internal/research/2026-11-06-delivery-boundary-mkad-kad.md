# Delivery limited to a settlement's real area — «в пределах МКАД / КАД», «только Москва»

Research worker report, 06.11.2026. Task `task_8c5984d588a7`, dispatch `ctx_33bd8ab50c5a`. No code, no commits.
Probe scripts and downloads: `/private/tmp/claude-501/-Users-maksimmartirosov-Projects-woodev-plugin-framework/fe395236-f77e-48ed-9328-83da3ce36d7d/scratchpad/{probe,dl}/`.
Evidence labels: **READ** (page or code read today), **MEASURED** (ran today), **UNVERIFIED** (reason given).

## 1. Summary for the operator

1. **Yes, we can — cheaply — for the two cases merchants actually ask for.** «В пределах МКАД / КАД» is a point-in-polygon test against a ring-road polygon; «только <населённый пункт>» is a FIAS-id membership test. Both need only what every DaData record already carries on the **free** tariff: coordinates and `city_fias_id` / `settlement_fias_id`.
2. **DaData's own `beltway_hit` / `beltway_distance` are a paid feature**: filled by address suggestions only on the «Расширенный» (28 000 ₽/год, 100 000 req/day) and «Максимальный» (56 000 ₽/год) plans, and by the standardisation API at 0.20 ₽/record (needs the secret key, Russia only). Free and «Лёгкий» return `null` — that is why our 124 fixture rows are `null`. It is a good *optional* data source, never the only one.
3. **Boundary data**: the МКАД and КАД rings are tiny (118 and 98 vertices, ~6 KB and ~3.5 KB; an MIT-licensed PHP library ships both). A whole settlement's administrative polygon from OSM is 80–270 KB and must be fetched at build time, never at runtime (Nominatim policy forbids app-side periodic/auto-complete use; every public Overpass instance was at capacity or timing out today). None of the 5 routing services the plugin integrates, nor Yandex, 2GIS or Google, returns an «inside» answer for a ring road; 2GIS reverse geocoding gives city/district membership (paid), Google has no locality polygons for Russia.
4. **Measured**: point-in-polygon in pure PHP-equivalent code costs ~0.5 ms per test; against the МКАД polygon, Строгино and the Kremlin come out inside, Митино, both Бутово, Коммунарка, Щербинка, Зеленоград, Реутов, Химки, Котельники and Люберцы outside — exactly the merchant's intent. The administrative Moscow polygon (OSM relation 102269) puts Зеленоград, Коммунарка and Щербинка *inside* — proof that «только Москва» and «в пределах МКАД» are different questions.
5. **Recommendation**: not a separate method. Add one setting to the existing range method — **«Граница доставки»: нет / в пределах населённого пункта <picker> / в пределах МКАД / в пределах КАД** — checked before the band grid: outside → «не доставляем» (or an optional «за кольцевой: +N ₽/км» row later). Effort **S–M** (shape B + C below, ≈ 2–3 worker rounds): a bundled GeoJSON of the two rings with «© OpenStreetMap contributors» attribution on the settings page, a FIAS ancestor check reusing `Location_Record::is_within()`, and `qc_geo ≥ 2` treated as approximate. Use DaData `beltway_hit` opportunistically when the record carries it (shape A, +S). A merchant-drawn polygon (shape D) is an L-size feature for after v2.

## 2. Findings per question

### Q1. What «inside» means, and which one merchants need

| Meaning | What it is | Who asks for it | Data needed |
|---|---|---|---|
| (a) inside the ring road (МКАД, КАД) | A physical ring, not an administrative border. Moscow's administrative territory (OSM relation 102269, `admin_level=4`, «город федерального значения») includes Зеленоград, Новая Москва (Коммунарка, Щербинка), Митино, Бутово, Строгино — only Строгино of those is inside МКАД (MEASURED, §4). The OSM `place=city` polygon of Москва (relation 2555133) is a third thing: it includes Митино/Бутово/Строгино but not Зеленоград or Новая Москва | Moscow and St Petersburg couriers, food/flowers/water: «доставка в пределах МКАД бесплатно, за МКАД +30 ₽/км». DaData built a paid product (`beltway_hit`, `beltway_distance`, READ dadata.ru/api/clean/address/) precisely because this is the retail convention in the two capitals | a ring polygon + the buyer's coordinates; or DaData paid field |
| (b) inside the settlement's administrative area | «только Химки», «только Казань», «Москва» in the FIAS sense (with Зеленоград and Новая Москва) | every other city; also Moscow shops that mean «не в область» | FIAS ids, which every DaData record carries on all tariffs (READ dadata.ru/api/suggest/address/: `city_fias_id`, `settlement_fias_id`, `area` are «базовые поля, заполняются для всех тарифов») |
| (c) inside a merchant-drawn zone | «мы возим вот сюда» — arbitrary shapes, often several zones with different prices | larger couriers, dark stores | a map editor in admin, polygon storage, PIP |

What real shops map to: (a) is the question behind «в пределах МКАД / КАД»; (b) is what city mode already approximates at the suggestion level (K11′/K13: a listed Москва scopes suggestions to the FIAS city, which *does* include Зеленоград as a city district under DaData's FIAS tree — UNVERIFIED for Зеленоград specifically; the fixture `moscow-zelenograd-corp-1` scopes by Moscow's `city_fias_id` and the city-mode spec says Зеленоград «сам не добавится», so treat the FIAS tree shape as needing a rig check). «Только Москва» is ambiguous between (a) and (b); the settings text must say which one a choice means.

### Q2. DaData

READ today: https://dadata.ru/api/suggest/address/, https://dadata.ru/api/clean/address/, https://dadata.ru/pricing/, https://dadata.ru/api/geolocate/, https://dadata.ru/api/geocode/ (all HTTP 200, 06.11.2026).

| Fact | Source |
|---|---|
| `beltway_hit`: `IN_MKAD` / `OUT_MKAD` (Москва и область), `IN_KAD` / `OUT_KAD` (Санкт-Петербург и область), empty elsewhere. `beltway_distance`: km from the ring, filled only when `OUT_*`. The page does not say how the distance is computed | clean/address |
| In **suggestions** both fields sit under «Дополнительные поля, заполняются в зависимости от тарифа» → «„Расширенный“ и „Максимальный“». Free tier and «Лёгкий» do not fill them | suggest/address |
| The same section says additional fields «заполняются при выборе конкретной подсказки» (a `count=1` request for the chosen row). Our fixture shows `geo_lat/geo_lon` filled already in `count=10` lists (124/124 rows, MEASURED), so this note is not strict for coordinates; whether a paid key fills `beltway_hit` in a `count=10` list is **UNVERIFIED** (needs a paid key; the framework sends `count=10` / `20`, never `1`, READ `class-dadata-provider.php:235,247`) | fixture + code |
| Pricing: free = «как „Лёгкий“, но 10 000 запросов в день»; «Лёгкий» 50 000/day 14 000 ₽/год; «Расширенный» 100 000/day **28 000 ₽/год, adds «Расстояние от МКАД и КАД»**; «Максимальный» 200 000/day 56 000 ₽/год. Over the limit, subscription services switch off until 00:00 | pricing |
| Standardisation (`cleaner.dadata.ru/api/v1/clean/address`): 0.20 ₽ per record, every call billed, needs `Authorization: Token` **and** `X-Secret`, «Только для России», not callable from browser JS, 20 req/s per IP. Fills `beltway_*`, `geo_*`, `qc_geo` | clean/address, geocode |
| Reverse geocoding `geolocate/address` (lat/lon → addresses within ≤ 1 000 m): free up to 10 000/day, token only | geolocate |
| FIAS membership on the free tier: `city_fias_id`, `settlement_fias_id`, `area`, `fias_level` are base fields; `city_district` / `city_district_fias_id` come only after selecting a specific address (`count=1`) | suggest/address |

How the framework already handles this (READ):
- The DaData provider stores the **whole** suggestion `data` as `raw` on every `Location_Record` (`class-dadata-provider.php:1534-1578`, `'raw' => $fields`) and the ancestor FIAS keys as `ancestors` (`ancestor_keys_from_dadata_fields()`). So a `beltway_hit` from a paid key would already ride into the customer chain's house record with **no client change**; the plugin reads that record in `Destination_Point_Resolver::point_from_address_record()` (`class-destination-point-resolver.php:104`) and the geocoder gets the same rows from `suggest()` (`class-dadata-geocoder.php:86`). What is lost today: `Geo_Point::from_record()` keeps only lat/lon/qc_geo, and `Geocode_Cache` caches the `Geo_Point`, so a beltway value would have to be added to `Geo_Point` (one field) to survive the cache.
- `clean_address()` exists in the client (`class-dadata-api-client.php:510`), is used by `normalize()` and is narrowed away when no secret is configured — the paid 0.20 ₽ route is wired but off by default. The plugin's geocoder deliberately uses `suggest` (free).
- `Location_Record::is_within()` / `ancestors()` already answer «is this address under settlement X» by FIAS id (READ `class-location-record.php`, the city-mode spec §2).

### Q3. Boundary data

**OSM administrative boundaries via Nominatim `polygon_geojson=1`** (MEASURED 06.11.2026, files in `dl/nominatim-moscow`, `dl/nominatim-spb`):

| Relation | What | Geometry | Vertices | JSON size |
|---|---|---|---|---|
| R102269 | Москва, `boundary=administrative`, `admin_level=4`, «город федерального значения» | MultiPolygon, 10 rings | 10 402 | 268 KB |
| R2555133 | Москва, `place=city` (the city proper; no admin_level) | MultiPolygon, 3 rings | 7 311 | 189 KB |
| R337422 | Санкт-Петербург, `admin_level=4` | MultiPolygon | — | 88 KB |
| R421007 | Санкт-Петербург, `place=city` | Polygon | — | 81 KB |

Nominatim usage policy (READ https://operations.osmfoundation.org/policies/nominatim/): max 1 req/s per application, a real User-Agent, results must be cached, **auto-complete and periodic requests from apps are forbidden/discouraged**, «commercial applications should keep that in mind when relying on this API for serving paying customers», may change without notice. → usable as a **build-time** source for a curated set of polygons, never a runtime dependency of a checkout.

Overpass (READ https://wiki.openstreetmap.org/wiki/Overpass_API; MEASURED today): the main instance answered `502 … at capacity` to a tiny tag query, the VK mirror the same, `overpass.kumi.systems` timed out at 150–200 s, `overpass.openstreetmap.fr` is white-listed only, `overpass.private.coffee` returned zero ways for the МКАД bbox query. The wiki itself says «commercial use should use self-hosted or paid Overpass servers». Same conclusion: build-time only, and with a fallback source.

Ring-road geometry: in OSM the МКАД is `highway=trunk` ways tagged `ref=МКАД`, one way per kilometre and carriageway (Nominatim search «МКАД», MEASURED: ways 24239566, 30131716, 440031930 …); a route relation was not found by `ref` on Overpass. Assembling a closed ring from the ways is a one-off script. **Ready-made alternative**: `mrden/mkad-distance` (MIT, packagist, last push 19.02.2024, READ GitHub API) ships `MscMkad.php` (118 vertices, lat 55.572–55.911, lon 37.369–37.843) and `SpbKad.php` (98 vertices) plus junction lists; its PIP answers for 12 test points are correct (§4). Provenance of its vertices is not stated (UNVERIFIED accuracy beyond the 12 points); `hector68/mkad-google-distance` (MIT, 2018) is a second, older copy.

Licence (READ https://osmfoundation.org/wiki/Licence/Community_Guidelines/Geocoding_-_Guideline and …/Produced_Work_-_Guideline): individual geocoding results are «insubstantial database extracts» and may be stored and mixed with proprietary data, but the application «must provide attribution to OpenStreetMap». A shipped polygon is a database extract, not a produced work; share-alike applies only when the extract is **substantial** (the guideline FAQ points to a quantitative safe harbour of 100 features — that page not fetched). Two or four ring/city polygons are far below it; a bundle of every Russian settlement's polygon would be a derivative database that must itself be offered under ODbL (the data file, not the plugin code). Either way the settings page (and ideally the checkout notice) shows «© OpenStreetMap contributors» with a link to openstreetmap.org/copyright. `osm-boundaries.com` extracts must not be redistributed under that site's own terms (READ) — do not use it. Note the framework already consumes OSM-derived data through DaData for BY/KZ/UZ (`class-dadata-provider.php:152,216`) and DaData's clean page says its coordinates come from OpenStreetMap (per the fetched page summary, UNVERIFIED wording).

Commercial APIs (READ, via official docs, 06.11.2026):

| Service | Returns a polygon? | Returns «inside city/district»? | Ring road? | Price |
|---|---|---|---|---|
| 2GIS Geocoder (reverse) | no | yes — `items.adm_div` with `type: city / district` for a point (nearest object's membership, not strict containment) | no | demo 1 000 req; 4 700 ₽ / 10 000; 600/min |
| 2GIS Places / Regions | `items.geometry.selection` exists but no documented city/district outline; `polygon` is an *input* filter ≤ ~6 km² | partly | no | 6 700 ₽ / 10 000 |
| Yandex Geocoder | no (`boundedBy` is a bbox) | `kind=locality` / `district` components | no | 1 000/day free (unofficial), 390 ₽ per 1 000 over, 226 200 ₽/год licence (UNVERIFIED, from the product page summary) |
| Google boundaries (data-driven styling) | yes, but for RU only `COUNTRY` and `ADMINISTRATIVE_AREA_LEVEL_1` — no locality | no | no | Maps Platform billing |
| Mapbox Boundaries | enterprise product; page returned empty (UNVERIFIED) | — | — | — |
| OpenRouteService | no boundary API (docs URL 404, UNVERIFIED) | — | — | — |
| The plugin's 5 routing services (`class-service-catalog.php`: ORS, Mapbox, 2ГИС, Яндекс, Google) | all are distance/matrix endpoints; none answers «inside» | — | — | already paid by the merchant per call |

### Q4. Implementation shapes

| Shape | Effort | Merchant cost | What it answers | Failure modes | Fit with existing code |
|---|---|---|---|---|---|
| **A** DaData `beltway_hit` condition on the existing method | **S** (+ one `Geo_Point` field, one setting, one rule) | DaData «Расширенный» 28 000 ₽/год, or `clean` 0.20 ₽/order | МКАД / КАД only, Moscow & SPb only; `beltway_distance` for «+N ₽/км за МКАД» | `null` on free/«Лёгкий» keys (silent!), settlement-only addresses, non-RU; needs a settings-time probe or a quote-time «approximate» notice when the field is missing; `count=10` behaviour UNVERIFIED | Record `raw` already carries it; `Destination_Point_Resolver` already fetches the record; `Geocode_Cache` must cache it |
| **B** FIAS «inside settlement X» | **S** | free | administrative membership (Moscow incl. Зеленоград/Новая Москва; any RU settlement) | CDEK-provider records lack `fias_guid` until card E1; non-RU has no FIAS → name compare; a buyer who typed a free-text address with no record → fall back to Locality_Match by name | `Location_Record::is_within()` / `ancestors()`, `City_Limit`, the `location-picker` control from the city-mode tab; the method already resolves the chain record per package |
| **C** Bundled ring polygons + PIP | **M** (polygon file, PIP helper, one setting, attribution, tests) | free | МКАД / КАД (and any other ring we bundle: ЕКАД, ЦКАД…) for **every** DaData tariff and every active provider, needs only lat/lon | coordinates with `qc_geo ≥ 2` (street/settlement centre) near the ring → wrong side (use the existing Approximate_Notice path); polygon drift as the ring changes (rare); must attribute OSM/MIT | PIP on the `Geo_Point` the resolver returns; cached with the rate (`cache_context`) |
| **D** Merchant-drawn polygon on a map | **L** | a Yandex Maps JS key (frontend already loads one for pickup, `map/class-yandex-map-provider.php`; admin has no map control yet) | anything, including several price zones | UX, storage, validation, Yandex JS pricing (UNVERIFIED), block checkout — a feature in its own right | reuses PIP from C; new admin control in the settings API |
| **E** Operator's separate method «в пределах населённого пункта» (one settlement, base price, ₽/km) | **M** but mostly duplication | free | B's answer, optionally A/C's | two methods with 90 % shared code (warehouse, resolver, distance service, notices); merchants must learn a second method; «₽/км» is already expressible by the band grid / rules | — |

Combinations: **B + C** is the base (free, provider-agnostic); **A** layers on top as a data source when the record already carries `beltway_hit` (a paid key) and as the source of `beltway_distance`; **D** supersedes C later and reuses its PIP. E is better delivered as a «Граница доставки» setting on the existing method (B + C), with «за кольцевой +N ₽/км» as an optional rule row (distance to the ring = nearest-segment straight line, computed locally; DaData's own method of computing `beltway_distance` is undocumented).

Where it plugs in (READ): `Quote_Calculator::quote()` → `locate()` → `Destination_Point_Resolver::resolve()` returns a `Geo_Point`; a boundary check sits right after `locate()` and before `choose_warehouse()`/`distance()`, returning a `Rule_Result::BEYOND_GRID`-like «не доставляем» (the buyer text «Дальше %s км: не доставляем» already exists in `class-instance-assets.php:174`) — so an outside address costs **no routing call**, which is also a saving.

## 3. Options table (the operator's one-line view)

| Option | Can we? | How hard | Cost to merchant | Recommend |
|---|---|---|---|---|
| «В пределах МКАД/КАД» via DaData field | yes | S | 28 000 ₽/год or 0.20 ₽/order | only as an add-on |
| «В пределах МКАД/КАД» via bundled polygon | yes | M | 0 | **yes** |
| «Только <населённый пункт>» via FIAS | yes | S | 0 | **yes** |
| Any settlement's exact polygon at runtime | no (policy + reliability) | — | — | no |
| Curated city polygons bundled (top-N cities) | yes, 80–270 KB each | M | 0 | not needed — FIAS covers it |
| Merchant-drawn zones | yes | L | map key | after v2 |
| Separate method | yes | M | 0 | no — a setting on the existing method |

## 4. Measurements (06.11.2026)

Test points were geocoded through Nominatim (1 req/s, scripted; `dl/nominatim-points/points.json`). Note: the first free-text query «Москва, Кремль» resolved to a street named Кремль in Вешняки — free-text geocoding needs a house-level address.

| Point | lat | lon | МКАД ring (mrden polygon) | Москва admin (R102269) | Москва place=city (R2555133) |
|---|---|---|---|---|---|
| Кремль (Красная площадь 1) | 55.75531 | 37.61816 | **IN** | IN | IN |
| Строгино (Строгинский б-р 1) | 55.80555 | 37.39730 | **IN** | IN | IN |
| Митино (Пятницкое ш. 20) | 55.84453 | 37.37985 | out | IN | IN |
| Южное Бутово (Адм. Лазарева 40) | 55.54462 | 37.52244 | out | IN | IN |
| Северное Бутово (Грина 1) | 55.56204 | 37.58242 | out | IN | IN |
| Коммунарка (Лазурная 1) | 55.56922 | 37.47336 | out | IN | out |
| Щербинка (Пушкинская 2) | 55.51028 | 37.56414 | out | IN | out |
| Зеленоград (Савёлкинский пр. 4) | 55.99109 | 37.21724 | out | IN | out |
| Реутов (Юбилейный пр. 10) | 55.74795 | 37.85692 | out | out | out |
| Химки (Ленинский пр. 1) | 55.89537 | 37.45229 | out | out | out |
| Котельники (Новорязанское ш. 6) | 55.66642 | 37.87372 | out | out | out |
| Люберцы (Октябрьский пр. 1) | 55.68280 | 37.88427 | out | out | out |

- Pure-Python ray casting (`probe/pip.py`, `probe/pip_mkad.py`): 24 tests on the two Moscow multipolygons (7 311 and 10 402 vertices) in 12.4 ms → **0.5 ms per test**; the 118-vertex ring is ~100× cheaper. PHP will be in the same range.
- Polygon sizes: МКАД 118 vertices ≈ 6 KB PHP source; КАД 98 vertices ≈ 3.5 KB; Moscow admin 268 KB GeoJSON; Moscow city 189 KB; SPb 88 KB / 81 KB.
- Overpass: not reachable for a geometry download today (502 at capacity on overpass-api.de and maps.mail.ru; kumi.systems timed out twice and answered 502 on a 300 s attempt; openstreetmap.fr whitelisted; private.coffee empty). The МКАД ways exist with `ref=МКАД`, `highway=trunk`, split per kilometre (Nominatim).
- Fixture `tests/fixtures/location-dadata-city-mode.json`: 124 suggestion rows, all with `beltway_hit: null`, all with `geo_lat` set, 95 at house level, every request `count: 10` — consistent with a free-tier key.

## 5. Open questions only the operator can answer

1. Which meaning ships first — ring road (МКАД/КАД), administrative settlement (FIAS), or both as one «Граница доставки» setting? (Recommendation: both, one setting, three choices.)
2. A setting on the existing method, or the separate method he sketched? (Recommendation: setting; E duplicates the method.)
3. Is «за МКАД: +N ₽/км» needed in the same release, and measured from the ring (straight line to the ring, computed locally) or from the warehouse (what the grid already does)?
4. Is bundling OSM-/MIT-derived ring polygons with a visible «© OpenStreetMap contributors» line acceptable, or must the feature depend on the merchant's paid DaData tariff?
5. Other rings worth bundling now (ЕКАД Екатеринбург, ЦКАД, Третье кольцо) or only МКАД and КАД?

## 6. Not verified

- Whether a paid («Расширенный») key fills `beltway_hit` in `count=10` suggestion lists and in `findById/address` (needs a paid key; docs say additional fields fill «при выборе конкретной подсказки»).
- How DaData computes `beltway_distance` (page silent; a third-party article says straight line, rounded up).
- Accuracy of the `mrden/mkad-distance` polygons beyond the 12 points; no OSM-way ring was assembled today (Overpass down).
- Yandex Geocoder / Yandex Maps JS commercial terms and limits; Mapbox Boundaries (empty page); OpenRouteService docs (404); Google Geocoding `result_type` behaviour.
- The FIAS tree shape for Зеленоград under Москва (`city_fias_id` scoping) — rig check with the real key.
- The ODbL «substantial» safe-harbour page (100 features) was referenced by the Geocoding guideline FAQ but not fetched.

Sources read today: dadata.ru/api/suggest/address/, dadata.ru/api/clean/address/, dadata.ru/pricing/, dadata.ru/api/geolocate/, dadata.ru/api/geocode/; osmfoundation.org Geocoding and Produced Work guidelines; operations.osmfoundation.org/policies/nominatim/; wiki.openstreetmap.org/wiki/Overpass_API; wiki.openstreetmap.org/wiki/Tag:boundary=administrative; osm-boundaries.com; docs.2gis.com (regions, places items, geocoder, pricing); yandex.ru/dev/geocode/doc/ru/response; developers.google.com dds-boundaries overview + coverage; github.com/mrden/mkad-distance, github.com/hector68/mkad-google-distance; framework `class-dadata-provider.php`, `class-dadata-api-client.php`, `class-location-record.php`; plugin `class-destination-point-resolver.php`, `class-dadata-geocoder.php`, `class-quote-calculator.php`, `class-service-catalog.php`, city-mode spec r5.1.

## Operator decisions (06.11.2026, s219)

- One «Граница доставки» setting on the existing range method (no separate method): none / within a settlement (FIAS) /
  within or OUTSIDE МКАД / within or OUTSIDE КАД — «за МКАД / за КАД» is the inverted check. Next version after 2.0.0:
  `woocommerce-range-shipping#46`.
- Rings of other large cities (ЕКАД etc.) at the start too — which cities have a closed ring is measured in the spec.
- OSM attribution line accepted; «+N ₽/км за МКАД» not needed (two methods cover it).
- Merchant-drawn zones: backlog, only on demand — `woocommerce-range-shipping#47`.

## Related

- [../specs/2026-11-05-range-shipping-city-mode.md](../specs/2026-11-05-range-shipping-city-mode.md) — city mode, the
  settlement-level limit this research extends.
