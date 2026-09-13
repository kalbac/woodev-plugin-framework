# Two machines — the Windows desktop and the macOS laptop

> Compiled reference. Last compiled: 2026-09-13 (s136). The first macOS session has now RUN: every
> line marked *measured s136* is a measurement, everything still marked unmeasured is a prediction —
> correct it when you measure it, and date the correction.

**The arrangement (operator, 13.09.2026):** the project is developed on a Windows desktop and on a
MacBook Pro M1 (Apple Silicon, arm64), **one at a time, never both at once**, switching as
circumstances require. It is not a migration: both machines stay first-class, so nothing here may
turn a Windows fact into a macOS one — each fact says which machine it is about.

## What travels, and by which path

| What | Path | Why |
|---|---|---|
| Code, docs, scripts, hooks, `phpstan.neon`, `.worktreeinclude`, `orca.yaml` | **git** (push on one, pull on the other) | the normal path; the only one that carries history |
| Rig database, container-only `mu-plugins/`, `WOODEV_*` constants in the container's `wp-config.php`, `.wp-env.override.json`, `composer.lock`, `plugins-reference/` | **`.machine-transfer/`** (gitignored), written by `scripts/machine/rig-state-export.sh`, read by `rig-state-import.sh` | not in git on purpose: secrets and personal data, and **the repository is public** |
| `node_modules/`, `vendor/`, Playwright browsers | **reinstalled** on each machine | native binaries are per platform (win32-x64 vs darwin-arm64) |
| `~/.claude/`, `~/.codex/`, Orca settings, `gh` login | **per machine, never copied over** | they belong to the user's account on that machine; the laptop already has its own |

The wp-env container prefix differs per machine — and, as s136 found, per wp-env VERSION too: a bare
32-char hash of the project path on the desktop (`de59f74e…`), `wp-env-<project-dir>-<8 hex>` under
11.15.0 on the laptop. Never paste either — `scripts/machine/rig-container.sh <role>` resolves it and
now matches both shapes.

## First setup on the laptop

1. **Get the repo.** Either copy the whole folder from the desktop, or `git clone` it and copy only
   `.machine-transfer/` in. The clone is lighter and cleaner (no Windows CRLF, no win32
   `node_modules`, no stale worktree registrations); the copy also works, because the setup script
   repairs all three.
2. **Run `scripts/machine/setup-macos.sh`** (`--install` to let it `brew install` what is missing).
   It checks the toolchain (PHP ≥ 8.1 with ext-sodium, composer, node ≥ 22, gh, docker, no wrong
   wp-cli on PATH), sets `core.hooksPath` and `core.fileMode=true`, re-checks-out CRLF text files,
   restores the bundle's gitignored files, reinstalls `node_modules` (a `.machine-platform` marker
   records which machine built it) and `vendor`, starts the rig and imports its state, then runs the
   gates and prints a summary. It ran clean end to end on 13.09.2026 (s136) once the five defects
   listed below were fixed, and it is idempotent — re-running it is how you check a repair.
3. **By hand, once** — all of this was done on 13.09.2026 (s136):
   - Orca: the repo is registered (`orca repo list`), worktree base path relative (`.orca/worktrees`).
     ⚠ `orca` off PATH is unreadable to an agent's shell here — see the table below.
   - Orca skills: `orchestration` and `orca-team` are installed under `~/.claude/skills/`.
   - MCP: this machine had **none** configured. Serena is the official plugin, installed at USER
     scope (`claude plugin install serena@claude-plugins-official --scope user`) and needs `uv`
     (`brew install uv`); `context7` was added as stdio `npx -y @upstash/context7-mcp`, `supermemory`
     as HTTP `https://mcp.supermemory.ai/mcp` with **no headers**, which needs one interactive OAuth
     login via `/mcp`. ⚠ **MCP binds at session start** — whatever you add surfaces in the NEXT
     session, not the one that added it.
   - Codex: set the model in `~/.codex/config.toml`.
4. **Re-measure, do not copy** — see the last section.

## Switching machines (either direction)

