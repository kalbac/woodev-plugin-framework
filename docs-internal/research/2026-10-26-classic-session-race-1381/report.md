RACE1381

# Classic checkout session race for the city record (framework #1381, yandex#140)

Measured 26.10.2026 on the local rig (`http://localhost:8973`, WooCommerce 11.2.1, CDEK 2.3.0.9,
Yandex Delivery 2.0.10, Russian Post 2.0.9, all on the framework primary checkout `5d4a8942`), guest
sessions, classic checkout `/classic-checkout/`. No orders were created and no production code was
changed. Probes live outside the repo in `/tmp/woodev-1381/` (`lib.mjs`, `m1.mjs`, `m3rev.mjs`,
`b1.mjs`, raw logs `m1-*.log`, `b1-*.log`). Line numbers are 1-based, at `5d4a8942` unless stated
otherwise.

## Summary

- **The race is real on WooCommerce 11.2.1, and it happens on the real UI path.** A `update_order_review`
  that loads the session before `/location/select` and finishes after it writes the whole session back
  with the old `woodev_customer_location`. HTTP pairs lost the new city 5 of 5 times, sequential controls 0 of 5.
  In the browser, a Kazan → Tula → Kazan sequence ended with the session on **Tula** while the field
  showed Kazan in 2 of 7 runs at a 1.5 s pace. That is exactly the yandex#140 report.
- **The browser loss always came from an aborted refresh.** WooCommerce's `checkout.js` aborts the
  in-flight `update_order_review` XHR when the next `update_checkout` fires. The PHP request keeps
  running, and it saves last. The A → B → A shape makes this likely: the return to A is answered from
  the rate cache in ~40 ms, while B's first rating takes ~3 s.
- **WooCommerce 11.4 (#69559) closes it for the steady-state guest path.** On the trunk nightly
  (11.4.0-dev, 25.10.2026), the same HTTP probe kept the new city 15 of 15 times. A guest's
  `update_order_review` never writes `woodev_customer_location`, so WooCommerce's per-key merge leaves
  `/select`'s write alone. Narrow framework write paths remain that would still lose on 11.4
  (see "Which of our code writes session keys").
- **Recommendation: (B1) a server-side, key-level merge for framework-owned session keys on WooCommerce < 11.4.**
  It is a no-op from 11.4 on, about one small class, and covers classic, Store API and our REST
  writes alike. Client-side serialisation (A) cannot see when an aborted XHR finishes on the server,
  and the browser losses came from exactly such aborted XHRs. The Yandex-side guard belongs in the
  framework's staleness gate (rule (c) extended to classic `update_order_review`/`checkout`), compared
  by name + region, as defence in depth, not as the fix.

## 1. City record race, HTTP probe

`m1.mjs`. Each trial uses a fresh guest: add to cart → `/location/select` Moscow (`cdek:44`) →
`update_order_review` with CDEK pickup `edostavka_pickup:47` → pickup `/select` `MSK2` → a second
`update_order_review`. That gives the baseline: the record is Moscow, explicit. After 1.2 s, so that
`saved_at` differs, the city switches to Kazan (`cdek:424`). The slow request is a real address change:
`update_order_review` posts city «Казань», state TA and a random postcode `420000–420139`, so the package
hash is new and all three carriers re-rate live. The session row is then read straight from
`wp_woocommerce_sessions` (`readsess.php` via `wp eval-file`).

Modes:

- **pair**: `uor1` starts; 120 ms later `POST /location/select` Kazan; both awaited; the row is read.
- **triple**: like pair, plus `uor2` started the moment `/select` answers. This is what
  `location-cascade.js` does (`settleSelect()` → `triggerCheckoutUpdate()`, `location-cascade.js:3034`, `:3054`, `:2980`).
- **seq** (control): `uor1`, `/select` and `uor2`, each awaited before the next starts.

Times are ms from the start of `uor1`. "Kept" means the final row holds Kazan with the new `saved_at`.

### WooCommerce 11.2.1

| trial | mode | uor1 | /select | uor2 | final record | `saved_at` Δ vs baseline |
|---|---|---|---|---|---|---|
| 0 | pair | 0..1026 | 121..174 | – | **Москва (lost)** | 0 |
| 1 | pair | 0..928 | 121..177 | – | **Москва (lost)** | 0 |
| 2 | pair | 0..1058 | 121..232 | – | **Москва (lost)** | 0 |
| 3 | pair | 0..1267 | 122..183 | – | **Москва (lost)** | 0 |
| 4 | pair | 0..790 | 124..179 | – | **Москва (lost)** | 0 |
| 0 | triple | 0..876 | 121..180 | 180..1049 | Казань | +3 |
| 1 | triple | 0..1124 | 123..184 | 184..1125 | Казань | +3 |
| 2 | triple | 0..1969 | 122..176 | 176..2246 | Казань | +3 |
| 3 | triple | 0..**2879** | 121..177 | 177..**1152** | **Москва (lost)** | 0 |
| 4 | triple | 0..984 | 121..181 | 181..2997 | Казань | +3 |
| 0–4 | seq | 854–2011 ms each | after uor1 | after /select | Казань ×5 | +3…+5 |

| mode | lost | kept |
|---|---|---|
| pair | **5/5** | 0/5 |
| triple | **1/5** (exactly the run where `uor1` ended after `uor2`) | 4/5 |
| seq | 0/5 | 5/5 |

The rule is last finisher wins. Every `/select` answered 200 with `persisted: true` in 53–111 ms. In the
lost triple, Yandex's derived key `yandex_delivery_pickup_geo_id` also went back to 213 (Moscow) after `uor2`
had set 43 (Kazan). The chosen rate `edostavka_pickup:47` and the CDEK pickup map
(`woodev_edostavka_pickup_selection`, keyed per locality: `cdek:44 → MSK2`) were intact in all 15
trials. This probe is **not sensitive** for those two: every `uor` re-posts the method, and no pickup write
falls in the race window. The pickup-point loss itself was measured earlier on #1381 (3/3 overlapped
pairs lost `remembered_point_id`).

