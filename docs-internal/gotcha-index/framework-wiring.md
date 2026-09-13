# Gotcha index — [framework/wiring] Responsibilities that moved

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [framework/wiring] **A hook registered as `[ $this, … ]` from a class every plugin builds fires ONCE PER PLUGIN — WordPress keys it by `spl_object_hash()`; a static callback collapses every registration into one.** → [a-hook-registered-from-a-per-plugin-object-fires-once-per-plugin](../gotchas/a-hook-registered-from-a-per-plugin-object-fires-once-per-plugin.md) (s114)
- [framework/wiring] **A module writing into another module's field must ANNOUNCE the write — otherwise the owner reads it as the user's.** → [a-module-that-writes-into-another-modules-field-must-announce-it](../gotchas/a-module-that-writes-into-another-modules-field-must-announce-it.md) (s75)
- [framework/wiring] **An action fired beside a filter must carry the filter's RESULT, not its input.** → [an-action-beside-a-filter-must-carry-the-filters-result](../gotchas/an-action-beside-a-filter-must-carry-the-filters-result.md) (s65)
- [framework/wiring] **A feature built on both sides, with nothing calling it in the middle.** → [built-on-both-sides-with-no-caller-in-the-middle](../gotchas/built-on-both-sides-with-no-caller-in-the-middle.md) (s56, extended s59)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
