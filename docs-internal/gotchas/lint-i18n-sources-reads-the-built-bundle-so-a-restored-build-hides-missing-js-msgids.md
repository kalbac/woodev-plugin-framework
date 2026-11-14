# Gotcha: [tooling/i18n-gate] — `lint:i18n-sources` reads the BUILT bundle, so a worker that restores `woodev/assets/build` never sees missing JS msgids

> Tags: i18n, build, worktree, pre-push | Session: s230 (12.11.2026)

## What happens

A worktree worker changed JS under `src/`, hit the pre-push hook refusal (see the build-parity gotcha), and restored
`woodev/assets/build` to make the tree clean. Its own `lint:i18n-sources` stayed green. The coordinator then pushed from the primary
checkout, where the hook rebuilds the bundles — and the push failed on **16 missing msgids** in the catalogue.

## Root cause

`lint:i18n-sources` extracts JS strings from the built bundle, not from `src/`. A worktree with the old (restored) bundle has none of
the new `__()` calls to check, so the gate cannot see them. The primary checkout's rebuilt bundle has them all.

## Fix

❌ Wrong — restore the build directory, run the gate, commit:

```bash
git checkout -- woodev/assets/build && npm run lint:i18n-sources   # green: it read the OLD bundle
```

✅ Correct — a brief for any worker that touches JS strings says: build the touched bundle, run `npm run lint:i18n-sources` on that
build, add the missing msgids to the `.pot` / `.po` / `.mo`, and commit only the touched bundles (the coordinator still rebuilds in the
primary checkout; see the parity gotcha). Without the build step the gate answers about a bundle that does not contain the change.

## Related

- [lint-i18n-answers-about-the-catalogue-not-the-code](lint-i18n-answers-about-the-catalogue-not-the-code.md) — the sibling blind spot of the other i18n gate
- [local-npm-run-build-is-not-assets-parity-evidence](local-npm-run-build-is-not-assets-parity-evidence.md) — why bundles are built in the primary checkout and what the pre-push hook does
- [js-translations-are-handle-named-json-files](js-translations-are-handle-named-json-files.md) — how JS msgids reach the browser
