# Gotcha: [deprecation/hook-deprecator-usage] — Use Woodev_Hook_Deprecator, not _deprecated_hook()
> Tags: deprecation, hooks, wordpress | Session: s2

## What happens
Using WordPress's `_deprecated_hook()` or `_deprecated_argument()` directly for custom plugin hooks doesn't integrate with the framework's hook deprecation system. The `Woodev_Hook_Deprecator` provides automatic hook remapping — callbacks attached to old hook names are automatically invoked when the new hook fires.

## Root cause
`Woodev_Hook_Deprecator` does two things:
1. **Notices** — triggers `E_USER_NOTICE` when other actors have callbacks on deprecated hooks (only when `WP_DEBUG` is on)
2. **Remapping** — when `'map' => true` and `'replacement'` is set, it hooks into the new hook and automatically applies callbacks from the old hook

WordPress's `_deprecated_hook()` only triggers notices — it doesn't remap callbacks.

## Fix

❌ **Wrong:**
```php
// Only triggers notice, doesn't remap callbacks
_deprecated_hook( 'my_old_hook', '1.5.0', 'my_new_hook', 'Use my_new_hook instead' );
```

✅ **Correct:**
```php
// In plugin's init_hook_deprecator()
protected function init_hook_deprecator() {
    $this->hook_deprecator = new Woodev_Hook_Deprecator(
        $this->get_plugin_name(),
        [
            'my_old_hook' => [
                'version'     => '1.5.0',
                'removed'     => true,
                'replacement' => 'my_new_hook',
                'map'         => true,  // ✅ Automatically remaps old callbacks
            ],
            'my_other_old_hook' => [
                'version'     => '1.5.0',
                'removed'     => false, // just deprecated, not removed
                'replacement' => 'my_new_other_hook',
                'map'         => false, // don't remap automatically
            ],
        ]
    );
}
```

### Hook deprecator array format
| Key | Type | Description |
|-----|------|-------------|
| `version` | string | Version when deprecated/removed |
| `removed` | bool | `true` = removed, `false` = deprecated |
| `replacement` | string | New hook name (empty = no replacement) |
| `map` | bool | `true` = auto-remap old callbacks to new hook |

## Related
- [class-woodev-hook-deprecator.php](../../woodev/class-woodev-hook-deprecator.php) — Full implementation
- [AGENT-RULES.md](../AGENT-RULES.md) — PHP/WP Gotchas: `_doing_it_wrong()` and `Woodev_Hook_Deprecator`