**Once both machines are set up, git IS the sync.** Nothing else travels routinely — not
`node_modules`, not `vendor`, not the rig, not `.machine-transfer/`. The steps below are the
`AGENTS.md` session-start and session-end items, seen from the two-machine side.

Leaving a machine:

1. **Commit and push every branch that carries work.** A local-only branch does not travel — this is
   the only way the other side sees it.
2. Close the agent terminals (`AGENTS.md` → Session End).

Arriving:

1. `git fetch --all --prune`, compare your local branches with `origin`, pull.
2. If the pull moved `package-lock.json` or `composer.lock` → `npm ci` / `composer install`.

That is the whole routine. The rig is NOT part of it: each machine keeps its own, and a seeded rig
rebuilt from the working tree is equivalent to the other machine's.

### The rig bundle is for one job only

`rig-state-export.sh` / `rig-state-import.sh` and the gitignored `.machine-transfer/` exist to carry
**manual rig configuration you cannot reproduce from the repo** — hand-set plugin options, the
`WOODEV_*` constants in the container's `wp-config.php`, container-only `mu-plugins/`, and the
gitignored `plugins-reference/`, `composer.lock`, `.wp-env.override.json`.

Use them **only when there is such state to move**, and then delete the bundle again once the
receiving side has imported it — it holds secrets and the repository is public. A first setup on a
new machine is the normal reason to make one; a day-to-day machine switch is not.

⚠ **Never copy the whole project folder BACK** from the laptop to the desktop — it would carry
darwin binaries in `node_modules`. After the first setup the only path is git.

## Where the platforms differ for an agent

| Topic | Windows desktop | macOS laptop |
|---|---|---|
| Shell the agent's Bash tool uses | Git Bash (MSYS) — rewrites `/var/...` args (`MSYS_NO_PATHCONV=1`), mangles Cyrillic in `curl` args, eats backslashes in heredocs | zsh/bash 3.2 — none of those; scripts in `scripts/machine/` avoid bash 4 features |
| Line endings | `core.autocrlf=true` in the system gitconfig → CRLF in the working tree for `text=auto` files | LF; a copied tree is repaired by the setup script |
| Orca `sharedDirectories` (`node_modules` in a worktree) | a directory **symlink** — `git worktree remove` can empty the primary checkout's `node_modules` | **also a symlink** (measured s136, Orca 1.4.200 — `orca.yaml` predicted a clone-copy and was WRONG): the same trap applies, the build-parity caveat applies, and it arrives UNTRACKED because `/node_modules/` is a directory pattern |
| Codex's tool shell under Orca | WSL bash on a Windows path; an Orca worktree's absolute `gitdir` is unreadable to it | native — expected to read worktrees directly (unmeasured) |
| Container prefix | `de59f74e…` (32 hex) | **different SHAPE, not just a different hash**: `wp-env-woodev-plugin-framework-5fd870b7` (wp-env 11.15.0) — measured s136. Always `rig-container.sh`; it now matches both |
| Starting the rig | `npx wp-env start` resolves to an installed binary | **`npx @wordpress/env start`** — the bare `wp-env` is a different, stub package here (measured s136) |
| PHP | 8.5.1, sodium OFF unless `-d extension=sodium` | **8.5.7 (Homebrew), sodium ON out of the box** — measured s136 |
| PHPStan | needs `--memory-limit=4G`; parallel worker segfaults | 4G run clean, no segfault — measured s136 |
| Temp dir | a real path | **`/var/…` is a symlink to `/private/var/…`** and PHP reflection reports the resolved form — measured s136 |
| `orca` on PATH | works | `/usr/local/bin/orca` is a root-owned `lrwx------` symlink an agent's shell cannot read (`Unable to determine Orca.app path`) — call `/Applications/Orca.app/Contents/Resources/bin/orca` (measured s136) |
| Agent concurrency cap | 3 (measured on 15.3 GB RAM) | unmeasured — start at 2–3 and re-measure (the laptop shows the same 15.66 GB to docker) |
| `run-local-ci` (the global CI rehearsal tool) | does not run natively; WSL only | still unverified — not exercised in s136 |

