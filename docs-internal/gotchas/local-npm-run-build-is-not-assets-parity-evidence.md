# gotcha: generated bundles must be built from the PRIMARY CHECKOUT — a worktree build never matches CI

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s84 (2026-08-21)

## What happened

A worker changed two shared files under `src/components/`, rebuilt in its worktree, and committed
all six bundles. Its critic then ran the check independently and wrote:

> Ran `npm run build` myself after `generate-class-map.php`:
> `git status --short -- woodev/assets/build/` came back EMPTY — my fresh rebuild reproduced the
> author's committed bundles byte-for-byte across all 6 entries. So the build IS reproducible.

CI disagreed. The `Assets build parity` job on PR #422 failed on exactly ten files — `index.js`
and `index.asset.php` for the five entries that existed then. Two agents had measured parity locally and both were
wrong.

## Root cause: webpack resolves the shared `node_modules` symlink out of the project

`orca.yaml` clone-copies shared directories on macOS but shares `node_modules` by **symlink**
elsewhere, so a fresh non-macOS worktree can run the JS gate without a 658 MB install. Webpack resolves that symlink to its real path, so from inside such a worktree every
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

- **Build generated bundles only in the primary checkout.** This remains the project policy; on macOS,
  first compare a disposable worktree build with the primary checkout before attributing a parity
  difference to symlink resolution. If a worker in a worktree changed
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
  shared UI-kit modules imported by all six entry bundles, so a single-entry build
  (`npm run build:settings`) silently desyncs the other five.

## The second failure mode: built, but never COMMITTED (s133)

The variant above is "the worktree's build output is wrong". s133 hit the other one, and it looks
identical from the outside: **a worker ran `npm run build`, the output was fine, and it simply never
staged the result.** Its `worker_done` honestly listed `build` among its green gates — because
building IS what it had been asked to verify — and `Assets build parity` went red anyway.

The evidence was sitting in the worktree the whole time and only surfaced when removing it:

```
Failed to delete worktree at …/s133-829-badges.
 M woodev/assets/build/shipping-orders-page/index.js
 M woodev/assets/build/shipping-orders-page/style-index.css
 …plus the seven CRLF-only files a fresh worktree started dirty with until s135
```

Two consequences:

- **A brief that says "run `npm run build`" is not enough** — a worker that touches `src/` must be
  told the built bundles are COMMITTED artefacts, or told not to build at all and to leave the
  rebuild to the coordinator in the primary checkout. The second is better, and it is what this
  gotcha already recommends.
- **`orca worktree rm` without `--force` is a useful audit.** It refuses on a dirty worktree and
  prints exactly what was left behind — read that list before forcing it away, since it is the last
  moment anyone can see what the worker did not commit.

## Related

- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md) — the same symlink-resolution mechanism, one layer down: Composer bakes `$baseDir` and PHP resolves the link too
- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other gate that reads green in a worktree and is not
- [jest-scans-agent-worktrees-inside-the-repo](jest-scans-agent-worktrees-inside-the-repo.md) — the other JS-tooling trap worktrees create
