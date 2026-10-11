# Gotcha: [rig/acceptance] — the licence updater's `woodev_<md5>` cache options change by themselves during a run
> Tags: rig, acceptance, cdek, licensing | Session: s168

## What happens

The CDEK acceptance run (plugin repo, `tests/acceptance/e2e/`) failed ONE row — «cleanup restores the rig» — with
`rig differs from the start: option woodev_a8a51e…: changed; option woodev_ef0e6d…: changed; option woodev_fbd607…:
changed`. Nothing in the plugin code had touched them. A re-run minutes later was 105 / 12 / 0.

## Root cause

Those options are the version-info cache of the framework's licence updater:
`woodev/licensing/updater/class-plugin-updater.php:758` names them `'woodev_' . md5( serialize( … ) )` and stores
`{ timeout, value }`. When the TTL runs out during the ~7-minute run, any admin page load refreshes them. The rig
snapshot's `VOLATILE_OPTION` list (`tests/acceptance/e2e/cdek-acceptance.spec.js:59`) does not know them.

## Fix

❌ Reading the FAIL as a plugin regression, or widening `VOLATILE_OPTION` to every `woodev_*` option (that hides
real plugin options).

✅ Re-run once to confirm (the fresh cache lives for hours); the permanent fix — skip only `woodev_` + 32 hex whose
value is a `{timeout, value}` cache — is card kalbac/woocommerce-edostavka#55.

## Related

- [the-rig-runs-the-cdek-plugins-own-woodev-copy-not-the-framework-checkout](the-rig-runs-the-cdek-plugins-own-woodev-copy-not-the-framework-checkout.md)
- `docs-internal/sessions/s168.md`
