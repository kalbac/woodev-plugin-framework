# Orchestrating agents with Orca — Woodev Framework Wiki
> Compiled reference. Last compiled: 2026-08-27 (s100: four measured recipe fixes — pointer-not-paste,
> the canary/one-line-body conflict, `worker-release` leaving a terminal spent, and briefing a fresh
> worker into an existing worktree). Earlier passes: s83 (first capability pass), s97, s99.

This project runs inside the Orca app, so Orca's runtime — not raw `git worktree` or an ad-hoc
PTY — is the source of truth for worktrees, agent terminals and multi-agent coordination. This
article records **what we adopted and why**, not how the CLI works.

## The authority rule — never recall a flag

**The `orchestration`, `orca-cli` and `computer-use` skills are installed globally** (s83, via
`orca skills install --skill <name> --agent claude-code`), so they surface in every session on
their own and are the native way in. Invoke the skill; do not recall a flag.

Before that install only `orca-cli` was present, which is why s83 had to fetch the orchestration
guide by hand and why a fresh session would not have known orchestration existed at all. If a
future session cannot see them, re-run that install rather than working around it.

The same guides are also printable, which is useful inside a worker or a script:

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

Agent worktrees live at **`.orca/worktrees/`, inside the project** (operator decision, s83), on the
same volume as the repo. He set it **globally and relatively** in Settings → General → Worktree
directory as `.orca/worktrees`, so every repo gets the same layout inside itself. The per-repo
override is set to the same relative value so the two cannot drift:

```bash
orca project setup-update --setup <repoId> \
  --worktree-base-path D:/Projects/woodev_framework/orca/worktrees --json
```

Passing an empty string does NOT clear a per-repo override — set it to the value you want instead.

**Why same-volume matters:** shared directories are materialised as symlinks on Windows, and
directory symlinks across volumes need Developer Mode or elevation.

**Why inside the repo is safe here — measured, not assumed.** Every gate is path-scoped, so none of
them sees a worktree: phpcs reads `./woodev`, phpstan `paths: woodev`, phpunit `./tests/unit`. With
a worktree in place the main tree still reports phpcs clean, phpstan clean and 2475 unit tests —
not one test double-counted. `/.orca/` is gitignored, and Serena honours that through
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

## What s84 added — three agents, four launch steps, two lying gates

The s83 recipe above is still right. s84 ran twelve dispatches through it and found four things it
did not say.

**Codex is critic-only until 27.08.2026** (operator decision, 21.08.2026): s84 burned 45% of the
weekly Codex allowance in one night by running it as worker, planner and critic simultaneously. The
"use Codex proactively as a second worker" rule resumes after that date, under the caps below.

**Cap rounds per card at two, three at the outside.** s84 put six worker rounds and six critic
passes into #395 and still did not close it. The third REJECT is the signal to stop and hand the
operator the fork, not to brief a fourth round.

**Cap the wave at three agents.** With six live, free RAM hit 0.4 GB of 15.3 GB and a starting
Codex died on `VirtualAlloc`. Even at three, `composer phpcs` OOM'd for one agent and blamed five
innocent files for another (failed `shell_exec()` syntax checks read as PHPCS internal exceptions),
and jest died with `Fatal process out of memory`. A Codex terminal starts about eleven MCP servers
of its own, so it is much heavier than a Claude one. Release AND close settled workers before the
next wave, not at the end — and note that `worker-release` refuses with
`retained / user_takeover` for any terminal the coordinator wrote to, which is every Codex worker.
Gotcha: `three-agents-is-the-concurrency-cap-on-this-machine`.

**Launching Codex takes four steps.** `worker-start --agent codex` produced a bare PowerShell
terminal three times out of four, and the injected brief was executed by the shell as a here-string
until it hit a ParserError. The reliable path is `terminal create --command codex` → ESC the
`codex-update-prompt` that `tui-idle` reports → `dispatch --inject` → `terminal send --text "" --enter`
because the brief arrives as `[Pasted Content N chars]` and sits unsubmitted. After a `worker-stop`
the task goes to `blocked`; `task-update --status ready` before redispatching. Gotcha:
`starting-codex-under-orca-needs-four-steps-not-one`.

**Two gates read green in a worktree and are not.** Five `Contract/Yandex*` tests SKIP there
because `plugins-reference/` is gitignored (fixed in s84 by adding it to `.worktreeinclude`), and
`npm run build` in a worktree can never match CI, because webpack resolves the shared
`node_modules` symlink out of the project and emits `../../../../node_modules/...` module requests,
which changes every content hash. PR #422 went red on assets parity after two agents had
independently measured zero diff. Generated bundles must be rebuilt in the primary checkout
(`git checkout --detach origin/<branch>`, build, push, then `git checkout main`). Gotchas:
`a-worktree-silently-skips-five-contract-tests`, `local-npm-run-build-is-not-assets-parity-evidence`.

**Every brief needs four standing lines.** They earned their place: every agent told about the
memory pressure reported its OOM honestly instead of claiming a green gate, and every agent told
about the CRLF churn left the seven dirty files unstaged.

