# gotcha: checking the primary tree out to an old branch silently degrades every worktree created afterwards

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s85 (2026-08-21)

## What happens

`.worktreeinclude` is **copied from the primary checkout's working tree**, not from `main` and not
from the branch the new worktree is about to sit on. So the primary checkout's current state is an
input to every worktree made from it — including which files a worker's gates can see.

In s85 the primary tree was switched to `rig/pr-422` to bring the rig up for an operator check. That
branch was **15 commits behind `main`**, and among the commits it lacked was the s84 fix that added
`plugins-reference` to `.worktreeinclude`. Every worktree created while the primary sat on that
branch therefore had no `plugins-reference`, and five `Contract/Yandex*` tests skipped in it.

The failure is quiet in the worst way: the suite is **green**. Nothing fails, no error mentions the
missing directory, and the only visible trace is the aggregate line:

```text
OK, but incomplete, skipped, or risky tests!
Tests: 2566, Assertions: 6340, Skipped: 71      ← 71, not 66
```

A worker reporting "all green" is telling the truth. It is just green over a smaller suite.

## ✅ How to avoid it

**Pin the skipped count, not just pass/fail.** Every brief in this project carries the line *"the
SKIPPED count must be 66; if it is not, STOP and report it"* for exactly this reason. A count is
falsifiable; "green" is not.

**And treat the primary checkout as shared infrastructure.** Before creating a wave of worktrees:

```bash
git -C D:/Projects/woodev_framework rev-parse --abbrev-ref HEAD   # should be main
git -C D:/Projects/woodev_framework log --oneline origin/main..HEAD   # should be empty
git -C D:/Projects/woodev_framework log --oneline HEAD..origin/main   # ← must ALSO be empty
```

The third command is the one that catches this. If the primary is behind, merge `origin/main` into
it (or return it to `main`) **before** spawning workers — not after, because a worktree copies
`.worktreeinclude` once, at creation.

If the rig needs an old branch, bring the rig up on it and put the primary tree back on `main`
afterwards, rather than leaving it parked there while agents run.

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the s84 discovery this is the second-order cause of
- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md) — why `vendor` is copied rather than shared
- `../wiki/orchestrating-agents-with-orca.md` — worktree layout and what makes one gate-capable
