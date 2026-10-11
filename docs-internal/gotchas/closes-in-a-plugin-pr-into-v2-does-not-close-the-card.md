# Gotcha: [tooling/github] — `Closes #N` in a CDEK plugin PR does not close the card: the PR merges into `v2`, not the default branch
> Tags: github, backlog, edostavka | Session: s169

## What happens

A plugin PR whose body says `Closes #55` is squash-merged, CI is green, and the card stays **OPEN** on the board. Nothing
reports it; the morning summary then lists the card as done while the board says otherwise.

## Root cause

GitHub executes closing keywords only for PRs merged into the repository's **default branch**. In
`kalbac/woocommerce-edostavka` the default branch is `master` (the v1 line, see gotcha
`a-new-top-level-plugin-worktree-branches-from-master-not-v2`); every v2 PR targets `v2`.

## ✅ Correct

After merging a plugin PR, close its card by hand with a Russian comment naming the PR:

```sh
gh issue close 55 --repo kalbac/woocommerce-edostavka --reason completed --comment "Сделано в sNN, PR #58: …"
```

Framework PRs (base `main` = default branch) still close their cards themselves.

## ❌ Wrong

Trusting `Closes #N` in a `v2` PR body and moving on.

## Related

- [a-new-top-level-plugin-worktree-branches-from-master-not-v2](a-new-top-level-plugin-worktree-branches-from-master-not-v2.md)
- [a-pr-body-closes-cards-that-the-commit-msg-hook-would-have-refused](a-pr-body-closes-cards-that-the-commit-msg-hook-would-have-refused.md)
