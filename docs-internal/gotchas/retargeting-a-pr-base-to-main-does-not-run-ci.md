# Gotcha: [build/stacked-pr] — changing a PR's base to `main` does not start the full CI
> Tags: github-actions, stacked-prs, ci | Session: s145

## What happens
A stacked PR (base = the PR below it) gets ONE check, not the matrix. After the lower PR is squash-merged, the
branch is restacked onto `main`, pushed, and the base is changed with `gh pr edit N --base main`. In s145 #995
got its full CI that way, #997 did not: it stayed at 1 check and looked «waiting» for twenty minutes.

## Root cause
`ci.yml` and `integration-tests.yml` trigger on `pull_request` filtered to `branches: [main]` with the default
activity types (`opened`, `synchronize`, `reopened`). A base change is `edited` — not in the list. Whether the
push that precedes the edit counts depends on the order GitHub processes them, so it works sometimes.

## Fix
❌ wrong — push, retarget, wait.

✅ correct — push, retarget, then force a `reopened` event and confirm the matrix appeared:

```bash
git push --force-with-lease=<br>:origin/<br> origin <br>
gh pr edit N --base main && gh pr close N && gh pr reopen N
sleep 30 && gh pr checks N | wc -l   # expect ~19, not 1
```

## Related
- [local-npm-run-build-is-not-assets-parity-evidence.md](local-npm-run-build-is-not-assets-parity-evidence.md) — the restacked bundle must still be built in the primary checkout
