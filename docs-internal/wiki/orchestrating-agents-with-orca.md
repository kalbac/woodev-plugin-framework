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

**Then verify the worker actually began.** The receipt's `stage: input_accepted` only means Orca
handed the text to the terminal. Three of five agents in s83 sat with the prompt queued and
unsubmitted while `worker-show` reported `ready` and `dispatched` — indistinguishable from a
worker thinking hard, and it burned two full wait windows. Read the buffer once, early, and look
for real activity; if the prompt is sitting there as `[Pasted Content N chars]`, submit it with
`orca terminal send --terminal <handle> --text "" --enter --json` (gotcha
`input-accepted-is-not-proof-a-worker-started`).

**Reusing a settled worker for a follow-up** — which is how a critic's findings get fixed by the
agent that wrote the code — needs BOTH flags, not just the handle:

```bash
orca orchestration worker-start --task <new_task_id>   --worktree id:<repoId>::<thatWorkersWorktreePath>   --terminal <handle> --json
```

`--terminal` alone resolves `--worktree` to the coordinator's own worktree and fails with
`terminal_worktree_mismatch`. Note also that `--model`/`--effort` cannot combine with `--terminal`:
a reused terminal keeps the model it launched with, so a follow-up cannot silently change tiers.

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

**The worktree isolates the checkout, not the filesystem.** A worker can still `cd` into the main
tree, and — the trap that actually fired in s83 — a brief that repeats `CLAUDE.md`'s Serena rule
verbatim points `activate_project` at `D:/Projects/woodev_framework`, so every Serena edit lands in
the main tree while the worker's git work stays in its worktree. Two of three workers hit it. Every
brief must carry **the worker's own worktree path** plus an instruction to verify activation took
(gotcha `serena-activate-path-must-be-the-worker-s-worktree`).

## Worktree layout — configured, not accidental

Agent worktrees live at **`orca/worktrees/`, inside the project** (operator preference, s83), on the
same volume as the repo. Set per-repo, because there is no settings CLI:

```bash
orca project setup-update --setup <repoId> \
  --worktree-base-path D:/Projects/woodev_framework/orca/worktrees --json
```

The global default (`workspaceDir`, Settings → General) still points at `C:/Users/maksi/orca/workspaces`
and applies only to repos without their own base path.

**Why same-volume matters:** shared directories are materialised as symlinks on Windows, and
directory symlinks across volumes need Developer Mode or elevation.

**Why inside the repo is safe here — measured, not assumed.** Every gate is path-scoped, so none of
them sees a worktree: phpcs reads `./woodev`, phpstan `paths: woodev`, phpunit `./tests/unit`. With
a worktree in place the main tree still reports phpcs clean, phpstan clean and 2475 unit tests —
not one test double-counted. `/orca/worktrees/` is gitignored, and Serena honours that through
`ignore_all_files_in_gitignore: true`, so it does not index them either. The one real cost:
`.wp-env.json` maps the whole repo root (`"woodev-framework": "."`), so worktrees do land inside the
rig container. WordPress will not load them as plugins — those come from explicit mappings — but
each worktree's 76 MB `vendor` copy sits inside the project directory.

**Still run jest as `npm run test:js -- --roots "<rootDir>/tests/js"`.** A bare `npx jest` would
scan worktrees wherever they live, and it loses the wp-scripts jsdom environment regardless
(gotchas `jest-scans-agent-worktrees-inside-the-repo`, `npx-jest-bypasses-wp-scripts-jsdom`).

## A fresh worktree is gate-capable immediately

Two checked-in files make a new worktree runnable the moment it exists, with **no install step**:

- **`orca.yaml`** → `worktree.sharedDirectories: [node_modules]` — symlinked, so the 658 MB tree is
  never copied. Entries must exist in the primary checkout **and** be gitignored, or Orca skips them
  silently.
- **`.worktreeinclude`** → `vendor`, `.mcp.json`, `.wp-env.override.json`,
  `.claude/settings.local.json` — **copied**, so each worktree owns them.

`vendor` must be copied and never shared: Composer bakes `$baseDir = dirname(dirname(__DIR__))` into
its autoloader and PHP resolves a symlink to its real path, so a shared `vendor` makes the classmap
load the primary checkout's sources while the bootstrap loads the worktree's — every class declared
twice (gotcha `sharing-vendor-breaks-composer-autoload-in-a-worktree`).

Measured on a throwaway worktree: **2475 unit / 6112 assertions, 1260 jest, phpstan clean, zero
installs** — against **411 seconds** for a `composer install` there.

## What we deliberately did not adopt

| Surface | Why not |
|---|---|
| `orca artifacts` (public HTML/MD links) | Publishing is gated behind a device setting a human must grant, and we have no audience for a public link. Deliverables stay in `docs-internal/`. |
| `orca automations` (scheduled prompts) | Nothing here is recurring. Sessions are operator-initiated. |
| `orca linear` | The backlog is GitHub Issues + board №6. Two trackers is worse than one. |
| `orca emulator` (iOS/Android) | No mobile surface in this project. |

The built-in browser (`orca goto` / `snapshot` / `console` / `network`) was **tried once against
the rig and not adopted.** The argument for it is real — it returns an accessibility tree rather
than screenshots, which is cheaper than the chrome-devtools MCP path, and the operator can watch
it live inside Orca. But the first `snapshot` of a WordPress admin page returned
`runtime_unavailable` ("the Orca runtime closed the connection before responding"). The runtime
recovered on its own and the two live workers were unaffected, so this is one data point, not a
verdict on the feature — it was not investigated further, because debugging the browser while the
same runtime carries running workers is the wrong trade. **Rig verification stays on
chrome-devtools MCP against `:8973`.** Anyone who wants to revisit this should do it with no
workers in flight.

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
