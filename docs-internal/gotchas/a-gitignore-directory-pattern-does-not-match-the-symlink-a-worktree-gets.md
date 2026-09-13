# Gotcha: [tooling/worktrees] — `/node_modules/` does not ignore the SYMLINK a worktree gets, so every Orca worktree starts with it untracked
> Tags: tooling, worktrees, git, gitignore, orca, macos | Session: s136
> **Measured on:** macOS laptop, Orca 1.4.200, 13.09.2026. The same shape is possible anywhere Orca
> shares the directory as a symlink — check before assuming it is macOS-only.

## What happens

A fresh Orca worktree looks clean except for one line:

```text
$ git -C .orca/worktrees/woodev-plugin-framework/869-dead-css status --porcelain
?? node_modules
```

In the PRIMARY checkout the same path is ignored and invisible:

```text
$ git check-ignore -v node_modules
.gitignore:1:/node_modules/	node_modules
```

So the ignore rule works — just not on the thing the worktree actually has. A worker that runs
`git add -A` there commits an **absolute-path symlink** into the repository, and the repository is
public.

## Root cause

Two facts meeting:

1. `.gitignore` line 1 is `/node_modules/`. A pattern with a **trailing slash matches a directory
   only**. Git does not follow a symlink to decide what it points at, so a symlink named
   `node_modules` is not a directory and the pattern does not match it.
2. Orca shares the directory into the worktree as a **symlink**, not a copy:

```text
$ ls -ld .orca/worktrees/woodev-plugin-framework/869-dead-css/node_modules
lrwxr-xr-x  …  node_modules -> /Users/…/woodev-plugin-framework/node_modules
```

⚠ **`orca.yaml` said the opposite** — "Orca clone-copies them on macOS and symlinks them elsewhere"
— and `wiki/two-machine-setup.md` repeated it as a macOS prediction. Both were corrected in s136 by
this measurement. Believe `ls -ld`, not the comment.

## Why it matters beyond the stray line

Because it IS a symlink on macOS, two traps that were filed as Windows-only apply here as well:

- `git worktree remove --force` follows it and can **empty the primary checkout's `node_modules`** —
  remove Orca worktrees through Orca (gotcha
  [git-worktree-remove-empties-the-primary-checkout-s-node-modules](git-worktree-remove-empties-the-primary-checkout-s-node-modules.md)).
- webpack resolves the shared `node_modules` out of the worktree, so a build there can never be
  assets-parity evidence — **build bundles in the PRIMARY checkout** (gotcha
  [local-npm-run-build-is-not-assets-parity-evidence](local-npm-run-build-is-not-assets-parity-evidence.md)).

## Fix

For the worker, the existing rule already covers it, and this is one more reason it is not optional:

```sh
# ❌ wrong in any worktree — sweeps the untracked node_modules symlink into the commit
git add -A

# ✅ correct — stage by name, always
git add src/shipping-orders-page/style.scss
```

If the untracked line itself should go away, the pattern has to stop being directory-only
(`node_modules` rather than `/node_modules/`) — that is a repository-wide change to a line every
checkout depends on, so it belongs on a card, not in a worker's diff.

## Related

- [git-worktree-remove-empties-the-primary-checkout-s-node-modules](git-worktree-remove-empties-the-primary-checkout-s-node-modules.md) — the destructive half of the same symlink
- [local-npm-run-build-is-not-assets-parity-evidence](local-npm-run-build-is-not-assets-parity-evidence.md) — why a worktree build cannot prove parity
- [git-add-all-sweeps-crlf-normalisation-in-a-fresh-worktree](git-add-all-sweeps-crlf-normalisation-in-a-fresh-worktree.md) — the other reason `git add -A` is banned in a worktree
- [../wiki/two-machine-setup.md](../wiki/two-machine-setup.md) — where the corrected macOS row lives
