# Two PRs that each add a gotcha pass `lint:docs` alone and fail it once merged — the `GOTCHAS.md` count drifts

**Namespace:** `[tooling/docs]` · **Discovered:** s240 (21.11.2026)

## What happens

After parallel merges `main` failed `lint:docs`: the `GOTCHAS.md` header said 543 while `gotchas/` held 544 files. Each PR was green on its own branch.

## Root cause

Both PRs edited the same header number from the same base (N to N+1). Git merged the line without conflict only because the edits were identical, so the count rose by one while two files arrived.

## Fix

- ❌ Trust each PR's green docs gate after several merges.
- ✅ After any parallel docs/gotcha merges, run `npm run lint:docs` on `main` and fix the count in the next build commit; or merge such PRs one at a time with a rebase between.

## Related

- [s240 session](../sessions/s240.md)
