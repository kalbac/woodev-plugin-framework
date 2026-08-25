# gotcha: widening `autoload.classmap` breaks every EXISTING checkout until `composer dump-autoload` runs

**Namespace:** `[build/*]`
**Discovered:** s91 (2026-08-25), right after #506 merged

## What happened

#506 added `woodev/settings-page` to `composer.json`'s `autoload.classmap` and removed the manual
`require_once` lines 23 test files were carrying to compensate. CI was green — a CI job runs
`composer install` from scratch, so it regenerates the map.

The next `composer check` in a checkout that had merely `git pull`ed produced this:

```text
Tests: 2733, Assertions: 6680, Errors: 8, Failures: 1, Skipped: 66.
Class "Woodev\Framework\Settings\Composite_Settings_Handler" not found
```

Nine red tests naming a class that plainly exists, in files nobody had touched. It reads exactly
like a broken merge.

## Root cause

`vendor/composer/autoload_classmap.php` is **generated at install/dump time**, not read from
`composer.json` at runtime. A checkout whose `vendor/` predates the `composer.json` change still
carries the old map — which knows nothing about `woodev/settings-page` — while the test files that
used to `require_once` those classes no longer do.

`composer dump-autoload` fixes it in seconds and is **not** an install:

```bash
composer dump-autoload
# Generated optimized autoload files containing 1614 classes
```

(It also prints two PSR-4 non-compliance warnings about `woodev/competitor/` — those are
pre-existing and unrelated.)

## It reaches agent worktrees too

`.worktreeinclude` **copies** `vendor/` from the primary checkout, so a worktree created before the
dump inherits the stale map, and one created after inherits the good one. Two worktrees on the same
branch can therefore disagree about whether the suite is green — with nothing in the diff to explain
it.

The standing "a fresh worktree needs NO install step" rule is unchanged and still right. This is the
one narrow exception, and it is triggered by a `composer.json` autoload change landing on `main`, not
by creating a worktree.

## ❌ Wrong

Reading nine "class not found" errors as a code defect, a bad merge, or a worker's mistake, and
starting to debug the classes.

## ✅ Correct

When `composer.json`'s `autoload` section changed on `main` since your `vendor/` was written:

```bash
composer dump-autoload && rm -f .phpunit.result.cache && composer check
```

If you maintain long-lived agent worktrees, do it in the primary checkout **before** creating the
next one, since the copy is taken at creation time.

## Note on the production side

None of this affects shipped plugins: they have **no Composer autoload at runtime** and load
framework classes through the generated `woodev/class-map.php` (`php bin/generate-class-map.php`).
`autoload.classmap` in `composer.json` is a dev/test concern only — which is exactly why #506 was
described as "a hole in dev autoloading, no runtime effect".

## Related

- [framework-classmap-autoload-vendored-boot](framework-classmap-autoload-vendored-boot.md) — the PRODUCTION map, a different mechanism with the same name
- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md) — why `vendor` is copied into a worktree rather than shared
- Issue #506 — the change that triggered this
