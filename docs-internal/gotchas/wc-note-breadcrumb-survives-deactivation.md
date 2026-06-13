# A remotely-deactivated single-v2-plugin can only surface a notice via WC Admin Notes — created AFTER `handle_deactivation`

> Namespace: `[licensing/remote-deactivation]` · Discovered s12 (2026-06-13)

## Why the `admin_notices` banner is not enough

The framework is **vendored inside each plugin**. When a plugin is deactivated, WP
never includes its `woodev/bootstrap.php` again → **no framework code loads at all**
on subsequent requests. The remote-deactivation banner
(`woodev_license_remote_deactivation_notices` → rendered in
`Woodev_Plugins_License::notices()`) is therefore only drawable by an **active**
sibling v2 plugin. On a site whose only v2 plugin is the one just deactivated, the
banner never shows. "Render from the bootstrap" does not help — the bootstrap also
needs ≥1 active plugin, and every active plugin already constructs a license engine.

## The fix that works: hand the notice to WooCommerce

WooCommerce renders **WC Admin inbox notes** from its own `wp_wc_admin_notes` table,
**independent of the source plugin's active state**. WC is always active for these
plugins, so a note written at deactivation time survives the plugin going dark.
`Woodev_License_Command_Deactivate_Plugin::execute()` writes it via
`Woodev_Notes_Helper::add_note()`.

## The critical ordering gotcha

`Woodev_Lifecycle::handle_deactivation()` is hooked on `deactivate_{plugin_file}` and
**bulk-deletes all WC notes whose `source` is the plugin's `get_id_dasherized()`**.
The remote command calls `deactivate_plugins( $file, false, false )` (non-silent),
which fires that hook **synchronously**. So the breadcrumb note MUST be created
**after** `deactivate_plugins()` returns — otherwise `handle_deactivation` wipes it.
The command does exactly this: `deactivate_plugins()` → `write_notice()` (option) →
`maybe_add_breadcrumb_note()` (WC note) → hook → log. Proven on the rig: a same-source
pre-seed note is deleted by `handle_deactivation`, the breadcrumb (created after)
survives.

On reactivation, `handle_activation()` clears both the option entry and the WC note
(`clear_remote_deactivation_artifacts()`), so a running plugin never shows a stale
"you were disabled" message.

## Related
- [[is-enhanced-admin-available-always-true]] — the guard the note code needs
- SESSION-LOG s12 — remote-deactivation UX (Findings A + B)
- `reviews/remote-deactivation-ux-findings-2026-06-13.md`
