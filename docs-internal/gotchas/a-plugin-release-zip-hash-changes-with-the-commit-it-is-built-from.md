# Gotcha: [build/release] — a plugin release ZIP's sha256 changes with the commit it is built from, even with identical files
> Tags: release, zip, sha256, git-archive, critic | Session: s197

`scripts/build-release-zip.sh` in the carrier plugins exports the tree with `git archive HEAD`, which stamps every file with the
COMMIT time. The ZIP is deterministic for one commit (two builds are byte-identical), but the squash-merge commit has a new time, so
the ZIP built from `main` after the merge never matches the hash the release critic recorded on the PR head — and an amended
README-only release commit changes it too. A hash mismatch here is not a content change.

❌ **Wrong:** treat «critic's sha256 ≠ the asset's sha256» as a failed release, or publish the critic's PR-head ZIP instead of one built
from the merge commit the tag points at.

✅ **Correct:** build the asset from the merge commit (the tag target), then prove it equals what was reviewed by CONTENT:

```sh
OUT_DIR=/tmp/main WOODEV_FRAMEWORK_DIR=<framework at the tag> scripts/build-release-zip.sh   # asset (merge commit)
git worktree add /tmp/head <reviewed PR head sha> && (cd /tmp/head && OUT_DIR=/tmp/head-zip WOODEV_FRAMEWORK_DIR=… scripts/build-release-zip.sh)
unzip -q /tmp/main/*.zip -d /tmp/u1 && unzip -q /tmp/head-zip/*.zip -d /tmp/u2 && diff -r /tmp/u1 /tmp/u2   # must be empty
```

The PR-head build reproduces the critic's hash; the downloaded release asset must equal the merge-commit build byte for byte.
Note the argument: CDEK and Russian Post take `OUT_DIR=` (a positional path is rejected as «unknown argument»); Yandex takes the
output directory as `$1`.

## Related

- [run-local-ci-requires-run-subcommand.md](run-local-ci-requires-run-subcommand.md) — the merge-evidence run before a release
- [../gotcha-index/build.md](../gotcha-index/build.md) — build index
