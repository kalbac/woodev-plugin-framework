# gotcha: a local `npm run build` cannot prove assets parity from an agent worktree

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s84 (2026-08-21)

## What happened

A worker changed two shared files under `src/components/`, rebuilt, and committed all five
bundles. Its critic then ran the check independently and wrote:

> Ran `npm run build` myself after `generate-class-map.php`:
> `git status --short -- woodev/assets/build/` came back EMPTY — my fresh rebuild reproduced the
> author's committed bundles byte-for-byte across all 5 entries. So the build IS reproducible.

CI disagreed. The `Assets build parity` job failed on **all ten** files:

```text
##[error]The committed bundle in woodev/assets/build/ does not match the build output.
woodev/assets/build/license-page/index.asset.php
woodev/assets/build/license-page/index.js
… (all five entries)
```

Two agents measured parity locally and both got zero diff. Both were wrong.

## Root cause: worktrees share `node_modules`, so they share its cache and its drift

`orca.yaml` shares `node_modules` by symlink so a fresh worktree can run the JS gate without a
658 MB install. That symlink also shares:

- `node_modules/.cache/` — babel-loader and webpack's persistent cache, warm from every previous
  build in the primary checkout, which is what assigns module ids and content hashes;
- whatever dependency versions the primary checkout happens to hold, which may have drifted from
  `package-lock.json`.

CI runs `npm ci && npm run build` — a **cold** build from the lock file. The two disagree, and the
local one is the one that lies. That CI is the honest half is measurable: PRs #413 and #420 both
passed the parity job against `main`'s committed bundles, so a cold build does reproduce `main`.

## ✅ Correct

- **The CI job is the only authority on assets parity.** A local `npm run build` producing no diff
  is not evidence; say "CI will decide" rather than "parity holds".
- Before committing bundles, make the local build cold: `npm ci` in the **primary checkout** (it
  refreshes the shared tree to the lock and drops the cache), then rebuild. Never run `npm ci`
  while another agent is using the shared `node_modules` — it deletes and reinstalls the tree
  under them.
- Always run the **full** `npm run build`. `control-field.js` and `location-picker-field.js` are
  shared UI-kit modules imported by all five entry bundles, so a single-entry build
  (`npm run build:settings`) silently desyncs the other four.

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other gate that reads green in a worktree and is not
- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md) — why `vendor` is copied while `node_modules` is shared
- [jest-scans-agent-worktrees-inside-the-repo](jest-scans-agent-worktrees-inside-the-repo.md) — the other JS-tooling trap that worktrees create
