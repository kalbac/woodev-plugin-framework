# A region derived from a settlement pick must not scope the next search

> `[shipping/location]` — s204 (30.10.2026), card #1511 (edostavka#156). Measured on the rig, classic checkout,
> `region_field=show`.

## The shape

Picking a settlement writes its region into the saved chain (`backwardsFill()` fills the field, the server keeps the
region in `Location_Service::get_customer_chain()`). `scopeKeyFor()` then read that region record like a pick of the
customer's own, so EVERY later settlement search went out with `within=<region of the previous city>` — on the same
page and after a reload. The customer never chose a region; a buyer who changed their mind about the city was searching
inside the old city's region. Measured: `Подольск` picked → `Казань` searched with `within=cdek:r9` (Moscow oblast).

#1069 closed one case only: `region_field=remove` (`regionFieldRemoved`). A region field that is SHOWN but hidden by the
theme or CSS looks identical to the client, so the lock stayed. A provider that searches the whole country on a miss
(CDEK since edostavka#157) hides the symptom; the request still carries `within`.

## The rule

- ✅ A region record scopes the settlement search only when the customer picked it in the region field
  (`entry.explicitRegion`, set in `onSelectFor()` for the region level). A region from a settlement pick, the saved
  chain, `config.location.current` or the store's default locality never scopes.
- ✅ The marker is the record OBJECT, not a key: dropping the record (field edited, cleared, country change) drops the
  marker with it, and `adoptChain()` re-binds it only when the server answers with the same region key.
- ✅ The popular list keeps narrowing by the recorded region (`parentKeyFor()`): it only filters what is offered and
  never blocks a search.
- ❌ Do not fix this per provider or per field mode; do not read the DOM to decide "visible or not" — the client cannot
  know what a theme hides.
- The address level still scopes by the settlement (spec §4.4) — unchanged.

Not done (not in the card): the server's `Location_Controller::build_scope()` still trusts whatever `within` arrives; the
block checkout chooser (`src/checkout-blocks`) has its own scoping and was not measured.

## Related

- [a-derived-ancestor-is-not-the-one-the-customer-picked](a-derived-ancestor-is-not-the-one-the-customer-picked.md) — the same "remember the pick, do not derive it" principle one level down
- [dadata-collapses-region-and-settlement-into-one-key](dadata-collapses-region-and-settlement-into-one-key.md) — the region/settlement key overlap #1069 and #707 deal with
