# Gotcha: [tooling/gh] — `gh pr view --jq .body > f` that failed (rate limit) + `gh pr edit --body-file f` wipes the PR description
> Tags: gh, graphql, rate-limit, pr-body | Session: s222

Under the GraphQL rate limit `gh pr view N --json body --jq .body > f` prints the error to stderr and leaves `f` EMPTY; a later
`sed … f && gh pr edit N --body-file f` then succeeds and replaces the whole PR description with nothing (tracking PR #11, s222 — restored by
hand from the worker's report). Bulk board edits (`gh project item-edit` per field, ~20 cards) are what exhausted the limit.

❌ **Wrong:**

```sh
gh pr view 11 --json body --jq .body > b.md; sed -i '' 's/Closes/Refs/' b.md; gh pr edit 11 --body-file b.md
```

✅ **Correct:**

```sh
gh pr view 11 --json body --jq .body > b.md && [ -s b.md ] || { echo "fetch failed"; exit 1; }
sed -i '' 's/Closes/Refs/' b.md && gh pr edit 11 --body-file b.md
```

## Related

- [gh-project-item-edit-takes-the-project-number-and-url-now.md](gh-project-item-edit-takes-the-project-number-and-url-now.md) — the bulk edits that spend the limit
- [../gotcha-index/tooling.md](../gotcha-index/tooling.md) — tooling index
