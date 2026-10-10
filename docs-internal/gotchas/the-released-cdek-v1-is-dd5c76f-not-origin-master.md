# Gotcha: [rig/plugin] — The released CDEK v1 (2.2.5.5) is commit `dd5c76f`, NOT `origin/master`
> Tags: rig/plugin, migration, cdek | Session: s167

## What happens

A migration test "from the version shops run" built from `origin/master` of `woocommerce-edostavka` installs
**2.3.2** — a refactor line that was never released. Nothing above 2.2.5.5 is installed anywhere (CURRENT-STATE,
settled 06.09.2026), so every conclusion drawn from that build is about data no real shop holds.

Two more traps of the real 2.2.5.5, found while building the migration stand (edostavka#41, s167):

- it has **no CDEK test mode** — `api.cdek.ru` is hard-coded, so a stand must redirect its HTTP calls to
  `api.edu.cdek.ru` (the stand's mu-plugin, `tests/migration-stand/` in the plugin repo, fails closed);
- its `related` city mode posts no nonce on the classic checkout (403, the city is never stored), its method-form
  JS needs a global `sprintf`, and it wants a woodev.ru licence answer.

## Root cause

The v1 history continued on `master` after the last release (`dd5c76f` "initial state before AI-assisted
refactoring", 07.05.2026, Version 2.2.5.5; `efce9f6` already says 2.3.0).

## Fix

❌ `git show origin/master:…` / a checkout of `master` as "v1".

✅ `git -C <plugin> worktree add --detach <dir> dd5c76f` — and check the header:
`git show <ref>:woocommerce-edostavka.php | grep Version`.

## Related

- [standing-up-a-second-carrier-plugin-has-three-traps-a-green-unit-suite-cannot-see](standing-up-a-second-carrier-plugin-has-three-traps-a-green-unit-suite-cannot-see.md)
- `docs-internal/sessions/s167.md`