### WooCommerce 11.4.0-dev (trunk nightly with #69559)

| mode | uor1 durations (ms) | /select | final record | lost |
|---|---|---|---|---|
| pair | 722, 1452, **3932**, 759, 1839 | 53–61 ms, inside uor1 every time | Казань ×5 | **0/5** |
| triple | 746, 747, 765, 756, 790 (uor2 698–1854) | inside uor1 | Казань ×5 | 0/5 |
| seq | 667–850 | after uor1 | Казань ×5 | 0/5 |

The pair mode is the decisive one: `uor1` loaded the session before `/select` and saved after it in all five
trials, up to 3.9 s later, and the record survived. In this run, triple never had `uor1` finishing last, so it
adds nothing beyond pair.

## 2. Real browser path (Playwright, Chromium headless)

`b1.mjs`: fresh guest, cart, `/classic-checkout/`, then the yandex#140 sequence **Казань → Тула → Казань**
through the real widget. Each pick: open the field, type the name (90–120 ms per key), click the
suggestion, wait `GAP`. Every `update_order_review` and `/location/select` is logged with start, end and
`net::ERR_ABORTED`. The session row is read after the network settles; "field" is what `#billing_city`
shows.

| WC | field mode | pace between picks | runs | session ≠ field (lost) | runs with an overlapping uor |
|---|---|---|---|---|---|
| 11.2.1 | `ajax-select2` (rig setting) | 1.5 s | 7 (1 smoke + 6) | **2/7** | 2/7 |
| 11.2.1 | `ajax-select2` | 4 s | 5 | 0/5 | 0/5 |
| 11.2.1 | `typeahead` (switched temporarily) | 1.5 s | 6 | 0/6 | 1/6 (rolled back to Kazan = next pick, harmless) |
| 11.4.0-dev | `ajax-select2` | 1.5 s | 6 | 0/6 | 0/6 (no overlap happened → not evidence either way) |

Both losses had the same trace (ms from the first pick):

```text
sel[Казань] 1482..1550
uor[Казань] 1558..2791
sel[Тула]   4202..4264
uor[Тула]   4273..7203  aborted:net::ERR_ABORTED   ← browser gave up; PHP kept running
sel[Казань] 7117..7193                              ← new record saved
uor[Казань] 7203..7244                              ← 41 ms: rates for Kazan already cached
=> session row: Тула (Yandex geo_id 15 = Tula); field shows Казань
```

What the trace shows:

