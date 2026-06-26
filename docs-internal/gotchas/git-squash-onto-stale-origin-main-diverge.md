# gotcha: GitHub squash-merge onto a stale origin/main leaves local main "diverged but content-complete"

**Namespace:** `[tooling/git-merge]`
**Discovered:** s33 (2026-06-26)

## Symptom

After `gh pr merge <N> --squash --delete-branch`, `git switch main && git pull --ff-only` fails:

```
Your branch and 'origin/main' have diverged, and have 1 and 1 different commits each.
fatal: Not possible to fast-forward, aborting.
```

## Root cause

A previous session committed docs **directly to local `main`** (here `5ff50d3`, the s32 shipping
decomposition) and **never pushed it** — so `origin/main` was a commit behind. The feature branch
(`fix/autodev-loop-hardening`) was created on top of that local-only commit, then pushed and PR'd.

GitHub squash-merges the branch's whole diff **onto the PR base = `origin/main`** (the stale one). The
resulting squash commit (`b7c738e`) therefore:
- is a NEW commit whose **parent is the stale base** (so `5ff50d3` is NOT in its ancestry — git reports
  divergence), but
- **contains all of `5ff50d3`'s tree changes** (because the branch was based on top of it and the squash
  flattens the full branch diff).

So local `main` (`5ff50d3`) and `origin/main` (`b7c738e`) diverge by *commit ancestry* while `origin/main`
already holds *all the content* of the local-only commit.

## Fix

**Verify content containment, THEN reset — never blind-reset.**

```bash
# 1. Confirm the local-only commit's files are already in origin/main (empty diff = contained):
git fetch origin
git diff --stat origin/main <local-only-sha>        # restrict to the suspect files first
git cat-file -e origin/main:<key-file> && echo PRESENT
# 2. Only if content is fully contained:
git reset --hard origin/main                          # untracked files (e.g. a new spec) survive
```

A `git diff --stat origin/main main` that shows origin/main is strictly *richer* (your local main is the
older side) confirms local main is stale, not ahead.

## Prevention

- **Push `main` immediately** after any direct-to-main docs commit — local-only main commits are the trigger.
- Prefer never committing straight to `main`; use a branch + PR even for docs (then this can't happen).

## Related

- [[autodev-loop-gate-fence-pitfalls]] — same s33 session (autodev-loop tooling).
- Merge protocol: verify each CI job green + state CLEAN before `--squash --delete-branch`, never `--auto`
  (AGENT-RULES / global feedback patterns).
