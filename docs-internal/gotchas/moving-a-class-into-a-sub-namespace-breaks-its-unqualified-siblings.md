# Gotcha: [php/namespaces] — Moving a class into a sub-namespace silently rebinds every unqualified sibling reference
> Tags: php, namespaces, refactoring, class-map, testing | Session: s104

## What happens

`Shipping_Integration` lived in `Woodev\Framework\Shipping` and referred to its sibling as plain
`Shipping_Plugin` — correct, because an unqualified name resolves in the CURRENT namespace.

#647 moved it to `Woodev\Framework\Shipping\Settings` to match its directory. Every one of those
unqualified references then resolved to `Woodev\Framework\Shipping\Settings\Shipping_Plugin`, which
does not exist. **No parse error, no PHPCS complaint, no failure in a per-file test run.** The unit
suite died at LOAD time:

```
PHP Fatal error: Could not check compatibility between
  Woodev\Tests\Unit\Shipping\Woodev_Test_Shipping_Integration_For_Guards::init_plugin(): Woodev\Framework\Shipping\Shipping_Plugin
  and Woodev\Framework\Shipping\Settings\Shipping_Integration::init_plugin(): Woodev\Framework\Shipping\Settings\Shipping_Plugin,
  because class Woodev\Framework\Shipping\Settings\Shipping_Plugin is not available
```

PHPStan reported twelve errors, all in that one file. The worker that did the rename ran seven
targeted test files and reported them green — the fatal needs the whole suite's load order to fire.

## Root cause

A namespace declaration is not a rename of one symbol; it re-points every unqualified name in the
file at once. Grep for the class being MOVED finds its call sites elsewhere. It does not find the
siblings the moved file refers to, because those references contain no namespace at all.

## Fix

After moving a class into a new namespace, **read the moved file's own unqualified type references**
— property types, parameter and return types, docblock `@param`/`@return`, `instanceof`, `new` — and
import each sibling explicitly:

```php
namespace Woodev\Framework\Shipping\Settings;

use Woodev\Framework\Shipping\Shipping_Plugin;
```

`composer phpstan` is the cheap systematic finder: it lists every one as
`… has unknown class … as its type`.

## The wider rule this is an instance of

**A worker's targeted test runs are not the gate; the coordinator's full-suite run is.** This was
caught only because the full `--testsuite=Unit` was run in the worktree after the worker reported
green. Two other s104 defects had the same shape.

Also: a worktree's COPIED `vendor/` keeps its own Composer classmap, so after rebasing onto a branch
that renamed classes, `composer dump-autoload` is required there — see
[a-worktree-s-vendor-autoload-goes-stale-after-a-class-rename](a-worktree-s-vendor-autoload-goes-stale-after-a-class-rename.md).

## Related

- [a-worktree-s-vendor-autoload-goes-stale-after-a-class-rename](a-worktree-s-vendor-autoload-goes-stale-after-a-class-rename.md)
- [framework-classmap-autoload-vendored-boot](framework-classmap-autoload-vendored-boot.md) — how the shipped autoloader finds classes
- `docs-internal/AGENT-RULES.md` → Rule 3 — regenerate the class map after adding or moving a class
