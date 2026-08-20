# gotcha: two agents editing one file is the ORCHESTRATOR's bug, not the agent's

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s82 (2026-08-20)

## What happened

Two agents were dispatched against the same working tree at the same time:

- Codex, told to fix two review defects in `class-location-provider-registry.php` and
  `class-shipping-plugin.php`;
- a Claude worker, whose Block 2 brief *required* editing `class-location-provider-registry.php`.

The worker botched an edit in that file and ran `git checkout -- <file>` to undo it. That reverted
the file to `HEAD` — **including Codex's finished, uncommitted fix**, which had landed in the same
file minutes earlier. The other half of Codex's work survived in `class-shipping-plugin.php` (the
worker never touched it), leaving a call to a registry method that no longer existed.

The worker noticed the dangling reference, reconstructed both pieces by inference, and flagged the
whole thing loudly in its report. The reconstruction turned out faithful — a mutation check
reproduced Codex's own recorded red result, message and line number identical — but that was luck,
not process.

## Root cause: the dispatch, not the `git checkout`

It is tempting to file this under "the agent used a destructive command". That is the second-order
cause. The first-order cause is that **the orchestrator sent two agents into one file.** The brief
even said "another agent is editing OTHER files in this same tree" — which was false when written,
because the other brief named that exact file.

An agent cannot know what an unrelated agent is editing. `git status` shows changes but not who owns
them or whether they are mid-flight. So the collision has to be prevented where the dispatch happens.

## ❌ Wrong

```text
Agent A: "fix defects in class-location-provider-registry.php"   (in flight)
Agent B: "split field_mode — requires class-location-provider-registry.php"
```

Both run against `D:\Projects\woodev_framework`. Whoever discards first wins.

## ✅ Correct — pick one

1. **Serialize on the file.** Wait for A, commit A's work, then dispatch B. Committing is what makes
   the work safe: a `git checkout` after a commit costs nothing.
2. **Isolate.** Give the second agent its own Orca worktree
   (`orca worktree create --name <task> --agent <id> --prompt "…"`), so there is no shared tree to
   collide in. Note the Claude Code Agent tool's own `isolation: "worktree"` creates a
   `.claude/worktrees/agent-*` checkout that is invisible in Orca's UI — fine for read-only work,
   but the operator cannot see or steer it.
3. **Partition by file and say so precisely.** Only safe when the briefs genuinely name disjoint
   files — verify that by reading both briefs, not by assuming.

## The cheap habit that would have contained it

**Commit each block the moment its gates are green.** Uncommitted work is the only thing a stray
`git checkout` can destroy. Every minute a finished block sits uncommitted is a window.

That habit was then tested on the author of this file, in the same session, an hour after writing
it: wanting to inspect an untouched file's line endings, I ran `git checkout -q main -- .` — which
does not inspect anything, it OVERWRITES the working tree and index from another branch. Fifty
files reverted. Nothing was lost, purely because the block had been committed minutes earlier;
`git reset --hard HEAD` restored everything and the suite came back at the same 2440/6013.

Two lessons, both worth more than the embarrassment:

- **To READ another revision, never check it out.** `git show <ref>:<path>`, `git diff <ref> -- <path>`
  and `git ls-files --eol <path>` all answer without touching the working tree. Reaching for
  `git checkout` to answer a question is the mistake, independent of who else is editing.
- **Knowing the trap does not disarm it.** This file existed, indexed and committed, and the trap
  still fired — because the destructive command arrived dressed as a read. The protection that
  actually worked was the commit, not the knowledge.

## Related

- [[git-checkout-destroys-uncommitted-mutation-revert]] — the same command, the same class of loss,
  found in s52. That entry blames the command; this one blames the dispatch that made two agents
  reach for the same file.
- [[rig-serves-the-working-tree-branch-switch-reverts-fixes]] — the other reason a shared working
  tree serializes work.
- [[codex-shell-sandbox-broken-windows]] — how the Codex channel is driven.
