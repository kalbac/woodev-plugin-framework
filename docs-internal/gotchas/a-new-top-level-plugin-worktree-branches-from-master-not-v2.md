# gotcha: a `new-top-level` worktree of the CDEK plugin branches from `master`, not `v2`

**Namespace:** `[tooling/orca]`
**Discovered:** s164 (2026-10-09), Orca 1.4.222

## What happened

`worker-start --worktree new-top-level --name s164-edostavka-courier-bulk --repo <woocommerce-edostavka>` created a
worktree on `34d21af` — the tip of `master`, the old 2.2.5.5 line — although every v2 task works on `v2`. The receipt
said nothing about the base. The repo's `origin/HEAD` is `origin/master`, and that is what Orca branches from.

A worker on that base would build v2 code on top of the v1 plugin and the diff against `v2` would be thousands of lines.
Caught only because the coordinator printed `git log -1` of the new worktree against `v2`.

## ❌ Wrong

Trusting `new-top-level` to start from the branch the project works on.

## ✅ Right

Right after `worker-start`, before the worker edits anything, compare the base and fix it:

```bash
P=<new worktree path>
git -C "$P" log --oneline -1; git -C ~/Projects/woocommerce-edostavka log --oneline -1 v2
git -C "$P" status --short            # must be empty
git -C "$P" reset --hard v2           # fresh branch, no work yet — safe
```

then `terminal send` the worker a note that its base changed. Alternatively create the worktree first with an explicit
base (`orca worktree create … --base-branch v2`, after `git fetch` — see
[orca-worktree-create-base-branch-takes-the-local-ref](orca-worktree-create-base-branch-takes-the-local-ref.md)) and
start the worker with `--worktree path:<abs>`.

## Related

- [orca-worktree-create-base-branch-takes-the-local-ref](orca-worktree-create-base-branch-takes-the-local-ref.md)
- `../gotcha-index/tooling.md`
