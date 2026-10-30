# Gotcha: [tooling/local-ci] — a private plugin's `release.yml` may run only on a tag or `main`; the PR gate is `ci.yml`
> Tags: local-ci, release-workflow, merge-gate | Session: s205

## What happens

A merge-evidence run of every workflow in a plugin repo can "fail" on a workflow that was never meant to run on a PR. T-Bank
(`woodev-vkredit`) `release.yml` fails its tag guard on a PR branch **by design**; CDEK and Yandex `release.yml` trigger only on
`main` or a tag. Reading that red as a regression sends a worker into a fix for a non-bug.

## Root cause

The workflows differ per repo: Ozon and Russian Post `release.yml` do run on pull requests (their build is the gate), the others
guard the release job on a tag.

## Fix

Read the workflow's `on:` and guard before counting a run. The PR gate per repo:

| Repo | PR gate |
|---|---|
| Ozon, Russian Post | `ci.yml` **and** `release.yml` |
| CDEK, T-Bank | `ci.yml` (`release.yml` is tag/main only) |
| Yandex | `ci.yml` plus `markdown-lint` |

```text
❌ "release.yml failed on the PR" → fix the plugin
✅ the tag guard refused a non-tag ref → expected; judge the PR by ci.yml
```

## Related

- [run-local-ci-requires-run-subcommand](run-local-ci-requires-run-subcommand.md) — running each workflow explicitly with `-w`
- [local-ci-github-token-flag-puts-the-token-on-argv](local-ci-github-token-flag-puts-the-token-on-argv.md) — the token for the same run
