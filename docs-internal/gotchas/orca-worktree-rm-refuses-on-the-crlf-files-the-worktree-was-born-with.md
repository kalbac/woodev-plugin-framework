# gotcha: `orca worktree rm` refuses to delete a worktree because of the CRLF files it created itself

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s111 (2026-09-01)

## What happened

Four finished worktrees, every worker's real work committed and merged, nothing outstanding. Every
removal refused:

```text
Failed to delete worktree at …/s111-707.
M woodev/payment-gateway/assets/js/frontend/woodev-payment-gateway-frontend.js
```

Orca declines to remove a worktree with uncommitted changes — correct in general, and useless here,
because **those changes are the ones every fresh Orca worktree is born with**: four files that
differ from the index by line endings only, nothing else.

```bash
git -C "$W" diff --ignore-cr-at-eol --numstat   # → empty. Zero content changed.
```

## The trap inside the trap: you cannot clean them

The obvious move fails silently:

```bash
git -C "$W" checkout -- woodev/assets/js/admin/ …   # ❌ the files come back modified immediately
```

`.gitattributes` pins `*.js text eol=lf`, the working copy holds CRLF, and re-checking-out writes
the same state again. The worktree cannot be made clean this way, so the refusal never lifts.

## ✅ Use `--force`, after proving the diff is line-endings-only

```bash
# 1. Prove there is nothing to lose — this must print 0 for every worktree.
git -C "$W" diff --ignore-cr-at-eol --numstat | wc -l

# 2. Then, and only then:
orca worktree rm --worktree "<repo-id>::<abs/path>" --force --json
```

`--force` is safe **here specifically** because step 1 proved the working tree differs from the
index by CR bytes alone and the worker's real work is already committed on its branch. Do not skip
step 1 and do not reach for `--force` on a worktree whose diff you have not inspected — that is how
an unmerged branch's work disappears.

⚠ Removal also tries to delete the checked-out local branch. Orca keeps branches it cannot prove
merged, which is why a squash-merged branch may survive the removal and still need
`git branch -D` afterwards.

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other way a fresh worktree differs from the primary checkout
- [git-diff-name-only-interleaves-eol-warnings](git-diff-name-only-interleaves-eol-warnings.md) — the same CRLF files misreported by a different command
- `../wiki/orchestrating-agents-with-orca.md` — the worktree contract