1. Serena `activate_project` on YOUR worktree — get it from `git rev-parse --show-toplevel`, then
   verify a `find_symbol` result reports a path under it.
2. No install step. `vendor` and `node_modules` are already there.
3. Never `git add -A` — this worktree starts dirty with seven CRLF-only files.
4. The machine is shared and low on memory; if a gate OOMs, say so and retry once — never report a
   gate green whose aggregate result you never saw, and never substitute `npx jest`.

A fifth line is worth its weight on anything non-trivial: **you are licensed to contradict this
brief; if the code disagrees, the code wins.** In s84 it produced a correction in almost every
report — a third AJAX handler with the same hole, a preflight check that existed but was never
consumed, and a "the brief's baseline is stale" that turned out to be the skipped-test gap above.

## kilo as the critic — the recipe that actually returns an event (s97)

Codex was unreachable for a stretch (unpaid subscription), and kilo — reached through the same
Kilo Gateway balance — took the critic seat. Getting it to behave as a supervised Orca worker took
a full elimination pass; this is the outcome, so nobody repeats it.

**Orca cannot detect that kilo's TUI accepted a prompt.** `dispatch --inject` types the brief,
kilo receives it and works — and Orca, seeing no signal it recognises, declares
`agent_prompt_stalled`, settles the dispatch as failed and **revokes the capability**. Eight to
twelve seconds later the worker finishes and its `worker_done` is rejected:

```
$ orca orchestration send ... --type worker_done ... --outcome succeeded
Dispatch ctx_c6a9d9f6223a capability is revoked.
```

Reproduced three times. Delivery succeeded every time; detection failed every time. Ruled out by
measurement, not by guessing: `--timeout-ms 240000`, an explicit `terminal wait --for tui-idle`,
a fresh terminal, kilo 7.5.5, `autoupdate: false`, and re-injecting into an already-running
terminal. None of them changes it.

**And it is not the launch method.** The operator created a worktree with the Kilocode agent from
Orca's own UI — the one path an agent cannot take, and therefore the only real control available.
It behaved identically to `worker-start --agent kilo`: recognised as an agent, `--inject` returned
`agent_prompt_stalled`, the worker read the brief, worked 17.7 s, reported correctly, and the
capability had already been revoked. Do not go looking for a better launch incantation.

**The recipe: dispatch WITHOUT `--inject`.** Orca then makes no judgement about delivery, never
revokes the capability, and a real `worker_done` arrives on `check --wait` — verified, payload
free of `_orcaLifecycleRejection`.

```bash
orca terminal create --worktree <sel> --command "kilo --model luna"
orca terminal wait --terminal <H> --for tui-idle --timeout-ms 90000
# READ THE BUFFER. The first send is lost while the welcome screen paints; re-send if the
# prompt box is still empty. Same house rule as Codex: read back after every step.
T=$(orca orchestration task-create --spec "…")
D=$(orca orchestration dispatch --task $T --to <H>)          # <- no --inject
orca terminal send --terminal <H> --text "<brief> + the literal worker_done line carrying $T and $D" --enter
orca orchestration check --wait --types worker_done --timeout-ms 180000
# check replays the oldest FIFO batch until acknowledged:
orca orchestration check --ack <deliveryId> --json
```

**The `worker_done` line MUST carry `--payload '{"taskId":"..."}'`** (s99). The command as written
above delivers the body fine, but Orca answers `Rejected worker_done: worker_done requires taskId`
and wraps the report in a rejection envelope. The findings still arrive and are still readable —
which is exactly why this is easy to miss — but the task never settles. Put the payload in the
literal line you hand the critic:

```bash
orca orchestration send --type worker_done --to dispatch:<D>   --subject "..." --body "<findings>" --payload "{\"taskId\":\"<T>\"}" --outcome succeeded
```

**`check --wait` replays the oldest UNACKNOWLEDGED batch first** (s99). Waiting for a new critic's
report will hand you the PREVIOUS one again, verbatim, if you never acked it — and it looks like
the new agent answered instantly with someone else's findings. Check the `subject`, which carries
the task id. Ack with `orca orchestration check --ack <deliveryId>` and wait again.

**`check --wait --json` does not emit one JSON document.** It streams `{"_keepalive":true,...}`
lines every 15 s and the real object last, so `json.load()` on the whole output fails with
"Extra data". Strip the keepalive lines, or parse only the final object.

Four facts that cost time, each measured:

1. **The agent id is `kilo`,** not `kilocode` — Orca's settings label it "Kilocode" but the CLI
   takes the command. `--agent kilocode` → `agent_unconfigured`.
2. **`--model` never reaches kilo.** `worker-start --help` says the flag serves Claude, Codex and
   Cursor; for kilo the receipt returns `launch.effective.model: null`. Pin it with `--command`,
   or put it in Orca → Settings → Agents → Kilocode → Arguments. Without a pin kilo starts on its
   LAST USED model — which is how a worker silently ended up on the `openai/…` route whose OAuth
   token had expired, while `kilo/openai/…` on the Kilo Gateway worked fine. **Always name the
   provider prefix.**
3. **Operator rule: prefer a discounted variant.** `kilo models | grep -i discount`. On
   27.08.2026 there was exactly one, and the status bar confirms it: `GPT-5.6 Sol (50% off)`.
