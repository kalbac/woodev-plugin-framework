# gotcha: `gh pr merge` from a detached HEAD merges the PR, THEN prints an error that reads like it refused

**Namespace:** `[tooling/git]`
**Discovered:** s149 (2026-10-02), PRs #1066 #1067

## What happened

The primary checkout was left detached on a parked PR branch (the rig serves the primary checkout, and a UI PR waiting
for the operator keeps it there). From that directory:

```text
$ gh pr merge 1066 --squash --delete-branch
could not determine current branch: failed to run git: not on any branch
```

That reads as «nothing happened». It is false: the PR was **already squash-merged** on GitHub. `--delete-branch`
makes `gh` touch the LOCAL repository after the merge (switch off / delete the local branch), and that step is what
failed. A retry said `! Pull request … was already merged`, and the remote head branch was **not** deleted.

## ❌ Wrong

```bash
gh pr merge 1066 --squash --delete-branch   # in a detached checkout → "error", merged anyway
gh pr merge 1066 --squash --delete-branch   # a "retry" that is really a no-op
```

## ✅ Correct

Name the repository so `gh` never consults the local checkout, then check the result instead of the exit text:

```bash
gh pr merge 1066 -R kalbac/woodev-plugin-framework --squash --delete-branch
gh pr view 1066 -R kalbac/woodev-plugin-framework --json state,mergeCommit
git ls-remote --exit-code origin <head-branch> && git push origin --delete <head-branch>   # if it survived
```

## Related

- [git-checkout-ref-dot-overwrites-the-primary-checkout](git-checkout-ref-dot-overwrites-the-primary-checkout.md) — another way the primary checkout's state bites a merge flow
- `../gotcha-index/tooling.md`
