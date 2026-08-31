# Card #644 tail pass — pickup, map, and shipping cards

> Reviewed: 2026-08-31. Scope: open cards #144, #148, #150, #151, #152, #163, #165, #170,
> #171, #173, #174, #181, #215, #270, #364, #379, #417, #418, #419.

Serena MCP was activated on `D:/Projects/woodev_framework` and used for every PHP/JS symbol
lookup below (`find_symbol`, `search_for_pattern`, `read_file`). Every claim in the table was
checked against source and cross-checked by a second method (a second symbol/grep, a
`git log`/`gh` date comparison, or a `docs-internal/CURRENT-STATE.md` cross-reference) before
being written down.

| Card | Claim | Checked how (file:line / commit) | Verdict | Commented? |
| --- | --- | --- | --- | --- |
| #144 | `Abstract_Bulk_Point_Source` dedup is premature — only one real `STRATEGY_BULK` implementation (a fixture) exists; wait for a second (real carrier pilot). | `interface-point-source.php:26` (`STRATEGY_BULK`); `class-test-bulk-point-source.php:160-168` and `class-test-live-yandex-point-source.php:320-328` both implement `fetch_details()` as the identical "scan the already-fetched list for this id" loop the card predicts. | Scope changed: the predicted duplication now exists twice, but both instances are rig fixtures, not a production carrier plugin — the literal entry condition ("СДЭК/Яндекс pilot") has not fired. | Yes |
| #148 | Picker verdicts go stale if the payment method changes while the card is open; `pickup-mount.js`'s `updated_checkout` handler only remounts the trigger, never re-queries. | `pickup-mount.js:4415-4472` (`handleCartChanged`/`flushCartChangeRefresh`), `:4486` (second permanent `updated_checkout` subscriber, citing #232/#238), `:4063` (`refresh()` forgets point details and refetches). Card filed 2026-07-31; #232/#238 closed 2026-08-08/09, neither references #148 (`gh issue view`). | Already fixed by unrelated work (#232/#234/#238/#248): every open session now refreshes on `updated_checkout`. | Yes |
| #150 | Single-point city degenerates the bbox and can zoom the map into grey tiles; not verified live, gated on "map fully done". | `docs-internal/CURRENT-STATE.md:198` marks SP-5 (map/ПВЗ incl. viewport accumulation) ✅ DONE. Code: `checkZoomRange: true` still guards every `setBounds()` (`map-provider-yandex.js:1594,1775,2514`), `maxZoom: MAX_ZOOM` in `_buildMap()` (`:1138`) — but `_openPlacemarkBalloon()`/`_loadBulk()`, the exact call sites the card cited, no longer exist (presentational layer rebuilt). | Entry condition fired (map rework is done per CURRENT-STATE); defenses are still architecturally present but the card's own requested live-rig check is still outstanding. | Yes |
| #151 | Viewport strategy has no server-side pagination; dense bbox can return unbounded point counts. | `search_for_pattern` for `page_size`/`pageSize`/`per_page` in `class-point-query.php` and `pickup-datasource.js` — zero matches. | Accurate as filed. | No |
| #152 | `Pickup_Point::work_time` is a plain string, not a structured schedule. | `class-pickup-point.php:132` — `'work_time' => isset(...) ? (string) $payload['work_time'] : ''` — still cast to a bare string. | Accurate as filed. | No |
| #163 | Sidebar distance only renders after an address search; `_anchor` starts `null` and the spec/docblock claim "map centre by default" is not what the code does. | `pickup-panels.js:1900` (`this._anchor = null`), `:2545` (`setAnchor`), docblock at `:87` still says "the map centre by default" with no code path setting it. | Accurate as filed — code/spec/docblock mismatch persists exactly as described. | No |
| #165 | Rare, unreproduced race: marker stays `resting` after a list-row click; Codex hypothesis blamed `setPoints()`'s bulk fit running outside `_focusSeq`. | `map-provider-yandex.js:2645-2664` — `focusGroup()` now waits on `this._cameraFit` before moving the camera; docblock (`:2620-2634`) describes the identical class of race, fixed in PR #177 (commit `ae23071`, 2026-08-07, s52). Card filed 2026-08-05 (s51); PR #177 doesn't mention #165. | Likely already fixed via the general `_cameraFit` gate, though the card's own bug was never reproduced reliably — live-rig confirmation still recommended. | Yes |
| #170 | `Pickup_Controller`/`Pickup_Handler` constructors are fragile — 3 same-typed callbacks and 14 positional params; trigger is "bundle on the 4th callback". | `class-pickup-controller.php:226-284` — a 4th callable (`$location_context`) was added (Task 15) but deliberately NOT bundled, with a docblock explaining why. `class-pickup-handler.php:392-530` — still 14 positional params; `$replace_address`/`$close_on_select` removed (moved to store settings, #362 S7), `$selection_scope`/`$plugin` added, both docblocked "Appended LAST" citing #170 directly. | Scope changed (membership of the param list shifted) but the core defect is unchanged; the controller's literal 4th-callback trigger fired but was answered with a documented exception rather than the bundle refactor. | Yes |
| #171 | `renderCard()` fully rebuilds the card DOM on every state change, losing focus and scroll position. | `pickup-panels.js:1471-1508` — still `empty(self._cardEl)` + rebuild header/body/footer; no `activeElement`/`scrollTop` save-restore anywhere in the file (`search_for_pattern`, zero matches). | Accurate as filed. | No |
| #173 | A click during the initial bulk camera fit can leave a marker parked at ymaps' off-screen sentinel (`-32760px`) for ~400ms after load; restore-on-reopen path already fixed, normal open still exposed. | `map-provider-yandex.js:1706` still documents the `-32760px` sentinel risk for the general (non-restore) open path; the restore path's own `setPoints(groups, {focus})` fix is intact. | Accurate as filed — restore path confirmed fixed (matches card's own claim), normal-open window still open. | No |
| #174 | `method_id` in the select-request context silently depends on the `cart_weight` callback running before `shipping_method` and initializing the WC session as a side effect. | `class-pickup-controller.php:571-580` (`$cart_weight = (...)(); ... 'method_id' => (string)(...)()` — cart-weight callback still evaluated first in the context array). `class-pickup-handler.php:2167-2196` (`rest_shipping_method()`/`wc_session_chosen_shipping_methods()`) has no `bridge_wc_context()`/session guard, unlike `current_cart_weight_grams()` (`:2277-2287`), which now has one via #324. | Accurate as filed — the exact asymmetry persists, though `bridge_wc_context()` (extracted in #324) would now make the fix a one-line call. | No |
| #181 | Search results don't show a ПВЗ/Постамат type chip; only the section header distinguishes points from addresses. | `pickup-panels.js:950-990` (`buildSearchPointItem`) still renders only address + name, no type chip. | Accurate as filed. | No |
| #215 | Post-v2 idea: dark theme + more map providers; explicitly gated "not before v2 ships". | `docs-internal/CURRENT-STATE.md:325` — `Woodev_Plugin::VERSION` is `2.0.1 (unreleased)`. | Accurate — gate has not fired. | No |
| #270 | Rig fixture only serves one locality (`Москва`), so multi-location scenarios (e.g. #176's persist-across-locations) are only partially testable. | `class-test-bulk-point-source.php:47,61,148` and `class-test-live-yandex-point-source.php:191,200,343` — `FIXTURE_LOCALITY_ALIASES` still `['Москва', 'Moscow']` only; `fetch_points()` (`:96-99`) still returns `[]` for anything else. | Accurate as filed. | No |
| #364 | Locale payload grows 10KB→58KB on the block checkout after the country-preset policy; recorded as an accepted tradeoff, not a bug. | `class-checkout-field-policy.php:258-260` (`filter_country_locale()`) still contributes a locale entry for every shipping country. | Accurate as filed — card is a record, not an open defect. | No |
| #379 | Idea (explicitly "optional, for discussion"): store-level accent color/button color/button text options for the map section, blocked on an S7-style precedence decision. | `search_for_pattern` for accent/button-color/button-text fields in `class-pickup-map-settings.php` — zero matches; no such settings exist yet. | Accurate as filed. | No |
| #417 | Framework's "one shipment per order" model (`Abstract_Shipment_Handler::export(): string`) doesn't fit Ozon's 1..100 postings per order; decide before SP-7. | `abstract-shipment-handler.php:168` — `export()` still returns `: string`, one carrier order id. `CURRENT-STATE.md:198` — SP-6…SP-11 still pending. | Accurate as filed — entry condition (SP-7 start) not fired. | No |
| #418 | Reverse logistics (Ozon returns) needs its own entity, not "shipment in reverse"; decide scope before SP-7/SP-8. | `search_for_pattern` for `return_number`/reverse-logistics terms under `woodev/shipping-method` — zero matches; no reverse-logistics code exists. `CURRENT-STATE.md:198` — SP-7/8 pending. | Accurate as filed. | No |
| #419 | Ozon needs an async posting-confirmation pattern and an `in_courier_service` status; input for not-yet-built SP-7/SP-8. | `specs/2026-06-25-shipping-module-decisions.md:101-105` still specifies the same ~9-status canonical set the card cites, with no `in_courier_service`/async-confirmation machinery; SP-7/8 unbuilt per `CURRENT-STATE.md:198`. | Accurate as filed — explicitly design input, not a defect. | No |

## Candidates for closure

- #148 — cart-change refresh (`handleCartChanged`/`session.refresh()`) already covers the
  staleness window the card describes.
- #165 — the general `_cameraFit` gate added in PR #177 closes the exact race class Codex
  hypothesized, though live-rig confirmation of the specific symptom is still missing.

## Entry conditions that have now fired

- #150 — "after the map rework is finished": SP-5 is ✅ DONE per `CURRENT-STATE.md`. The
  card's own requested live-rig zoom/tile check is now unblocked.
- #170 (partial) — the controller's "4th callback" trigger fired (`$location_context`), but
  was answered with a documented exception rather than the bundle refactor the card
  describes; worth an operator read, not an automatic close.

## Related

- [Card #644](https://github.com/kalbac/woodev-plugin-framework/issues/644) — audit umbrella
  and required verification standard.
- [Core/gateway/infra tail pass](2026-08-31-644-tail-core-gateway-infra.md) — sibling review
  covering the rest of #644's tail, run in parallel by a second worker.
