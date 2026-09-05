# Gotcha: [tooling/parallel-agents] — A `cd` in the Bash tool persists across calls, so a later `git checkout -b` lands in the worktree you visited, not the tree you meant

> Tags: tooling, bash, git, worktrees | Session: s117

## What happens

You inspect a worker's output the obvious way:

```bash
cd "$W" && node scripts/signature-probe.mjs     # $W = the worker's worktree
```

Several tool calls later, having moved on to something else entirely, you create a branch and copy
the worker's files into "the main tree":

```bash
git checkout -b feat/767-signature-probe
cp "$W/scripts/signature-probe.mjs" scripts/
```

`cp` answers **`are the same file`**, and the branch was created in the worktree. The tell is easy
to misread: "same file" looks like a junction or a shared directory — the `orca.yaml` sharing rules
make that a plausible story — and you start investigating whether the worker corrupted the main
checkout. Nothing is corrupted. The working directory simply never went back.

## Root cause

**The Bash tool's working directory persists between calls; its shell state (variables, functions)
does not.** That asymmetry is what makes it easy to miss: `$W` had to be re-exported in every call,
so it *feels* like each call starts fresh. It does not.

Relative paths — `scripts/`, `git status`, `git checkout` — then resolve against wherever the last
`cd` left you, which in an Orca session is very often a sibling checkout of the same repository
with the same directory layout. Every command succeeds. Nothing looks wrong.

This is the s83 failure — work splitting silently across two checkouts — arriving through a
completely different door. There it was Serena activated on the wrong path; here it is the shell.
Reading only the Serena gotcha does not protect against this one.

## Fix

**Never rely on the implicit working directory for anything that writes.** Two habits, both cheap:

```bash
# git: name the tree explicitly
git -C "D:/Projects/woodev_framework" checkout -b feat/x
git -C "$W" status --short

# files: absolute paths on both sides
cp "$W/scripts/signature-probe.mjs" "$M/scripts/signature-probe.mjs"
```

If a call must `cd`, `cd` back in the same call, or make the very next call start with an explicit
`cd` to the intended root and `pwd` to prove it.

When something surprising happens, **establish which tree you are in before diagnosing anything
else** — `pwd` plus `git -C <each tree> branch --show-current` costs one call and rules out the
entire class:

```bash
pwd
git -C "D:/Projects/woodev_framework" branch --show-current
git -C "$W" branch --show-current
```

Untracked files follow a branch switch, so recovering is usually just switching the worktree back
and deleting the stray branch — but only if you notice before committing.

## Related

- [serena-activate-path-must-be-the-worker-s-worktree](serena-activate-path-must-be-the-worker-s-worktree.md) — the same split, through Serena instead of the shell
- [serena-index-vs-git-worktree](serena-index-vs-git-worktree.md)
- [an-orca-worktree-starts-dirty-with-crlf-churn](an-orca-worktree-starts-dirty-with-crlf-churn.md) — why the stray tree also shows modifications you did not make
