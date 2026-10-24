# gotcha: the shared rig mounts the plugin repos — an overwrite install there deletes a git checkout

**Namespace:** `[rig/plugin]`
**Discovered:** s196 (2026-10-24), live acceptance of #1438

## Symptom

#1438 needed a live click on «Обновить», which installs a store ZIP with `overwrite_package` over
`wp-content/plugins/woodev-russian-post`. On the shared rig (`:8973`) that directory is not a copy.

## Root cause

`.wp-env.override.json` → `env.development.mappings` maps `wp-content/plugins/woocommerce-edostavka`,
`woocommerce-yandex-delivery` and `woodev-russian-post` to the sibling git checkouts (`../woocommerce-edostavka` …). WordPress's
upgrader deletes the destination folder before unpacking, so an overwrite install on the shared rig would wipe the checkout
(and a bind-mounted folder also makes `remove_old` fail, which is how the disposable rig first broke).

## ❌ Wrong

Test install/update/overwrite flows (Woodev → Плагины, WP's own updater) on the shared rig.

## ✅ Correct

A disposable `wp-env` in the scratchpad on other ports (e.g. 8983/8984) with plugin directories COPIED in
(`git archive <ref>` extracted), the licence mu-plugin copied out read-only, then `wp-env destroy`. s196's brief for it:
`sessions/s196.md` → «Disposable rig». A woodev.ru account token copied from the shared rig answers 403 for the package on
another site URL — use a local package mock for the install step and state that the real download is unproven.

## Related

- [the-rig-runs-the-cdek-plugins-own-woodev-copy-not-the-framework-checkout](the-rig-runs-the-cdek-plugins-own-woodev-copy-not-the-framework-checkout.md)
