# Gotcha: [build/hooks] — `pre-push` in a linked scratch worktree without `node_modules` refuses a release-branch merge that touched build inputs

> Tags: pre-push, worktree, release-branch, bundles, node_modules | Session: s220 (07.11.2026)

## What happens

Merging `main` into the release branch (`kalbac/fw-release-2026`) in a throwaway `git worktree` under the scratchpad
worked once (#1634, «linked worktree, no build input changed — skipping the bundle build»). The next time (#1642, `src/`
changed) the push failed: `pre-push: REFUSED — npm run build failed`. The worktree had no `node_modules` (a temporary
symlink had been removed before the push).

## Root cause

The hook skips the bundle build in a linked worktree only when no build input changed; otherwise it builds, and a
worktree without `node_modules` cannot.

## Fix

❌ Merge + push the release branch from a scratch worktree when the merge brings `src/` or `woodev/**/*.js` changes.

✅ Do it in the PRIMARY checkout: `git checkout -B kalbac/fw-release-2026 origin/kalbac/fw-release-2026`, merge
`origin/main`, `npm run build` (must leave no diff), push, then `git checkout main` — the rig serves the primary tree,
so keep the detour short. Never `--no-verify` a release branch.

## Related

- [local-npm-run-build-is-not-assets-parity-evidence](local-npm-run-build-is-not-assets-parity-evidence.md) — bundles are built in the primary checkout
- [Session s220](../sessions/s220.md) — where it was hit
