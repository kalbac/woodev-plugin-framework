# Gotcha: [tooling/local-ci] — local-ci runs the LIVE worktree, so a run started while a worker still edits it proves nothing
> Tags: local-ci, orca, worker, merge-evidence | Session: s244

## What happens

s244, Ozon PR #149: the coordinator started the merge-evidence local-ci run in the worker's worktree while a fix worker was still
editing that same worktree. The run went red on two unit tests calling `Tool::to_array()` — a method that never existed; the worker had
used it in an intermediate draft of the test and replaced it with `describe()` before committing. The same happened on PR #148 the same
night (a run started before the fix round; its result described code that no longer existed).

## Root cause

local-ci copies (or mounts) the working tree as it is at each step, not a commit. Files a worker writes mid-run land in the run.

## Fix

- Start the merge-evidence run only after the worker's `worker_done`, on a clean tree: `git status --porcelain` empty (untracked critic
  reports aside), and note the head sha (`git log -1`) in the report.
- If a fix round starts while a run is going, the run's result is void — rerun on the new head; never cite it.

## Related

- [a-local-ci-exit-marker-waiter-must-require-digits-or-it-matches-its-own-command](a-local-ci-exit-marker-waiter-must-require-digits-or-it-matches-its-own-command.md)
- [run-local-ci-requires-run-subcommand](run-local-ci-requires-run-subcommand.md)
