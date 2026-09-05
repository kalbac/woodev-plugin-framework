# Gotcha: [tooling/parallel-agents] — `worker-release` answers `ok: true, state: "retained"` and leaves the agent running

> Tags: tooling, orca, parallel-agents, cleanup | Session: s117

## What happens

You finish with a worker, release it, and the receipt looks like success:

```json
{ "ok": true, "result": { "state": "retained" } }
```

`ok: true` is the field the eye lands on. The agent is still alive — it keeps its process, its
terminal and its tab — and it is still there at the end of the session, which is when somebody else
notices it and you find out you never cleaned up.

Found in s117: the Codex critic was released after its `worker_done`, the receipt said
`retained`, and the terminal was still running an hour later when the operator pointed it out.

## Root cause

`retained` is not a synonym for released. It is Orca reporting that it did **not** own the process
and therefore took no action. The usual reason here is how the worker was started:

**`orchestration dispatch --inject` deliberately leaves the terminal unsupervised.** It creates no
`worker_dispatches` row, so `worker-stop` and `worker-abandon` never touch that process, and
`worker-release` settles the dispatch while reporting `retained` with `no_owned_resource`.

That path is easy to end up on without choosing it. `worker-start --agent codex` frequently fails
with `Agent startup blocked: codex-update-prompt` (gotcha
`starting-codex-under-orca-needs-four-steps-not-one`), and the documented recovery is exactly
`terminal create` → ESC → `dispatch --inject` → submit. So the fix for the START problem is what
produces the CLEANUP problem, and the two gotchas have to be read together.

Orca also retains — correctly — reused or pre-existing terminals, setup terminals, coordinators,
active workers, terminals the user took over, and identities it cannot prove. Retention is the safe
default; it is only a trap because the receipt reads like success.

## Fix

**Read `state`, not `ok`.** Only `released` means the terminal is gone:

```bash
orca orchestration worker-release --dispatch <id> --json   # -> check .result.state
```

If it says `retained` (or `release_pending` / `release_unknown` — for those, follow the recovery
action the receipt names), close the terminal yourself:

```bash
orca terminal read  --terminal <handle> --screen --json    # confirm nothing is in flight FIRST
orca terminal close --terminal <handle> --json
```

**And verify at session end rather than trusting the receipts.** One command, and it also catches a
worker whose release you never issued at all:

```bash
orca terminal list --json                       # expect only your coordinator (+ other projects')
orca orchestration worker-list --json           # expect no ready/running workers
orca orchestration task-list --brief --json     # expect no non-terminal tasks
```

⚠ Terminals belonging to ANOTHER project show up in that list too — match the worktree path before
closing anything, and never close the coordinator (its handle is in the `run-create` receipt as
`coordinator_handle`).

This is the same shape as `Stop-Process` reporting `completed, exit 0` for a background task: a
receipt that reads like the outcome you wanted while describing something else.

## Related

- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — the start problem whose fix creates this cleanup problem
- [reusing-a-worker-terminal-needs-its-worktree-too](reusing-a-worker-terminal-needs-its-worktree-too.md)
- [orca-worktree-rm-refuses-on-the-crlf-files-the-worktree-was-born-with](orca-worktree-rm-refuses-on-the-crlf-files-the-worktree-was-born-with.md) — the other half of session-end cleanup
