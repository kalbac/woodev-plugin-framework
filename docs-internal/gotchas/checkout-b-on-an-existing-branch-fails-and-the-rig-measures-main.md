# Gotcha: [tooling/git] — `git checkout -b <branch>` on an existing local branch fails and leaves HEAD on main, so the rig measures main

> **Namespace:** `[tooling/git]` | **Discovered:** s225 (10.11.2026) | Session: s225

## What happens

An e2e rig check meant for a PR branch was started with `git checkout -b <pr-branch>` on a
name that already existed locally. Git refused with `fatal: a branch named '<pr-branch>'
already exists`, and HEAD stayed on `main`. The next command in the same shell line ran the
e2e suite anyway, so the run measured `main` while the notes called it PR evidence. The
e2e result matched the main baseline, which is exactly what a main run looks like, so the
mismatch was not visible in the numbers. Re-run on the detached PR commit (see the fix) is the
only evidence that counts.

## Root cause

`git checkout -b` creates a branch and switches to it in one step. When the name exists the
command fails and does not switch, and a failed command does not stop the lines after it
unless the shell is told to. The error is one line in long output, and the rig output does not
name the branch it ran on, so the run looks normal.

## Fix

❌ Wrong — a failed `-b` does not stop the run that follows:

```bash
git checkout -b fix/some-branch origin/fix/some-branch
npm run test:e2e        # runs on main if the local branch already existed
```

✅ Correct — detach on the PR commit and prove it before the run:

```bash
git fetch origin
git checkout --detach origin/fix/some-branch
git rev-parse --abbrev-ref HEAD    # must print HEAD, not main
git log --oneline -1               # must show the PR commit
npm run test:e2e
git checkout main                  # put the primary checkout back afterwards
```

`--detach` works even when the branch is checked out in another worktree, which is the
situation that makes a plain checkout refuse.

## Related

- [local-npm-run-build-is-not-assets-parity-evidence](local-npm-run-build-is-not-assets-parity-evidence.md) — the detached-HEAD rig and build pattern this fix uses
- [a-critic-cannot-get-a-fresh-worktree-for-a-branch-already-checked-out](a-critic-cannot-get-a-fresh-worktree-for-a-branch-already-checked-out.md) — the same git refusal seen from the critic's side
- [a-cd-in-the-bash-tool-persists-and-sends-the-next-git-command-into-a-worktree](a-cd-in-the-bash-tool-persists-and-sends-the-next-git-command-into-a-worktree.md) — another way a git command lands in the wrong tree
