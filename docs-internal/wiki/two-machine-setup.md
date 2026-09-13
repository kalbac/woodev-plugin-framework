# Two machines — the Windows desktop and the macOS laptop

> Compiled reference. Last compiled: 2026-09-13 (s135). Written before the first macOS session, so
> every macOS statement below that is not marked *measured* is a prediction — correct it on the first
> run and date the correction.

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

The wp-env container prefix (`de59f74e…` on the desktop) is a hash of the project's ABSOLUTE path,
so it differs per machine. Never paste it — `scripts/machine/rig-container.sh <role>` resolves it.

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
   gates and prints a summary.
3. **By hand, once:** add the folder to Orca (the worktree base path is the relative
   `.orca/worktrees`, verified s135, so it needs no change); install the Orca skills
   (`orca skills install --skill orchestration --agent claude-code`, likewise `orca-cli`,
   `computer-use`); check `/mcp` shows Serena and Context7 connected; set the Codex model in
   `~/.codex/config.toml`.
4. **Re-measure, do not copy** — see the last section.

## Switching machines (either direction)

Leaving a machine:

1. Commit and push every branch you want on the other side (`git push` — a local-only branch does
   not travel).
2. `scripts/machine/rig-state-export.sh` — only if the rig changed and the other side should have it.
3. Close the agent terminals (`AGENTS.md` → Session End).

Arriving:

1. `git pull` (and fetch the branches you need).
2. Copy `.machine-transfer/` over if you exported; `scripts/machine/rig-state-import.sh` (it
   REPLACES this machine's dev DB and asks first).
3. After a pull that moved `package-lock.json` or `composer.json`: `npm ci` / `composer install`.

⚠ **Never copy the whole folder BACK** from the laptop to the desktop once both are set up — it
would carry darwin binaries in `node_modules`. After the first setup the paths are git + the bundle.

## Where the platforms differ for an agent

| Topic | Windows desktop | macOS laptop |
|---|---|---|
| Shell the agent's Bash tool uses | Git Bash (MSYS) — rewrites `/var/...` args (`MSYS_NO_PATHCONV=1`), mangles Cyrillic in `curl` args, eats backslashes in heredocs | zsh/bash 3.2 — none of those; scripts in `scripts/machine/` avoid bash 4 features |
| Line endings | `core.autocrlf=true` in the system gitconfig → CRLF in the working tree for `text=auto` files | LF; a copied tree is repaired by the setup script |
| Orca `sharedDirectories` (`node_modules` in a worktree) | a directory **symlink** — `git worktree remove` can empty the primary checkout's `node_modules` | clone-**copied** (`orca.yaml`) — that trap does not exist; the build-parity caveat may not either (unmeasured) |
| Codex's tool shell under Orca | WSL bash on a Windows path; an Orca worktree's absolute `gitdir` is unreadable to it | native — expected to read worktrees directly (unmeasured) |
| Container prefix | `de59f74e…` | a different hash — use `rig-container.sh` |
| PHP | 8.5.1, sodium OFF unless `-d extension=sodium` | Homebrew PHP — sodium is normally built in; check `php -m` |
| Agent concurrency cap | 3 (measured on 15.3 GB RAM) | unmeasured — start at 2–3 and re-measure |
| `run-local-ci` (the global CI rehearsal tool) | does not run natively; WSL only | expected to run natively (unverified) |

Gotchas that apply to only one OS carry a `> **Platform:**` or `> **Measured on:**` line under their
H1 (`DOCS-SCHEMA.md` → Gotcha Detail File Format). `grep -rl '\*\*Platform:\*\*' docs-internal/gotchas`
lists them.

## Re-measure on the first laptop session

The baselines in `CURRENT-STATE.md` were measured on the desktop. On the laptop, record your own and
say which machine each number came from:

- unit tests / assertions and the **SKIPPED** count (with sodium on), `rm -f .phpunit.result.cache` first
- jest count; integration count (inside the tests container)
- `npm run build` leaves `woodev/assets/build` with **zero git diff** (cross-platform build parity)
- the agent cap and the Codex launch recipe (`CLAUDE.md` → Orca, fact 3) under Orca for macOS

## Related

- [local-rig.md](local-rig.md) — the rig itself: carriers, options, containers, the integration command
- [orchestrating-agents-with-orca.md](orchestrating-agents-with-orca.md) — worktrees, sharedDirectories, the Codex launch
- [../gotcha-index/tooling.md](../gotcha-index/tooling.md) — most platform-specific traps live under `[tooling/*]`
