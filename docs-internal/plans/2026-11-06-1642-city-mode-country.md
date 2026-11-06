# #1642 — city mode r7, a country per place — implementation plan

**Date:** 06.11.2026 (s220). **Status:** plan r2 (the r7 critic's findings 1–7 folded in), not started. **Spec:** [city mode
r7.1](../specs/2026-11-05-range-shipping-city-mode.md) — K15, §3.1, §3.2, §3.4, §4.2, §5, §8 A13–A18, K16 / K17 (§10 items 1–7, confirmed by
the operator 06.11.2026). **Card:** #1642 (operator decision 06.11.2026, variant 2). Target: framework **2.0.26** (still unreleased, draft
release PR #1606) and plugin «Стоимость доставки за расстояние» **2.0.0** (PR #41, branch `kalbac/s219-c14`).

Read for this plan (READ = code read on `origin/main` `bdd3a659` and plugin `3e72dd4b`): `checkout/class-checkout-config.php`
`with_settlement_choices()`; `location/class-location-service.php` `get_settlement_choices()` … `saved_city_for_checkout()`,
`filtered_default_locality()`, `resolve_default()`, `get_default_locality_policy()`, `get_supported_countries()`, `resolve_provider_for_level()`,
`customer_shipping_country()`; `checkout/class-checkout-handler.php` `is_rejected_city_prefill()`, `classic_checkout_default_target()`,
`guard_custom_settlement()`; `pickup/class-address-target.php` `resolve()`; `rest-api/class-location-controller.php` `build_scope()`,
`handle_admin_suggest_request()`, `perform_suggest()`; `location/class-city-limit.php` `country_options()`; `settings-api/class-setting.php`
`evaluate_conditions()` (`count`); `settings-api/abstract-class-settings.php` `filter_visible_values()`, `records_table_setting_ids()`;
`settings-page/class-composite-settings-handler.php` (its own records-table id loop); `settings-page/class-settings-page-registry.php`
(`window.woodevSettings`); `src/components/records-table.tsx`, `location-picker-field.tsx`, `control-field.js`, `validate.js`
`evaluateConditions()`; `src/shipping-zone-city-limit/city-limit-list.tsx` (the existing «country above the search» precedent);
`location-cascade.js` city-mode section, `resolveModeRenderer()`, `reconcileAfterCheckoutUpdate()`, `handleFieldChanged()`,
`handlePickupAddressReplacing()`, `holdsGuardedSettlement()`; `src/checkout-blocks/city-mode.ts`, `single-place.tsx`, `locality-chooser.tsx`;
plugin `includes/city-mode/*` and `assets/js/city-select.js`.

## What already works per country (no change)

- `allowed` is already keyed by country (`with_settlement_choices()`), and both clients already read it per country: classic `allowedPlaces( entry,
  country )` / `closedListFor()`, block `allowedPlaces( config, country )` / `singlePlace()`.
- The classic hide rule already checks the per-country count (`syncHiddenSettlementRows()`: `hideSettlement && 1 === places.length`).
- `build_scope()` already refuses a `within` key from another country, and the controller resolves a listed key without a chain.
- A stored record already carries `country` — the «Страна» column needs no migration.
- The JS condition evaluator already receives every field's full schema (`evaluateConditions( conditions, values, fieldSchemas )`,
  `validate.js:296`), so the client twin of the per-country `count` can read the column declarations; only the two PHP save paths lack them (WP-D).

## Work packages

Disjoint files; WP-A … WP-D can run in parallel (each codes against the wire stated in spec §3.1 / §4.2), WP-E after WP-A and WP-D are merged (it
needs their signatures and the bundled `woodev/`); its final vendor snapshot and the integrated acceptance carry WP-B and WP-C too.

