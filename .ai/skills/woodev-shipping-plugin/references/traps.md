# Shipping plugin traps

Each detail link records the failure and the correct seam. Check the relevant entry before changing that subsystem.

- A method class that does not extend `Shipping_Method` is filtered out; verify the contract in `Shipping_Plugin::get_valid_shipping_method_classes()` (`woodev/shipping-method/class-shipping-plugin.php`).
- Do not calculate a multi-parcel price by summing parcel rates in the shared rate seam: [shipping rate is not a parcel sum](../../../../docs-internal/gotchas/shipping-rate-no-parcel-sum.md).
- Keep session keys and order-meta prefixes as separate contracts, and provide both explicitly: [session key vs order-meta prefix](../../../../docs-internal/gotchas/session-key-vs-order-meta-prefix.md); [contract strings are not derivable](../../../../docs-internal/gotchas/contract-string-not-derivable.md).
- A negative meta query OR-ed across carrier providers can match every order; bind it to that provider's positive marker: [negative meta clause across providers](../../../../docs-internal/gotchas/a-negative-meta-clause-or-ed-across-providers-matches-every-order.md).
- A shipping orders filter relies on two unenforced carrier invariants; one marker and one carrier-owned status write are not guaranteed. The current `edostavka` carrier already violates the one-marker assumption, so do not treat it as framework-enforced: [orders filter assumptions](../../../../docs-internal/gotchas/the-orders-filter-stands-on-two-unenforced-carrier-invariants.md).
- Never hide a required checkout field only in JavaScript: server validation can still reject an invisible field: [JS-hidden field remains required](../../../../docs-internal/gotchas/js-hidden-checkout-field-is-still-required-server-side.md).
- Block checkout reads country locale, not `woocommerce_checkout_fields`; it also uses Store API requests rather than classic checkout hooks: [block checkout field seam](../../../../docs-internal/gotchas/block-checkout-reads-country-locale-not-checkout-fields.md); [block checkout is a REST request](../../../../docs-internal/gotchas/the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md).
- Never use a locality display name as a key; it changes by locale: [locality name is not an identifier](../../../../docs-internal/gotchas/a-locality-display-name-is-not-an-identifier.md).
- Scope settlement lists by region; a name suggestion can return same-name settlements from another region: [scope list by region](../../../../docs-internal/gotchas/list-by-region-scope-not-suggest-by-name.md).
- DaData can collapse region and settlement onto one key; use `Location_Record::is_within()` rather than raw ancestor assumptions: [DaData city identity](../../../../docs-internal/gotchas/dadata-collapses-region-and-settlement-into-one-key.md).
- WooCommerce uppercases posted state values and flips its map; human labels used as state keys are mangled: [posted state normalization](../../../../docs-internal/gotchas/wc-uppercases-the-posted-state-and-flips-the-map.md). `get_states()` can also return `false`, and casting it to array is still non-empty: [false states cast](../../../../docs-internal/gotchas/array-cast-of-get-states-false-is-not-empty.md).
- A `Pickup_Handler` without its owning plugin can silently address points by DOM place name: [pickup handler needs its plugin](../../../../docs-internal/gotchas/a-pickup-handler-built-without-its-plugin-silently-addresses-by-name.md).
- A pickup handler without `Selection_Scope` has no Store API transport in block checkout: [pickup selection scope](../../../../docs-internal/gotchas/a-pickup-handler-without-a-selection-scope-has-no-store-api-transport.md).
- Managed custom checkout field values do not repopulate after reload by design; persist the data through its owner: [custom checkout field reload](../../../../docs-internal/gotchas/custom-checkout-field-is-empty-on-reload-by-construction.md).
- A per-plugin static once-per-request flag can let only the first carrier through: [multi-plugin request gate](../../../../docs-internal/gotchas/a-process-static-once-per-request-gate-checks-only-the-first-plugin.md).
- A warehouse's storage row ID is not its carrier-unique ID: [warehouse identity](../../../../docs-internal/gotchas/warehouse-storage-id-vs-carrier-id.md).
- Measure cached rates against every input to the quote and opt in only when context is complete; see `Shipping_Method::get_rate_cache_context()` and `Shipping_Rate_Cache`.
- Treat box-packer inputs and axis identities carefully: [box-packer gotcha index](../../../../docs-internal/gotcha-index/box-packer.md). The `boxes` mode and the virtual box now place items for real; do not re-derive "does it fit" from summed volume ([packaging](packaging.md)).
- A raw JSON byte count is not a wire cost; measure gzip on the real response before adding pickup/locality payload: [compressed payload measurement](../../../../docs-internal/gotchas/a-raw-payload-size-is-not-a-wire-cost-measure-it-after-gzip.md).

