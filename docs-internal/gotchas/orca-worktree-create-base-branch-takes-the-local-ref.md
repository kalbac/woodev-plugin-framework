# `orca worktree create --base-branch main` takes the LOCAL ref, not `origin/main`

**Namespace:** `[tooling/git-merge]` · **Discovered:** s87 (2026-08-24), writing a docs branch

## The trap

```bash
orca worktree create --name s87-docs-3 --base-branch main --setup skip
```

The new worktree came up on `69187e2` while `origin/main` was already at `c478caa` — two commits
behind, both of them the docs this very session had merged an hour earlier. Nothing warned.

The tell was the structural gate disagreeing with itself:

```
gotchas: 185 files, 185 index entries      # on the new worktree
gotchas: 187 files, 187 index entries      # what this session had actually merged
```

A branch made from it and pushed would have silently REVERTED the two merged commits on merge.

## Root cause

`--base-branch` is passed to git as a ref name, and `main` resolves to the LOCAL `refs/heads/main`.
A session that has been merging PRs through `gh` never fast-forwards its local `main` — `gh pr merge`
happens server-side, so `origin/main` moves and `main` stays wherever it was when the session
started. The longer the session, the further behind it drifts.

## ✅ Correct

```bash
git fetch origin --quiet
orca worktree create --name <name> --base-branch origin/main --setup skip --json
```

And verify, because the receipt does not tell you:

```bash
git -C <new-worktree> log --oneline -1     # must equal origin/main
```

If it is already wrong, no need to recreate the worktree — reset the branch inside it:

```bash
git -C <worktree> fetch origin --quiet
git -C <worktree> checkout -B <branch> origin/main
```

## Why it matters more here than it looks

This repo's `docs-internal` files are *append-and-rewrite* — `CURRENT-STATE.md`,
`next-session-prompt.md`, `GOTCHAS.md` counts. A stale base does not conflict; it produces a clean
diff that quietly deletes whatever landed in between. The structural gate
(`scripts/lint-docs.mjs`) catches the gotcha/session COUNTS, which is what exposed it here — but it
would not catch a stale `CURRENT-STATE.md` body.

## Related

- [[a-stale-primary-checkout-degrades-every-worktree-made-from-it]] — the sibling: there the primary
  checkout's own working copy is behind, so `.worktreeinclude` copies the wrong files. Same family:
  **a worktree is only as current as what it was made from, and nothing says so out loud.**
- [[git-worktree-remove-empties-the-primary-checkout-s-node-modules]] — remove them through Orca.
