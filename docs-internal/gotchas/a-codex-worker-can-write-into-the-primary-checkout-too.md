# Gotcha: [tooling/parallel-agents] — a Codex worker can write a file into the PRIMARY checkout as well as its own worktree
> Tags: codex, orca, worktree, rig | Session: s181

## What happens

A coordinator `git pull --ff-only` in the Yandex plugin's primary checkout aborted: «Your local changes … would be overwritten».
`git diff` showed one edited file (`includes/lifecycle/class-yandex-test-mode-retirement.php`, a constant made `public`), modified at
04:34 — exactly while a Codex worker was making the same edit in ITS worktree for PR #96. Nobody else touched that tree.

## Root cause

Codex has no Serena and edits with its own shell/file tools; a brief that names the primary repo path anywhere (as a reference to
read, a sync source, a v1 copy) gives it an absolute path it can also write to. The worktree isolates the checkout, not the
filesystem. The primary checkout is the one the rig serves, so a stray edit there silently changes what the rig runs.

## ✅ Correct

- Before any `pull` or branch switch in a primary checkout, run `git status --short` and, for an unexpected change, compare it with
  `git diff origin/<base> -- <file>`: if it is byte-identical to merged work it is safe to drop (`git checkout -- <file>`), otherwise
  stop and find its owner.
- In Codex briefs say explicitly: «edit ONLY inside your worktree; every other path in this brief is read-only».

## Related

- [serena-activate-path-must-be-the-worker-s-worktree](serena-activate-path-must-be-the-worker-s-worktree.md) — the Serena variant of the same split
- [rig-serves-the-working-tree-branch-switch-reverts-fixes](rig-serves-the-working-tree-branch-switch-reverts-fixes.md)
