# gotcha: zsh reads `$SHA:r…` as a history modifier, so a `git push $SHA:refs/heads/x` refspec breaks

**Namespace:** `[tooling/shell]`
**Discovered:** s196 (2026-10-24), macOS zsh (the Bash tool's shell on this Mac)

## Symptom

`git push origin $SHA:refs/heads/fix/1451-…` failed with
`src refspec 7a55aa…efs/heads/fix/1451-… does not match any` — the `:r` vanished.

## Root cause

In zsh, `$name:r` is the "remove extension" modifier; `$SHA:refs/...` expands to `${SHA:r}efs/...`. Bash has no such
modifiers, so the same line works in a bash script.

## ❌ Wrong

```zsh
git push origin $SHA:refs/heads/$B
```

## ✅ Correct

```zsh
git push origin "${SHA}:refs/heads/${B}"
```

## Related

- [npx-run-local-ci-in-a-fresh-terminal-waits-for-an-install-prompt](npx-run-local-ci-in-a-fresh-terminal-waits-for-an-install-prompt.md)
