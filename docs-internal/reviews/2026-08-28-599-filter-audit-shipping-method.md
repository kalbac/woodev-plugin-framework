# #599 audit — Worker B — `woodev/shipping-method/`

Serena MCP: available and used (`activate_project` on `D:/Projects/woodev_framework`, verified via
`find_symbol`/`get_symbols_overview` reporting paths under that tree). Deep-dive reads of specific
line ranges were done with `Bash`/`awk` for speed once the file was already open via Serena — no
source file was edited, no git/gate command was run.

## Coverage note — the brief's count is off by 3

`grep -rn "apply_filters(" --include='*.php' woodev/shipping-method/` returns **43** lines, matching
the brief. But **3 of those 43 are inside docblock prose**, not actual calls (they read
`` `apply_filters()` call `` as English text describing something else):

- `checkout/class-checkout-field-policy.php:164` — describing WordPress's hook-table identity
  semantics, no call on this line.
- `location/class-location-service.php:1676` — describing `wc_get_base_location()`'s own internal
  use of `apply_filters( 'woocommerce_get_base_location', ... )` (a WooCommerce-core filter, not
  ours).
- `location/class-location-settings.php:140` — describing `resolve_active_provider_for_id()`'s own
  filter call, referenced from a different method's docblock.

**Actual `apply_filters()` invocation sites in this directory: 40.** All 40 are enumerated and
verdicted below.

## Table

