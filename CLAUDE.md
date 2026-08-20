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

The `orchestration` and `orca-cli` skills are installed globally, so they surface on their own —
invoke them rather than recalling a flag. Their guides are version-matched to the binary; a flag
remembered from a previous release is a guess. Recipe, placement rules and the traps that cost s83
real time: `docs-internal/wiki/orchestrating-agents-with-orca.md`.

Three project facts no skill knows, because they are ours:

1. **A fresh worktree needs NO install step.** `orca.yaml` shares `node_modules`,
   `.worktreeinclude` copies `vendor` and the local config. Never put "run composer install and
   npm ci" in a brief — it is stale and wastes a worker's lap.
2. **Every subagent brief carries the WORKER's own worktree path** for Serena `activate_project`,
   never this repo's root, and requires the worker to verify the activation took. Copying the path
   from this file is how s83 split two workers' edits into the wrong tree, silently.
3. **`input_accepted` is not proof a worker started.** Read its buffer once, early; if the prompt
   sits there unsubmitted, send `orca terminal send --terminal <handle> --text "" --enter`. Every
   Codex launch in s83 needed it.

Codex is launched through orchestration (`worker-start --agent codex`) or an Orca terminal, never
through `codex exec` — gotcha `codex-shell-sandbox-broken-windows`.

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
