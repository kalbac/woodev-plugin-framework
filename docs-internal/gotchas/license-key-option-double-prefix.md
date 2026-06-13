# License-key option double-prefix for plugin ids starting with `woodev`

**Namespace:** `licensing`
**Discovered:** session 11 (2026-06-13), local e2e stand with id `woodev-stand`

## The trap

Two code paths compute the license-key option name differently:

- `Woodev_Plugin::get_plugin_option_name('license_key')` →
  `sprintf( 'woodev_%s_license_key', get_id_underscored() )` — **always** prepends `woodev_`.
- `Woodev_License::__construct($id)` (the store, read path) → normalizes
  `$id` to underscores and prepends `woodev_` **only if it doesn't already start with `woodev_`**.

For a normal plugin id (`cdek`, `edostavka`, `yandex` …) both yield
`woodev_cdek_license_key` — they agree. But for an id that **already starts with
`woodev`** (e.g. the test id `woodev-stand` → underscored `woodev_stand`):

- write (activate): `woodev_woodev_stand_license_key` (double prefix)
- read (`Woodev_License`): `woodev_stand_license_key` (single prefix)

→ the key is written to one option and read from another. `get_state()` showed
the key (in-memory) but a fresh `Woodev_License` / the scheduled cron check saw
it empty.

## Impact / rule

**No production impact** — real Woodev plugin ids never start with `woodev`. But:
- **Never give a plugin/test-fixture an id starting with `woodev`.** (The s11 stand
  was renamed `woodev-stand` → `cdek-stand` to mirror a real plugin and make all
  key-option paths agree.)
- If the framework ever needs to support such ids, reconcile `Woodev_License`'s
  conditional prefix with `get_plugin_option_name()`'s unconditional one — but
  that changes a **stored option-key data contract**, so it is release-blocking
  to change on installed sites; leave it unless forced.

## Related
- [[license-need-vs-required]] — other licensing option/flag subtleties