| file:line | hook name | return goes to | verdict | note |
|---|---|---|---|---|
| checkout/class-checkout-config.php:468 | `woodev_pickup_slot_placements` | `?array` return of `resolve_pickup_slot_placements()` | GUARDED | `is_array()` checked; non-array → `return null` |
| checkout/class-checkout-config.php:511 | `woodev_location_i18n` | `array<string,string>` return of `location_i18n_strings()` | GUARDED | `return array_map( 'strval', (array) $strings )` — cast + per-element stringify, cannot fatal |
| checkout/class-checkout-field-policy.php:297 | `woodev_checkout_field_policy_restore_invariants` | `if ( apply_filters(...) )` | HARMLESS | Truthy/falsy `if()` gate — no type can fatal it. Skipping the restore on a falsy return is the hook's **documented purpose** (an intentional carrier escape hatch), not an accidental disable |
| checkout/class-checkout-handler.php:1346 | `$this->hook('checkout_fields')` (dynamic) | `: array` return of `inject()` | GUARDED | `(array)` cast satisfies the return type; inner shape (per-field config) is not further validated, same trust level as WooCommerce's own native `woocommerce_checkout_fields`-family filters |
| checkout/class-checkout-handler.php:1652 | `woodev_checkout_pickup_slot_fields` | local `$filtered`, then filtered/returned | GUARDED | Explicit `is_array()` guard + per-entry `is_array()`/`isset('id')` filter, with an explicit comment on why ("the backstop exists to BLOCK a checkout... must never be the reason it silently stops") |
| class-shipping-method.php:149 | `woodev_shipping_method_{id}_form_fields` | `$this->instance_form_fields` (untyped property, inherited from WooCommerce's `WC_Settings_API`) | **UNKNOWN** | No cast/validation here. Consumed later by WooCommerce's own admin-settings rendering (`WC_Settings_API`/`WC_Admin_Settings`, vendor code not present in this repo checkout) — I could not verify from this directory alone whether a non-array return there causes a `TypeError` (e.g. via `array_merge()`) or a silent empty-admin-form no-op. Would need the vendored WooCommerce source to settle FATAL vs DISABLES |
| class-shipping-method.php:246 | `woodev_shipping_method_pre_calculate_rate` | `$rate`, feeds a later `$rate->to_array()` call | **FATAL** | See combined note below — flows into the same unguarded sink as :261 |
| class-shipping-method.php:261 | `woodev_shipping_method_calculated_rate` | `$rate`, feeds `if ( $rate ) { $this->add_rate( $rate->to_array() ); }` at line ~276 | **FATAL** | **Runs unconditionally**, regardless of whether :246 was used. Docblock says `@param Shipping_Rate\|null $rate` but nothing casts or `instanceof`-checks it. Any truthy non-`Shipping_Rate` return (a string, array, `true`, an int) → `Call to a member function to_array() on <type>` — a hard fatal on the **cart/checkout rate-calculation path**, the exact shape of the fixed s100 `Location_Service::resolve_geoip_default()` bug |
| class-shipping-method.php:396 | `woodev_shipping_{id}_is_available` | `: bool` return of `is_available_for_package()` | **FATAL** | Returned with **no cast** against a declared `bool` return type. File has no `declare(strict_types=1)`, but PHP's weak-typing coercion table only covers scalar↔scalar; an array, object, or `null` return from a plugin filter throws `TypeError: Return value must be of type bool, <type> returned` — fatal on the checkout method-availability path |
| class-shipping-plugin.php:305 | `woodev_shipping_plugin_method_classes` | `foreach ( $classes as $class )` in `register_shipping_methods()` | DISABLES | No `is_array()`/cast. A non-iterable return (`null`, `false`, a string) makes `foreach` silently no-op (PHP8: warning only, not fatal) — this shipping plugin registers **zero** of its own shipping methods, with no error surfaced. Whole shipping option silently vanishes from checkout |
| class-shipping-plugin.php:350 | `woodev_shipping_plugin_registered_methods` | `: array` return of `register_shipping_methods()` | **FATAL** | No cast against the `array` return type. `array` is non-scalar — no weak-type coercion applies at all, so any non-array return is an immediate `TypeError`. Fires on the `woocommerce_shipping_methods`-family registration path, hit on every cart/checkout calculation |
| class-shipping-plugin.php:1171 | `woodev_shipping_plugin_{id}_accepted_currencies` | `: array` return of `get_accepted_currencies()` | **FATAL** | Same shape as :350 — no cast, `array` return type, any non-array return is a `TypeError`. Not called from within this directory today (only a payment-gateway sibling method of the same name exists, out of scope), but it is public API — the risk is live for any future/plugin caller |
| class-shipping-plugin.php:1190 | `woodev_shipping_plugin_{id}_accepted_countries` | `: array` return of `get_accepted_countries()` | **FATAL** | Same shape as :350/:1171. **Confirmed reachable from the checkout path**: `class-shipping-method.php:362` and `:366` call `$this->get_plugin()->get_accepted_countries()` directly inside `is_available_for_package()` |
| location/class-location-provider-registry.php:934 | `Location_Provider_Registry::FILTER_PROVIDERS` | `$candidates` in `collect()` | GUARDED | `(array)` cast + per-candidate `instanceof Location_Provider` check + `_doing_it_wrong()` logging; bundled providers still register regardless. Textbook example of the s100 fix pattern |
| location/class-location-provider-registry.php:2532 | `Location_Provider_Registry::FILTER_ACTIVE_PROVIDER` | `?Location_Provider` return of `resolve_active_provider_for_id()` | **FATAL** | **Unfixed sibling of the s100 bug** — same exact shape as the already-fixed `Location_Service::resolve_geoip_default()`. No `instanceof`/`null` check at all, straight `return apply_filters(...)`. Reached via the public `get_active_provider()` (line 1139), which the location typeahead needs on every checkout render. A plugin returning e.g. a string or an array → immediate `TypeError` |
| location/class-location-resolution-cache.php:286 | `Location_Resolution_Cache::FILTER_TTL` | `(int)` cast, local `$ttl` | GUARDED | Cast to `int`; array/object casts to int cannot throw (return 1/0 with at most a warning) |
| location/class-location-service.php:668 | `Location_Service::FILTER_REGION_ANCESTOR_CACHE_TTL` | `(int)` cast, local `$ttl`, then `max(0, $ttl)` | GUARDED | Same shape as above, plus an explicit floor |
| location/class-location-service.php:745 | `Location_Service::FILTER_REGION_ANCESTOR_CACHE_TTL` | `(int)` cast, local `$ttl`, then `max(0, $ttl)` | GUARDED | Same as :668 (documented as sharing one docblock) |
| location/class-location-service.php:1542 | `Location_Service::FILTER_GEOIP_COUNTRY_MISMATCH` | `?Location_Record` return path | GUARDED | `return $filtered instanceof Location_Record ? $filtered : null;` — explicit, and the docblock says outright "The return is validated" (issue #587 fix). This is the post-mortem pattern the other `?Location_Provider`/`?Location_Record` sites (:2532, :2085, and :149/:110's cousins) should be matching but are not |
| location/class-location-service.php:1742 | `Location_Service::FILTER_DEFAULT_COUNTRY` | `: string` return of `resolve_default_country()` | GUARDED | `is_string()` check, then re-parsed/re-validated, falls back to the already-safe `$resolved`. Docblock explicitly calls out **why** it avoids a bare `(string)` cast ("PR #320 review, finding 5 — `(string) $object`... would fatal the checkout render") — this is the project's own documented awareness of exactly this bug class |
| location/class-location-service.php:2085 | `Location_Service::FILTER_PROVIDER_FOR_LEVEL` | `?Location_Provider` return of `provider_for_level()` | **FATAL** | **Second unfixed sibling of the s100 bug.** No validation at all — plain `return apply_filters(...)` against a `?Location_Provider` return type. Confirmed reachable from `rest-api/class-location-controller.php:879` (`$this->service->provider_for_level( $level )`), the handler behind the checkout's own `/location/suggest` REST endpoint. A bad plugin return here 500s the location-typeahead AJAX call the customer is actively using |
| location/class-popular-settlement-store.php:172 | `Popular_Settlement_Store::FILTER_LIST_CAP` | `(int)` cast, `: int` return of `list_cap()` | GUARDED | Cast matches return type; a nonsensical (negative/zero) value only makes a `count($rows) < list_cap()` comparison behave oddly (fewer/no popular-settlement rows shown) — cosmetic, not a fatal or a disabled protection |
| location/class-popular-settlement-store.php:183 | `Popular_Settlement_Store::FILTER_TTL_SECONDS` | `(int)` cast, `: int` return of `ttl_seconds()` | GUARDED | Same shape |
| location/class-popular-settlement-store.php:198 | `Popular_Settlement_Store::FILTER_VERIFY_TTL_SECONDS` | `(int)` cast, `: int` return of `verify_ttl_seconds()` | GUARDED | Same shape |
| location/providers/class-dadata-api-client.php:257 | `woodev_location_dadata_language` | `(string)` cast, then `in_array( $language, ['ru','en'], true )` allow-list | GUARDED | Double-guarded: cast handles type, allow-list handles meaning — even `(string)` of a garbage array (→ `"Array"`, a PHP warning not a fatal) fails the allow-list and falls back to `'en'` |
| location/providers/class-dadata-provider.php:266 | `Dadata_Provider::FILTER_COUNTRIES` | `(array)` cast, `: array` return of `get_countries()` | GUARDED | Cast satisfies return type; a wrong-shaped array (non-string entries) can only make a later `in_array($country, ...)` check behave oddly — WRONG-DATA at worst, not fatal |
| map/class-yandex-map-provider.php:488 | `woodev_shipping_map_fallback_api_key` | `(string)` cast, `: string` return of `resolve_api_key()` | GUARDED | Cast satisfies the return type; a garbage key (e.g. `"Array"` from an array-returning plugin) just makes the map fail to load — degraded feature, not a fatal, not a disabled protection |
| pickup/class-constraint-checker.php:151 | `woodev_shipping_pickup_point_selectable` | `Constraint_Checker::sanitize_verdict()` | GUARDED | Verified the implementation, not just the docblock claim: `sanitize_verdict()` checks `is_array()`, then `array_key_exists('allowed') && is_bool()`, then `array_key_exists('reason')`, then `null !== reason && !is_string(reason)` — any failure returns the framework's own computed `$computed` verdict unchanged. Exactly matches its own docblock's promise |
| pickup/class-pickup-handler.php:560 | `woodev_pickup_accent_color` | `(string)` cast → `sanitize_hex_color()` → fallback chain | GUARDED | `sanitize_hex_color()` (WP core) returns `null` for anything invalid; falls back through the constructor value to `self::DEFAULT_ACCENT_COLOR` |
| pickup/class-pickup-handler.php:601 | `woodev_pickup_accent_fill_color` | `(string)` cast → `sanitize_hex_color()` → derived fallback | GUARDED | Same pattern as :560 |
| pickup/class-pickup-handler.php:632 | `woodev_pickup_accent_contrast_color` | `(string)` cast → `sanitize_hex_color()` → derived fallback | GUARDED | Same pattern as :560/:601 |
| pickup/class-pickup-handler.php:984 | `woodev_pickup_map_point_glyphs` | `normalized_point_glyphs()`'s per-entry loop | GUARDED | `is_array()` on the whole return, then per-entry `is_string($type)`/`is_string($value)`, then `sanitize_glyph_markup()` for anything not a built-in glyph name; unusable entries are dropped, not passed through |
| pickup/class-pickup-handler.php:1298 | `woodev_pickup_map_i18n` | `$strings`, placed into `get_js_config()`'s `'i18n'` key, → `wp_localize_script()` at line ~1973 | **WRONG-DATA** | **No validation at all** — unlike its documented sibling `woodev_location_i18n` (checkout-config.php:511, which does `array_map('strval', (array) $strings)`). Cannot PHP-fatal (any value survives `wp_localize_script()`'s own JSON encoding), but a non-array/wrong-shaped return reaches the **checkout pickup-map's** JS config as `i18n`, where client code indexing `i18n.someKey` on a non-object would break the map's rendered text/labels. Inconsistent with the sibling filter's own defensive pattern for the same class of data |
| pickup/class-pickup-handler.php:1308 | `woodev_pickup_map_search_enabled` | `(bool)` cast | GUARDED | Cast satisfies everything; array/object → truthy, cannot fatal |
| pickup/class-pickup-handler.php:1332 | `woodev_pickup_max_accumulated_points` | `is_numeric()` check → `(int)` cast → `max(0, ...)` | GUARDED | Explicitly documents an **adversarial-review-caught near-miss**: a bare `(int)` cast on a non-empty array (e.g. a plugin returning its whole settings struct by mistake) would fold to `1`, silently reinstating issue #234. `is_numeric()` first avoids that, defaulting to `0` (unlimited/safe) instead |
| pickup/class-pickup-handler.php:1674 | `woodev_shipping_pickup_recheck_outage_allows_checkout` | `(bool)` cast, `: bool` return of `evaluate_recheck_outage()` | GUARDED | Cast satisfies the return type; default is documented as fail-open (allow checkout) by design for this specific hook, not an accidental disable |
| pickup/class-pickup-selection.php:593 | `woodev_pickup_max_remembered_selections` | `is_numeric()` check → `(float)` cast → integer floor logic | GUARDED | Explicitly documents avoiding a different near-miss: casting straight to `int` would fold a fractional cap (`0.5`) to `0` and silently disable the bound — checked and commented on directly |
| rest-api/class-pickup-controller.php:686 | `woodev_shipping_pickup_point_selection` | `Selection_Result::sanitize()` | GUARDED | Verified: `sanitize()` is an intentional line-for-line copy of `Constraint_Checker::sanitize_verdict()`'s four guards (documented in a shared comment on both sides explaining why they're not shared code), extended for the 5-key result shape |
| settings/class-shipping-integration.php:110 | `woodev_shipping_plugin_settings_{id}_form_fields` | `$this->form_fields` (untyped property, inherited from WooCommerce's `WC_Settings_API`) | **UNKNOWN** | Same shape and same caveat as class-shipping-method.php:149 — no local validation, and the consuming code (WooCommerce's admin settings screen) is vendor code not in this repo checkout. Could not verify FATAL vs. a silent empty-admin-form DISABLES from this directory alone |
| settings/class-shipping-tools-registry.php:139 | `Shipping_Tools_Registry::FILTER_TOOLS` | `$candidates` in `collect()` | GUARDED | Identical pattern to `location-provider-registry.php:934` — `(array)` cast + per-candidate `instanceof Shipping_Tool` + `_doing_it_wrong()` |

