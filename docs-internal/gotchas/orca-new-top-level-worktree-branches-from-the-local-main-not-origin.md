# Gotcha: [tooling/orca] — A `new-top-level` worktree branches from the LOCAL `main`, not from origin

> Tags: orca, worktree, git | Session: s213

## What happens

After the coordinator merges PRs with `gh pr merge` (server-side), the repo's local `main` is still the old commit. A worker started
with `worker-start --worktree new-top-level` gets a branch cut from that stale `main`, so its work conflicts with the release that
was merged minutes earlier. Seen twice in s213: Russian Post #71 and CDEK #170 (both on top of a just-released framework bump).

## Root cause

`gh pr merge` merges on GitHub and does not touch the local checkout. Orca creates the new worktree from the local `main` ref; it
does not fetch first.

## Fix

❌ **Wrong** — merge with `gh pr merge`, then launch the next worker at once.

✅ **Correct** — update the repo's local `main` BEFORE the launch: `git -C <repo> fetch origin && git -C <repo> checkout main && git -C <repo> pull --ff-only`
(or `git -C <repo> fetch origin main:main` when `main` is not checked out there). Then `worker-start --worktree new-top-level`.
If a worker already started on a stale base, rebase its branch onto `origin/main` before the critic reads it.

## Related

- [orchestrating-agents-with-orca.md](../wiki/orchestrating-agents-with-orca.md) — the worker recipe and placement rules
- [tooling topic index](../gotcha-index/tooling.md)
