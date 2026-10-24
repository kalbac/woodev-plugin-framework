# Gotcha: [build/ci] — a release trigger on "version changed" never fires for a version already on the branch
> Tags: release, github-actions, plugins | Session: s188

The framework's `version` job publishes when `VERSION` differs from `HEAD~1`. Copied into the CDEK and Yandex plugins,
it would have published NOTHING: both base branches already declared the release version (`2.3.0.0` on `v2`,
`2.0.0` on `main`) long before the release PR, so merging the PR changes only the framework pin — "version unchanged,
nothing to release". The first Yandex critic missed it; the CDEK critic (Codex sol) reproduced it by running the step
with `BEFORE=origin/v2`. A `workflow_dispatch` fallback did not help either: GitHub offers the button only for a workflow
on the DEFAULT branch, and CDEK's default is still `master` (the v1 line). (Since s195, 23.10.2026, CDEK and Russian Post default to `main`, so the button is there.)

❌ **Wrong:**

```bash
previous=$(git show "${{ github.event.before }}:plugin.php" | sed -n 's/^ \* Version: //p')
[ "$current" != "$previous" ] && echo changed=true
```

✅ **Correct:** publish when the tag does not exist yet — idempotent, and right for a first release.

```bash
if git ls-remote --exit-code --tags origin "refs/tags/v${current}" >/dev/null 2>&1; then
  echo "v${current} already released"; echo exists=true >> "$GITHUB_OUTPUT"
fi
```

## Related

- [a-pipe-into-grep-q-fails-at-random-under-pipefail](a-pipe-into-grep-q-fails-at-random-under-pipefail.md)
- [../gotcha-index/build.md](../gotcha-index/build.md)
