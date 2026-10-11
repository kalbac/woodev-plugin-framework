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

## Fee by payment method (`FEATURE_FEE_PAYMENTS`)

The instance gets `fee_payments`. Three things have to hold, and `Fee_Payments` owns all of them: the chosen payment method
reaches the PACKAGE (`woocommerce_cart_shipping_packages`, key `chosen_payment_method`), so WooCommerce's per-package-hash
rate cache cannot serve a stale rate; a change of method recalculates in BOTH checkouts (classic `update_checkout` script,
block `extensionCartUpdate` through the `woodev-shipping-fee-payments` Store API callback); nothing is wired unless some
instance actually restricts a fee. «Nothing chosen yet» = a restricted fee does not apply (v1 parity). Gotchas
[payment method change recalculates nothing](../../../../docs-internal/gotchas/payment-method-change-recalculates-nothing-in-either-checkout.md),
[cart and checkout share one rate cache entry](../../../../docs-internal/gotchas/cart-and-checkout-share-one-shipping-rate-cache-entry.md).
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
value is still submitted). It works on the method's own page; inside WooCommerce's zone modal it does not yet (#1166), and a
`.wc-enhanced-select` / `.wc-product-search` field needs the framework's re-init on `wc_backbone_modal_loaded` (already wired —
gotcha [enhanced select in the method modal](../../../../docs-internal/gotchas/wc-enhanced-select-is-not-initialised-in-the-shipping-method-modal.md)).
Source: `class-instance-field-conditions.php`.

## A signature trap that takes the site down

A custom form-field renderer on a method (`generate_<type>_html`) keeps WooCommerce's exact signature `( $key, $data )` —
the framework's own `generate_multiselect_html()` does. A default or a type that differs from the parent is a fatal at class
DECLARATION that a mocked unit suite cannot see (a framework default added in s159 took the CDEK rig down for ~15 minutes).
Gotcha [a stricter base class fatals on signatures](../../../../docs-internal/gotchas/a-stricter-base-class-fatals-on-signatures.md),
section «s159».