## Worth acting on

Ranked FATAL/DISABLES first, most severe first:

1. **`class-shipping-method.php:246` + `:261` (FATAL, cart/checkout rate calculation).** Both
   `woodev_shipping_method_pre_calculate_rate` and `woodev_shipping_method_calculated_rate` feed
   the same unguarded `if ( $rate ) { $rate->to_array(); }` a few lines later. Proposed fix: after
   the second filter, `if ( null !== $rate && ! $rate instanceof Shipping_Rate ) { $rate = null; }`
   before the `if ( $rate )` check — degrade to "no rate added" rather than fatal, matching the
   s100 rule.
2. **`location/class-location-provider-registry.php:2532` (FATAL, checkout-render path — active
   provider resolution).** Unfixed sibling of the already-fixed s100 bug. Proposed fix: mirror
   `Location_Service`'s own `FILTER_GEOIP_COUNTRY_MISMATCH` pattern —
   `return $filtered instanceof Location_Provider ? $filtered : $provider;` (degrade to the
   pre-filter `$provider`, not to `null`, since `$provider` is already the resolved-with-fallback
   value).
3. **`location/class-location-service.php:2085` (FATAL, checkout-adjacent REST endpoint —
   `/location/suggest`).** Second unfixed sibling. Proposed fix: same shape —
   `return $filtered instanceof Location_Provider || null === $filtered ? $filtered : $resolved;`.