4. **`"autoupdate": false` in `~/.config/kilo/kilo.jsonc`** removes the startup update dialog that
   otherwise swallows the injected brief. Three tasks circuit-broke on it before it was found.

**Cost — the number that actually moved the decision.** The `$0.01–0.03` first recorded here was
a round over a SMALL task. A round over a large PR is **$1.5–3.5**, and across one evening plus one
day (s99) sol-discounted totalled **$10** — half the monthly cost of the operator's Codex
subscription. Operator decision 27.08.2026: **the model is `luna`**, far cheaper even without a
discount. Measure against the bill, not against a per-round estimate taken on the smallest task
that was handy.

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
- **Hand a critic a POINTER, never a pasted brief (s100).** Sending a 5.6 KB brief with
  `terminal send --text "<the whole brief>" --enter` reached the PTY and echoed into the
  scrollback, but never submitted — three sends in a row sat unconsumed while kilo's welcome screen
  was still painting. Writing the brief to a scratchpad file and sending a 216-byte
  `Read the file <path> in full and do exactly what it says` submitted first time, every time
  afterwards. Four critics in s100 ran this way; the one exception still needed one re-send.
  **Always read the buffer back:** an idle footer with the welcome tips still visible means the
  prompt was NOT consumed, and re-sending the same one-liner fixes it.
- **Require the canary IN THE REPORT BODY, and say so explicitly (s100).** A brief that says «say
  CANARY as the first word of your report» AND «write `--body` as a single line» conflicts, and the
  critic drops the canary — which reads exactly like the fabrication the canary exists to catch. It
  happened once in s100: the canary was absent, the critic had in fact read the file (it quoted the
  word back when asked), and the review was sound. Cost: one round-trip and a re-verification.
  Word the instruction so the canary survives a one-line body.
- **`worker-release` reporting `retained` still leaves the terminal unusable (s100).** Releasing two
  settled workers returned `state: retained, reason: identity_unproven, processAction: none` — which
  reads as «nothing was touched». Both agent terminals were gone from `terminal list` moments later,
  and the next `worker-start --terminal <that handle>` failed with `terminal_not_writable`. Treat a
  released dispatch's terminal as spent regardless of what the receipt says: start the follow-up with
  `--worktree <the exact existing worktree> --agent claude`, which creates a fresh terminal in the
  same checkout and reruns no setup.
- **A fresh worker in an existing worktree needs its brief adjusted.** «You are already activated on
  this worktree» is false for it, and so is «start from origin/main» — its branch is checked out with
  the previous rounds committed. Tell it to read `git log --oneline origin/main..HEAD` first, or it
  may re-derive work that is already there.
- **Do not `worker-release` a dispatch you are still sending work to (s100).** A tiny follow-up sent
  with `terminal send` onto an ALREADY-SETTLED dispatch's ids looks like it works — the worker does
  the job and pushes the commit — but releasing that dispatch revokes its capability, so the later
  `worker_done` comes back as `Rejected worker_done: … capability is revoked`
  (`_orcaLifecycleRejection: dispatch_capability_invalid`). The report body is still readable inside
  the rejection envelope, which is exactly why this is easy to miss. Either create a NEW task and
  dispatch for the follow-up, or hold the release until the worker has reported. Same family as the
  kilo `--inject` revocation, different cause.

### s101 — three traps, all measured

- **`worker-start` can answer `input_accepted` and still not start the worker.** One of three
  died that way: `dispatch-show` reported `status: failed`, `last_failure:
  agent_prompt_stalled`, and a `completed_at` ten seconds after `dispatched_at`. The receipt says
  nothing about it. **Read `dispatch-show` after every `worker-start`**, not the acceptance
  status — I did not, and waited ten minutes on a corpse. Recover with
  `worker-start --retry-of <old ctx>`; it allocates a fresh dispatch on the same task.
- **`check --wait --timeout-ms` above 600000 is pointless from a Bash tool call**, which caps at
  ten minutes and kills the call. Use ≤ 540000 and chain several waits; a timeout is a
  checkpoint, not a failure.
- **Three workers CAN share ONE worktree** — proven twice in s101, six workers total — provided
  their file sets are disjoint AND the brief forbids them git commands and gate runs. The
  coordinator runs the gates. That last clause is what keeps this inside the three-agent RAM cap:
  the OOM in s84 came from concurrent heavy gate runs, not from the agents themselves.

## Related

- [two-agents-one-file-is-the-orchestrator-s-bug](../gotchas/two-agents-one-file-is-the-orchestrator-s-bug.md) — the loss this placement rule exists to prevent
- [codex-shell-sandbox-broken-windows](../gotchas/codex-shell-sandbox-broken-windows.md) — why Codex must be launched in an Orca terminal, not via `codex exec`
- [jest-scans-agent-worktrees-inside-the-repo](../gotchas/jest-scans-agent-worktrees-inside-the-repo.md) — the gotcha Orca worktrees sidestep
- [AGENT-RULES.md](../AGENT-RULES.md) — "Subagent-Driven Execution for Parallelism" points here
