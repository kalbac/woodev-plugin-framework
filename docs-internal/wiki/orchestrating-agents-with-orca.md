# Orchestrating agents with Orca — Woodev Framework Wiki
> Compiled reference. Last compiled: 2026-08-20 (s83, first Orca capability pass).

This project runs inside the Orca app, so Orca's runtime — not raw `git worktree` or an ad-hoc
PTY — is the source of truth for worktrees, agent terminals and multi-agent coordination. This
article records **what we adopted and why**, not how the CLI works.

## The authority rule — never recall a flag

The `orca` binary serves its own version-matched guide. Read it, do not remember it:

```bash
orca skills get orca-cli        # worktrees, terminals, handoffs, built-in browser, artifacts
orca skills get orchestration   # runs, tasks, dispatch, worker_done, ask/reply, gates
```

Flags change between Orca releases. A cached copy in this repo would drift and be wrong in
exactly the way that is hardest to notice. Confirm the app is up with `orca status --json`
first, and pass `--json` on every agent-driven call.

## The adopted worker loop — worker Sonnet, critic Codex

The operator's standing shape for substantial work (s83): **the worker is Sonnet 5, the critic
is Codex, and the orchestrator is whoever runs the session.** Nobody accepts their own work.

```bash
orca orchestration run-create --objective "<what this whole batch is for>" --json
orca orchestration task-create --spec "<full brief>" --json
orca orchestration task-create --spec "<full brief>" --deps '["task_<upstream>"]' --json
orca orchestration worker-start --task <task_id> --worktree new-top-level \
  --name <slug> --agent claude --model sonnet --effort high --setup run --json
orca orchestration check --wait --types worker_done,escalation,question --timeout-ms 900000 --json
orca orchestration worker-release --dispatch <dispatch_id> --json
```

Why this rather than in-process subagents: the worker holds its own context in its own terminal,
and the orchestrator reads only the `worker_done` report. The failure mode the operator named at
the end of s82 — «контекст раздутый, нужна новая сессия» — is caused by pulling every worker's
transcript into the orchestrator's window. This shape does not do that.

`--model` and `--effort` are per-launch and are echoed back in the receipt under
`launch.requested` / `launch.effective`. **Read the receipt** — that is the proof the model you
asked for is the model that started, and it costs nothing.

Verify provenance before calling anything orchestrated:

```bash
orca orchestration task-list --brief --json
orca orchestration dispatch-show --task <task_id> --json
orca orchestration worker-read --dispatch <dispatch_id> --limit 50 --json
```

`worker-read` returns the worker's actual transcript, not a terminal scrape, when Orca can prove
the session. It is the cheap answer to "did that background agent really return a result" — a
question this project has been burned by before.

## Placement — the rule that comes from a real loss

Orca's own guidance is to keep workers in the current worktree and create a new one only when a
concrete filesystem conflict makes sharing unsafe. **In this repo that conflict is routine, so
state it and then create the worktree.** In s82 two agents edited
`class-location-provider-registry.php` at once and a `git checkout --` erased the other's
finished, uncommitted work (gotcha `two-agents-one-file-is-the-orchestrator-s-bug`).

The procedure, which is the orchestrator's job and not the workers':

1. Before starting a wave, name each worker's expected file set.
2. Any two workers whose sets overlap → separate worktrees, or serialize them with `--deps`.
3. Tell each worker which files a parallel worker owns, and that it must **report** an overlap
   rather than coordinate directly.

A worker cannot know what another worker is editing. Dispatching them into the same tree and
hoping is the orchestrator's bug, every time.

## Consequences of where Orca puts a worktree

`worker-start --worktree new-top-level` creates the checkout **outside the repository**, at
`C:/Users/maksi/orca/workspaces/woodev_framework/<name>`. Two things follow:

- The gotcha `jest-scans-agent-worktrees-inside-the-repo` does **not** apply to Orca worktrees.
  It was about `.claude/worktrees/` living inside the repo tree. The `--roots` discipline still
  stands for every other reason (`npx-jest-bypasses-wp-scripts-jsdom`).
- **The repo has no Orca setup hook** (`orca repo show` → `hookSettings.scripts.setup` is empty,
  and `worker-start` reports `hookFound: false`). A fresh worktree therefore has no `vendor/` and
  no `node_modules/`, so it cannot run a single gate until the worker bootstraps it with
  `composer install` and `npm ci`. Until that hook is configured, **say so in the brief** — a
  worker that discovers it alone wastes a lap.

## What we deliberately did not adopt

| Surface | Why not |
|---|---|
| `orca artifacts` (public HTML/MD links) | Publishing is gated behind a device setting a human must grant, and we have no audience for a public link. Deliverables stay in `docs-internal/`. |
| `orca automations` (scheduled prompts) | Nothing here is recurring. Sessions are operator-initiated. |
| `orca linear` | The backlog is GitHub Issues + board №6. Two trackers is worse than one. |
| `orca emulator` (iOS/Android) | No mobile surface in this project. |

The built-in browser (`orca goto` / `snapshot` / `console` / `network`) is an open candidate for
rig verification, not yet adopted. It returns an accessibility tree rather than screenshots,
which would be cheaper than the current chrome-devtools MCP path, and the operator can watch it
live inside Orca. It has not been measured against the rig yet — do not switch on the strength of
that argument alone.

## Traps

- **`terminal wait --for tui-idle` lies.** It counts an open dialog as idle. Check the
  `satisfied` field and read the buffer before sending with `--enter`, or the prompt answers a
  Codex update dialog instead of the task (this already happened once, s82).
- **A `check --wait` timeout is a checkpoint, not a failure.** Coding tasks routinely run 15–60
  minutes. Do not stop, close or restart a worker because it has not reported yet.
- **Ask before assuming a handoff.** «Hand off» / «give this to another agent» means ownership
  transfer, and orchestration lifecycle commands are wrong for it. Supervised orchestration is
  only for when someone actually waits on the result — which is the case for everything in this
  article.

## Related

- [two-agents-one-file-is-the-orchestrator-s-bug](../gotchas/two-agents-one-file-is-the-orchestrator-s-bug.md) — the loss this placement rule exists to prevent
- [codex-shell-sandbox-broken-windows](../gotchas/codex-shell-sandbox-broken-windows.md) — why Codex must be launched in an Orca terminal, not via `codex exec`
- [jest-scans-agent-worktrees-inside-the-repo](../gotchas/jest-scans-agent-worktrees-inside-the-repo.md) — the gotcha Orca worktrees sidestep
- [AGENT-RULES.md](../AGENT-RULES.md) — "Subagent-Driven Execution for Parallelism" points here
