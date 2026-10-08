# gotcha: the `woodev-modal` script and style handles are registered on the STOREFRONT hook only

**Namespace:** `[admin-ui/modal]`
**Discovered:** s163 (09.10.2026), #1180 — reading `class-plugin.php`, before the order metabox needed a dialog

## Symptom

Declaring `woodev-modal` as a script dependency of an admin script looks right — the pickup map does exactly that on
the storefront — and does nothing in wp-admin: WordPress drops an enqueue whose dependency is not registered, so the
script never loads and `window.WoodevModal` is undefined. Nothing errors.

## Root cause

`Woodev_Plugin::frontend_enqueue_scripts()` registers both handles (`woodev-modal` script and style), and that method
runs on `wp_enqueue_scripts`, which wp-admin never fires.

## Fix

An admin surface that wants the shell registers it itself, once, if nobody has: `Orders_Registry::enqueue_modal_shell()`
(`wp_script_is` / `wp_style_is` guard, same URLs and versions as the storefront registration) is called from
`enqueue_metabox_script()`, and the metabox script lists `woodev-modal` as its dependency. The next admin consumer
should reuse that method rather than register a third copy.

## Related

- [modal-backdrop-opacity-dims-the-whole-dialog](modal-backdrop-opacity-dims-the-whole-dialog.md) — the shell's other trap
- `docs-internal/wiki/architecture.md` — "Shipping orders page" → an order action may declare input fields
