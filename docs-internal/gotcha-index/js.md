# Gotcha index — [js/*] JavaScript language traps

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [js/wc-globals] **A hand-written `.d.ts` for `window.wc.*` is a claim about someone else's bundle, not a check of it: `typecheck` stays green while the page dies and takes all of wc-admin with it. Measure the shape in a browser first.** → [a-hand-written-d-ts-for-a-runtime-global-is-an-assertion-not-a-check](../gotchas/a-hand-written-d-ts-for-a-runtime-global-is-an-assertion-not-a-check.md) (s127)
- [js/select-value-space] **A select2 `language` callback returning `undefined` renders a BLANK message: the merge is `$.extend({}, EN, ours)`, so defining the key shadows English forever. OMIT it instead.** → [a-select2-language-callback-that-returns-undefined-renders-blank](../gotchas/a-select2-language-callback-that-returns-undefined-renders-blank.md) (s93)
- [js/select-value-space] **Painting early in a select2 ajax transport costs both honest states: an empty list reads as «не найдено» (`noResults` only asks "is it empty?"), and ANY `success()` strips the loading row (`append()` starts with `hideLoading()`).** → [an-empty-list-while-the-search-runs-is-not-a-zero-result](../gotchas/an-empty-list-while-the-search-runs-is-not-a-zero-result.md) (s94)
- [js/select-value-space] **`select2:close` fires BEFORE `select2:select` — a guard that expects the pick to cancel the close cannot work, and a fake dispatching the other order pins a fiction.** → [select2-close-fires-before-select2-select](../gotchas/select2-close-fires-before-select2-select.md) (s92)
- [js/select-value-space] **A `<select>` is not an `<input>`: an unmatched `.value` write submits nothing, a bare `selectedIndex` leaves select2 stale, and select2 caches the option's data ON the node — so the first write works and every later one lies.** → [a-select-value-write-with-no-matching-option-submits-nothing](../gotchas/a-select-value-write-with-no-matching-option-submits-nothing.md) (s86)
- [js/jquery-event-worlds] **A jQuery `.trigger( 'change' )` fires no native event — and that is how select2 reports a pick.** → [jquery-trigger-change-fires-no-native-event](../gotchas/jquery-trigger-change-fires-no-native-event.md) (s66)
- [js/object-as-map] **A plain object is not an insertion-ordered map, and not a safe one.** → [plain-object-is-not-an-insertion-ordered-map](../gotchas/plain-object-is-not-an-insertion-ordered-map.md) (s59)
- [testing/global-stub-collision] **A `class_exists`-guarded global test stub is won by whichever file loads first — one file returning `get_id() === 0` silently broke an untouched test in another directory.** → [a-class-exists-guarded-test-stub-is-won-by-whoever-loads-first](../gotchas/a-class-exists-guarded-test-stub-is-won-by-whoever-loads-first.md) (s84)
- [testing/result-cache] **`executionOrder="depends,defects"` + a stale `.phpunit.result.cache` makes the same tree report 45 errors on one run and 2 failures on the next.** → [phpunit-result-cache-makes-a-run-unreproducible](../gotchas/phpunit-result-cache-makes-a-run-unreproducible.md) (s84)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