- **The overlap source is our own refresh chain, not typing.** On the rig, `checkout.js`
  `maybe_update_checkout()` withholds WooCommerce's own refreshes, because `billing_address_1` is empty
  and the cascade clears it on a city change (`clearAddressOnChange: true`). So every `uor` came from
  `triggerCheckoutUpdate()` after a persisted `/select`. In a shop where the address stays filled,
  WooCommerce's own `change` handler on `.address-field select` also fires a `uor` the instant the select2
  value changes, in parallel with our `/select`. That is the HTTP "pair" pattern. Not measured in the browser,
  see the end.
- `update_order_review` durations in the browser: median 1.2 s, max 3.3 s. A first-time city costs 2–3 s,
  a cached one 40 ms.
- Typeahead mode spaces the picks further apart (typing plus suggest debounce), which is why it lost 0/6
  at the same nominal pace.

## 3. WooCommerce 11.4 behaviour (#69559)

The trunk nightly `woocommerce-trunk-nightly.zip` (GitHub release `nightly`, asset updated 25.10.2026
00:13 UTC, `Version: 11.4.0-dev`, contains `src/Internal/Session/SessionDataWriter.php`) was swapped
into the rig temporarily. The measurements are above.

What #69559 does (diff read in full): `WC_Session_Handler` records a snapshot of the session as loaded
(`set_loaded_session_snapshot()` in `init_session_cookie()` / `restore_session_data()`). `save_data()`
then calls `SessionDataWriter::merge()`. Merge first tries a compare-and-swap
(`UPDATE … WHERE CAST(session_value AS BINARY) = <loaded>`). If the row changed meanwhile, it re-reads the
row and applies only the keys whose **serialized value differs from the loaded snapshot**
(`apply_changes()`; a key set to `null` is removed). Two requests that change the **same** key: last
writer wins. The Store API `SessionHandler` gets the same treatment. `forget_session()`, guest → user
migration and `clone_session_data()` still replace the whole session.

Does it close our race?

- **Our request writes the key, the concurrent request never touched it** (the measured case: `/select`
  writes `woodev_customer_location`, a guest `update_order_review` only reads it): **closed.** Measured
  15/15 on 11.4.0-dev. Re-setting the same value would not count either, because the comparison is by
  serialized value.
- **The slow request ALSO writes `woodev_customer_location`**: **not closed.** Whichever saves last
  wins. Framework paths that write the key while a `update_order_review` or another read runs are listed
  in §4: implicit default (`class-location-service.php:446`), logged-in saved-city resolution
  (`:1804`), the logged-in session repopulate from meta (`class-customer-location-store.php:1140`), and
  retention expiry (`class-customer-location-store.php:302` → `:530`). None runs for the guest
  steady state in the ticket: there an explicit chain exists, so `may_overwrite()` refuses the implicit
  default and the guest path never repopulates.
  The reverse case could not be produced on the rig. `m3rev.mjs` (3 runs) found that `add-to-cart`
  already writes the implicit default, so a later `update_order_review` had nothing to write; the record
  was kept 3/3. Treat this half as code analysis.
- **Derived keys written by the stale request** (Yandex `yandex_delivery_pickup_geo_id`, WooCommerce's own
  `chosen_shipping_methods` and `shipping_for_package_0`): on 11.4 they are still last-writer-wins
  per key. A stale `update_order_review` that finishes last still leaves its geo id and possibly its
  chosen rate. The next rating recomputes them from the (now correct) record. The rate cache is safe
  because the package hash includes the record's provenance
  (`Checkout_Handler::add_location_provenance_to_packages()`, `class-checkout-handler.php:363`, `:513`).

The rig was restored afterwards. 11.2.1 files were rsynced back from a full copy (`diff -rq` identical,
5488 files). The DB was re-imported from a `wp db export` taken just before the swap, because the nightly
had run its updates (`woocommerce_db_version` 11.4.0, Action Scheduler schema 9.0 with a new
`unique_key` column). A diff of the two dumps first showed no posts, users, orders or shipping-zone
changes by anyone else in the window. Verified after import: `woocommerce_version`/`woocommerce_db_version`
= 11.2.1, AS schema `8.0.1781176002`, the `unique_key` column gone, table set hash unchanged, plugin
list/versions unchanged, `woodev_location_field_mode_settlement` = `ajax-select2`,
`woocommerce_ship_to_destination` = `billing_only`.

