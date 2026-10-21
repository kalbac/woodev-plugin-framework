# Gotcha: [php/options] — `update_option()` returns `false` both on a failed write AND when the value is unchanged

> Tags: php, wordpress, options, concurrency, quota | Session: s191 (21.10.2026)

## What happens

A reserve-before-send counter (reserve a request in an option, send only if the reservation was stored) that treats `update_option() === false` as «write
failed» blocks forever the moment the new state equals the stored one (a retry, a repeated reservation). One that ignores the return value lets an
UNPERSISTED reservation through, so the request goes out uncounted. Found in `woodev-russian-post` P5, fix round 2.

## Root cause

`update_option()` compares the new value with the stored one first and returns `false` without touching the database when they are equal; it also returns
`false` when `$wpdb->update()` fails. The two cases are indistinguishable from the return value.

## Fix

❌ `if ( ! update_option( $key, $state, false ) ) { return null; }` — a repeat of the same state reads as a failure.
❌ `update_option( $key, $state, false );` and carry on — a failed write is invisible.

✅ On `false`, drop the cached copy and compare with the stored one:

```php
if ( update_option( self::OPTION, $state, false ) ) {
	return true;
}
wp_cache_delete( self::OPTION, 'options' );
return get_option( self::OPTION, null ) === $state;
```

For a counter several requests change, do not read-modify-write through `update_option()` at all: use one non-autoloaded row per counter changed by
`UPDATE … WHERE option_value = <what was read>` (compare-and-swap), failing closed — see the plugin-author skill
`.ai/skills/woodev-plugin-core/traps.md` → «Reserve before send».

## Related

- [wp-add-option-is-not-an-atomic-lock](wp-add-option-is-not-an-atomic-lock.md) — the sibling: `add_option()` is no claim either
- [get-option-turns-a-failed-select-into-the-default-and-caches-it](get-option-turns-a-failed-select-into-the-default-and-caches-it.md) — why the read-back must bypass the cache
