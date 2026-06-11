---
id: s6-p3-wp-scripts-scaffold
title: "@wordpress/scripts build scaffold: package.json rework, src/license-page entry, committed build output, ADR-007"
phase: S3.2 license UI — JS toolchain (spec §5.1/§6, plan task s6-p3)
type: feature
model: sonnet
touches_contract_zone: false
writes_guard: false
file_set:
  - package.json
  - package-lock.json
  - .nvmrc
  - src/license-page/index.js
  - src/license-page/style.scss
  - woodev/assets/build/license-page/
  - docs-internal/adr/007-react-admin-stack-wordpress-scripts.md
  - docs-internal/adr/README.md
depends_on: []
contract_zones_touched: []
needs_guard: no
acceptance:
  - composer check green (no PHP touched, but run it anyway)
  - "package.json reworked from the docs stub: private:true, devDependency @wordpress/scripts pinned to an exact version, scripts: build = 'wp-scripts build ./src/license-page/index.js --output-path=woodev/assets/build/license-page', start = same with wp-scripts start; stub fields (main, directories, ISC placeholder description) cleaned up"
  - ".nvmrc created with a current Node LTS major (e.g. 22); engines.node aligned in package.json"
  - "npm install run -> package-lock.json COMMITTED; node_modules/ NOT committed (already gitignored, verify)"
  - "src/license-page/index.js = minimal placeholder entry importing './style.scss' and rendering an empty grid div via @wordpress/element createRoot into #woodev-licenses-app (guard: element may be absent — bail silently); style.scss = minimal .woodev-licenses-grid skeleton. Full app is s6-p4 — keep this compilable-minimal"
  - "npm run build run -> woodev/assets/build/license-page/{index.js, index.asset.php, style-index.css} COMMITTED; index.asset.php contains a dependencies array (wp-element at minimum) and a version hash"
  - "Reproducibility proven: running npm run build a second time leaves git status clean (state the check in the report)"
  - "ADR docs-internal/adr/007-react-admin-stack-wordpress-scripts.md: decision (= @wordpress/scripts + JSX, native @wordpress/components, @wordpress/* + React externalized to wp.* via DependencyExtractionWebpackPlugin, build output committed so vendored consumers need no node), context (first React surface, S5 foundation), consequences (build-parity CI job, node toolchain in dev only, release zip ships build/ not src/); indexed in adr/README.md"
  - "No installed-site contract touched; everything additive"
---

# Task

Implement plan task **s6-p3** of `docs-internal/platform-v2-s3-licensing-ui-plan.md`
(spec `docs-internal/platform-v2-s3-licensing-ui-spec.md` §5.1/§6 — toolchain decision
D-1 locked by operator).

Goal: a deterministic, committed build pipeline — NOT the full app. The placeholder
entry exists only so the pipeline produces real artifacts to commit; s6-p4 replaces its
contents. The committed bundle + lockfile + .nvmrc are what make the s6-p5 CI
build-parity job (`git diff --exit-code woodev/assets/build/`) meaningful.

Notes:
- The existing root `package.json` is a leftover docs stub — rework it, keep the name.
- `node_modules/` is already in `.gitignore` (line 1) — verify, do not duplicate.
- The release-zip rsync in ci.yml already excludes `package.json`/`package-lock.json`
  and `node_modules`; `woodev/assets/build/` rides inside `woodev/` and IS shipped —
  that is intended. Do not touch ci.yml (that is s6-p5).
- ADR template/format: see `docs-internal/adr/005-platform-v2-clean-break-policy.md`
  and `adr/README.md` index style.

## What NOT to change
- ci.yml (s6-p5). Any PHP file. .gitignore (already correct).

## Verification
- `npm ci && npm run build` from a clean state succeeds; second build is a no-op for git.
- `composer check` green. Report node/npm versions used.
