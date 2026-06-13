# gotcha: rebuilding the license-page bundle on Windows — pin build artifacts to LF or CI build-parity fails

**Namespace:** `[tooling/assets-build]`
**Discovered:** s14 (2026-06-14, OB-2)

## Symptom

The CI "Assets build parity" job runs `npm run build` on Linux and asserts
`git diff -- woodev/assets/build/` is empty. If you rebuild the bundle on this
Windows box and commit the artifacts, the committed CSS/JS can carry CRLF while
the Linux CI rebuild emits LF → the diff is non-empty → **parity job fails** even
though the content is identical.

## The fix (already applied s14)

`.gitattributes` now pins the build output to LF:

```
# Built front-end assets — enforce LF so a Windows rebuild matches the
# Linux CI build-parity check byte-for-byte.
woodev/assets/build/** text eol=lf
```

With `text=auto` alone, `.css`/`.js` were normalised to LF in the repo on commit
but a Windows working-tree checkout could be CRLF, so a local rebuild showed the
files as "modified" (pure line-ending churn) and risked committing CRLF blobs.

## Workflow when changing `src/license-page/*`

1. Edit `src/license-page/{index,app,license-card}.js` or `style.scss`.
2. `npm run build` (uses the pinned `@wordpress/scripts` 32.4.0 from `node_modules`).
3. Commit BOTH the source and `woodev/assets/build/license-page/*`.
4. Confirm `npm run build` is idempotent: a second run leaves `git status` clean.

Only the files you actually changed should diff — a PHP-only change (e.g. the
`.wrap` markup) leaves `index.js`/`index.asset.php` untouched; a `style.scss`
change touches only `style-index.css` + `style-index-rtl.css`.

## Related

- [[license-page-css-bundle-only]] — what belongs in `style.scss`
- `.github/workflows/ci.yml` → "Assets build parity" job
- `package.json` → `build` script; ADR-007 (committed build artifacts + CI parity)
