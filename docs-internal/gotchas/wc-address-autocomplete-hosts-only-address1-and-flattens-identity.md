# WC Address Autocomplete hosts ONLY address_1, flattens identity, and clears what a provider omits

**Session:** s67 (12.08.2026) · **Context:** locality/provider brainstorm (#273/#127/#159), verified
against `woocommerce/woocommerce` trunk sources, not the docs alone.

## The trap

WooCommerce's Address Autocomplete Provider API (since 9.9.0) *sounds* like a ready-made base for a
region→city→address cascade. It is not, on four measured counts:

1. **The search UI attaches ONLY to `${type}_address_1`** (`client/legacy/js/frontend/address-autocomplete.js`
   caches `city/state/postcode/country` inputs purely as WRITE targets). There is no per-field
   suggest, no region→city scoping, and no mode for a checkout without an address field.
2. **`select( id )` must return flat strings** `{ address_1, address_2, city, state, postcode, country }` —
   any provider identity (ФИАС, `city_code`, `geo_id`) is destroyed by the contract itself.
3. **Fields the provider does NOT return are actively CLEARED** (the `else` branches around
   `address-autocomplete.js:762-809`), so a foreign system owning the same fields will fight it.
4. **Search context is `country` only** (`canSearch( country )` / `search( query, country, type )`);
   there is nothing to constrain suggestions by region or city.

`supportedCountries` seen in their docs is NOT an API property — it is an example array inside the
sample `canSearch()`.

## What IS reusable

- Activation is double-gated server-side: option `woocommerce_address_autocomplete_enabled` AND a
  non-empty `woocommerce_address_providers` filter result (`class-wc-frontend-scripts.php:507-518`,
  same `AddressProviderController` feeds the block checkout). Returning `[]` from the filter at
  late priority is the documented full kill — scripts are not even enqueued.
- Per-country arbitration is client-side and live: on every country change WC walks
  `wc.addressAutocomplete.serverProviders` in server-preference order (option
  `woocommerce_address_autocomplete_provider`) and activates the first provider whose
  `canSearch( country )` is true; none → its UI stands down. Registered provider objects are
  frozen, but the registry SLOT (`wc.addressAutocomplete.providers[id]`) can be replaced with a
  delegating clone — the timing-safe lever for per-country suppression.
- The progressive-enhancement shape (native input kept; combobox role + adjacent listbox added,
  removable cleanly) is the right pattern to copy for our own typeahead fields.

## Rule

Treat WC Address Autocomplete as an address_1-only, string-only facade to COEXIST with per country
— never as machinery to host a locality cascade or carry a carrier identity. Our layer's design
decisions over it are D1/D2 in the location-provider spec.

## Related

- [[../specs/2026-08-12-location-provider-design.md]] — D1 (own contract), D2 (per-country arbitration)
- [[checkout-field-takeover-woocommerce-states.md]] — the region field's existing takeover seam
- [[wc-does-not-save-the-address-until-every-required-text-field-is-filled.md]] — why selection is persisted by our own AJAX, not WC's serialization
