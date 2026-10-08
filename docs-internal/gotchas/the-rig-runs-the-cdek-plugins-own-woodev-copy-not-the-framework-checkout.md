# Gotcha: [rig/plugin] — The rig runs the CDEK plugin's OWN `woodev/` copy, not the framework checkout
> Tags: rig/plugin, tooling/parallel-agents | Session: s162

## What happens

A framework fix was rebuilt in the framework primary checkout and the CDEK acceptance was run «against it». A Codex worker even
reported that `docker inspect` showed the framework's `woodev/` bind-mounted into the plugin and hashed the bundle to prove it.
The run still exercised **stale** framework code: the nested bind mount is shadowed by the plugin's own gitignored `woodev/`
directory, which only `woocommerce-edostavka/scripts/sync-framework.sh` (rsync) refreshes. The copy dated from 08.10 05:00 and
lacked two rounds of the #1171 fix. A later Sonnet worker found it by instrumenting the page, not by reading the mount table.

## Root cause

`wp-content/plugins/woocommerce-edostavka` is the plugin checkout; its `woodev/` subdirectory is a real directory with files in it.
A mount listed in `docker inspect` is not proof the container sees it — what the PHP process loads is the file under that path.

## Fix

❌ «The framework checkout holds the fix, the rig serves it» / «`docker inspect` lists the mount».
✅ Before ANY plugin rig measurement after a framework change:
`WOODEV_FRAMEWORK_DIR=/Users/maksimmartirosov/Projects/woodev-plugin-framework bash scripts/sync-framework.sh` in the plugin
checkout, and prove it by observing the new behaviour on the page (or a version marker), not by the mount table. Put the sync line
in every acceptance brief.

## Related

- [a-fresh-cdek-plugin-worktree-has-no-vendor-and-no-woodev](a-fresh-cdek-plugin-worktree-has-no-vendor-and-no-woodev.md) — the same sync script, for worktrees
- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
