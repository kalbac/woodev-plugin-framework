# Gotcha index — [build/*] Build/CI/release

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [build/wp-scripts] **A `.tsx` build entry silently emits `index.tsx.js` — wp-scripts names the chunk with `basename(path, '.js')` and strips nothing else, so the enqueue asks for a file that does not exist, with no build error. Use its `name=path` entry syntax.** → [wp-scripts-names-a-chunk-from-basename-minus-js](../gotchas/wp-scripts-names-a-chunk-from-basename-minus-js.md) (s125)
- [build/ci] **A PR's check rollup keeps SUPERSEDED failures under the same job name — `CLEAN` and "eight failures" can both be true; filter to the current run ids before counting.** → [a-check-rollup-keeps-superseded-failures-under-the-same-job-name](../gotchas/a-check-rollup-keeps-superseded-failures-under-the-same-job-name.md) (s98)
- [build/ci] **Every job failing in TWO SECONDS — including `Label PR` — is an Actions billing block, not a red build; the annotation is only in `gh run view`.** → [every-ci-job-failing-in-two-seconds-is-a-billing-block](../gotchas/every-ci-job-failing-in-two-seconds-is-a-billing-block.md) (s98)
- [build/ci] **A `pull_request` workflow can simply not fire on a CLEAN PR — only `PR Triage` shows up. Close and reopen; and COUNT the jobs (19 code-only, 20 with `.md`), never read the colour.** → [a-pull-request-workflow-can-simply-not-fire](../gotchas/a-pull-request-workflow-can-simply-not-fire.md) (s97)
- [build/composer] **Widening `autoload.classmap` breaks every EXISTING checkout until `composer dump-autoload` runs — nine "class not found" errors that read as a bad merge.** → [a-widened-autoload-classmap-needs-dump-autoload-in-every-existing-checkout](../gotchas/a-widened-autoload-classmap-needs-dump-autoload-in-every-existing-checkout.md) (s91)
- [build/ci] **All three integration jobs red at once on a docs-only PR — it is an `api.github.com` 504 inside the wp-env image build, not your change.** → [integration-jobs-die-on-a-github-api-504-not-on-your-code](../gotchas/integration-jobs-die-on-a-github-api-504-not-on-your-code.md) (s87)
- [build/ci] **A failing early CI job silently SKIPS dependent jobs — they never run.** → [ci-failing-gate-skips-dependent-jobs](../gotchas/ci-failing-gate-skips-dependent-jobs.md)
- [build/ci] **`composer audit --no-dev` errors when there are no runtime dependencies.** → [composer-audit-no-prod-deps](../gotchas/composer-audit-no-prod-deps.md)
- [build/git] **`git add -A` in a fresh worktree sweeps CRLF→LF normalisation of files you never touched into your commit.** → [git-add-all-sweeps-crlf-normalisation-in-a-fresh-worktree](../gotchas/git-add-all-sweeps-crlf-normalisation-in-a-fresh-worktree.md) (s71)
- [build/ci] **markdownlint-cli2 ignores `.markdownlintignore` when globs are passed as CLI args.** → [markdownlint-ignorefile-vs-globs](../gotchas/markdownlint-ignorefile-vs-globs.md)
- [build/ci] **A credential that is public elsewhere is still not ours to commit here.** → [public-repo-third-party-credentials](../gotchas/public-repo-third-party-credentials.md) (s55)
- [build/ci] **An empty `statusCheckRollup` + `CLEAN` can be a GitHub Actions OUTAGE, not your config.** → [empty-status-rollup-can-be-a-github-actions-outage](../gotchas/empty-status-rollup-can-be-a-github-actions-outage.md) (s54)
- [build/ci] **A PR that conflicts with base runs no `pull_request` CI — only `pull_request_target`.** → [pr-conflict-skips-pull-request-ci](../gotchas/pr-conflict-skips-pull-request-ci.md)
- [build/js] **`@wordpress/scripts` automatic JSX runtime requires WP ≥ 6.6 — use the classic runtime for WP 6.3+ support.** → [wp-scripts-jsx-runtime-wp66](../gotchas/wp-scripts-jsx-runtime-wp66.md) (s8)
- [build/assets-eol] **rebuilding the license-page bundle on Windows — pin build artifacts to LF or CI build-parity fails.** → [build-artifacts-eol-lf-windows-parity](../gotchas/build-artifacts-eol-lf-windows-parity.md) (s14)
- [build/assets-version] **`woodev-modal.js` is versioned by `self::VERSION`, so editing it never busts the browser cache.** → [modal-script-versioned-by-version-constant-not-filemtime](../gotchas/modal-script-versioned-by-version-constant-not-filemtime.md) (s62)
- [build/css-enqueue-version] **enqueue the wp-scripts `style-index.css` with its OWN filemtime, not the JS bundle's asset-hash version.** → [wp-scripts-css-enqueue-version-by-mtime](../gotchas/wp-scripts-css-enqueue-version-by-mtime.md) (s31)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
