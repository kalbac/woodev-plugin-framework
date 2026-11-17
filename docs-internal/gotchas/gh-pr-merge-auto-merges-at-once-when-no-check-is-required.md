# gotcha: `gh pr merge --auto` merges at once when the branch has no required checks

**Namespace:** `[tooling/github]` · **Discovered:** s235 (17.11.2026, framework PR #1834)

## What happens

`gh pr merge <N> --merge --auto` is read as «merge when CI is green». On this repo `main` has no required status
checks, so GitHub has nothing to wait for: the PR merged immediately while the unit jobs were still `pending`.
`gh pr view --json autoMergeRequest` then reports `null` and the state is `MERGED`.

## Correct

Wait for the checks yourself, then merge:

```bash
gh pr checks <N> --watch >/dev/null 2>&1; gh pr checks <N> | grep -v pass   # read what is not green
gh pr merge <N> --merge
```

❌ `gh pr merge <N> --merge --auto` as a «merge after CI» shortcut on a repo without required checks.

## Related

- [every-ci-job-failing-in-two-seconds-is-a-billing-block](every-ci-job-failing-in-two-seconds-is-a-billing-block.md)
