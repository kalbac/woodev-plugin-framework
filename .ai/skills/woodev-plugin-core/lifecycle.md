# Lifecycle, upgrades and data preservation

Source of truth: `woodev/class-lifecycle.php` (`Woodev_Lifecycle`), `Woodev_Plugin::init_lifecycle_handler()`
and `Woodev_Plugin::get_plugin_version_name()` / `get_plugin_option_name()` in `woodev/class-plugin.php`.
Policy: `docs-internal/adr/005-platform-v2-clean-break-policy.md`, `docs-internal/AGENT-RULES.md` → Rule 0.
Gotcha: `docs-internal/gotchas/lifecycle-install-upgrade-detection.md`.

## The policy in one paragraph

On the v2 line the framework's INTERNAL API may break without notice (class names, method signatures,
file layout) — you will not get deprecation shims, so pin your bundled framework version and read
release notes when you bump it. What may NEVER break is what is stored on an installed site. If you
rewrite a plugin that already has users, every one of these is preserved byte-for-byte: option keys and
settings arrays, license key option names / activation state / instance ids, updater identity
(`download_id`), WooCommerce payment-gateway ids, shipping-method ids and instance setting keys, public
action/filter hook names, cron hooks + recurrence + payload shape, custom DB tables, REST namespaces,
AJAX action names, admin page slugs, log source names, background-job ids, order/session meta keys,
WooCommerce email ids. Rename in code freely; never rename on the wire.

## The lifecycle handler

`Woodev_Lifecycle` is built by `Woodev_Plugin::init_lifecycle_handler()`. Subclass it per plugin and wire
it by overriding that method:

```php
protected function init_lifecycle_handler() {
    $this->lifecycle_handler = new My_Plugin_Lifecycle( $this );
}
```

What it does (all verified in `woodev/class-lifecycle.php`):

- **Runs only in admin, not in AJAX.** `init()` is hooked to `wp_loaded` under
  `is_admin() && ! wp_doing_ajax()`. So install/upgrade routines execute on the first wp-admin page load
  after the plugin files change — never on a storefront request, never in WP-Cron. Do not write an
  upgrade that a front-end request depends on having already run, and do not assume WP-CLI triggers it.
- **Detects install vs upgrade by comparing versions.** `get_installed_version()` reads the option
  `woodev_{plugin id}_version` (`Woodev_Plugin::get_plugin_version_name()`); empty → `install()`
  then store an `install` event and fire `woodev_{plugin id}_installed`; lower than the plugin version →
  `upgrade( $installed )`, store an `upgrade` event, fire `woodev_{plugin id}_updated`; then the stored
  version is set to the plugin version. Never delete or hand-edit that option — a deleted one re-runs
  `install()`.
- **Routines:** declare `protected $upgrade_versions = [ '2.3.1', '2.4.0.0' ];` (ascending) and a method
  `upgrade_to_X_Y_Z( string $installed_version )` per entry (dots and dashes become underscores, so
  `2.4.0.0` → `upgrade_to_2_4_0_0`). `upgrade()` calls each one the installed version is below, logging
  start and end. Put one-time installs in `install()` (both are `protected`, empty in the base).
  `install_default_settings( array $settings )` writes each `[ 'id' => …, 'default' => … ]` option.
- **Activation/deactivation without `register_activation_hook()`** (it cannot be called from
  `plugins_loaded`): `handle_activation()` watches the option `woodev_{plugin id}_is_active`, calls your
  `activate()` once, reschedules the weekly license check and fires `woodev_{plugin id}_activated`;
  `handle_deactivation()` is hooked to `deactivate_{plugin file}`. Override `activate()` / `deactivate()`.
- **Event history:** the last 30 events (`install`, `upgrade`, `migrate`) are stored in
  `woodev_{plugin id}_lifecycle_events`. `add_migrate_event( $from_plugin, $from_version, $data )`
  records a migration from another plugin.
- **Milestone notices** (ask for a review after a key action): `register_milestone_message( $id, $message )`
  and the `woodev_{plugin id}_milestone_reached` action. Options `woodev_{plugin id}_milestone_messages`
  and `woodev_{plugin id}_milestone_version`. A plugin that never had milestones gets them marked reached
  on its first upgrade so old users are not nagged.

Note the id spelling: the lifecycle's own options use `get_id()` as written (`woodev_my-plugin_version`),
while `Woodev_Plugin::get_plugin_option_name( $key )` uses `get_id_underscored()`
(`woodev_my_plugin_<key>`). When you replace a v1 plugin, compute the exact option names the v1 plugin
used before you pick your `plugin_id`.

## Migrating a v1 plugin's data

Method: one checklist per plugin, verified at rewrite time (ADR-005). Templates, both already filled in
for real plugins:

- `docs-internal/migration/edostavka-data-preservation-checklist.md`
- `docs-internal/migration/yandex-data-preservation-checklist.md`

Their sections are the contract inventory to copy: Installed-Site Identity; Options And Settings;
Legacy Migration Maps (old key → new key, idempotent); Licensing And Updater State; WooCommerce Method
Contracts (shipping-method ids, instance settings, zone persistence, email ids); Scheduled Work And
Queues; Stored Data Schemas; Checkout And Frontend State; Web And Admin Surface (admin slugs, AJAX,
REST, webhooks); Operational Surface (log sources); Public Hooks (third-party contract);
Release-Blocking Verification Gates. Build the checklist for YOUR plugin from the old plugin's source —
a literal scan of `get_option` / `update_option` / `apply_filters` / `do_action` / `register_rest_route` /
`wp_schedule_*` / `add_meta_box` / `update_meta_data` — and keep it in the plugin repo.

Rules that follow from the lifecycle mechanics:

1. **Seed the version option.** The first v2 load of a rewritten plugin on an existing site finds no
   `woodev_{id}_version` if the v1 plugin tracked its version elsewhere — the lifecycle then runs
   `install()` on a live site. Decide explicitly: either `install()` is safe to run over existing data
   (idempotent), or an early step copies the v1 version into `woodev_{id}_version` first.
2. **Make every migration idempotent** and guard it with its own flag option (the edostavka v1 flag
   `wc_edostavka_upgraded_to_2_2_2_0` is an example of a key you must keep). Leave the old keys in place
   after copying: `Settings_Provider` takes an optional `legacy_option_key` (migration source) and a
   `legacy_page` (redirect from the old admin URL) — see [settings.md](settings.md).
3. **Preserve the old public hooks.** Alias only where consumers justify it; never silently drop one.
4. **Preserve the license.** Same `download_id`, and the license option names the old plugin used (gotcha
   `docs-internal/gotchas/license-key-option-double-prefix.md` — plugin ids starting with `woodev`
   double-prefix their license option key).
5. **Keep the old shipping-method id and instance setting keys** (zones are stored in
   `woocommerce_shipping_zone_methods.method_id`); losing one empties a merchant's shipping zones.
6. **Probe the class signatures** before the first run: `npm run probe:signature`
   (`docs-internal/migration/signature-probe.md`).

## Related

- [SKILL.md](SKILL.md), [plugin-class.md](plugin-class.md), [settings.md](settings.md),
  [traps.md](traps.md)