## 4. Which code writes session keys during `update_order_review` (classic)

### Framework

| key | writer | when it runs on a classic `update_order_review` | file:line |
|---|---|---|---|
| `woodev_customer_location` | `Location_Service::get_customer_record()` → `Customer_Location_Store::set( $default, true )` | only when no usable chain is stored and the default policy resolves one (`fixed` on the rig). Refused over an explicit chain (`may_overwrite()`, store `:1391`) | `class-location-service.php:446` → `class-customer-location-store.php:376`, `:1369` |
| `woodev_customer_location` | `resolve_saved_city_entry()` → `set( $match, false )` (explicit) | logged-in only, no record, WC holds a saved city (#1075) | `class-location-service.php:1760`, `:1804` |
| `woodev_customer_location` | `get_chain_for_logged_in_user()` repopulates the session from user meta | logged-in, session row lacks the key | `class-customer-location-store.php:1118`, `:1140` |
| `woodev_customer_location` (`null`) | `get_chain()` → `forget()` on retention expiry | chain older than the retention TTL | `class-customer-location-store.php:302`, `:530` |
| `woodev_location_resolution_cache` | `Location_Resolution_Cache::resolve_for()` → `write_entry()` | every rating that resolves a carrier code for the record (Yandex `geo_id`, …) on a cache miss. A lost write is a cache miss, nothing more | `class-location-resolution-cache.php:133`, `:180`, `:313`, `:332` |
| pickup map `woodev_<plugin>_pickup_selection` | `Pickup_Selection::remember()/forget()` | **not** on `update_order_review`: from pickup `/select` (`Pickup_Handler::remember_selection()`, `class-pickup-handler.php:3870`/`:3896`), Store API paths (`:1895`, `:2065`) and order processing (`:4597`) | `class-pickup-selection.php:121`, `:196`, `:366` |
| `chosen_payment_method` (`Fee_Payments::PACKAGE_KEY`, WooCommerce's own key) | `Fee_Payments::update_cart()` | Store API extension callback only, not classic | `class-fee-payments.php:335`, `:345` |

Writers outside `update_order_review` that it overwrites (on 11.2.1): `/location/select` →
`Location_Service::set_customer_record()` (`class-location-service.php:1979`, controller
`class-location-controller.php:1520`), `/location/forget` (`:2020`), `promote_customer_record_to_explicit()`
(`:2074`), and pickup `/select`. `persisted` (`class-location-controller.php:1556`) is
`session_will_survive()` = `has_session()` (`class-customer-location-store.php:989`). It means "the
cookie exists", not "the write outlived concurrent requests".

The logged-in path also loses on 11.2.1, by code reading, not measured. `/select` writes user meta **and**
the session (`persist()`, `:1349`). The read path prefers the session (`:1118`), so a stale session copy
saved by a slow request shadows the fresh meta until the next pick.

### Carrier plugin and WooCommerce

| key | writer | file:line |
|---|---|---|
| `yandex_delivery_pickup_geo_id` | `Yandex_Pickup_Point_Source::remember_geo_id()` during rating; read by `current_geo_id()` for detail lookups without a locality | yandex `includes/pickup/class-yandex-pickup-point-source.php:484`, `:488`, `:497` (@ `9eec67f`, v2.0.10) |
| `yandex_delivery_pickup_fit_<instance>` | `Yandex_Pickup_Guard::remember_fit()` | `includes/shipping/class-yandex-pickup-guard.php:137`, `:140` |
| interval field | `class-yandex-interval-checkout.php:253` | — |
| `customer`, `cart`, `cart_totals`, `chosen_shipping_methods`, `previous_shipping_methods`, `shipping_method_counts`, `shipping_for_package_N`, `chosen_payment_method` | WooCommerce core, saved whole at `shutdown` priority 20 (`class-wc-session-handler.php:94`, `save_data()` `:551` in 11.2.1) | — |

Conclusion for the guard question: **on the guest steady-state path no framework code re-sets
`woodev_customer_location` during `update_order_review`.** 11.4 therefore fixes the ticket's case on its
own. The listed narrow paths (default write, logged-in repopulate/resolve, expiry) would still race on
11.4. They are rare (first contact, a new session of a logged-in user, a TTL boundary) and not the
ticket's symptom.

## 5. Design options

### (A) Client-side serialisation in classic checkout (like #1378 for blocks)

Make `/location/select`, pickup `/select` and WooCommerce's `update_order_review` wait for each other
in the browser.

- **Against, from the measurement:** both browser losses came from a `update_order_review` that the
  browser had **aborted** (`wc_checkout_form.update_checkout_action()` calls `xhr.abort()` before every
  new refresh). The server request keeps running. A client queue sees an aborted XHR as finished at once,
  so it would release `/select` while the stale PHP request is still loading/saving. To work, A must stop
  WooCommerce from aborting: patch `wc_checkout_form` internals or wrap jqXHR `abort` in an
  `ajaxSend` hook. It must also serialise two transports (jQuery for `wc-ajax`, `fetch` for our REST) and
  every third-party script's `wc-ajax` calls. That is fragile against WooCommerce releases.
- Costs latency: each pick waits for the previous 1–3 s refresh. #1378 accepted this for blocks, where
  `apiFetch` middleware is a clean seam. Classic has no such seam.
- It does not help logged-in multi-tab use or non-checkout writers.

### (B) Server-side

- **(B1) Key-level merge for framework-owned keys before WooCommerce's save: recommended.**
  Hook `shutdown` at priority 19 (WooCommerce saves at 20; `WC_Customer`/cart work runs earlier). For each
  framework-owned key **this request did not write**, re-read the row straight from
  `{$wpdb->prefix}woocommerce_sessions`, bypassing the object cache: `get_session()` reads the cache
  first, while the DB row is what `save_data()` writes first. Copy the stored value into `WC()->session`
  (unset when absent) when it differs. The writers already know when they write
  (`Customer_Location_Store::persist()`/`forget()`/the repopulate at `:1140`, `Pickup_Selection`), so a
  per-request "dirty keys" set is all that is needed. No load-time snapshot is required.
  - Scope: `woodev_customer_location` and every `Pickup_Selection` scope key. Optionally a filter so a
    carrier plugin can register its own keys (Yandex geo id).
  - Active only for `WC_Session_Handler` itself, with `has_session()`, and only while
    `\Automattic\WooCommerce\Internal\Session\SessionDataWriter` does not exist. From 11.4 WooCommerce
    merges every key itself and B1 turns into a no-op without a release of ours. Gating on the class rather
    than a version string tracks the real code if #69559 were reverted before 11.4.0.
  - Residual window: between our re-read (priority 19) and WooCommerce's write (priority 20). Milliseconds,
    against 1–3 s today. 11.4's own compare-and-swap has no such window.
  - It keeps "same key, two writers → last writer wins", which is 11.4's semantics too.
  - Covers classic `update_order_review`/`checkout`, Store API cookie sessions, and our REST routes,
    whatever the client does.
- **(B2) `GET_LOCK` per session key.** It must be taken before WooCommerce reads the session (an early
  hook that parses the cookie itself) and held through the `shutdown` save. Every refresh would hold it
  for its full carrier round-trip (1–3 s, measured), so `/select` and every other request of that shopper
  would queue behind it. A hung carrier call holds the shopper's whole checkout. It needs timeout policy,
  request-type allow-listing and a per-site connection budget. That is a much larger behaviour change than
  B1 for the same protection; not recommended.

### (C) Other simpler options considered

- **Keep the record out of the WooCommerce session blob** (own per-session storage keyed by the session
  customer id). This removes the race structurally on every WooCommerce version, but it is a storage
  migration touching privacy export/erase (`class-customer-location-store.php:685`, `:828`),
  login migration (`:1062`), retention and multisite. It is too large for a race that WooCommerce fixes
  in 11.4 and B1 covers before it.
- **Client reconcile** (re-post `/select` when a refresh reports a different server key). It masks the
  symptom with one more overlapping request; the blocks gotcha
  `overlapping-store-api-cart-requests-lose-each-other-s-session-writes` already rejects this.
- **Make `persisted` honest about survival**: impossible at response time; `/select` cannot know about a
  request that will save later.

### Yandex-side guard (yandex#140 item 2): where and how

- **Where: the framework seam, not the plugin.** Yandex reads the record only through
  `Location_Service::get_customer_record_at()` / `get_customer_record()` (yandex
  `includes/shipping/class-yandex-package-location.php:53-74` @ `9eec67f`). CDEK and Russian Post use the
  same accessors, and the staleness gate already exists there: `is_customer_record_stale()` rule (c),
  `native_address_disagrees()` (`class-location-service.php:1247`, `:1426`). Today rule (c) is limited to Store
  API requests by `is_native_address_authority()` (`:1374`). Its docblock excludes classic because between
  `/select` and the next refresh the customer's *stored* city legitimately lags. That reasoning does not
  apply inside a classic `update_order_review` or `checkout` POST: WooCommerce has just copied the posted
  fields into `WC()->customer`, so the native city is current there. Extending
  `is_native_address_authority()` to `wc-ajax=update_order_review` and `wc-ajax=checkout` gives every
  carrier the guard. A disagreeing record is then treated as absent ("no locality"), so Yandex returns no
  rate for that request instead of the other city's rate. The provenance in the package hash follows
  automatically (`get_customer_provenance()`, `:1567`).
- **How to compare: by name plus region, not by key.** The classic form posts only text (city, state,
  postcode); no locality key travels with the package. `record_names_city()` (`:1320`) already
  compares `Location_Record::normalize_city_name()` with the record's own component type («г. Подольск»
  = «Подольск»). The region half (`record_state_code()` vs the posted state) separates same-named
  settlements when the state is set.
- **Trade-off:** in `typeahead` mode a refresh fired while the customer is still typing («Каз») would drop
  the record for that one refresh. Yandex rates would vanish until the pick, while CDEK rates by the field
  text keep showing. In `ajax-select2` mode the field always equals the record name, so nothing changes.
  At placement (`wc-ajax=checkout`) the guard is unambiguous: an order must never carry a rate or point
  for a city the form does not show.
- **Status:** defence in depth, not the fix. Under the race, the guard turns "Tula's rate under a Kazan
  address" into "no Yandex rate" until the customer re-picks. B1/11.4 keep the record from going stale in
  the first place.

## 6. Recommendation

1. **Implement B1** in the framework (#1381): a per-request dirty-key set in `Customer_Location_Store` and
   `Pickup_Selection`, plus one `shutdown`@19 merger that re-reads the row for clean framework keys. Active
   only while `SessionDataWriter` is absent. Prove it with this report's `m1.mjs` pair mode on 11.2.1
   (target 0/5 lost) and `b1.mjs` select2 at a 1.5 s pace.
2. **Extend rule (c) to classic `checkout`** (placement) at least, and to `update_order_review` if the
   operator accepts the typeahead trade-off above. This answers yandex#140 item 2 for every carrier at
   once; Yandex needs no plugin change.
3. **Story by version:**
   - **WC < 11.4:** B1 protects the record and pickup map against stale whole-session saves, with a ms
     residual window. WooCommerce's own keys (chosen rate, customer) stay whole-blob last-writer-wins, as
     today.
   - **WC ≥ 11.4:** B1 is a no-op; WooCommerce merges per key. Residual on both: a key written by **both**
     requests, meaning derived keys (Yandex geo id, chosen rate after a rate-set change) and the narrow
     framework paths in §4 (default write, logged-in repopulate/resolve, retention expiry).
4. **Risks of B1:** reading the session table directly assumes `WC_Session_Handler`. Custom handlers
   (Redis sessions via `woocommerce_session_handler`) must be skipped by class check, not assumed.
   Adopting a stored value marks the session dirty and adds one `SELECT` per request that has a session
   and did not write our keys. Restrict it to requests that loaded our key (or to `wc-ajax` and REST
   routes) to keep page loads free.

## Not done / not checked / risk

Not checked: the browser path with `billing_address_1` kept filled, where WooCommerce's own `change`
refresh races our `/select` directly (the rig's `clearAddressOnChange` empties it); logged-in customers
(code reading only); B1 is a design, not prototyped. Risk: the rig DB was re-imported from a dump taken
~25 minutes earlier, which would have reverted any concurrent agent's rig writes in that window. The dump
diff showed none. Guest session rows created by my 11.2.1 probes before the dump remain until they
expire, and the container scratch dir `/tmp/w1381` was removed.