4. **`class-shipping-method.php:396` (FATAL, checkout method-availability check).** Proposed fix:
   `return is_bool( $available = apply_filters(...) ) ? $available : $is_available;` (or a plain
   `(bool)` cast, if any truthy/falsy coercion is acceptable here — the hook's own docblock never
   promises anything narrower than "modify method availability").
5. **`class-shipping-plugin.php:350` (FATAL, shipping-method registration — runs on every
   cart/checkout calculation).** Proposed fix: `is_array($filtered) ? $filtered : $methods` before
   returning, mirroring the registry's own `934`/`139` pattern one function up in the same file.
6. **`class-shipping-plugin.php:1171` and `:1190` (FATAL, public API; `:1190` confirmed on the
   checkout availability path).** Proposed fix: same `(array)`-cast-is-not-enough — needs an
   explicit `is_array($filtered) ? $filtered : $this->currencies` / `$this->countries` guard, since
   `array` is a non-scalar return type with zero coercion.
7. **`class-shipping-plugin.php:305` (DISABLES, whole shipping method silently vanishes from
   checkout).** Proposed fix: `is_array($classes) ? $classes : $this->get_shipping_method_classes()`
   before the `foreach`.
8. **`pickup/class-pickup-handler.php:1298` (WRONG-DATA, checkout pickup-map i18n).** Proposed fix:
   reuse the exact pattern already sitting one file away —
   `array_map( 'strval', (array) $strings )` — so this filter stops being the one inconsistent
   i18n-string filter in the codebase.
