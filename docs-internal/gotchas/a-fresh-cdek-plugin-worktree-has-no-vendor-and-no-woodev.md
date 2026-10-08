# Gotcha: [tooling/worktrees] — A fresh worktree of the CDEK plugin has NO `vendor/` and NO bundled `woodev/`
> Tags: tooling/worktrees, tooling/parallel-agents | Session: s160, s161

## What happens

A worker brief said «a fresh worktree needs no install step (node_modules shared, vendor copied)». That is true for
the FRAMEWORK repo (`orca.yaml` + `.worktreeinclude`), not for `woocommerce-edostavka`: its Orca worktree starts
without `vendor/` and without `woodev/`, so `composer test` cannot even start.

## Fix

✅ Every plugin brief carries the two setup lines:
`cp -R /Users/maksimmartirosov/Projects/woocommerce-edostavka/vendor .` (copy, never symlink — see the related gotcha)
and `WOODEV_FRAMEWORK_DIR=/Users/maksimmartirosov/Projects/woodev-plugin-framework bash scripts/sync-framework.sh`.
The bundle comes from whatever branch the framework main checkout holds — check it first.

## s161: «test files only» does not exempt a brief from these lines

The #1169 brief said the worker "adds test files only, so it does not need vendor". It rewrote
`scripts/rig/setup.php`, and `tests/unit/RigHarnessTest.php` pins that script's SOURCE text — three unit
tests went red on CI after the merge, invisible in the worktree because nothing there could run phpunit.
❌ "harness/scripts only — no vendor needed". ✅ The two setup lines go into EVERY plugin brief that touches
`scripts/` or `tests/`, and the gate list includes `composer test`.

## Related

- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md)
