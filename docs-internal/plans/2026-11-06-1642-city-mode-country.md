# #1642 — city mode r7, a country per place — implementation plan

**Date:** 06.11.2026 (s220). **Status:** plan, not started. **Spec:** [city mode r7](../specs/2026-11-05-range-shipping-city-mode.md) — K15, §3.1,
§3.4, §4.2, §5, §8 A13–A18, §10 items 3–7. **Card:** #1642 (operator decision 06.11.2026, variant 2). Target: framework **2.0.26** (still
unreleased, draft release PR #1606) and plugin «Стоимость доставки за расстояние» **2.0.0** (PR #41, branch `kalbac/s219-c14`).

Read for this plan (READ = code read on `origin/main` `bdd3a659` and plugin `3e72dd4b`): `checkout/class-checkout-config.php`
`with_settlement_choices()`; `location/class-location-service.php` `get_settlement_choices()` … `saved_city_for_checkout()`,
`filtered_default_locality()`, `get_supported_countries()`, `resolve_provider_for_level()`, `customer_shipping_country()`;
`checkout/class-checkout-handler.php` `is_rejected_city_prefill()`, `guard_custom_settlement()`; `rest-api/class-location-controller.php`
`build_scope()`, `handle_admin_suggest_request()`, `perform_suggest()`; `location/class-city-limit.php` `country_options()`;
`settings-api/class-setting.php` `count` condition; `src/components/records-table.tsx`, `location-picker-field.tsx`, `control-field.js`;
`src/shipping-zone-city-limit/city-limit-list.tsx` (the existing «country above the search» precedent); `location-cascade.js` city-mode section,
`resolveModeRenderer()`, `reconcileAfterCheckoutUpdate()`, `handleFieldChanged()`; `src/checkout-blocks/city-mode.ts`, `single-place.tsx`,
`locality-chooser.tsx`; plugin `includes/city-mode/*` and `assets/js/city-select.js`.

## What already works per country (no change)

- `allowed` is already keyed by country (`with_settlement_choices()`), and both clients already read it per country: classic `allowedPlaces( entry,
  country )` / `closedListFor()`, block `allowedPlaces( config, country )` / `singlePlace()`.
- The classic hide rule already checks the per-country count (`syncHiddenSettlementRows()`: `hideSettlement && 1 === places.length`).
- `build_scope()` already refuses a `within` key from another country, and the controller resolves a listed key without a chain.
- A stored record already carries `country` — the «Страна» column needs no migration.

## Work packages

Disjoint files; WP-A … WP-D can run in parallel (each codes against the wire stated in spec §3.1 / §4.2), WP-E after WP-A and WP-D are merged (it
needs their signatures and the bundled `woodev/`).

### WP-A — framework PHP: per-country config and reads (M)

| File | Change |
|---|---|
| `woodev/shipping-method/checkout/class-checkout-config.php` | `with_settlement_choices()`: `levels[country].region = false` only for countries in `$allowed`; `allowCustomSettlement` no longer clamped; `hideSettlement` = the published `hide_field` as is (no `1 === count( records )`); docblock + `@since` |
| `woodev/shipping-method/location/class-location-service.php` | `filtered_default_locality()`: `apply_filters( FILTER_DEFAULT_LOCALITY, null, $this->customer_shipping_country() )` + docblock of the constant; new private `listed_records_for( string $country ): Location_Record[]`; `settlement_choices_narrow_this_read( string $country )`; `is_outside_settlement_choices()` uses the record's own country; `saved_city_for_checkout()` and the region skip in `get_customer_chain()` (~1181) use `customer_shipping_country()`; `names_listed_settlement( string $city, string $country = '' )` (`''` = any country, for callers that have none) |
| `woodev/shipping-method/checkout/class-checkout-handler.php` | `is_rejected_city_prefill()`: the section's own country (`get_{section}_country()`) into `names_listed_settlement()` and a per-country `has_settlement_choices_for()` (or the new reader); no other server rule changes — `guard_custom_settlement()` stays as is (spec §4.2: no place → the store's own rule) |

Tests (`rm -f .phpunit.result.cache` first; `composer test`, `composer phpcs`):

