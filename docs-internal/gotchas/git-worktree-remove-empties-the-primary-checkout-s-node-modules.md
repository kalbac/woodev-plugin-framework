# gotcha: `git worktree remove --force` follows the shared `node_modules` symlink and empties the PRIMARY checkout

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s85 (2026-08-22)

## What happens

`orca.yaml` shares `node_modules` into every worktree, and on Windows Orca materialises that share
as a **directory symlink** back to the primary checkout:

```text
.orca/worktrees/woodev_framework/<name>/node_modules -> D:/Projects/woodev_framework/node_modules
```

Removing such a worktree with plain git recurses **through** that link and deletes what it points
at:

```bash
git worktree remove --force .orca/worktrees/woodev_framework/fix-448-level-mode
git worktree remove --force .orca/worktrees/woodev_framework/fix-450-447-select2
# → D:/Projects/woodev_framework/node_modules is now 0 entries, 0 bytes
```

Both commands report success. Nothing warns. The damage is in a directory neither command names.

**The failure surfaces much later and points at the wrong thing.** The next worker to start reports
that it cannot run the JS gate, and everything about the report says "broken worktree": its own
`node_modules` symlink is present and resolves, it just lists nothing. The worktree is fine. The
primary checkout is the casualty, and every other worktree sharing the same link is dark too. In
s85 this cost a worker a stalled lap and the coordinator a wrong first diagnosis.

## ✅ Remove Orca worktrees through Orca

Orca created the share and knows the entry is a link, not a directory:

```bash
orca worktree rm --worktree "id:<repoId>::<path>" --force --json
```

Verified the same day, s85: after `orca worktree rm --worktree "id:<repoId>::<path>" --force`
the primary checkout still reported 1003 packages. Orca's removal is safe; raw git's is not.

Use raw `git worktree remove` only for a worktree Orca did not create — and even then, delete or
unlink the shared entries first:

```bash
rm -f "<worktree>/node_modules"      # removes the LINK; -f, never -rf
git worktree remove --force "<worktree>"
```

`rm -rf` on the link is the same trap by another route. `rm -f` unlinks; `rm -rf` may recurse.

## Recovery

```bash
cd <primary checkout>
npm ci        # ~2 min with a warm cache; package-lock.json is checked in
ls node_modules | wc -l                      # expect ~1000
ls node_modules/.bin/ | grep wp-scripts      # the gate's actual entry point
```

Then verify **through a live worktree's symlink**, not only in the primary — that is the path a
worker actually uses:

```bash
ls "<worktree>/node_modules/.bin/" | grep wp-scripts
```

No worktree needs its own `npm ci`; restoring the primary restores every share at once. Tell any
blocked worker so explicitly, or it will install 658 MB into its own tree and defeat the sharing.

## Why this is not the `vendor` story

`vendor` is **copied** into each worktree precisely so no worktree writes through to the primary
(`sharing-vendor-breaks-composer-autoload-in-a-worktree`). `node_modules` is **shared**, which buys
a 658 MB install per worker and costs exactly this: the primary checkout is reachable from inside
every worktree, so a delete there is a delete here.

## Related

- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md) — why one is shared and the other copied
- [a-stale-primary-checkout-degrades-every-worktree-made-from-it](a-stale-primary-checkout-degrades-every-worktree-made-from-it.md) — the other direction: the primary's state leaking into new worktrees
- `../wiki/orchestrating-agents-with-orca.md` — worktree layout and the shared-directory contract
