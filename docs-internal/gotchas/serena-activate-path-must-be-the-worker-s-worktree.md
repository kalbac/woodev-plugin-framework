# gotcha: a worker's Serena `activate_project` path must be its OWN worktree, not the repo root

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s83 (2026-08-20)

## What happened

Three Sonnet workers were dispatched into three separate Orca worktrees under
`C:/Users/maksi/orca/workspaces/woodev_framework/`. Every brief repeated the mandatory Serena rule
verbatim from `CLAUDE.md`:

> Activate by **path**, not by name: `activate_project` with `D:/Projects/woodev_framework`.

That path is the **main checkout**. So each worker had its git operations — branch, status, add,
commit — in its own worktree, while **every Serena edit landed in the main tree**. The work split
across two checkouts without a single error message.

It surfaced three different ways, none of them as an error:

- Worker A hit it, noticed, recovered with `git restore --staged`, and reported "fully recovered".
- Worker B was caught mid-flight by the orchestrator, who saw `cd /d/Projects/woodev_framework`
  in its terminal and then found six stray paths in `git status` on the main tree.
- Worker C was warned before its first edit and never hit it.

Nothing was lost, but only because the main tree happened to be committed and clean. Had the
orchestrator had uncommitted work there, a worker's cleanup would have taken it.

## Root cause: the isolation is the checkout, not the filesystem

An Orca worktree isolates the **checkout**. It does not sandbox the filesystem. A worker can read
and write any path on the machine, and Serena will happily target a project outside the worktree
it was launched in — that is what it was asked to do.

The rule in `CLAUDE.md` is written for a session working **in the main tree**, where the repo root
is the right answer. Copying it unchanged into a worker brief silently makes it the wrong answer.
**This is the orchestrator's bug**, the same way
[two-agents-one-file-is-the-orchestrator-s-bug](two-agents-one-file-is-the-orchestrator-s-bug.md)
was: a worker cannot know that the path it was handed is not its own.

## The tell

The two copies **differed in content, not just line endings.** A CR-insensitive diff still showed
20–64 differing lines per file. So this is not the Serena CRLF flip
([serena-replace-content-eol-flip](serena-replace-content-eol-flip.md)) wearing a different hat —
it is genuinely half the work in one tree and half in the other. Check content, not `diff -q`,
before concluding anything about a split like this.

## ❌ Wrong — the repo-root path, copied from `CLAUDE.md` into a worker brief

```text
Activate Serena by PATH first: activate_project with D:/Projects/woodev_framework.
```

## ✅ Correct — the worker's own worktree, plus a verification step

```text
Activate Serena on YOUR worktree:
  activate_project with C:/Users/maksi/orca/workspaces/woodev_framework/<your-worktree-name>
After activating, VERIFY it took: a find_symbol result must report a path under your worktree.
Never cd outside your worktree. To read another revision use `git show <ref>:<path>` or
`git fetch` — neither touches a working tree.
```

The verification line is not decoration. The failure is silent: a wrongly-activated Serena
returns correct-looking symbols and applies edits successfully. The only visible signal is a path
in a result, or stray files in someone else's `git status`.

## The same failure with no Serena in it — the COORDINATOR's shell cwd (s108)

This gotcha was written about workers and Serena. s108 hit the identical outcome as the coordinator,
with neither.

The Bash tool's working directory **persists between calls**. A single
`cd "$WORKTREE" && <gate command>` — run once to measure a worker's branch — leaves the session's cwd
inside that worktree for every later call. Doc edits written afterwards with a RELATIVE path
(`docs-internal/gotchas/...`) then land in the worktree, not the primary checkout. Nothing errors:
the file exists in both trees, the edit succeeds, a `lint:docs` run in that tree passes.

The loss happens at cleanup. `orca worktree rm` takes the worktree away and the edit with it. In s108
a corrected gotcha section was written, verified by `grep`, and was simply **gone** an hour later —
noticed only because `git status` in the primary did not list a file that had definitely been edited.

The harness says so, and it is easy to read past:

    Session cwd remains D:\Projects\woodev_framework\.orca\worktrees\...;
    directory changes made by the backgrounded command do not apply to subsequent commands.

**Rules:**

- Coordinator edits to repo files use an **absolute path**, always. The primary checkout's path is
  known; there is no reason to depend on cwd for it.
- Prefer `cd "$W" && cmd` inside a single background call over letting the cwd drift, and treat the
  harness's "Session cwd remains …" line as a warning, not a footnote.
- `git status` in the PRIMARY checkout is the check that catches it: a file you edited that is not
  listed as modified was edited somewhere else.

## Related


- [two-agents-one-file-is-the-orchestrator-s-bug](two-agents-one-file-is-the-orchestrator-s-bug.md) — the same lesson: a worker cannot know what it was not told
- [serena-replace-content-eol-flip](serena-replace-content-eol-flip.md) — the other Serena trap, and the one this is NOT
- [git-checkout-destroys-uncommitted-mutation-revert](git-checkout-destroys-uncommitted-mutation-revert.md) — what a cleanup in the wrong tree can cost
- `../wiki/orchestrating-agents-with-orca.md` — the brief checklist this rule belongs to