- `tests/unit/Shipping/Checkout/CheckoutConfigTest.php`: RU 3 places + KZ 1 → `levels.RU.region` and `levels.KZ.region` false, `levels.UZ.region`
  unchanged; `allowCustomSettlement` equals the store value; `hideSettlement` is the switch whatever the total count;
- `tests/unit/Shipping/Location/LocationServiceTest.php` (or a new `LocationServiceCityModeCountryTest.php`): a UZ customer's UZ record is **not**
  read as outside the list while RU lists places; a saved UZ city survives `saved_city_for_checkout()`; RU with one place gives the filtered default
  for an RU customer and **none** for a KZ customer with two KZ places; the filter receives the country as its second argument;
- `tests/unit/Shipping/Checkout/CheckoutHandlerCityModePrefillTest.php`: a saved Tula is blanked for RU (places), a saved Ташкент is kept for UZ (no
  places);
- `tests/unit/Shipping/Checkout/CheckoutHandlerCustomSettlementGuardTest.php`: `ajax-select2`, custom off, UZ without places → the guard applies the
  store rule exactly as with city mode off (no city-mode refusal of any kind).

### WP-B — framework classic JS (M)

| File | Change |
|---|---|
| `woodev/shipping-method/assets/js/frontend/location-cascade.js` | (1) the country-change path (`handleFieldChanged()` → `handleLayoutRelevantChange()` / `reconcileAfterCheckoutUpdate()`) re-attaches the settlement node when `resolveModeRenderer()` would now pick another renderer or another country's closed list — today it re-attaches only when the input element was replaced; (2) a per-country custom clamp `allowCustomFor( entry, country ) = !! allowCustomSettlement && ! allowedPlaces( entry, country )`, used by the pickup City guard (~2610) and handed to the renderer options; (3) switching **into** a country with exactly one place writes `allowed[country][0]` (`value`, region via the existing ancestor fill) and seeds the chain the way `prefill()` does from `defaultLocality`, and switching **out** clears it through the existing `clearCountryScope()`; (4) `syncHiddenSettlementRows()` re-runs on the switch |
| `woodev/shipping-method/assets/js/frontend/location-select-modes.js` | only if (2) cannot be passed through `options.location`: read the per-country clamp from the options instead of `options.location.allowCustomSettlement` (~1333) |

Tests (`npm run test:js`, from bash): `tests/js/location-cascade-city-mode.test.js` — RU (3) → KZ (1) → UZ (0) → RU: renderer `closed-select` →
`closed-text` → the shop's own mode → `closed-select`; no settlement `/suggest` while RU/KZ, a normal `/suggest` in UZ; State row hidden in RU/KZ,
shown in UZ; no RU value left in City after the switch to UZ; the KZ single place is written and posts its `value`; the pickup City guard is on in
RU and follows the store setting in UZ. `tests/js/location-select-modes-city-mode.test.js` only if `location-select-modes.js` changes. The `.min.js`
is rebuilt in the **primary checkout** only (CLAUDE.md project fact 5).

### WP-C — framework block TS (S)

| File | Change |
|---|---|
| `src/checkout-blocks/locality-chooser.tsx`, `single-place.tsx` (and `native-rows.ts` if the row toggle lives there) | a country switch away from a single-place country removes that place's City/State write and unhides the rows; into a country without places the combobox and State row come back; `levels[country].region` is read per country (the WP-A wire) — no new key |
| `src/checkout-blocks/types.ts` | docblock of `hideSettlement` (now the raw switch; the client applies it to a one-place country) |

Tests: `tests/js/checkout-blocks-city-mode.test.tsx` — the same RU → KZ → UZ → RU walk on the block chooser; the single KZ place written, then gone
in UZ; zero settlement `/suggest` in RU/KZ. Bundles: primary checkout only.

### WP-D — framework admin: records-table «Страна» and the per-country count (M)

