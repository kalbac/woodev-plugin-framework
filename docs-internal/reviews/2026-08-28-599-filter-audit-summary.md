# #599 — audit of `apply_filters()` return values: summary

**Date:** 2026-08-28 (s101) · **Scope:** every `apply_filters()` call in `woodev/` · **Kind:** audit,
not refactor — nothing in this pass changed a source file.

Run as three parallel read-only audits under Orca (worker = Sonnet), one per slice, each answering
the four questions on card #599 for every site it found.

## Counts

| Slice | Sites | FATAL | DISABLES | WRONG-DATA | GUARDED | HARMLESS | UNKNOWN |
|---|---|---|---|---|---|---|---|
| `payment-gateway/` | 70 | 12 | 8 | 8 | 4 | 38 | 0 |
| `shipping-method/` | 40 | **10** | 1 | 1 | 27 | 1 | **0** |
| everything else | 49 | 20 | 0 | 6 | 15 | 8 | 0 |
| **total** | **159** | **42** | **9** | **15** | **46** | **47** | **0** |

The card said 162. The real number is **159**: three of the grep hits are `apply_filters` written
inside docblock prose, not calls. Worker B caught that and said so rather than padding its table.

**Read `FATAL` as "a plugin CAN cause a fatal here", not as "this is broken".** Every one of the 40
needs a plugin to return the wrong thing. That is exactly the premise of the card — the framework
hands out a seam and then trusts what comes back — but it means the 40 are a WORK LIST to triage,
not 40 defects.

## The findings that matter

Four were verified independently, by hand, after the workers reported. The rest of each table rests
on its worker's own reading.

1. **`shipping-method/class-shipping-method.php:246` and `:261` — FATAL on cart/checkout rate
   calculation.** Both `woodev_shipping_method_pre_calculate_rate` and
   `woodev_shipping_method_calculated_rate` feed the same `if ( $rate ) { … $rate->to_array(); }`
   a few lines below. The only check is truthiness, so any truthy non-`Shipping_Rate` return is a
   fatal while a customer is calculating shipping. **Verified.**

2. **Two unfixed siblings of the s100 bug, both `?Location_Provider` returns with no validation:**
   `location/class-location-provider-registry.php:2532`
   (`resolve_active_provider_for_id(): ?Location_Provider`) and
   `location/class-location-service.php:2085`. This is the *same shape* as
   `Location_Service::resolve_geoip_default()`, which was the s100 blocker — fixed there, never
   swept for elsewhere. `:2532` **verified.**

3. **`payment-gateway/payment-tokens/…-tokens-handler.php:700` — DISABLES, and the worst of the
   nine.** The transient-key filter: a plugin returning a key that ignores `$user_id` serves one
   customer's saved payment tokens to another. Not a type mismatch — a data-isolation defeat that
   crashes nothing. The hook's own docblock already warns about it in prose ("filter responsibly!"),
   which is the whole point of question 4: a warning is not an enforcement.

4. **`licensing/api/class-licensing-api.php:91` — FATAL, and the cheapest to fix.**
   `get_url(): string` returns `apply_filters( 'woodev_license_base_url', … )` with nothing in
   between. Built on every licensing-API and updater instantiation. **Verified.**

5. **Six sites share two crash shapes because each filters a URL independently** rather than going
   through one validated helper (`account/`, `admin/`, `rest-api/`) — one of them inside
   `is_trusted_package_url()`, i.e. a security check. One helper closes six of the 20 FATAL rows in
   that slice at once.

## What this does NOT settle

The card is explicit that each site is a separate decision, and that stands. In particular:

- **Which boolean filters count as `DISABLES` and which as `HARMLESS` is a judgement call**, and
  worker A said so out loud rather than picking quietly. `ssl-verify`, `memory-exceeded`,
  `time-exceeded` and friends are documented intentional override points; a filter that turns off a
  *protection* is a different thing from one that turns off a *behaviour*, and the line between
  them is the operator's to draw.
- ~~Two `UNKNOWN` rows in `shipping-method/`.~~ **RESOLVED the same day, by measurement.** The
  worker could not settle them because WooCommerce's source is not in this checkout — it is in
  the rig container. Both filters write into a property WooCommerce then hands to `array_map()`
  (`abstract-wc-shipping-method.php:565`, `abstract-wc-settings-api.php:67`), and
  `array_map()` on a non-array is a `TypeError` in PHP 8. Verdict for both:
  **FATAL**, on the admin settings screen. The totals above carry the corrected figures.
  Marking them UNKNOWN rather than guessing was the right call — the answer simply lived
  outside the worker's reach.

## The per-site tables

- [payment-gateway](2026-08-28-599-filter-audit-payment-gateway.md) — 70 sites
- [shipping-method](2026-08-28-599-filter-audit-shipping-method.md) — 40 sites
- [everything else](2026-08-28-599-filter-audit-rest.md) — 49 sites

Each has a `## Worth acting on` punch list with a proposed fix per site. The fixes are **proposed,
not applied**.

## Related

- Card #599 — this audit's card, and the rule it rests on: degrade to a safe default, never throw,
  never disable a protection
- #587 / PR #591 and #585 / PR #592 — the three reproduced s100 blockers this generalises from,
  and the reference implementation of a correctly-validated filter return
  (`Woodev_API_Base::redact_secret_log_text()`)
