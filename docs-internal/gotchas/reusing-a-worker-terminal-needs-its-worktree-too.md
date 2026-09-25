# `worker-start` needs `--worktree` on BOTH sides: a name for a new one, the terminal's own for reuse

**Namespace:** `[tooling/orca]` · **Discovered:** s89 (2026-08-24), dispatching round 2 to a worker
that had already reported `worker_done` · **Extended:** s138 (2026-09-25), Orca 1.4.211 on macOS —
the `name:` selector, the `--name` requirement for a new worktree, and the `worktree rm` refusal

## The trap

The orchestration guide's follow-up recipe reads:

```bash
orca orchestration worker-start --task <next_task_id> --terminal <handle> --json
```

Run exactly that from a coordinator sitting in the PRIMARY checkout and it fails:

```
terminal_worktree_mismatch
Terminal term_02f9… does not belong to worktree cb27dca8-…::D:/Projects/woodev_framework.
```

`worker-start` resolves the worktree from the COORDINATOR's own directory when `--worktree` is
absent, then checks the named terminal against it. The worker's terminal lives in the worker's
worktree, so the check fails — even though `--terminal` alone unambiguously identifies where that
terminal is.

## ❌ Wrong

```bash
orca orchestration worker-start --task task_65489f1f7f1c \
  --terminal term_02f91139-d747-49fe-b98c-b3cafd34f7a1 --json
```

## ✅ Correct

Pass the worker's own worktree selector alongside the handle. Both come out of
`worker-show --dispatch <id> --json` (`worker.worktree_id`, `worker.agent_terminal_handle`):

```bash
orca orchestration worker-start --task task_65489f1f7f1c \
  --worktree "id:cb27dca8-…::D:/Projects/woodev_framework/.orca/worktrees/woodev_framework/s89-488-resolve-key" \
  --terminal term_02f91139-d747-49fe-b98c-b3cafd34f7a1 --json
```

## s138: the shorter selector, and the same flag failing from the other side

**The selector does not have to be the `id:` monster.** `--worktree name:<worktree-name>` is accepted
and is what a coordinator actually has to hand — the name it passed when the worktree was created:

```bash
ORCA orchestration worker-start --spec "…" \
  --terminal term_624e7d15-e225-44a8-8352-c43dffcfa8fb --worktree name:s138-card-907 --json
```

Re-measured on Orca 1.4.211: the rejection is unchanged, `terminal_worktree_mismatch`, and it still
names the COORDINATOR's worktree rather than the terminal's, which reads as if the terminal were
orphaned. It is not.

⚠ **A reused terminal keeps its own cwd.** If the follow-up task is about a DIFFERENT branch — a
critic reviewing another worker's worktree, say — the spec must hand it absolute paths. Otherwise it
looks for the diff where it is standing and finds nothing, and a probe that finds nothing "passes".

**The same flag, the other failure: creating a worktree without naming it.**

```bash
worker-start --spec "…" --worktree new-child --agent claude --model claude-sonnet-5
# → invalid_argument: New worktrees require --name.
```

So `--worktree` is never satisfied by a default in either direction: Orca will not invent a name for
a new worktree, and will not infer an existing one from `--terminal`.

## s138: `worktree rm` refuses a dirty worktree, and one file is enough

Cleanup fails the same way — loudly, with the file named:

```text
worktree rm --worktree name:s138-cards-910-912
# → runtime_error: Failed to delete worktree at … . M .serena/project.yml
```

Restore or commit it first (`git -C <worktree> checkout -- <file>`). The likely culprit is not the
worker: a Serena upgrade regenerates `.serena/project.yml` on its first activation in that worktree
(see Related). Check every worktree before the session-end sweep, not just the one you remember
editing.

**And `worker-release` on a dispatch whose terminal was later reused answers `retained` /
`ownership_transferred`.** That is correct bookkeeping, not a leaked resource — the reuse dispatch
owns the terminal, and releasing THAT dispatch is what closes it. Do not reach for `terminal close`
on the strength of the word "retained".

## Why it matters beyond the error message

The failure is loud, so it costs a retry rather than correctness — but the fix is what makes
multi-round work on one card possible at all. Handing a follow-up task to the SAME terminal keeps
the worker's context: it already knows the file it wrote, the reasoning it chose and what it
rejected. Starting a fresh worker for round 2 throws that away and pays for the re-reading twice.
Rounds 2 and 3 of #488 both landed this way in s89.

## Related

- [orca-worktree-create-base-branch-takes-the-local-ref](orca-worktree-create-base-branch-takes-the-local-ref.md)
- [dispatch-inject-reports-failure-after-succeeding](dispatch-inject-reports-failure-after-succeeding.md)
- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md)
- [serena-activate-project-now-requires-a-session-id](serena-activate-project-now-requires-a-session-id.md) — where the modified `.serena/project.yml` that blocks `worktree rm` comes from
- `docs-internal/wiki/orchestrating-agents-with-orca.md`
