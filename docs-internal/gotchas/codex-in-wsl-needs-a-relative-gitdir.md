# Gotcha: [tooling/parallel-agents] — Codex's own shell tool is a Linux shell, so an Orca worktree's `gitdir` is unreadable to it; and the git config that "fixes" it breaks the whole repo
> Tags: tooling, orca, codex, git, wsl | Session: s107

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

- **The `orca` CLI is not on the PATH of that Linux shell.** `orca`, `orca-ide` and `orca-dev` are
  all absent, and the Windows `orca.exe` invoked by its `/mnt/c/...` path dies with
  `sh: 1: C:/Program: not found`. One worker read its brief literally, could not send `worker_done`,
  and therefore did **no work at all** — it traded the task for the receipt. A brief must say that
  the work is the deliverable and the lifecycle message is not a precondition for it.
- Three other Codex workers the same session DID send `worker_done`. So this is not deterministic;
  do not build a recipe on either outcome.

## Fix — one line, per worktree, and write it shell-agnostically

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
