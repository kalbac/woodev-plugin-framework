# Gotcha: [tooling/worktrees] — A fresh worktree of the CDEK plugin has NO `vendor/` and NO bundled `woodev/`
> Tags: tooling/worktrees, tooling/parallel-agents | Session: s160

## What happens

A worker brief said «a fresh worktree needs no install step (node_modules shared, vendor copied)». That is true for
the FRAMEWORK repo (`orca.yaml` + `.worktreeinclude`), not for `woocommerce-edostavka`: its Orca worktree starts
without `vendor/` and without `woodev/`, so `composer test` cannot even start.

## Fix

✅ Every plugin brief carries the two setup lines:
`cp -R /Users/maksimmartirosov/Projects/woocommerce-edostavka/vendor .` (copy, never symlink — see the related gotcha)
and `WOODEV_FRAMEWORK_DIR=/Users/maksimmartirosov/Projects/woodev-plugin-framework bash scripts/sync-framework.sh`.
The bundle comes from whatever branch the framework main checkout holds — check it first.

## Related

- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md)
