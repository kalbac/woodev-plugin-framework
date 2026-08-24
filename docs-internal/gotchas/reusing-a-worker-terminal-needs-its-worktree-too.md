# Reusing an Orca worker terminal needs `--worktree` too, or the dispatch is rejected

**Namespace:** `[tooling/orca]` · **Discovered:** s89 (2026-08-24), dispatching round 2 to a worker
that had already reported `worker_done`

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
- `docs-internal/wiki/orchestrating-agents-with-orca.md`
