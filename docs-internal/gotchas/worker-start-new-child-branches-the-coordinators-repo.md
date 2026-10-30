# Gotcha: [tooling/orca] — `worker-start --worktree new-child` makes the child of the COORDINATOR's repo, not of the repo you are working on

> Tags: orca, worktree, multi-repo | Session: s204 (30.10.2026, Mac, Orca 1.4.x)

## What happens

The overnight Ozon build was coordinated from the framework repo but the code lives in `woodev-ozon-delivery`. The first
W0 launch used `orca orchestration worker-start --worktree new-child` and the worker started in a fresh worktree of the
**framework** repo. It was stopped before any edit, but a Codex or Sonnet worker pointed at the wrong repo can write a
whole feature into a tree that has no such plugin.

## Root cause

`new-child` resolves the parent from the coordinator's own worktree. It has no notion of «the repo the task is about».

## Fix

❌ Wrong — for a task in another repo:

```bash
orca orchestration worker-start --worktree new-child …
```

✅ Correct — create the worktree explicitly in the right repo, then hand it over by path:

```bash
/Applications/Orca.app/Contents/Resources/bin/orca worktree create --name <name> \
  --repo id:<repo-id> --base-branch origin/main --branch kalbac/<branch> --no-parent --json
orca orchestration worker-start --worktree path:<the printed path> …
```

Then check `git rev-parse --show-toplevel` and `git remote -v` in the worker's first lines of output. (`--base-branch` takes
the LOCAL ref — see the related gotcha — so pass `origin/main` after a fetch.)

## Related

- [orca-worktree-create-base-branch-takes-the-local-ref](orca-worktree-create-base-branch-takes-the-local-ref.md) — why `origin/main`, not `main`
- [serena-activate-path-must-be-the-worker-s-worktree](serena-activate-path-must-be-the-worker-s-worktree.md) — the same «wrong tree» failure on the Serena side