9. **`class-shipping-method.php:149` and `settings/class-shipping-integration.php:110` (UNKNOWN,
   admin settings screen).** Not confirmed FATAL or DISABLES from this directory alone — flagging
   because both hand an unvalidated filter return straight to an untyped property that only
   vendor WooCommerce code (`WC_Settings_API`) consumes. Proposed action regardless of which it
   turns out to be: add `is_array($filtered) ? $filtered : $this->{property}` in both places — it
   costs nothing and forecloses the question either way.

## Counts

Examined: **40** actual `apply_filters()` invocation sites (the directory's 43 grep hits minus 3
comment-only mentions — see coverage note above; this matches the brief's own "43" figure once the
3 comments are subtracted, so I believe the brief counted via the same raw grep I ran first).

| Verdict | Count |
|---|---|
| FATAL | 8 |
| DISABLES | 1 |
| WRONG-DATA | 1 |
| GUARDED | 27 |
| HARMLESS | 1 |
| UNKNOWN | 2 (both RESOLVED to FATAL after this report — see the note below) |
| **Total** | **40** |

## What was wrong in the brief

- The "43 of 162" count is grep's raw line count, not the actual call count — 3 of those 43 lines
  are docblock prose mentioning `apply_filters()`, not real invocations. Actual sites here: 40.
  (I flagged rather than silently corrected, per the brief's own honesty rule — worth checking
  whether Workers A/C have the same 3-comment-style inflation in their slices before trusting the
  162 total.)
- Otherwise the brief's hints were accurate and well-targeted: `location/` genuinely holds two
  more unfixed siblings of the exact s100 bug shape (`:2532`, `:2085`), and `pickup/`/`map/` were
  correctly predicted to be more WRONG-DATA-shaped than FATAL-shaped (only one WRONG-DATA finding
  there, `:1298`, and it's exactly the "type-valid but meaning-wrong" shape the brief asked to
  hunt for) — except the single most severe finding in this whole slice
  (`class-shipping-method.php:246`/`:261`, `$rate->to_array()`) sits **outside** every directory
  the brief called out by name (`location/`, `pickup/`, `map/`), in the shipping-method base class
  itself.

## Both UNKNOWN rows resolved to FATAL (same day, by measurement)

This worker could not settle `class-shipping-method.php:149` and
`settings/class-shipping-integration.php:110` because WooCommerce's source is not in this
checkout. It IS in the rig container. Read there:

```
woocommerce.latest-stable/includes/abstracts/abstract-wc-shipping-method.php:565
    return apply_filters( ..., array_map( [ $this, 'set_defaults' ], $this->instance_form_fields ) );

woocommerce.latest-stable/includes/abstracts/abstract-wc-settings-api.php:67
    return apply_filters( ..., array_map( [ $this, 'set_defaults' ], $this->form_fields ) );

$ php -r 'array_map("strval", "not-an-array");'
TypeError: array_map(): Argument #2 ($array) must be of type array, string given
```

Both filters write straight into the property WooCommerce then hands to `array_map()`, so a
non-array return is a **FATAL on the admin settings screen**, not a silent empty form. Verdict for
both rows: **FATAL**. The worker's instinct to mark them UNKNOWN rather than guess was right — the
answer simply lived outside its reach.