| WP | Files (every named file belongs to exactly one package) |
|---|---|
| A | `checkout/class-checkout-config.php`, `location/class-location-service.php`, `checkout/class-checkout-handler.php`, `pickup/class-address-target.php` + `CheckoutConfigTest`, `LocationServiceTest` (or a new `LocationServiceCityModeCountryTest`), `CheckoutHandlerCityModePrefillTest`, `CheckoutHandlerCustomSettlementGuardTest`, `AddressTargetTest` |
| B | `assets/js/frontend/location-cascade.js` (+ `location-select-modes.js` only if needed) + `location-cascade-city-mode.test.js` (+ `location-select-modes-city-mode.test.js` only if that file changes) |
| C | `src/checkout-blocks/locality-chooser.tsx`, `single-place.tsx`, `types.ts` (+ `native-rows.ts` if needed) + `checkout-blocks-city-mode.test.tsx` |
| D | `settings-api/class-control.php`, `settings-api/class-setting.php`, `settings-api/abstract-class-settings.php`, `settings-page/class-records-table.php`, `settings-page/class-field-schema.php`, `settings-page/class-composite-settings-handler.php`, `src/components/records-table.tsx`, `src/components/validate.js`, `adr/008-…` + `records-table.test.tsx`, `settings-page-validate.test.js`, `RecordsTableTest`, `ConditionalFieldsTest`, `CompositeSettingsHandlerTest`, `FieldSchemaTest` |
| E | plugin `includes/city-mode/class-city-mode-settings.php`, `class-city-list.php`, `class-city-mode.php`, `class-city-mode-notices.php`, `class-city-select-fallback.php`, `class-city-mode-environment.php`, `assets/js/city-select.js`, `languages/*` + `tests/unit/city-mode/*`, `tests/js/city-select.test.js` |

Built bundles and `.min.js` files are the coordinator's, rebuilt in the **primary checkout** only (CLAUDE.md project fact 5).

### WP-A — framework PHP: per-country config and reads (M)

| File | Change |
|---|---|
| `woodev/shipping-method/checkout/class-checkout-config.php` | `with_settlement_choices()`: `levels[country].region = false` only for countries in `$allowed`; `allowCustomSettlement` no longer clamped; `hideSettlement` = the published `hide_field` as is (no `1 === count( records )`); docblock + `@since` |
| `woodev/shipping-method/location/class-location-service.php` | new protected seam `checkout_view_country(): string` — the **delivery target's** country (spec §4.2 r7.1): on the initial classic page build (not `wp_doing_ajax()`, not a REST / Store API request) `WC()->customer->get_{target}_country()` with `target = Address_Target::classic_default()`, else `customer_shipping_country()` (WooCommerce's `update_order_review` has copied billing into shipping by then, and the Store API cart address is the delivery address); either way `resolve_default_country()` when empty. `filtered_default_locality()`: `apply_filters( FILTER_DEFAULT_LOCALITY, null, $this->checkout_view_country() )` + docblock of the constant (it now passes **two** arguments — a consumer registers with `accepted_args = 2`); `resolve_default()` and `get_default_locality_policy()` follow, since both go through it; new private `listed_records_for( string $country ): Location_Record[]`; `settlement_choices_narrow_this_read( string $country )`; `is_outside_settlement_choices()` uses the record's own country; `saved_city_for_checkout()` and the region skip in `get_customer_chain()` (~1181) use `checkout_view_country()`; `names_listed_settlement( string $city, string $country = '' )` (`''` = any country, for callers that have none). **Unchanged:** `customer_shipping_country()` and its existing consumers (staleness rule (b), #346 / #587) |
| `woodev/shipping-method/checkout/class-checkout-handler.php` | `is_rejected_city_prefill()`: the section's own country (`get_{section}_country()`) into `names_listed_settlement()` and a per-country `has_settlement_choices_for()` (or the new reader); `classic_checkout_default_target()` delegates to `Address_Target::classic_default()` (same body, one copy); no other server rule changes — `guard_custom_settlement()` stays as is (spec §4.2: no place → the store's own rule) |
| `woodev/shipping-method/pickup/class-address-target.php` | new `public static classic_default(): string` — the body of today's private `Checkout_Handler::classic_checkout_default_target()` (`woocommerce_ship_to_different_address_checked` over `woocommerce_ship_to_destination`, then `resolve()`), moved so `Location_Service` can share it |

Tests (`rm -f .phpunit.result.cache` first; `composer test`, `composer phpcs`):

- `tests/unit/Shipping/Checkout/CheckoutConfigTest.php`: RU 3 places + KZ 1 → `levels.RU.region` and `levels.KZ.region` false, `levels.UZ.region`
  unchanged; `allowCustomSettlement` equals the store value; `hideSettlement` is the switch whatever the total count;
