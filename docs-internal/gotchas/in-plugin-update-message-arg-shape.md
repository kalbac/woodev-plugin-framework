# `in_plugin_update_message-{$file}` — arg shape: `package`/`new_version` live on arg 2 (response), not arg 1

> Namespace: `[php/*]` (WP plugin-update hooks). Discovered s18 (OB-3 F8, 2026-06-17).

## The trap

WordPress core fires this hook from `wp_plugin_update_row()` as:

```php
do_action( "in_plugin_update_message-{$file}", $plugin_data, $response );
```

- **arg 1 `$plugin_data`** = the plugin **header** array (Name, Version, …). It does
  **NOT** contain `package` or `new_version`. WP never puts the download package on arg 1.
- **arg 2 `$response`** = the update **response object** from the `update_plugins`
  transient — this is where `package`, `new_version`, `sections`, etc. live.

Two independent bugs we hit:

1. **Producer side** (`Woodev_Plugin_Updater::show_update_notification()`, the multisite
   custom row) fired `do_action( "in_plugin_update_message-{$file}", $plugin, $plugin )` —
   the plugin-data array **twice**. Any consumer reading the response off arg 2 got the
   header array instead.
2. **Consumer side** (`Woodev_Plugins_License::plugin_row_license_missing()`) gated on
   `isset( $plugin_data['package'] )` reading arg **1** — which WP never populates with
   `package`. So the gate was **permanently false** and the "backup before updating"
   notice **never rendered** (single-site too, where WP core fires the same hook).

## Correct pattern

- **Producer:** pass the response object as arg 2:
  `do_action( "in_plugin_update_message-{$file}", $plugin, $update_cache->response[ $this->name ] );`
- **Consumer:** read `package` / `new_version` off the **response (arg 2)**, tolerating
  both object and array shapes (WP core sends stdClass; some callers send arrays):

```php
private function extract_update_field( $response, string $field ) {
    if ( is_array( $response ) && array_key_exists( $field, $response ) ) {
        return $response[ $field ];
    }
    if ( is_object( $response ) && isset( $response->$field ) ) {
        return $response->$field;
    }
    return null;
}
```

## Why it's a contract, but a safe one to fix

The hook is a **WP-core** hook (not Woodev-owned). Fixing arg 2 to the response object
**aligns** with the public WP convention — a conformant external consumer benefits, and
only a consumer that wrongly relied on the doubled plugin-data would notice. Under
ADR-005 this is a repair, not a break. Audit external consumers anyway (out-of-repo
plugins follow WP convention).

## Related

- `docs-internal/reviews/ob3-plugin-updater-review-2026-06-14.md` — F8.
- `woodev/licensing/updater/class-plugin-updater.php` `show_update_notification()`.
- `woodev/licensing/class-plugin-license.php` `plugin_row_license_missing()` + `extract_update_field()`.
- [[updater-cache-source-stamp-not-key]] — sibling OB-3 Step 4 finding (F10).
