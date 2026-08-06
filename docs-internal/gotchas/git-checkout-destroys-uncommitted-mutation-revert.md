# gotcha: `git checkout <file>` reverts a deliberate-regression mutation by deleting the uncommitted implementation with it

**Namespace:** `[tooling/git-checkout]`
**Discovered:** s52 (2026-08-06)

## Symptom

Mid-task, after finishing an implementation but **before committing it**, a deliberate-regression
check (mutate the code, watch the right test go red, restore) was reverted with:

```bash
git checkout woodev/shipping-method/assets/js/frontend/pickup-mount.js
```

The next mutation script then aborted on its own `assert` (the string it meant to patch was gone),
and the suite went from `12 passed` to almost every test in the file failing:

```
● selection confirmation › fires the requested event, locks the card and posts the point
● selection confirmation › writes the field and shows continueCheckout when close is false
… 10 more
Tests: 15 failed
```

## Root cause

`git checkout <path>` restores the file **from the index/HEAD** — it does not "undo the last edit".
With the implementation still uncommitted, HEAD's copy of that file is the state *before the task
started*, so the command discarded the mutation **and the entire task's work in that file** in one
go. There is no reflog entry for working-tree content that was never staged: this is unrecoverable
from git alone.

The mass failure also misreads as "the mutation broke everything" rather than "the file reverted to
HEAD", because the mutation and the revert are the same command's subject. The tell is the *shape*
of the failure — tests unrelated to the mutated line failing too.

Recovery here was luck: a `cp` backup had been taken before the first mutation.

## Fix

**Commit the implementation first, then mutate.** A committed baseline makes `git checkout <file>`
mean exactly what it appears to mean, and the whole check becomes free:

```bash
git add <impl> <tests> && git commit -m "feat(...): …"   # green, verified

# now each mutation is safe to revert:
python - <<'EOF'
… patch one line …
EOF
npm run test:js -- tests/js/<file>.test.js               # expect RED, for the right reason
git checkout <impl>                                      # back to the committed, green state
```

Committing before the regression check costs nothing: if a mutation reveals the implementation is
wrong, that is an ordinary follow-up commit on the same branch — which is what a mutation revealing
a real hole should produce anyway.

If a mutation genuinely must happen before a commit, copy the file out first
(`cp <impl> "$SCRATCH/x.bak"`) and restore with `cp`, never `git checkout`.

## Prevention

- Never run `git checkout <file>` / `git restore <file>` while that file holds uncommitted work you
  intend to keep. Check `git status --short` first: an `M` on the file you are about to check out
  means you are about to lose that `M`.
- The deliberate-regression step belongs **after** the commit in a TDD task's order, not before.

## Related

- [[npx-jest-bypasses-wp-scripts-jsdom]] — the other way a JS run reads as "I broke everything" when
  the code is fine; same session, and the two failure shapes are easy to confuse.
- Session protocol: run `npm run test:js` (never `npx jest`) to confirm the restored state is green
  again before continuing.
