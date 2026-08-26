# gotcha: every fresh Orca worktree starts dirty with seven CRLF-only files — never `git add -A` there

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s84 (2026-08-21)

## What happens

A worker creates nothing, edits nothing, and `git status` in its brand-new worktree already shows:

```text
 M .github/ISSUE_TEMPLATE/2-feature-request.yml
 M .github/SECURITY.md
 M .github/labeler.yml
 M woodev/assets/js/admin/jquery.jquery-confirm.min.js
 M woodev/assets/js/admin/woodev-admin-job-batch-handler.js
 M woodev/assets/js/admin/woodev-admin-script.js
 M woodev/payment-gateway/assets/js/frontend/woodev-payment-gateway-frontend.js
```

Every one is 100% context — `git diff --numstat` reports identical insertion and deletion counts
(`68 68`, `105 105`, …) and git warns:

```text
warning: in the working copy of '.github/SECURITY.md', CRLF will be replaced by LF the next time
Git touches it
```

The primary checkout is clean. Only worktrees show it.

## Why it matters

A worker that finishes its task and runs `git add -A && git commit` ships 483 lines of pure
line-ending churn alongside its three-line fix. The diff becomes unreviewable, the PR looks like a
mass rewrite, and a reviewer cannot tell the real change from the noise.

## ✅ Correct

- **Every brief must say: never run `git add -A` in a worktree. Stage only the files you edited.**
  Both s84 workers that were told this reported back that they had left the seven files untouched
  and unstaged — the warning works.
- **Every critic brief must say the seven files are pre-existing and not a finding**, or the critic
  spends a paragraph on them.
- Reading another revision is safe and does not touch the tree: `git show <ref>:<path>`,
  `git diff`, `git log`, `git ls-files --eol`. `git checkout --` and `git add -A` in a live
  worktree are how another agent's work disappears.

## The same dirt also blocks worktree REMOVAL (s95)

`orca worktree rm --worktree branch:<name>` **refuses** to delete a worktree whose tree is dirty —
and every worktree is dirty, by this gotcha, the moment it is created. So the ordinary cleanup at
the end of a wave fails with what reads like a real warning:

```
ok: false
Failed to delete worktree at .../s95-critic-545.
 M woodev/assets/js/admin/jquery.jquery-confirm.min.js
 M woodev/assets/js/admin/woodev-admin-job-batch-handler.js
 M woodev/assets/js/admin/woodev-admin-script.js
 M woodev/payment-gateway/assets/js/frontend/woodev-payment-gateway-frontend.js
```

Those are the CRLF files, not work. `orca worktree rm --worktree branch:<name> --force` removes it.

**Read the listed paths before reaching for `--force`.** If the list contains anything other than
these known line-ending files, the worktree holds real uncommitted work and forcing it destroys
that work. `--force` is correct for THIS list and dangerous for any other.

## Related

- [two-agents-one-file-is-the-orchestrator-s-bug](two-agents-one-file-is-the-orchestrator-s-bug.md) — the loss that made tree-mutating git commands a standing worry
- [serena-replace-content-eol-flip](serena-replace-content-eol-flip.md) — the other line-ending trap in this repo, from the other direction
- `../wiki/orchestrating-agents-with-orca.md` — the brief template these rules belong in