- `tests/unit/Shipping/Location/LocationServiceTest.php` (or a new `LocationServiceCityModeCountryTest.php`): a UZ customer's UZ record is **not**
  read as outside the list while RU lists places; a saved UZ city survives `saved_city_for_checkout()`; RU with one place gives the filtered default
  for an RU customer and **none** for a KZ customer with two KZ places; the filter receives the country as its second argument;
- the same file, **first render** (critic finding 5; no prior `update_order_review`, not an AJAX/REST request): saved billing RU (one listed place) +
  saved shipping UZ (none), delivery target billing (billing-only shop, and checkbox default unchecked) → the filter is asked for **RU**,
  `resolve_default()` is the RU place and `get_default_locality_policy()` is `fixed`; the reverse — billing UZ (no places) + shipping RU (one place),
  target billing → no filtered default, the saved UZ city survives `saved_city_for_checkout()`; target shipping (checkbox default checked) → the
  shipping country is used; an AJAX request → `customer_shipping_country()` exactly as before;
- `tests/unit/Shipping/Pickup/AddressTargetTest.php`: `classic_default()` — billing-only → `billing`; destination `shipping` → `shipping`;
  destination `billing` → `billing`; the `woocommerce_ship_to_different_address_checked` filter wins;
- `tests/unit/Shipping/Checkout/CheckoutHandlerCityModePrefillTest.php`: a saved Tula is blanked for RU (places), a saved Ташкент is kept for UZ (no
  places);
- `tests/unit/Shipping/Checkout/CheckoutHandlerCustomSettlementGuardTest.php`: `ajax-select2`, custom off, UZ without places → the guard applies the
  store rule exactly as with city mode off (no city-mode refusal of any kind).

### WP-B — framework classic JS (M)

| File | Change |
|---|---|
| `woodev/shipping-method/assets/js/frontend/location-cascade.js` | (1) the country-change path (`handleFieldChanged()` → `handleLayoutRelevantChange()` / `reconcileAfterCheckoutUpdate()`) re-attaches the settlement node when `resolveModeRenderer()` would now pick another renderer or another country's closed list — today it re-attaches only when the input element was replaced; (2) a per-country custom clamp `allowCustomFor( entry, country ) = !! allowCustomSettlement && ! allowedPlaces( entry, country )`, handed to the renderer options; (3) switching **into** a country with exactly one place writes `allowed[country][0]` (`value`, region via the existing ancestor fill) and seeds the chain the way `prefill()` does from `defaultLocality`, and switching **out** clears it through the existing `clearCountryScope()`; (4) `syncHiddenSettlementRows()` re-runs on the switch; (5) **the pickup City guard independent of the shop's ordinary mode** (critic finding 4): `holdsGuardedSettlement()` (~2603) returns true for the settlement field with a settlement record when the entry's live country has a closed list (`allowedPlaces( entry, country )` non-empty) **OR** the existing #961 rule holds (`'ajax-select2' === settlementAxisMode( entry ) && ! allowCustomSettlement`) — today the mode test at ~2609 alone makes it false under `typeahead`, although `resolveModeRenderer()` (~852–856) renders `closed-*` for a listed country whatever the mode, so `handlePickupAddressReplacing()` would `writeSilently()` the point's City over the listed place. The PHP #531 rule (`guard_custom_settlement()`) is unchanged |
| `woodev/shipping-method/assets/js/frontend/location-select-modes.js` | only if (2) cannot be passed through `options.location`: read the per-country clamp from the options instead of `options.location.allowCustomSettlement` (~1333) |

