# Gotcha index — [woocommerce/*] WooCommerce-specific

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

## [woocommerce/*] — WooCommerce-specific

- [woocommerce/shipping-api-broken-contract] **Shipping_API interface references types that don't exist in the framework.** → [shipping-api-broken-contract](../gotchas/shipping-api-broken-contract.md)
- [woocommerce/shipping] **`add_rate()` discards `description`/`delivery_time` silently, though `WC_Shipping_Rate` has had both since WC 9.2.0 — set them on the object AFTERWARDS.** → [wc-add-rate-ignores-the-rate-description-and-delivery-time](../gotchas/wc-add-rate-ignores-the-rate-description-and-delivery-time.md) (s117)
- [woocommerce/shipping] **Never stringify a numeric cost: `(string) 1.0e20` is `'1.0E+20'`, and `wc_format_decimal()`'s string branch strips the `E` and returns **1.02**. Pass the number through.** → [stringifying-a-float-cost-lets-wc-format-decimal-destroy-it](../gotchas/stringifying-a-float-cost-lets-wc-format-decimal-destroy-it.md) (s117)

## [woocommerce/*] — WooCommerce-specific (session)

- [woocommerce/segmented-selection] **WooCommerce draws that grid's divider by CHILD PARITY (`nth-child(2n)`), so ONE full-width cell inside the container shifts every following item and the borders land on the wrong sides. Geometry stays correct.** → [a-wide-cell-breaks-woocommerce-s-nth-child-grid-borders](../gotchas/a-wide-cell-breaks-woocommerce-s-nth-child-grid-borders.md) (s132)
- [woocommerce/date] **`wc.date` THROWS rather than degrades — `custom` with one date, or an unknown period — and the throw unmounts the whole wc-admin app. «All time» must be the ABSENCE of the parameter.** → [wc-date-throws-on-a-half-filled-custom-range](../gotchas/wc-date-throws-on-a-half-filled-custom-range.md) (s132)
- [woocommerce/menu] **`wc_admin_register_page()` never reads the `order` key its docblock advertises, and neighbouring entries pass NO `position` — place by the neighbour's SLUG.** → [wc-admin-register-page-ignores-order-and-its-neighbours-declare-no-position](../gotchas/wc-admin-register-page-ignores-order-and-its-neighbours-declare-no-position.md) (s132)
- [woocommerce/navigation] **`wc-admin` REWRITES `document.title` after it mounts, so a DOM read says the markup never leaked while the response body shows it did — ask the response, not the settled DOM, about anything the server rendered.** → [a-dom-read-cannot-answer-a-question-about-server-rendered-markup](../gotchas/a-dom-read-cannot-answer-a-question-about-server-rendered-markup.md) (s130)
- [woocommerce/navigation] **A sidebar link's `href` carries no query, and `wc-admin` re-adds the date params anyway (`getPersistedQuery()`) — so a probe using a full page load proves the OPPOSITE of what a click does.** → [wc-admin-re-adds-the-date-params-a-menu-link-does-not-carry](../gotchas/wc-admin-re-adds-the-date-params-a-menu-link-does-not-carry.md) (s129)
- [woocommerce/navigation] **`addHistoryListener` fires BEFORE the real `pushState`, so `getQuery()` inside it reads the PREVIOUS URL and the page stays one navigation behind. Raise a flag; read the query in a later effect.** → [addhistorylistener-fires-before-the-url-changes](../gotchas/addhistorylistener-fires-before-the-url-changes.md) (s128)
- [woocommerce/filter-options] **The rule select comes from the TITLE token, but the URL key and reading the filter BACK come from `rules` — dropping the rule to hide a one-option select kills the round trip.** → [advancedfilters-renders-the-rule-select-only-where-the-title-asks-for-it](../gotchas/advancedfilters-renders-the-rule-select-only-where-the-title-asks-for-it.md) (s129)
- [woocommerce/filter-options] **An `AdvancedFilters` option keyed `key` instead of `value` makes «Filter» a DISABLED button: no URL, no request. A picked option then submits its LABEL.** → [a-filter-option-keyed-key-instead-of-value-disables-the-filter-button](../gotchas/a-filter-option-keyed-key-instead-of-value-disables-the-filter-button.md) (s128)
- [woocommerce/script-handles] **Declaring `wc-settings` as a script dependency makes WordPress drop your whole bundle SILENTLY — it is only conditionally registered.** → [declaring-wc-settings-as-a-script-dependency-silently-drops-the-bundle](../gotchas/declaring-wc-settings-as-a-script-dependency-silently-drops-the-bundle.md) (s127)
- [woocommerce/address-save] **WooCommerce saves no address until every required TEXT field in the block is filled — the gate is in the JS.** → [wc-does-not-save-the-address-until-every-required-text-field-is-filled](../gotchas/wc-does-not-save-the-address-until-every-required-text-field-is-filled.md) (s65)
- [woocommerce/session] **A guest's `WC()->session->set()` can silently not persist — a logged-in developer never sees it.** → [guest-session-write-needs-the-cart-cookie](../gotchas/guest-session-write-needs-the-cart-cookie.md) (s65)
- [framework/contracts] **An empty string from a domain seam is the domain FAILING to answer, not a key.** → [an-empty-domain-key-is-not-a-key](../gotchas/an-empty-domain-key-is-not-a-key.md) (s65)
- [woocommerce/address-autocomplete] **WC Address Autocomplete hosts ONLY address_1, flattens identity, and clears what a provider omits.** → [wc-address-autocomplete-hosts-only-address1-and-flattens-identity](../gotchas/wc-address-autocomplete-hosts-only-address1-and-flattens-identity.md) (s67)
- [woocommerce/address-autocomplete] **Wrapping `window.wc.addressAutocomplete.providers` touches a namespace, not a contract — and two implementation traps along the way.** → [wc-address-autocomplete-registry-wrap-is-not-a-documented-contract](../gotchas/wc-address-autocomplete-registry-wrap-is-not-a-documented-contract.md) (s69)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
