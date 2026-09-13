# Gotcha: [testing/paths] — On macOS `sys_get_temp_dir()` is a SYMLINK, so a reflected file path never matches a fixture built from it
> Tags: testing, paths, macos, phpunit, fixtures | Session: s136
> **Platform:** macOS only — `/var` → `/private/var`. The same test is green on Windows and in CI.

## What happens

Two tests in `tests/unit/MixedFleetBootstrapGateTest.php` failed on the laptop and nowhere else:

```text
1) …::test_direction_a_notice_names_resolved_conflicting_plugin
The resolver must map the winning v1 framework file to its owning plugin display name.
-'Legacy Woodev Plugin'
+''
2) …::test_direction_a_notice_escapes_hostile_conflicting_plugin_name
Failed asserting that '<div class="error"><p>Плагин …' contains "alert(1)".
```

It reads as a resolver regression — the notice fell back to its generic wording, exactly as it would
if `resolve_conflicting_plugin_name()` had broken. Nothing is wrong with the framework.

## Root cause

The fixture writes a stub `bootstrap.php` under `sys_get_temp_dir()` and defines `WP_PLUGIN_DIR` to
the same string. `Loader::resolve_conflicting_plugin_name()` then compares
`ReflectionClass::getFileName()` against `WP_PLUGIN_DIR` **as strings** (`strpos( $file, $dir . '/' )`).

On macOS the temp dir is reached through a symlink, and PHP reports the **resolved** path for a file
it has included:

```text
sys_get_temp_dir():        /var/folders/…/T
realpath( same ):          /private/var/folders/…/T
getFileName() of the stub: /private/var/folders/…/T/woodev_probe_…/legacy-woodev-plugin/woodev/bootstrap.php
prefix match:              NO
```

So the resolver correctly finds no owner and returns `''` — its documented best-effort fallback. The
**fixture** is what is wrong: it assumes the path it made and the path PHP reports are the same
string, which holds only where the temp dir is not a symlink.

## Fix

Resolve the base after creating it, and derive everything else from the resolved value:

```php
// ❌ wrong — WP_PLUGIN_DIR keeps the unresolved /var/… form
$base       = sys_get_temp_dir() . '/woodev_mf_' . uniqid( '', true );
$plugin_dir = $base . '/legacy-woodev-plugin/woodev';
mkdir( $plugin_dir, 0777, true );

// ✅ correct — both sides of the resolver's comparison speak the same path
$base       = sys_get_temp_dir() . '/woodev_mf_' . uniqid( '', true );
$plugin_dir = $base . '/legacy-woodev-plugin/woodev';
mkdir( $plugin_dir, 0777, true );
$base       = realpath( $base );
$plugin_dir = $base . '/legacy-woodev-plugin/woodev';
```

⚠ **The production question is separate and is NOT closed by this.** A real site whose
`WP_PLUGIN_DIR` is symlinked loses the plugin name from the notice in exactly this way. The resolver
is best-effort by design and falls back to truthful generic wording, so it is not a bug — but whether
it should `realpath()` both sides is a decision for a card, not for a test fixture.

## Related

- [the-skipped-count-is-dominated-by-whether-sodium-is-enabled](the-skipped-count-is-dominated-by-whether-sodium-is-enabled.md) — the other environment fact that moves a unit-suite number without any code changing
- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — a green/red difference that is about the checkout, not the diff
- [../wiki/two-machine-setup.md](../wiki/two-machine-setup.md) — where the platforms differ
