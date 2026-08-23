# The three location field modes, and the Russian labels that do not map the way you would guess

**Namespace:** `[shipping/location]` · **Discovered:** s87 (2026-08-24), after sending the operator
to verify the wrong mode

## The mapping — the only authority is `class-location-provider-registry.php:1005-1007`

| Mode id | Label in the settings UI | What it is |
|---|---|---|
| `typeahead` | **«Текст с подсказками»** | a plain input; options appear as you type |
| `related-list` | **«Предустановленный список»** | the whole list loaded once, searched in the browser |
| `ajax-select2` | **«Список с поиском»** | a dropdown that queries the provider on each character |

## The trap

**«Список с поиском» is `ajax-select2`, not `related-list`.** Both labels contain a word that sounds
like "list", and the mode ids read the other way round to a Russian ear: `related-list` is the one
that is *not* «Список с поиском».

In s87 I wrote «Связанный список» in a card and in a PR comment — a label that **does not exist in
the UI at all** — and sent the operator to verify a mode he already had selected. He came back with
"we already have «Список с поиском» on both axes, what am I supposed to do?", which was correct.

Issue titles are a second source of the same confusion: **#447, #449 and #450 are all titled
«Список с поиском» and are all about `ajax-select2`.**

## Which modes each axis actually offers

Not symmetric, and both gates are read-side
(`Location_Provider_Registry::offered_field_modes_for()`):

- `typeahead` — always, both axes.
- `ajax-select2` — always, both axes (unconditional since #380; it no longer needs `CAPABILITY_LIST`).
- `related-list` — needs the active provider to declare `CAPABILITY_LIST`, **and** for the SETTLEMENT
  axis additionally requires the REGION axis to already be on `related-list` (the #404 cross-axis
  condition).

Consequences worth knowing before you go to the rig:

- **DaData can never offer `related-list`.** The CDEK fixture's own docblock says why: "This is the
  capability DaData structurally cannot have (a query-driven API cannot enumerate)". With `dadata`
  active, «Предустановленный список» is simply absent from both selects — not broken, absent.
- To exercise the settlement `related-list` branch you must set the provider to `test-cdek`, then the
  region axis, and only then does the settlement axis offer it.

## And it is being deleted

`specs/2026-08-21-settlement-search-design.md`, decision 1: **"The settlement axis is never a flat
preset list. Removed as a mode."** `list_localities()` for settlements and `LIST_HARD_CAP` are
deleted rather than tuned (#437). So `related-list` survives only on the REGION axis. Anything you
fix in `attachRelatedListSettlement()` is fixing code with a delete date — #478 was closed for
exactly that reason.

## Related

- [[a-level-served-can-come-from-the-fallback-not-the-active-provider]] — the other place where
  "which provider answers this" is not what the settings screen suggests.
- `../specs/2026-08-21-settlement-search-design.md` — why the settlement axis loses the preset list.