Tests (`npm run test:js`, from bash): `tests/js/location-cascade-city-mode.test.js` — RU (3) → KZ (1) → UZ (0) → RU: renderer `closed-select` →
`closed-text` → the shop's own mode → `closed-select`; no settlement `/suggest` while RU/KZ, a normal `/suggest` in UZ; State row hidden in RU/KZ,
shown in UZ; no RU value left in City after the switch to UZ; the KZ single place is written and posts its `value`. **Pickup City guard** (finding
4): a listed RU place under the shop mode `typeahead` **and** under `ajax-select2` → a pickup announcement with another City is vetoed (the field
keeps the place, `fields` loses the key); unlisted UZ with custom settlements **on** → the point's City is written; unlisted UZ, `ajax-select2`,
custom **off** → vetoed (#961 unchanged). `tests/js/location-select-modes-city-mode.test.js` only if `location-select-modes.js` changes. The
`.min.js` is rebuilt in the **primary checkout** only.

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
| `woodev/settings-page/class-field-schema.php` | passes the column keys to the client, **plus two server-resolved keys** on a `location-country` column (critic finding 6): `default` — `WC()->countries->get_base_country()` when it is among `options`, else the first option's code, `''` without options; `names` — `code => WooCommerce name` for every code in `options` **and** every country found in the setting's stored rows (the `of` records' `country`), from `WC()->countries->get_countries()`, the code itself where WooCommerce knows none. A plugin declares neither. `assert_count_controllers_are_tables()` also checks that a `per` names a `location-country` column of that table |
| `woodev/settings-api/class-setting.php` | the `count` condition: optional `per` + `aggregate` (`min` / `max`) — rows grouped by the country of the record in the `of` column, only countries in `in_scope`; no groups → the condition does not match. The third argument of `evaluate_conditions()` changes from `string[] $records_table_ids` to **`array<string, array[]> $records_tables`** — records-table setting id → its column declarations (`Woodev_Control::get_columns()`); the parameter is new in 2.0.26 (unreleased), so it changes in place. A `count` without `per` counts every row exactly as today, and so does a table without a `location-country` column (warehouses) |
| `woodev/settings-api/abstract-class-settings.php` | `filter_visible_values()` (`:471`, `:488`, `:499`) passes the new map; `records_table_setting_ids()` (`:517`) becomes `records_table_columns()` returning `id => get_columns()` (critic finding 1) |
| `woodev/settings-page/class-composite-settings-handler.php` | its own records-table loop (`:316–322`) builds the same `id => get_columns()` map from the registered controls and passes it at `:334` / `:345` (critic finding 1) |
| `src/components/records-table.tsx` | the `location-country` cell (`names[code]`, the code when absent, + the flag note when the record's country is not in `in_scope`); the form's «Страна» select first (text when one option; preselected `default`); the chosen code as that row's picker `country`; clearing a picked record of another country; rows kept sorted by `names` (stable) on every add/edit; a table without a `location-country` column renders and sorts exactly as today |
| `src/components/validate.js` | the client twin of the `per` / `aggregate` count, reading the column declaration from `fieldSchemas` |
| `docs-internal/adr/008-conditional-fields-operator-set.md` | amendment: `count` + `per` / `aggregate`; the evaluator's third argument carries column declarations |

Tests: `tests/js/records-table.test.tsx` (one option → no select; several → select; the picker request carries the chosen country; sort order after
add/edit; the flag on an out-of-scope row; changing country clears a mismatched pick; **base country not first** in `options` (KZ, RU with
`default: RU`) → RU preselected; a **flagged country absent from `options`** shows its `names` entry «Казахстан», not `KZ`, and sorts by that name;
a warehouse-shaped table without a `location-country` column is unchanged), `tests/js/settings-page-validate.test.js` (`min` / `max` per-country
counts; flagged rows form no group), `tests/unit/SettingsApi/RecordsTableTest.php` (the derived column is not stored and not validated),
`tests/unit/SettingsApi/FieldSchemaTest.php` (`default` = base country when offered, else the first option; `names` covers options ∪ stored
countries, the code where WooCommerce knows none; no extra keys on a table without the column), and — through the **real save paths**, not the
evaluator alone (critic finding 1) — `tests/unit/SettingsApi/ConditionalFieldsTest.php` (`Woodev_Abstract_Settings::filter_visible_values()`) and
`tests/unit/CompositeSettingsHandlerTest.php` (the composite handler's `filter_visible_values()`), each with a hide switch whose `disabled_if` is the
per-country `count` (`min`): RU 3 + KZ 1 → enabled, the submitted `true` **survives**; RU 3 + a KZ 1 row whose country is outside `in_scope` → groups
{RU: 3} → disabled, the switch is **stripped**; a `count` without `per` on the same rows → today's total-count result; the PHP twin agrees with the
JS one on every case.

### WP-E — plugin `woocommerce-range-shipping` (M)

On PR #41's branch (or a follow-up PR on top of it), bundling the framework `woodev/` that carries WP-A … WP-D.

| File | Change |
|---|---|
| `includes/city-mode/class-city-mode-settings.php` | `register_cities()`: a `location-country` column «Страна» (`of: city`, `options` = `City_Limit::country_options( shipping ∩ get_supported_countries() )`, `in_scope` = shipping countries) before the picker column — `default` and `names` are resolved by the framework (WP-D), not declared here; `register_hide_field()` / `disabled_when_several_places()`: `per: country` + `aggregate: min` (K16; also removes today's duplicated inline condition); `update_value()` / `several_places_saved()`: the same per-country rule; copy per spec §3.3 r7 (operator, K16) |
| `includes/city-mode/class-city-list.php` | `published_records()` — rows whose record's country is in WooCommerce shipping countries; `hides_field()` returns the stored switch; a per-country count helper shared with the settings save |
| `includes/city-mode/class-city-mode.php` | `register()` (`:82`): `add_filter( self::FILTER_DEFAULT_LOCALITY, [ $this, 'publish_default_locality' ], 10, 2 )` — **`accepted_args = 2`**, or WordPress delivers only `$current` (critic finding 2); publish `published_records()`; `is_in_force()` needs at least one; `publish_default_locality( $current, $country = '' )` returns the single published place of `$country` |
| `includes/city-mode/class-city-mode-notices.php` | the «every row flagged» notice (spec §3.4, operator, K16) |
| `includes/city-mode/class-city-select-fallback.php` | values keyed by country; the server `select` only when the delivery country at render has places; **`enqueue_script()` (`:123`) drops its `wc_ship_to_billing_address_only()` exclusion** (critic finding 3) — the script is now also the country switcher, which a billing-only shop needs; docblock rewritten |
| `assets/js/city-select.js` | swaps select ↔ text on a country change; **no «Доставить по другому адресу» checkbox on the page → the billing City is the delivery City** (today `wire()` assumes the checkbox, `:85`, `:114`); when both the old and the new country have lists, an existing select's options are **replaced** with the new country's |
| `includes/city-mode/class-city-mode-environment.php` | a `shipping_countries()` / `supported_countries()` seam for tests |
| `languages/*` | the new and changed strings |

Tests: `tests/unit/city-mode/CityModeSettingsTest.php` (column declaration and options = shipping ∩ supported, one option; per-country disabled
condition and save-time clearing; a stored foreign-country row saves without error), `CityListTest.php` (published vs flagged; **K8**: the DaData key
removed — `options` empty — while a RU place remains in a RU-shipping shop → the row is **published and not flagged**, critic finding 7),
`CityModeTest.php` (not in force when every row is flagged; the default-locality hook per country; **hook dispatch**: `register()` adds
`publish_default_locality` with priority 10 and `accepted_args = 2` — Brain Monkey `Filters\expectAdded( … )->with( …, 10, 2 )` — and the callback
called as `( null, 'KZ' )` with RU 3 + KZ 1 returns Алматы), `CityModeNoticesTest.php`, `CitySelectFallbackTest.php` (per country; **the script is
enqueued in a billing-only shop**; the key-removed K8 fixture keeps the RU select), `tests/js/city-select.test.js` (country switch; **billing-only
RU → KZ → RU** with no checkbox — KZ with its own list replaces the options, KZ with no places turns the City back into text, RU restores the
select). Plugin phpcs + suite green; the coordinator's Playwright look at the tab at 1440 and 1280 (single-country shop, several countries, a
flagged row) before «done».

### Rig (inside C15's run)

Spec §8 A13–A18 on classic and block, each with the placed order's fields; screenshots on disk with the masked phone.

## Not in this plan

- No change to the providers or to `build_locations_constraint()`: the coverage measurement (spec §3.1) shows every choice can add a place once the
  picker gets the row's country.
- No migration, no new option.
- No change to `window.woodevSettings` (`class-settings-page-registry.php:717–723`): the base country and the names travel on the column schema.

## Related

- [specs/2026-11-05-range-shipping-city-mode.md](../specs/2026-11-05-range-shipping-city-mode.md) — the spec this plan implements (r7.1).
- [plans/2026-11-06-s219-city-mode-overnight.md](2026-11-06-s219-city-mode-overnight.md) — the s219 run that built city mode r6.
- [adr/008-conditional-fields-operator-set.md](../adr/008-conditional-fields-operator-set.md) — the condition set WP-D amends.
