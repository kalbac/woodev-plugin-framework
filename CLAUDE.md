# CLAUDE.md — entry point for Claude Code

> **Read [AGENTS.md](AGENTS.md) first.** It carries the rules every agent follows: session
> start/end protocol, coding principles, conventions, the gotcha rule, the backlog rule.
> This file adds only what is **specific to Claude Code** — the MCP tooling and where to look
> things up. Nothing here restates AGENTS.md; if the two ever disagree, AGENTS.md wins.

## Serena MCP — mandatory for PHP

**Never use `Read` on a `.php` file.** Serena has the codebase pre-indexed and answers
symbolically — faster and more accurate than a raw file read.

**This is enforced, not best-effort (operator decision, s60):**

1. Verify at session start that Serena's tools are present (including deferred ones).
2. If they are missing — **report to the operator before any PHP work**. Do not silently fall back
   to `Read`; that is how sessions s45–s59 drifted.
3. Activate by **path**, not by name: `activate_project` with `D:/Projects/woodev_framework`.
   **That path is right only for a session working in the main tree.** A worker running in its own
   worktree must activate on ITS OWN path, and verify it took by checking that a `find_symbol`
   result reports a path under that worktree — otherwise its Serena edits land in the main tree
   while its git work happens in the worktree, and the work splits silently across two checkouts
   (gotcha `serena-activate-path-must-be-the-worker-s-worktree`, s83).
4. **Repeat this rule in every subagent brief that touches PHP — with the brief's own path
   substituted.** A rule that does not travel into delegated work is not in force (s68); a rule
   that travels with the wrong path is worse than absent (s83).

| Task | Tool |
|------|------|
| Find a class / function / method | `find_symbol` |
| Understand a file's structure | `get_symbols_overview` |
| Find who uses a symbol | `find_referencing_symbols` |
| Search a pattern across the codebase | `search_for_pattern` |
| Read source | `read_file` |
| Edit a function body | `replace_symbol_body` |
| Add code around a symbol | `insert_after_symbol` / `insert_before_symbol` |

`Read`/`Edit`/`Write` remain correct for non-source files (markdown, JSON, YAML, `.env`).

Dashboard: <http://localhost:24282/dashboard> · Docs: <https://oraios.github.io/serena/> ·
Known traps: gotchas `serena-replace-content-eol-flip`, `serena-index-vs-git-worktree`.

## Context7 MCP — before writing against any external API

Fetches current documentation for a library or framework instead of relying on training data.
Use it whenever the task touches WooCommerce, WordPress, React, wp-scripts, jest or any vendored
dependency — **read the reference before writing the implementation**, never recall an API
signature that is reachable.

Package: `@upstash/context7-mcp`.

## Orca — the runtime this session lives in

Sessions run inside the Orca app, so Orca owns worktrees, agent terminals and multi-agent
coordination. **Substantial work goes through Orca orchestration: worker = Sonnet 5, critic =
Codex, nobody accepts their own work.**

**⛔ Until 27.08.2026, Codex is CRITIC-ONLY** — not a worker, not a planner, not a scout. One
overnight session burned 45% of the weekly Codex allowance running it in every role at once
(operator decision, 21.08.2026). Cap the wave at **2–3 agents** and **2–3 rounds per card**; a card
still REJECTed after the third verdict needs decomposition or an operator decision, not a fourth
round.

The `orchestration` and `orca-cli` skills are installed globally, so they surface on their own —
invoke them rather than recalling a flag. Their guides are version-matched to the binary; a flag
remembered from a previous release is a guess. Recipe, placement rules and the traps that cost s83
real time: `docs-internal/wiki/orchestrating-agents-with-orca.md`.

Five project facts no skill knows, because they are ours:

1. **A fresh worktree needs NO install step.** `orca.yaml` shares `node_modules`,
   `.worktreeinclude` copies `vendor`, `plugins-reference` and the local config. Never put "run
   composer install and npm ci" in a brief — it is stale and wastes a worker's lap.
2. **Every subagent brief carries the WORKER's own worktree path** for Serena `activate_project`,
   never this repo's root, and requires the worker to verify the activation took. Copying the path
   from this file is how s83 split two workers' edits into the wrong tree, silently.
3. **`input_accepted` is not proof a worker started**, and for Codex the launch itself is not
   either: `worker-start --agent codex` produced a bare PowerShell terminal three times out of four
   in s84, and the shell executed the brief as a here-string. Codex takes four steps —
   `terminal create --command codex` → ESC the `codex-update-prompt` → `dispatch --inject` →
   `terminal send --text "" --enter` — and you read the buffer back after each
   (gotcha `starting-codex-under-orca-needs-four-steps-not-one`).
4. **Cap the wave at three agents.** At six, free RAM hit 0.4 GB of 15.3 and a starting Codex died
   on `VirtualAlloc`; even at three, `phpcs` failed in ways that read as code defects and jest
   OOM'd (gotcha `three-agents-is-the-concurrency-cap-on-this-machine`).
5. **A worker's green gate is not this tree's green gate.** A worktree can skip tests the primary
   checkout runs, and its `npm run build` can never match CI's. Generated bundles are built in the
   PRIMARY CHECKOUT only (gotchas `a-worktree-silently-skips-five-contract-tests`,
   `local-npm-run-build-is-not-assets-parity-evidence`). And `phpunit.xml` sets
   `executionOrder="depends,defects"`, so `rm -f .phpunit.result.cache` before every measurement or
   two runs of the same tree disagree.

Codex is launched through an Orca terminal (see fact 3 for the four steps), never through
`codex exec` — gotcha `codex-shell-sandbox-broken-windows`.

## Where to look things up

| Question | File |
|---|---|
| What did the last session leave me? | `docs-internal/next-session-prompt.md` |
| What state is the project in? | `docs-internal/CURRENT-STATE.md` |
| Has this trap been hit before? | `docs-internal/GOTCHAS.md` (index) → `gotchas/{slug}.md` |
| Where does this responsibility live? | `docs-internal/wiki/architecture.md` |
| Why was it built this way? | `docs-internal/adr/` |
| What may I break? | `docs-internal/adr/005-platform-v2-clean-break-policy.md` |
| How do I write a doc here? | `docs-internal/DOCS-SCHEMA.md` |
| Workflow + architecture rules | `docs-internal/AGENT-RULES.md` |
| Everything else | `docs-internal/DOCS-INDEX.md` |

## Claude Code specifics worth knowing here

- **JS tests must run from bash, not PowerShell** — PowerShell drops the `--roots` flag and the
  run reads as successful while the restriction never applied (gotcha
  `powershell-drops-the-roots-flag-from-the-jest-command`).
- **Rig probes go to the scratchpad, never into the repo** — a stray probe file once rode along in
  a commit.
- **Codex critic runs must be an inline bundle with a canary first line.** With its shell dead
  Codex does not report that it could not read a file — it fabricates the contents (gotcha
  `codex-shell-sandbox-broken-windows`).
