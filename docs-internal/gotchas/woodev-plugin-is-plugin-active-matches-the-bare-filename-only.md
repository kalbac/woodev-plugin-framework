# gotcha: `Woodev_Plugin::is_plugin_active()` matches the bare file name, not `folder/file.php`

**Namespace:** `[framework/plugin]`
**Discovered:** s170 (2026-10-12), carrier competitor notices (#1230)

## What happened

The new carrier catalogue passed full basenames (`cdekdelivery/cdek.php`) to
`Woodev_Plugin::is_plugin_active()`. That method strips the directory from every `active_plugins` entry
and compares bare file names, so a basename with a slash never matched: no competitor was ever detected
and «our plugin is active» was never true. The tests mocked `is_plugin_active` and stayed green.

## ✅ Correct

For an exact plugin, compare the full basename with `get_option( 'active_plugins' )` plus the keys of
`get_site_option( 'active_sitewide_plugins' )` (see `Carrier_Competitor_Notification_Handler`). Match a
generic file name such as `cdek.php` only together with its folder; our own products may be matched by
file name in any folder (a renamed zip folder). Test against stubbed option data, never a mocked
`is_plugin_active`.

## Related

- [woocommerce-11-note-actions-have-no-primary-flag-and-layout-setters-are-deprecated](woocommerce-11-note-actions-have-no-primary-flag-and-layout-setters-are-deprecated.md)
