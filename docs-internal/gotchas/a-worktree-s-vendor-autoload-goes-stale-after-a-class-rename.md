# Gotcha: [tooling/worktrees] — "A fresh worktree needs no install step" stops being true the moment another branch renames a class
> Tags: orca, worktrees, composer, autoload, testing | Session: s104

## What happens

The standing fact is right and stays right: `.worktreeinclude` COPIES `vendor` into a new worktree,
`orca.yaml` shares `node_modules`, and a fresh worktree is gate-capable with zero installs.

What that fact does not say is that the copied `vendor/composer/autoload_classmap.php` is a
SNAPSHOT. Rebase a worktree onto a `main` where another branch moved classes into new namespaces
(#647 did exactly this) and the suite dies at load:

```
PHP Fatal error: Uncaught Error: Class "Woodev\Framework\Shipping\Settings\Shipping_Integration" not found
```

The rebase is clean, the source is correct, and nothing in the diff is wrong. The autoloader is
simply describing the tree as it was when the worktree was created.

## Root cause

`composer dump-autoload` writes a static classmap. Copying `vendor` copies that file. Git does not
regenerate it, because Composer is not a build step git knows about.

## Fix

**After any rebase or merge that brings in a class rename or move, run `composer dump-autoload` in
the worktree** before believing a red suite:

```bash
composer dump-autoload -q
rm -f .phpunit.result.cache
php -d extension=sodium vendor/bin/phpunit --testsuite=Unit
```

Read the failure before assuming code is broken: `Class "…" not found` for a class that plainly
exists on disk is this, not a defect in the diff.

The same applies in the PRIMARY checkout after pulling such a merge.

## Related

- [moving-a-class-into-a-sub-namespace-breaks-its-unqualified-siblings](moving-a-class-into-a-sub-namespace-breaks-its-unqualified-siblings.md) — the rename that triggered this
- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md) — why `vendor` is COPIED and never shared
- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other way a worktree's gate differs from the primary's