| File | Change |
|---|---|
| `woodev/settings-api/class-control.php` | `COLUMN_LOCATION_COUNTRY = 'location-country'`; `set_columns()` accepts it with `of` (an existing `location-picker` column id), `options` (`{ code, name }[]`) and `in_scope` (codes) |
| `woodev/settings-page/class-records-table.php` | the derived column is never stored or validated as a cell (`get_cell_error()` skips it); a stored row is never refused for its country |
| `woodev/settings-page/class-field-schema.php` | passes the column keys to the client; `assert_count_controllers_are_tables()` also checks that a `per` names a `location-country` column of that table |
| `woodev/settings-api/class-setting.php` | the `count` condition: optional `per` + `aggregate` (`min` / `max`) — rows grouped by the country of the record in the `of` column, only countries in `in_scope`; no groups → the condition does not match |
| `src/components/records-table.tsx` | the `location-country` cell (country name + the flag note when the record's country is not in `in_scope`); the form's «Страна» select first (text when one option; default = store base country if offered, else the first); the chosen code as that row's picker `country`; clearing a picked record of another country; rows kept sorted by country name (stable) on every add/edit |
| `src/components/validate.js` | the client twin of the `per` / `aggregate` count |
| `docs-internal/adr/008-conditional-fields-operator-set.md` | amendment: `count` + `per` / `aggregate` |

Tests: `tests/js/records-table.test.tsx` (one option → no select; several → select; the picker request carries the chosen country; sort order after
add/edit; the flag on an out-of-scope row; changing country clears a mismatched pick), `tests/js/settings-page-validate.test.js` (`min` / `max`
per-country counts), `tests/unit/SettingsApi/RecordsTableTest.php` (the derived column is not stored and not validated),
`tests/unit/SettingsApi/ConditionalFieldsTest.php` (the PHP twin agrees with the JS one on the same tables).

### WP-E — plugin `woocommerce-range-shipping` (M)

On PR #41's branch (or a follow-up PR on top of it), bundling the framework `woodev/` that carries WP-A … WP-D.

| File | Change |
|---|---|
| `includes/city-mode/class-city-mode-settings.php` | `register_cities()`: a `location-country` column «Страна» (`of: city`, `options` = `City_Limit::country_options( shipping ∩ get_supported_countries() )`, `in_scope` = shipping countries) before the picker column; `register_hide_field()` / `disabled_when_several_places()`: `per: country` + the §10 item 3 aggregate (also removes today's duplicated inline condition); `update_value()` / `several_places_saved()`: the same per-country rule; copy per spec §3.3 r7 (provisional) |
| `includes/city-mode/class-city-list.php` | `published_records()` — rows whose record's country is in WooCommerce shipping countries; `hides_field()` returns the stored switch; a per-country count helper shared with the settings save |
| `includes/city-mode/class-city-mode.php` | publish `published_records()`; `is_in_force()` needs at least one; `publish_default_locality( $current, $country = '' )` returns the single published place of `$country` |
| `includes/city-mode/class-city-mode-notices.php` | the «every row flagged» notice (§10 item 6, provisional) |
| `includes/city-mode/class-city-select-fallback.php`, `assets/js/city-select.js` | values keyed by country; the server `select` only when the delivery country at render has places; the script swaps select ↔ text on a country change |
| `includes/city-mode/class-city-mode-environment.php` | a `shipping_countries()` / `supported_countries()` seam for tests |
| `languages/*` | the new and changed strings |

Tests: `tests/unit/city-mode/CityModeSettingsTest.php` (column declaration and options = shipping ∩ supported, one option; per-country disabled
condition and save-time clearing; a stored foreign-country row saves without error), `CityListTest.php` (published vs flagged), `CityModeTest.php`
(not in force when every row is flagged; the default-locality hook per country), `CityModeNoticesTest.php`, `CitySelectFallbackTest.php` (per
country), `tests/js/city-select.test.js` (country switch). Plugin phpcs + suite green; the coordinator's Playwright look at the tab at 1440 and 1280
(single-country shop, several countries, a flagged row) before «done».

### Rig (inside C15's run)

Spec §8 A13–A18 on classic and block, each with the placed order's fields; screenshots on disk with the masked phone.

## Not in this plan

- No change to the providers or to `build_locations_constraint()`: the coverage measurement (spec §3.1) shows every choice can add a place once the
  picker gets the row's country.
- No migration, no new option.

## Related

- [specs/2026-11-05-range-shipping-city-mode.md](../specs/2026-11-05-range-shipping-city-mode.md) — the spec this plan implements (r7).
- [plans/2026-11-06-s219-city-mode-overnight.md](2026-11-06-s219-city-mode-overnight.md) — the s219 run that built city mode r6.
- [adr/008-conditional-fields-operator-set.md](../adr/008-conditional-fields-operator-set.md) — the condition set WP-D amends.
