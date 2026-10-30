# Gotcha: [tooling/codex] — a relative report path puts the critic's report inside the critic's worktree, not where the coordinator looks

> Tags: codex, critic, report-path | Session: s204 (30.10.2026)

## What happens

The W2 critic of the Ozon plugin was told to write `reports/w2.md`. The file appeared inside **the Ozon worktree** the critic
ran in; the coordinator, reading its own scratchpad, found nothing and the worktree later carried an untracked file that
could have ridden along in a commit.

## Root cause

A Codex worker resolves a relative path against its own cwd — the worktree it was started in — as any process does.

## Fix

❌ Wrong: «write the report to `s204-critic-w2-report.md`»

✅ Correct: give an ABSOLUTE report path under the scratchpad and require the canary as its first word; check that file, not
the worktree. Before removing a critic's worktree run `git status --short` there.

## Related

- [a-codex-bundle-over-32k-needs-a-file-and-an-in-file-canary](a-codex-bundle-over-32k-needs-a-file-and-an-in-file-canary.md) — the pointer + canary pattern
- [a-codex-critic-can-run-an-older-brief-with-a-similar-name](a-codex-critic-can-run-an-older-brief-with-a-similar-name.md) — another way a critic report goes astray
