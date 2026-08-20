# gotcha: sharing `vendor/` into a worktree breaks Composer's autoloader — copy it, never symlink

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s83 (2026-08-21)

## What happened

Orca can materialise gitignored directories into every new worktree instead of rebuilding them
there. The obvious configuration is to share both heavy trees:

```yaml
# orca.yaml — WRONG
worktree:
  sharedDirectories:
    - node_modules
    - vendor
```

`node_modules` works. `vendor` produces this on the first test run in the worktree:

```text
Fatal error: Cannot redeclare class Woodev_Packer
  (previously declared in …/_woodev_wt/woodev_framework/probe/woodev/box-packer/abstract-class-packer.php:3)
  in D:\Projects\woodev_framework\woodev\box-packer\abstract-class-packer.php on line 3
```

Note the two paths: the worktree's copy **and** the primary checkout's copy of the same file.

## Root cause: Composer hard-codes the project root, and PHP resolves symlinks

`vendor/composer/autoload_psr4.php` and `autoload_classmap.php` are generated as:

```php
$vendorDir = dirname( __DIR__ );
$baseDir   = dirname( $vendorDir );
```

Orca shares a directory by **symlinking** it (it clone-copies only on macOS/APFS). So inside a
worktree `vendor` points at the primary checkout, PHP resolves `__DIR__` to its real path, and
`$baseDir` becomes **the primary checkout** — not the worktree. Every classmap entry
(`$baseDir . '/woodev/…'`) therefore loads the primary checkout's sources, while the test bootstrap
loads the worktree's own. Same class, two files, fatal.

`node_modules` is immune because jest and wp-scripts resolve from `rootDir`/cwd, not from a
generated file that baked in an absolute path.

## ✅ Correct — share `node_modules`, COPY `vendor`

```yaml
# orca.yaml
worktree:
  sharedDirectories:
    - node_modules
```

```text
# .worktreeinclude — copies, so each worktree owns its own
vendor
```

`.worktreeinclude` copies rather than shares, and a real copy makes `__DIR__` resolve inside the
worktree, which is exactly what Composer needs. Measured on this repo: the copy costs seconds on
one volume; `composer install` in a fresh worktree cost **411 seconds**. A worktree created this
way runs every gate immediately — verified at 2475 unit / 6112 assertions and 1260 jest with **no
install step at all**.

## Also worth knowing

- Entries in `sharedDirectories` must **exist in the primary checkout and be gitignored**, or Orca
  skips them silently — no error, just an absent directory.
- Keep the worktree base path on the **same volume** as the repo
  (`orca project setup-update --setup <id> --worktree-base-path <path>`). Windows directory
  symlinks across volumes need Developer Mode or elevation; same-volume links are unprivileged.

## Related

- [input-accepted-is-not-proof-a-worker-started](input-accepted-is-not-proof-a-worker-started.md) — the other "it looked configured and wasn't"
- [serena-activate-path-must-be-the-worker-s-worktree](serena-activate-path-must-be-the-worker-s-worktree.md) — the other path-resolution trap in a worktree
- [classmap-autoload-breaks-class-exists-once-guard](classmap-autoload-breaks-class-exists-once-guard.md) — the framework's own class map, a different mechanism with its own trap
- `../wiki/orchestrating-agents-with-orca.md` — where the worktree configuration is documented
