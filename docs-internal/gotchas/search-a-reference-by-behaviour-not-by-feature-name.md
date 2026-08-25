# Search a reference implementation by what it DOES, not by what the feature is called

**Namespace:** `[tooling/*]`
**Found:** s92 (25.08.2026).

## The trap

Asked whether the CDEK reference has a "popular settlements" list, s92 grepped
`plugins-reference/woocommerce-edostavka` for `popular` / `популярн` — zero hits — and reported
twice, to the operator, that the mechanism does not exist there. Then that the thing he remembered
was the `related-list` renderer. **Both answers were wrong.**

The mechanism is `wc_edostavka_get_preloaded_data_locations()` (`includes/functions.php:223+`): a
fixed, filterable list of city codes per country. It does exactly the two jobs our own spec assigns
the popular list —

| Job | Where |
|---|---|
| Empty state (shown before the customer types) | `wc_edostavka_get_preloaded_locations()` (`:307`) → AJAX (`class-wc-edostavka-ajax.php:76`) |
| Ranking matches | `wc_edostavka_sort_cities_for_region()` (`:111`) uses the same array as `$priority_ids` |

— and it is called **preloaded**, a word no feature-name search would ever produce.

## ✅ How to be right

Grep for the **behaviour and its data shape**, not the product name:

- the consumer side: `append( '<option'`, `results:`, `priority`, `sort`, `prepend`
- the producer side: `apply_filters( '...locations'`, `get_..._list`, hardcoded id arrays
- and follow the call graph of the function you DO find, in both directions

A reference implementation names things after its own history, not after your card's title. When a
name-based search comes back empty, that is a signal to change the search, **not** evidence the
feature is absent — reporting "it does not exist there" off a name search is an inference, and this
one cost two corrections from the operator.

## Related

- [built-on-both-sides-with-no-caller-in-the-middle](built-on-both-sides-with-no-caller-in-the-middle.md)
