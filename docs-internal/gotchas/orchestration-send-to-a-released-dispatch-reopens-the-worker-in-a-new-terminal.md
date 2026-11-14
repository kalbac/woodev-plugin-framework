# Gotcha: [tooling/orchestration] — `orchestration send --to dispatch:<released>` REOPENS the released worker in a new terminal, next to the fresh worker already in that worktree

> Tags: orca, orchestration, dispatch, worktree | Session: s230 (12.11.2026, MacBook, Claude Sonnet 5.5 workers)

## What happens

A first worker finished and was released; a fresh worker was then started in the same worktree for the next round. The coordinator
sent a follow-up to the OLD dispatch id with `orchestration send --to dispatch:<released>`. Instead of an error, Orca **reopened the
released worker in a new terminal**. Two agents now edited one tree at the same time, and the reopened one committed a merge that
dropped 45–46 msgids from `.pot` / `.po` — a result that still looked well-formed.

## Root cause

A released dispatch is not necessarily a dead one: a send to it can resume the worker, with its old context, in a terminal of its own.
Nothing tells that worker a successor owns the worktree. (The older gotcha below records `dispatch_inactive` for a settled dispatch;
for a RELEASED Claude worker the send was instead accepted and the worker came back.)

## Fix

❌ Wrong — a follow-up, a nudge or a fix request to the old dispatch id once a fresh worker exists:

```bash
orca orchestration send --to dispatch:<released-id> --subject "also fix X" --body "…"
```

✅ Correct:

- Never send to a released dispatch. Give the follow-up to the worker that currently owns the worktree, or start a fresh worker with
  the follow-up in its brief.
- Before any send, check `orca orchestration worker-list` and `orca terminal list --json`: if the target's terminal is gone or
  released, it is not a recipient.
- If two agents already touched one tree: stop both, `git log` / `git diff` the tree against the last known-good commit, and
  recount catalogue entries (see the related `.po` gotcha) before trusting any commit.

## Related

- [orchestration-send-to-a-dispatch-fails-once-the-worker-has-sent-worker-done](orchestration-send-to-a-dispatch-fails-once-the-worker-has-sent-worker-done.md) — the other outcome of the same command: `dispatch_inactive` on a settled dispatch
- [a-terminal-send-to-a-settled-codex-worker-starts-new-unsupervised-work](a-terminal-send-to-a-settled-codex-worker-starts-new-unsupervised-work.md) — the Codex sibling: a message to a finished worker starts unsupervised work
- [a-po-merge-that-drops-obsolete-entries-still-looks-well-formed](a-po-merge-that-drops-obsolete-entries-still-looks-well-formed.md) — the damage the reopened worker's merge did, and the count that catches it
