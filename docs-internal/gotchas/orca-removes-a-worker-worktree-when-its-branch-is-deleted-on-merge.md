# Gotcha: [tooling/orca] — `gh pr merge --delete-branch` makes Orca remove the worker's worktree, so a follow-up `send --to dispatch:` fails
> Tags: orca, worktree, delete-branch, worker | Session: s227

## What happens

After a PR was merged with `--delete-branch`, the worker that built it could no longer receive a message: `orca orchestration send
--to dispatch:<id>` failed because Orca had removed the worker's worktree along with the deleted branch.

## Root cause

Orca ties a worktree to its branch; deleting the branch on merge retires the worktree and its terminal.

## Fix

Send every follow-up (a fix round, a question) BEFORE merging with `--delete-branch`. After the merge, start a fresh worker for any
further work on that card; do not try to resume the old dispatch.

## Related

- [an-orca-claude-worker-exits-after-worker-done-so-its-terminal-is-not-writable](an-orca-claude-worker-exits-after-worker-done-so-its-terminal-is-not-writable.md)
