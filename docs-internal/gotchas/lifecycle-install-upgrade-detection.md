# Gotcha: [lifecycle/install-upgrade-detection] — Lifecycle detects install vs upgrade by version comparison
> Tags: lifecycle, install, upgrade, versioning | Session: s2

## What happens
If the stored installed version (`get_installed_version()`) is incorrect or not set, the lifecycle handler will either skip the install routine (installed_version is set but no install happened) or re-run upgrade for every version on every page load (installed_version is empty but version is stored).

## Root cause
`Woodev_Lifecycle::init()` uses this logic:
- `$installed_version` is empty/`null` → **install** (calls `$this->install()`)
- `$installed_version < $plugin_version` → **upgrade** (calls `$this->upgrade($installed_version)`)
- Otherwise → no action

The `get_installed_version()` method retrieves `woodev_{plugin_id}_version` option. If this option is deleted (e.g., plugin reinstall without proper activation hook), the install routine runs again.

## Fix

❌ **Wrong — direct version option manipulation:**
```php
// Never delete the version option manually
delete_option( 'woodev_my_plugin_version' );

// Never set it to wrong value
update_option( 'woodev_my_plugin_version', '0.0.1' ); // Will trigger upgrade through all versions
```

✅ **Correct — understand the lifecycle flow:**
```php
// Lifecycle auto-detects:
// $installed_version = null  → install()
// $installed_version < VERSION → upgrade($installed_version)
// $installed_version = VERSION → no action

// Override these in your plugin:
protected function install() {
    // One-time setup: default options, database tables
    update_option( 'my_plugin_default_settings', $defaults );
}

protected function upgrade( $installed_version ) {
    // Version-specific upgrade routines
    if ( version_compare( $installed_version, '1.2.0', '<' ) ) {
        // Migrate data from pre-1.2.0 format
        $this->migrate_to_120();
    }
}
```

### Key lifecycle hooks
| Hook | Format | Fires when |
|------|--------|------------|
| Installed | `woodev_{plugin_id}_installed` | After install() completes |
| Upgraded | `woodev_{plugin_id}_upgraded` | After all upgrade routines |
| Milestone | `woodev_{plugin_id}_milestone_reached` | Plugin reaches N active installs |

## Related
- [class-lifecycle.php](../../woodev/class-lifecycle.php) — Lines 74–100: install/upgrade detection
- [CURRENT-STATE.md](../CURRENT-STATE.md) — Framework version is `Woodev_Plugin::VERSION`