Gotchas that apply to only one OS carry a `> **Platform:**` or `> **Measured on:**` line under their
H1 (`DOCS-SCHEMA.md` → Gotcha Detail File Format). `grep -rl '\*\*Platform:\*\*' docs-internal/gotchas`
lists them.

## Baselines on the laptop — MEASURED s136 (13.09.2026)

Every gate was re-measured on the MacBook (PHP 8.5.7 Homebrew, sodium ON, node 22.22.3,
docker 29.4.0 arm64, `rm -f .phpunit.result.cache` first). **All of them match the desktop exactly**,
which is the useful finding: the numbers in `CURRENT-STATE.md` are not platform-dependent.

| Gate | Desktop (s134) | macOS laptop (s136) |
|---|---|---|
| unit / assertions / **SKIPPED** | 3901 / 9929 / **1** | **3901 / 9929 / 1** — identical |
| jest | 1973 in 32 suites | **1973 in 32 suites** — identical |
| integration (in `tests-cli`) | 195 / 719 | **195 / 719** — identical, ~2 min |
| `npm run build` git diff | zero | **zero** — cross-platform build parity HOLDS |
| phpcs (warning level on) / phpstan L3 / typecheck / `lint:*` | clean | **clean** |
| rig after import | WP 7.1, WC 11.1.0, 301 orders | **WP 7.1, WC 11.1.0, 301 orders**, `/` 200, `/wp-admin/` 302 |

⚠ **Two tests had to be fixed before that identity held** — `MixedFleetBootstrapGateTest` built a
fixture path from `sys_get_temp_dir()` and compared it to a reflected file path, which only agree
where the temp dir is not a symlink (gotcha
[on-macos-sys-get-temp-dir-is-a-symlink-so-a-reflected-path-never-matches-it](../gotchas/on-macos-sys-get-temp-dir-is-a-symlink-so-a-reflected-path-never-matches-it.md)).
The framework itself needed no change.

Still **unmeasured** on the laptop, and still worth doing: the agent concurrency cap, the Codex
launch recipe under Orca (`CLAUDE.md` → Orca, fact 3), `run-local-ci`, and whether an Orca worktree
there reproduces the build-parity caveat.

### What the first run of `setup-macos.sh` found (s136)

The script had never run on macOS. Five defects, all fixed in the same session:

1. **`grep -q` under `set -o pipefail`** reported a SUCCESSFUL wp-cli version match as a failure and
   advised uninstalling the very version the gate pins → gotcha
   [grep-q-under-pipefail-turns-a-successful-match-into-a-failed-pipeline](../gotchas/grep-q-under-pipefail-turns-a-successful-match-into-a-failed-pipeline.md).
2. **`npx wp-env` fetched a stub package**, exited 0 and started nothing; the rig was simply absent
   and the error blamed docker → gotcha
   [npx-wp-env-installs-a-stub-package-not-wordpress-env](../gotchas/npx-wp-env-installs-a-stub-package-not-wordpress-env.md).
   All four `scripts/machine/*.sh` now say `npx @wordpress/env`.
3. **`rig_container()` matched only the old 32-hex container prefix**, so every rig script died with
   "wp-env is not running" against a healthy rig. It now accepts both shapes.
4. **`chmod +x scripts/machine/*.sh` flipped `lib.sh`'s mode** — it is sourced, never run, and is
   committed `100644`, so with `fileMode=true` the tree came out dirty on a fresh clone.
5. **The ext-sodium check had defect 1 too, and there it was FLAKY** — run one said `sodium ok`, run
   two said `MISSING`, same machine, minutes apart. It now asks PHP directly
   (`php -r 'exit( extension_loaded( "sodium" ) ? 0 : 1 );'`), with no pipe to break.

## Related

- [local-rig.md](local-rig.md) — the rig itself: carriers, options, containers, the integration command
- [orchestrating-agents-with-orca.md](orchestrating-agents-with-orca.md) — worktrees, sharedDirectories, the Codex launch
- [../gotcha-index/tooling.md](../gotcha-index/tooling.md) — most platform-specific traps live under `[tooling/*]`
