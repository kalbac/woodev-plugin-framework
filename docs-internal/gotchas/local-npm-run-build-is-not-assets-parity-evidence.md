# gotcha: generated bundles must be built from the PRIMARY CHECKOUT — a worktree build never matches CI

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s84 (2026-08-21)

## What happened

A worker changed two shared files under `src/components/`, rebuilt in its worktree, and committed
all five bundles. Its critic then ran the check independently and wrote:

> Ran `npm run build` myself after `generate-class-map.php`:
> `git status --short -- woodev/assets/build/` came back EMPTY — my fresh rebuild reproduced the
> author's committed bundles byte-for-byte across all 5 entries. So the build IS reproducible.

CI disagreed. The `Assets build parity` job on PR #422 failed on exactly ten files — `index.js`
and `index.asset.php` for all five entries. Two agents had measured parity locally and both were
wrong.

## Root cause: webpack resolves the shared `node_modules` symlink out of the project

`orca.yaml` shares `node_modules` by **symlink** so a fresh worktree can run the JS gate without a
658 MB install. Webpack resolves that symlink to its real path, so from inside a worktree every
module request is emitted relative to a directory *outside* the project:

```text
# built in an Orca worktree
css ../../../../node_modules/css-loader/dist/cjs.js!./src/ui-kit-gallery/style.scss

# built in the primary checkout (what CI does)
css ./node_modules/css-loader/dist/cjs.js!./src/ui-kit-gallery/style.scss
```

Those request strings feed the module identifiers, which feed the content hash in
`index.asset.php`:

```diff
-<?php return array('dependencies' => array(…), 'version' => '037cad0f4683dc063030');
+<?php return array('dependencies' => array(…), 'version' => '6617edf69464dc0f4549');
```

A worktree build is therefore self-consistent — rebuild there and you get no diff, forever — and
permanently different from the root build CI performs.

## What it is NOT — all four ruled out by measurement

| Suspected cause | Measurement |
|---|---|
| Warm webpack/babel cache in the shared `node_modules/.cache` | Deleted it, rebuilt in the worktree → still zero diff there |
| `node_modules` drifted from `package-lock.json` | `@wordpress/scripts` 32.4.0, `webpack` 5.107.2, `@wordpress/components` 32.2.1 — all identical to the lock |
| Line endings (Windows author, Linux CI) | `.gitattributes` already pins `woodev/assets/build/** text eol=lf`, and `git ls-files --eol` confirms `i/lf w/lf` |
| Windows-versus-Linux webpack output | A build in the primary checkout on clean `main` reproduces `main`'s committed bundles byte-for-byte |

The decisive test: checking the branch out at a **detached HEAD in the primary checkout** and
rebuilding produced exactly the ten files CI had flagged.

## ✅ Correct

- **Build generated bundles only in the primary checkout.** If a worker in a worktree changed
  anything under `src/`, the coordinator rebuilds:

  ```bash
  cd D:/Projects/woodev_framework
  git fetch origin && git checkout --detach origin/<branch>
  npm run build
  git add woodev/assets/build/ && git commit
  git push origin HEAD:<branch>
  git checkout main          # the rig serves this tree — always put it back
  ```

  `--detach` works even though the branch is checked out in the worktree; a normal `git checkout`
  of it would be refused.
- **A local `npm run build` is never parity evidence.** Say "CI will decide", and treat the
  `Assets build parity` job as the only authority.
- Always run the **full** `npm run build`. `control-field.js` and `location-picker-field.js` are
  shared UI-kit modules imported by all five entry bundles, so a single-entry build
  (`npm run build:settings`) silently desyncs the other four.

## Related

- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md) — the same symlink-resolution mechanism, one layer down: Composer bakes `$baseDir` and PHP resolves the link too
- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other gate that reads green in a worktree and is not
- [jest-scans-agent-worktrees-inside-the-repo](jest-scans-agent-worktrees-inside-the-repo.md) — the other JS-tooling trap worktrees create
