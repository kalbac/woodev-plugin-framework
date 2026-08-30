# Gotcha: [tooling/parallel-agents] — Codex's tool shell is NOT fixed: measure which one it got before diagnosing anything else, because every symptom below is downstream of that one variable
> Tags: tooling, orca, codex, git, wsl | Session: s107, corrected by measurement s108

## ⚠ Read this first — s108 falsified the premise this gotcha was built on

s107 measured Codex executing its tool calls in a Linux `bash`. **s108 measured the same launch
path putting them in PowerShell 7.6.5**, on the same machine, same Orca, same repo:

| probe | s107 | s108 (30.08.2026) |
|---|---|---|
| the tool shell | Linux `bash`, `/mnt/d/…` paths | **PowerShell 7.6.5** — `Get-Content`, `ForEach-Object`; `uname` is "not recognized as a name of a cmdlet" |
| `orca` on its PATH | absent, `ORCA_CLI_COMMAND` empty | **reachable** — the worker ran `orca status --json` itself and got `runtime ready` |
| git in the worktree | `fatal: not a git repository` | **`git rev-parse --show-toplevel` succeeded with `.git` untouched** |

So the chain "Linux shell → absolute `gitdir` unreadable → `orca` off PATH → worker cannot report"
is real **only when the tool shell is a POSIX one**, and that is not a property of Orca, of this
repo, or of the worktree. It varies. The rewrite below is a conditional remedy, not a step 0.

**Therefore: the FIRST thing a Codex worker's brief should ask for is which shell it got.**
`echo $SHELL ; uname -a` and `$PSVersionTable.PSVersion` — one errors, and which one errors is the
answer. Diagnose nothing until that is on the table. s107 skipped this and every conclusion it drew
downstream inherited the error.

**And one conclusion s107 drew was wrong outright.** The worker that "traded the task for a receipt"
was blamed on an unreachable CLI — taken from the worker's own account of itself, never verified.
s108 reproduced the real defect: **the dispatch body does not always reach Codex's prompt.** The
worker gets the Orca preamble and no task, reports honestly that nothing arrived, and then explains
its own idleness with whatever it can see. Card #683 carries the measurement.

## What happens

A Codex worker started in an Orca worktree dies on its first git command:

```
fatal: not a git repository:
/mnt/d/Projects/woodev_framework/.orca/worktrees/woodev_framework/<name>/D:/Projects/woodev_framework/.git/worktrees/<name>
```

Every later command fails the same way. The main checkout reads fine, so it looks like a problem
with that one worktree. Before s107 this kept Codex to critic duty on the main checkout, and card
#510 recorded it as an open blocker.

## Root cause — and read this part carefully, it is easy to get wrong

**Do NOT conclude "Orca runs agents in WSL".** [orca-terminal-command-bash-lands-in-wsl-on-windows](orca-terminal-command-bash-lands-in-wsl-on-windows.md)
(s97) established the opposite and it still holds: **Orca's default terminal here is PowerShell on
Windows**, and the Agent-runtime setting is honoured. s107 asserted the blanket claim anyway, and
the operator corrected it the same day.

Two separate layers:

| layer | what it is here |
|---|---|
| the TERMINAL Orca creates for the agent | PowerShell on Windows (Orca's default; the operator's setting decides) |
| the shell **Codex's own tool calls** execute in | a Linux `bash` — hence `/mnt/d/…` paths |

So the failure is not about Orca's placement. It is that Codex's *internal* shell is a Linux one,
and a worktree created by **Windows** git carries `.git` as a FILE holding an absolute Windows path:

```
gitdir: D:/Projects/woodev_framework/.git/worktrees/<name>
```

Linux git does not recognise `D:/…` as absolute. It treats the line as **relative** and glues it
onto the cwd — the garbage path in the error. The main checkout is unaffected because its `.git` is
a real directory with no path inside it. Same path-format wall as s97, reached from the other side.

Two consequences measured in s107, both of which cost time:

- **When the tool shell IS a Linux one, the `orca` CLI is not on its PATH** (in PowerShell it is — measured s108). `orca`, `orca-ide` and `orca-dev` are
  all absent, and the Windows `orca.exe` invoked by its `/mnt/c/...` path dies with
  `sh: 1: C:/Program: not found`. One worker read its brief literally, could not send `worker_done`,
  and therefore did **no work at all** — it traded the task for the receipt. A brief must say that
  the work is the deliverable and the lifecycle message is not a precondition for it.
- Three other Codex workers the same session DID send `worker_done`. So this is not deterministic;
  do not build a recipe on either outcome.

## Fix — ONLY when the tool shell is a POSIX one; one line, per worktree, written shell-agnostically

Run it **from the worktree root**, with no absolute prefix at all:

```sh
printf 'gitdir: ../../../../.git/worktrees/<name>\n' > .git
git rev-parse --show-toplevel
```

Four levels up is Orca's layout (`<repo>/.orca/worktrees/<repo-name>/<worktree>`). Measured s107:
afterwards **both** gits read the same worktree — Linux git resolves it, and Windows git reports the
same toplevel it did before.

❌ Do **not** write `cd /mnt/d/Projects/...` into a brief, as s107 did. That hard-codes one shell and
one drive mapping, and it will break the moment the operator's runtime setting changes.

**The standing rule (operator, 30.08.2026):** everything goes through the **default Orca CLI**, and
**Orca decides** whether that lands in WSL or PowerShell based on his settings. Never route yourself
through `wsl …`, never `--command bash`, and never probe one shell to draw conclusions about
another. If you need to know which shell an agent actually got, read its own transcript — that is
evidence; your shell is not.

## ⚠ The trap: do NOT reach for `worktree.useRelativePaths`

Git 2.49 has exactly the option this seems to want, and it is the first thing anyone will try:

```bash
git config worktree.useRelativePaths true   # ❌
```

It does write a relative `gitdir:` — and it also writes a **repository extension** into the shared
config:

```ini
[extensions]
	relativeworktrees = true
```

Git 2.43 does not know that extension and refuses the repository outright — worktrees **and the
main checkout**:

```
fatal: unknown repository extension found:
	relativeworktrees
```

So the config turns a per-worktree failure into a total one, for every checkout at once. Measured
s107, then reverted with `git config --local --unset worktree.useRelativePaths` **and**
`git config --local --unset extensions.relativeWorktrees` — unsetting the first does not remove the
second. The manual per-worktree rewrite writes no extension and has none of this exposure.

## Related

- [orca-terminal-command-bash-lands-in-wsl-on-windows](orca-terminal-command-bash-lands-in-wsl-on-windows.md) — Orca's default IS PowerShell; the claim this gotcha nearly got wrong
- [codex-shell-sandbox-broken-windows](codex-shell-sandbox-broken-windows.md) — why a canary is mandatory for Codex at all
- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — the launch itself
- [codex-model-terra-is-a-400-that-looks-like-a-warning](codex-model-terra-is-a-400-that-looks-like-a-warning.md) — omit `--model`
- [wiki/orchestrating-agents-with-orca.md](../wiki/orchestrating-agents-with-orca.md) — the recipe this belongs to
