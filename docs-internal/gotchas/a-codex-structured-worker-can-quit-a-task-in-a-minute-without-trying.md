# Gotcha: [tooling/codex] — a structured-mode Codex worker can quit a task in about a minute without trying

> Tags: codex, orca, worker, reassign | Session: s212 (03.11.2026)
> **Measured on:** the MacBook, Orca, Codex `gpt-6-luna` (the default model) in structured mode: 2 of 2 runs on the same stage.

## What happens

Stage 3 of #711 (a delivery report in WooCommerce Analytics) went to a Codex `gpt-6-luna` worker in structured mode. The
worker ended twice, each run within about one minute, with no changes in its worktree and no blocker or question. The
stage was then completed by a Sonnet 5.5 worker in one run.

## Root cause

Not established. What was verified is only the outcome: a fast, silent end with no diff and no report of a blocker. Do
not write a cause into a handoff until one is measured.

## Fix

❌ Wrong: a third identical dispatch of the same model «to be sure», or treating the quit as a finding about the task.

✅ Correct: after two silent quits with no diff, reassign the stage to another worker (Sonnet 5.5 in s212). Before
reassigning, read the failed receipt and `git status` in the worker's worktree, so you know whether anything is worth
salvaging.

## Related

- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — the other ways a Codex worker fails to start under Orca
- [a-codex-critic-can-run-an-older-brief-with-a-similar-name](a-codex-critic-can-run-an-older-brief-with-a-similar-name.md) — another way a Codex agent's output goes wrong without an error
