---
id: s6-p5-ci-assets-build-parity
title: CI assets build-parity job (committed bundle must match src/) + release-zip src exclude + contributor docs
phase: S3.2 license UI — build/release/CI (spec §6, plan task s6-p5)
type: chore
model: sonnet
touches_contract_zone: false
writes_guard: false
file_set:
  - .github/workflows/ci.yml
  - README.md
depends_on: [ s6-p4-react-license-app ]
contract_zones_touched: []
needs_guard: no
acceptance:
  - composer check green
  - "New independent job `assets` in .github/workflows/ci.yml (parallel to lint, no needs beyond checkout): actions/checkout@v6 -> actions/setup-node with node-version-file: .nvmrc and npm cache -> npm ci -> npm run build -> `git diff --exit-code -- woodev/assets/build/` (fails the job when the committed bundle does not match src/). Job style/naming consistent with the existing jobs"
  - "release job's rsync gains --exclude='src' and --exclude='.nvmrc' (the shipped framework zip carries the committed build/, not the JS sources); existing excludes untouched"
  - "README.md gains a short 'Building admin JS' section: bundle under woodev/assets/build/ is committed; regenerate with `npm ci && npm run build` (node version from .nvmrc); CI fails on stale bundles"
  - "YAML valid (actionlint or careful review); no other workflow/job semantics changed"
---

# Task

Implement plan task **s6-p5** of `docs-internal/platform-v2-s3-licensing-ui-plan.md`
(spec §6). This is the guarantee behind the committed-bundle decision: any PR that
changes `src/` without rebuilding (or hand-edits `build/`) goes RED.

Mind gotcha `build/ci`/`ci-failing-gate-skips-dependent-jobs`: make `assets` an
INDEPENDENT job (no other job `needs:` it, it needs none beyond checkout) so a node
hiccup cannot mask the PHP matrix.

After this task lands, the conductor runs the **holistic whole-feature critic** over
s6-p1…p5 (not part of this worker's scope).

## What NOT to change
- Any PHP/JS source. The unit-tests/lint/compat/version jobs. Release tagging logic.

## Verification
- Local: `npm ci && npm run build && git diff --exit-code -- woodev/assets/build/` passes.
- `composer check` green. Report the YAML validation method used.