## Added after the CDEK v2 rewrite (s156–s169)

### Orders and data

- `wc_get_orders()` silently drops `meta_query` on the posts store and returns EVERY order; route richer lookups through a datastore-aware helper and measure on both storages — rule and recipe in [orders and tracking](orders-tracking.md#finding-orders--wc_get_orders-drops-meta_query-on-the-posts-store); gotcha [wc_get_orders drops meta_query](../../../../docs-internal/gotchas/wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore.md); card edostavka#46.
- `NOT IN` in a meta query drops rows with no such meta: [NOT IN gotcha](../../../../docs-internal/gotchas/a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all.md).
- A status set from the carrier's "cancelled" news must not re-enter `Order_Automation`: [cancel loop gotcha](../../../../docs-internal/gotchas/a-status-change-made-for-the-carrier-s-news-must-not-reach-order-automation.md).
- A webhook body is a hint, never the truth; acknowledge every authentic event with 200: [orders and tracking](orders-tracking.md#delivery-statuses-and-tracking).
- A failed `get_option()` reads as "absent" and is cached so; a one-time migration must use a verified reader: [get_option gotcha](../../../../docs-internal/gotchas/get-option-turns-a-failed-select-into-the-default-and-caches-it.md).
- Document "not ready yet" is `pending`, never a failure; a print task for an order the carrier has not accepted is not an error (edostavka#8).

### Plugin identity and wiring

- `get_plugin_name()` runs before `init`: return a plain, untranslated string: [get_plugin_name gotcha](../../../../docs-internal/gotchas/get-plugin-name-runs-before-init-so-a-translated-name-warns.md).
- The plugin name feeds the HTTP `User-Agent`; a non-ASCII name got HTTP 400 from CDEK on every call except the token endpoint, with all unit tests green. The framework now falls back to the plugin id when the name is not an RFC 9110 token (`Woodev_API_Base::get_user_agent_product()`); a test double of your plugin must answer `get_id_dasherized()`: [User-Agent gotcha](../../../../docs-internal/gotchas/a-non-ascii-plugin-name-in-the-user-agent-gets-http-400-from-cdek.md).
- Repointing a v1 class at a stricter v2 base fatals at DECLARATION on signatures, and a mocked suite stays green: run `npm run probe:signature` — [stricter base class gotcha](../../../../docs-internal/gotchas/a-stricter-base-class-fatals-on-signatures.md).
- A mocked provider or an invented fixture proves the mock, not the carrier: build fixtures from MEASURED carrier responses (CDEK: 53 contract fixtures, 31 more for intakes) — gotchas [a mocked provider proves the mock](../../../../docs-internal/gotchas/a-mocked-provider-proves-the-mock-not-the-contract.md), [an invented fixture tests your assumptions](../../../../docs-internal/gotchas/an-invented-fixture-tests-your-assumptions-not-the-carrier.md).
- Milestone notices are silent without a reviews URL: [orders and tracking](orders-tracking.md#milestones), card #1217.

### Rates, cache and checkout

- Cart and checkout share ONE cached, already-filtered rate set per package hash; partition the package by context before filtering by page: [shared rate cache gotcha](../../../../docs-internal/gotchas/cart-and-checkout-share-one-shipping-rate-cache-entry.md).
- A payment-dependent rate needs the payment method in the package AND a trigger in both checkouts: [payment method gotcha](../../../../docs-internal/gotchas/payment-method-change-recalculates-nothing-in-either-checkout.md).
- Percentage box cost needs per-line values in cache identity; carrier presets are fixed cm/kg: [packaging](packaging.md).
- A carrier-side refused service fails the whole quote and the method vanishes; plan the one-retry fallback: [method features](method-features.md#additional-services-declare_services-1145).
- A stored location field mode outlives the chooser, and a guard keyed on it can block the whole checkout when no DaData token is set: [stored field mode gotcha](../../../../docs-internal/gotchas/a-stored-field-mode-outlives-the-chooser-and-a-guard-keyed-on-it-blocks-checkout.md).
- DaData has no 402: an exhausted balance, an exhausted daily limit, an unconfirmed e-mail and a bad key are all 403: [DaData gotcha](../../../../docs-internal/gotchas/dadata-has-no-402-an-exhausted-balance-is-a-403.md).
- Under a non-Russian locale a DaData record is transliterated and matches no Cyrillic carrier dictionary: [English-locale gotcha](../../../../docs-internal/gotchas/an-english-locale-record-cannot-be-matched-against-a-cyrillic-dictionary.md).
- A carrier-owned location provider must throw on failure, never return an empty answer, and must keep a region list under the 1 MB cache-item limit: [model, rates, location, pickup](model-rates-location-pickup.md#location).

### A second carrier plugin on the same site

Several carriers on one shop is the ordinary production arrangement, and a green unit suite cannot see most of what follows.

- A fixture or a plugin that is driven only by PHPUnit never registers itself; a no-op license handler makes `load_updater()` fatal every admin/cron/CLI request; **two carriers must not share a checkout FIELD id** — the injections collapse into one and the other carrier's pickup button silently never renders: [standing up a second carrier plugin](../../../../docs-internal/gotchas/standing-up-a-second-carrier-plugin-has-three-traps-a-green-unit-suite-cannot-see.md).
- Carriers that ask the location layer for the SAME level on a native field share ONE cascade (classic) and one fleet-wide chooser (blocks) — the supported multi-carrier case (#1179, #1187). `Checkout_Handler::guard_native_field_conflicts()` reports only two DIRECT declarations, a direct against a location field, or two different levels on one id; it must stay silent for the rest, because its notice printed before headers on every page with `WP_DEBUG` display on and broke `wp-login.php` (s163, PR #1188). The neighbouring cross-provider guard has its own trap: [an empty level owner disarms it](../../../../docs-internal/gotchas/an-empty-level-owner-silently-disarms-the-cross-provider-guard.md).
- Each carrier needs its OWN marker meta key with a non-empty scalar value and its own status meta; the orders filter assumes one marker per order and a carrier writing only its own status meta ([orders filter assumptions](../../../../docs-internal/gotchas/the-orders-filter-stands-on-two-unenforced-carrier-invariants.md)); a negative clause OR-ed across carriers matches every order.
- A process-static once-per-request gate checks only the first plugin ([multi-plugin gate](../../../../docs-internal/gotchas/a-process-static-once-per-request-gate-checks-only-the-first-plugin.md)).
- The framework copy with the highest `framework_version` wins; its `backwards_compatible` becomes the floor and any plugin below it is silently dropped — "active", registers nothing, no notice: [loader floor gotcha](../../../../docs-internal/gotchas/a-fixture-plugin-below-the-winners-backwards-compatible-floor-is-silently-dropped.md). Each plugin bundles its own `woodev/`; on the rig sync it before measuring: [acceptance on the rig](acceptance-rig.md).
- A competitor's plugin for the same carrier on the same site is a conflict to detect and warn about (edostavka#53, open).
