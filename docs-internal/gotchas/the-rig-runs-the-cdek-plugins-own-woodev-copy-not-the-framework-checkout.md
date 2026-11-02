# Gotcha: [rig/plugin] — Which `woodev/` the rig runs in a plugin: the s162 «own copy» finding is CONTRADICTED by s184

> **⚠ s184 (16.10.2026, MacBook) CORRECTION.** On this Mac `.wp-env.override.json` bind-mounts the framework PRIMARY checkout's
> `./woodev` over `wp-content/plugins/woocommerce-edostavka/woodev` and `…/woocommerce-yandex-delivery/woodev` — the plugins do NOT
> run their own copies, so verifying a framework branch on the rig means the PRIMARY tree must serve that branch (a worktree is
> not mounted). The s162 text below (a gitignored `woodev/` in the plugin checkout shadowing the mount) was true for the rig
> state measured then and is NOT the current state; the two disagree, so never trust either by reading — put a version marker or
> observable behaviour on the page and check which code answers.
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
✅ Before ANY plugin rig measurement after a framework change, **prove which `woodev/` answers**: put a version marker or an observable
behaviour of the new code on the page and check it — the mount table proves nothing. If the marker shows stale code (the s162 state),
refresh the plugin's own copy with `WOODEV_FRAMEWORK_DIR=<framework primary checkout> bash scripts/sync-framework.sh` in the plugin
checkout and re-check. If it shows the new code (the s184 state on the Mac, where `.wp-env.override.json` bind-mounts the primary
`./woodev`), no sync is needed — but the PRIMARY checkout must serve the branch under test. Put the marker check, not an unconditional
sync, in every acceptance brief.

## Related

- [a-fresh-cdek-plugin-worktree-has-no-vendor-and-no-woodev](a-fresh-cdek-plugin-worktree-has-no-vendor-and-no-woodev.md) — the same sync script, for worktrees
- [GOTCHAS.md](../GOTCHAS.md) — the topic map
