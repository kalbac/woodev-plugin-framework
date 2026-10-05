# A hosted runner that is never acquired fails the job at exactly fifteen minutes, with zero steps

**Namespace:** `[build/*]`
**Found:** s154 (05.10.2026), PR #1120, twice in a row on the same head.

## The trap

`gh pr checks` shows a red build that looks like a real one: Lint, Markdown Lint and the three
integration matrices `fail`, while JS Tests, assets parity and the secret scan `pass`. A selective
failure reads as «the code broke something». It did not — no step of the failed jobs ever ran.

The tells:

- **Every failed job took the same time, `15m1s`–`15m2s`.** A real failure in Lint and in a
  WordPress integration matrix would not finish to the second.
- **The job has no runner and no steps:** `gh api repos/<owner>/<repo>/actions/jobs/<job-id>`
  → `runner_name: ""`, `steps: []`; its conclusion is `cancelled`, which `gh pr checks` prints as `fail`.
- **The annotation names it** — `gh api repos/<owner>/<repo>/check-runs/<job-id>/annotations`:
  `The job was not acquired by Runner of type hosted even after multiple attempts`.
  `gh run view --log-failed` prints nothing, because there is no log.

Sibling jobs of the SAME run can get a runner and pass (the WP 6.6 matrix did, on the rerun), so «one
matrix green, two red» is not evidence that the two red ones are code.

## What to do

`gh run rerun <run-id> --failed`, then wait again. It needed two reruns in s154; nothing in the repo
changed between them. Do not push a «fix», do not touch the workflow.

## How it differs from the billing block

[every-ci-job-failing-in-two-seconds-is-a-billing-block](every-ci-job-failing-in-two-seconds-is-a-billing-block.md)
fails EVERY job in ~2 s with a payments annotation. This one fails a SUBSET at the 15-minute
acquisition timeout. Both are infrastructure; both are diagnosed from the annotation, never the log.

## Related

- [every-ci-job-failing-in-two-seconds-is-a-billing-block](every-ci-job-failing-in-two-seconds-is-a-billing-block.md)
- [../gotcha-index/build.md](../gotcha-index/build.md)
