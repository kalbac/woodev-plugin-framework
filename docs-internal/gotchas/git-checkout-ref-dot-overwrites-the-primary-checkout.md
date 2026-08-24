# `git checkout <ref> -- .` is not a way to look at another branch's files

**Namespace:** `[tooling/git]` · **Discovered:** s89 (2026-08-24), inspecting an agent branch from
the primary checkout while verifying a critic finding

## The trap

To read one file from a worker's branch, `git checkout <ref> -- <path>` looks like the obvious move,
and `-- .` looks like the lazy version of it. It is not. It **stages the whole tree from that ref
over the working tree**, and it does so silently.

The damage is not limited to the file you wanted. An agent branch is usually cut from an OLDER
`main`, so every file that has been merged since is quietly reverted too. In s89 one such command
staged an agent branch's twelve files onto `main` and simultaneously rolled back the four files of a
PR merged twenty minutes earlier — including `GOTCHAS.md` and two source files. `git status` showed
twelve `M` entries with no hint that four of them were regressions rather than the branch's own work.

Nothing was lost only because everything was already committed. The recovery is `git reset --hard HEAD`;
if there had been uncommitted work in that tree, it would have been gone.

## ❌ Wrong

```bash
git checkout origin/feat/some-branch -- .          # overwrites the tree, reverts newer merges
git checkout origin/feat/some-branch -- path/to/file.php
```

## ✅ Correct

Read from the ref without touching the working tree at all:

```bash
git show origin/feat/some-branch:path/to/file.php | sed -n '100,160p'
git show origin/feat/some-branch:path/to/file.php | grep -n "function resolve_key" -A 40
git diff origin/main...origin/feat/some-branch                # the whole change
git diff origin/main...origin/feat/some-branch -- path/to/file.php
```

`git show <ref>:<path>` streams the file to stdout. It cannot modify anything, which is exactly the
property you want while reviewing someone else's branch.

## Why it is worth a gotcha

The rig serves the WORKING TREE (`rig-serves-the-working-tree-branch-switch-reverts-fixes`). So this
mistake does not just confuse a `git status` — it silently changes what the rig is serving, in the
middle of a session where browser measurements are being taken as evidence. A measurement taken in
that window would have been evidence about the wrong code.

## Related

- [rig-serves-the-working-tree-branch-switch-reverts-fixes](rig-serves-the-working-tree-branch-switch-reverts-fixes.md)
- [stacked-pr-github-mechanics](stacked-pr-github-mechanics.md)
- [serena-index-vs-git-worktree](serena-index-vs-git-worktree.md)
