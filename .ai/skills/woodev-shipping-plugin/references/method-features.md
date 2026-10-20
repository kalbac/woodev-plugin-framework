# Optional method features

Every feature below is OFF until the method declares it (set `$this->supports` before `parent::__construct()`, like the
packing feature). Each one stores a plain instance option. The option IDs for the named examples are stable: `include_insurance`, `min_cost` and `max_cost` are Yandex's v1 keys, kept so Yandex's migration carries them over (`class-shipping-method.php:64`, `97`, `100`). The framework does not migrate arbitrary v1 settings, though: each plugin verifies and maps its own released keys and values explicitly ([settings and migration](settings-migration.md#migrating-a-v1-plugin)).
Source: constants in `woodev/shipping-method/class-shipping-method.php` (`FEATURE_*`).

| Feature / seam | What the carrier does | Rules that cost time |
|---|---|---|
| `FEATURE_RATE_CACHE` | override `get_rate_cache_context()` | opt in only when the context names EVERY quote input — see [model, rates, location, pickup](model-rates-location-pickup.md#rate-cache-and-packing) |
| `FEATURE_COST_LIMITS` (#1159) | nothing — the framework clamps | below |
| `FEATURE_INSURANCE` (#1165) | read the resolver | below |
| `FEATURE_FEE_PAYMENTS` (#1144) | ask `fee_applies_for_package()` / `apply_fee_for_package()` before adding a fee | below |
| `FEATURE_CITY_LIMIT` (#1176) | nothing | below |
| `declare_services()` (#1145) | return `Carrier_Service` objects | below |
| `show_if` on instance form fields (#1158) | add `show_if` to a `form_fields` entry | below |
| `declares_payment_dependent_price()` (#1239) | return `true` from the method CLASS when the price always depends on the payment method | in «Fee by payment method» |
| `report_hidden()` (#1238, #1276) | report every refusal path's reason next to the log line | [below](#why-a-method-is-hidden-the-admin-only-hint) |

## Cost limits (`FEATURE_COST_LIMITS`)

For a carrier whose price is CALCULATED by its API. The instance gets `min_cost` / `max_cost` (Yandex's keys; empty = no
limit) and the framework raises or lowers the finished rate as the LAST step of `calculate_rate()` — after the carrier's
fee, free-shipping rule and the box cost. Equal limits make the price effectively fixed, so there is **no separate «fixed
price» option** (CDEK migrated v1 `static_price` to `min_cost = max_cost`, s160). A cost of 0 or below is returned as is: a
free rate stays free. The pair is validated together on save. A carrier whose merchant types the price himself must not
declare it. Source: `Shipping_Method::apply_cost_limits()`, `FEATURE_COST_LIMITS`.

## Insurance (`FEATURE_INSURANCE`)

The instance option `include_insurance` is `none` / `always` / `delivery_payment` (Yandex's contract). The carrier sets its
default with `get_default_insurance_mode()` (CDEK: `always`, because the carrier charges it anyway — an honest price) and
reads **one decision on both ends**: `resolve_insurance_for_package( $package )` for the quote and
`resolve_insurance_for_order( $order, $items )` for the export. Goods are valued after discounts, excluding tax, shipping and
fees; the amount is in the store currency and the carrier owns any API currency conversion. «Only on payment on receipt»
uses the shared gateway registry (filter `woodev_shipping_insurance_cod_gateways`, default `[ 'cod' ]`). The outcome is part
of the rate-cache context. Check the carrier itself: CDEK prices `INSURANCE` from the declared value on `/calculator/tariff`
and adds it at order creation whether or not the quote carried it — a quote that omits it is a wrong price (s159, s160).
Two traps for a plugin moving off a v1 plugin (#1163): the declared value is measured AFTER discounts and ex-tax, so it can
be lower than a v1 plugin's cart-before-discounts figure — deliberate, not a regression; and the admin order wizard
quotes tariffs at step ④, before step ⑤ chooses the payment method, so that FIRST quote has no `chosen_payment_method` on
the package and `delivery_payment` insurance and a `fee_payments`-limited fee are off in it. Since 2.0.7 step ⑤ re-asks the
rates route (`payment_method` param, validated against the enabled gateways) for the chosen tariff once a method is picked;
`Admin_Rate_Calculator::build_package()` then puts the method under `Fee_Payments::PACKAGE_KEY`, the key the checkout uses, so
read the method with the same helpers (`resolve_insurance_for_package()`, `fee_applies_for_package()`) and the wizard needs
nothing special. For a pickup tariff the request also carries `method_id` + `pickup_point` (validated by `Admin_Rate_Calculator::refuse_pickup()`: the point is of the method's type and passes the step-⑤ acceptance check) and the calculation runs inside `Pickup_Handler::with_request_selected_point()`, so a carrier that prices by point must read it through `get_selected_point_for_method()` — the admin has no WC session, and a carrier reading the session directly quotes without the point. A price the manager typed stays theirs across every quote (`cost_typed`). A failed re-quote keeps the old price with a warning; the export (`resolve_insurance_for_order()`) always
reads the order's real method. A registry row is never
needed for a carrier that declares `declares_payment_dependent_price()`; any other instance saved through `update_option`
(the admin form, a migration) enters the `Fee_Payments` registry by itself — only a raw SQL import skips it.

## Fee by payment method (`FEATURE_FEE_PAYMENTS`)

The instance gets `fee_payments`. Three things have to hold, and `Fee_Payments` owns all of them: the chosen payment method
reaches the PACKAGE (`woocommerce_cart_shipping_packages`, key `chosen_payment_method`), so WooCommerce's per-package-hash
rate cache cannot serve a stale rate; a change of method recalculates in BOTH checkouts (classic `update_checkout` script,
block `extensionCartUpdate` through the `woodev-shipping-fee-payments` Store API callback); nothing is wired unless some
instance actually restricts a fee. «Nothing chosen yet» = a restricted fee does not apply (v1 parity). Gotchas
[payment method change recalculates nothing](../../../../docs-internal/gotchas/payment-method-change-recalculates-nothing-in-either-checkout.md),
[cart and checkout share one rate cache entry](../../../../docs-internal/gotchas/cart-and-checkout-share-one-shipping-rate-cache-entry.md).
**A carrier whose price ALWAYS depends on the payment method** (Yandex: cash-on-delivery commission is part of the quote)
has no `fee_payments` setting to switch the wiring on. Declare it on the method CLASS:
`public static function declares_payment_dependent_price(): bool { return true; }` (#1239). It is a static answer, read once in
`Shipping_Plugin::add_hooks()` — on a cold request the packages are collected before the method objects exist, so an instance
`supports` list is too late — and gives every instance of the method the payment-change recalculation in both checkouts. Do not
copy `Fee_Payments` or patch the rate cache from the plugin (Yandex's wiring regression is pinned by framework test #1244).
Offering only some gateways for a method is a different thing: filter `woodev_shipping_available_payment_gateways`
(`checkout/class-checkout-handler.php`), used by CDEK's `allowed_payments` instance option (#1143).

## City limit (`FEATURE_CITY_LIMIT`)

The instance gets a mode («Доступен только в городах» / «Недоступен в городах») and a list of cities stored as WHOLE
`Location_Record`s (so a provider switch can re-resolve by name). Decisions (#1176): no customer city yet → the method stays
available (a limit must never hide shipping for lack of data); a stored city the active provider cannot vouch for is ignored
and the form warns; whole continents expand. It needs the location layer. WooCommerce's own «Самовывоз» (`local_pickup`) gets
the same limit store-wide. Classic checkout only — the block half is #1182; a city list is not re-resolved by name on a provider
switch (#1185). Source: `location/class-city-limit.php`, `class-core-pickup-city-limit.php`.

## Additional services (`declare_services()`, #1145)

Override `declare_services()` and return `Carrier_Service` objects (code, merchant-facing name, optional parameter name and
source `declared_value`/`custom`, `selectable = false` for a service the carrier adds itself). **Declaring the list IS declaring
support.** The instance option `services` is a plain list of codes (v1 CDEK's key); parameters are computed, never stored.
Read the SAME resolver on both ends: `resolve_services_for_package( $package, $packed )` and
`resolve_services_for_order( $order, $items, $packed )`. What was quoted is what gets billed: the rate carries the snapshot
`_woodev_quoted_services` (`{"version":1,"services":[{code,name,parameter}]}` — a data contract, never renamed) onto the
order's shipping line and the export reads it first. Known limit: an order with several shipping lines of one method is read
from the FIRST line (#1199). The framework keys the resolved list into `get_rate_cache_context()`; a custom
`resolve_service_parameter()` that reads anything else must add it to the key itself. Details: "Additional carrier services" in
`docs-internal/wiki/architecture.md`.

**A refused service can fail the WHOLE quote** — measured on CDEK production: five declared services were refused for the
account and the calculator returned nothing for every tariff, so the method vanished. The carrier owns the fallback: CDEK
retries once without the refused service, remembers it for 24 h, writes an order note and an admin notice
(`Cdek_Refused_Services`, `Cdek_Service_Rules` in the plugin; s167). Encode documented incompatibilities in your own rules class.

## Instance form `show_if`

A `form_fields` entry may carry `show_if` in the settings-API shape (`[ 'setting' => <unprefixed key>, 'operator' => '='|'!='|
'in'|'not_in', 'value' => … ]`, optionally a list with `relation`); `Instance_Field_Conditions` turns it into a data attribute
a framework script evaluates. A malformed rule is dropped and the field stays visible; hiding is presentation only (the
value is still submitted). It works on the method's own page AND inside WooCommerce's zone modal (#1166, which hides the whole
`fieldset` block there, not only table rows), and a
`.wc-enhanced-select` / `.wc-product-search` field needs the framework's re-init on `wc_backbone_modal_loaded` (already wired —
gotcha [enhanced select in the method modal](../../../../docs-internal/gotchas/wc-enhanced-select-is-not-initialised-in-the-shipping-method-modal.md)).
Source: `class-instance-field-conditions.php`.

## Why a method is hidden: the admin-only hint

A method that yields no rate is invisible to everyone, including the manager who is trying to find out why. Every `rate_package()`
path that returns `null` or throws reports its reason: `Shipping_Method::report_hidden( string $reason_code, string $message )`
(#1238, #1276). The rules, all measured:

- **One helper, one reason code, two sinks.** Yandex routes every refusal through a single `hide()` in `Yandex_Rate_Method`
  (`trait-yandex-rate-method.php`) that writes the always-on log line AND calls `report_hidden()` with the SAME code, so the log and
  the hint cannot disagree. CDEK does the same from its rate path (PR #104 — the acceptance matrix found it silent, defect M1).
- **The message is the REASON only**, plain Russian for a shop manager, WITHOUT the method's name: the framework records the
  instance title (what the buyer sees) beside it and folds the methods that share a reason text into one line
  («Скрыты: «А», «Б» — reason»). Never pass a carrier exception's text.
- **Report the plugin's own reason before rethrowing a carrier failure** (`api_error`); the framework then skips its generic
  «rate calculation failed» entry. The framework's own two reasons are `license_inactive` and that failure.
- `report_hidden()` is a no-op for a visitor without `manage_woocommerce` and outside a rate calculation, so calling it costs
  nothing for a guest. The hint is drawn on the classic checkout and, as a Store API extension, on the block checkout.
- **It shows only while THAT plugin's «Логирование» (`enable_debug`) is on** (operator, 14.10.2026): non-IT merchants read the line
  as a breakage of their shop. The log line is written regardless.
- A reason you cannot attach to a decision point is not reported (Yandex's `ambiguous_geo_id`, whose method only sees `null`) —
  say so in the PR rather than inventing a code.

## Settings fields that call the carrier — and WooCommerce's zone-method modal

A method form field whose option list comes from the carrier's API (CDEK tariffs) is rendered when the merchant opens the zone-method
modal, which loads through the **REST API**, not `is_admin()` (`wc/v2|v3 …/shipping/zones/N/methods`, `wc/v4 …/shipping-zone-method`).
A guard keyed on `is_admin()` alone silently serves the cached or empty list there (edostavka PR #84). Allow the live call when
`is_admin()` OR (a REST request, `manage_woocommerce`, and one of those zone-method routes); NEVER on a Store API request (a shopper
would pay 60 s × N); cap the timeout (`get_all_tariffs_quickly( 8 )`) and remember a failure for ~120 s keyed by base URL plus a
secret fingerprint.

## A signature trap that takes the site down

A custom form-field renderer on a method (`generate_<type>_html`) keeps WooCommerce's exact signature `( $key, $data )` —
the framework's own `generate_multiselect_html()` does. A default or a type that differs from the parent is a fatal at class
DECLARATION that a mocked unit suite cannot see (a framework default added in s159 took the CDEK rig down for ~15 minutes).
Gotcha [a stricter base class fatals on signatures](../../../../docs-internal/gotchas/a-stricter-base-class-fatals-on-signatures.md),
section «s159».
