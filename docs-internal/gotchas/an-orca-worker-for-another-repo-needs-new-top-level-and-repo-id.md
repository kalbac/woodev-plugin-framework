# An Orca worker for another repo needs `--worktree new-top-level --repo id:<id>`, or it lands in the coordinator's repo

**Namespace:** `[tooling/orca]`
**Found:** s231 (16.11.2026), starting the Ozon Seller O6 worker from the framework coordinator.

## Trap

`orca orchestration worker-start --worktree new-child --base-branch origin/main …` creates the child worktree in the
**coordinator's** repository, whatever the brief says. Run from the framework session, the Ozon worker got
`.orca/worktrees/woodev-plugin-framework/s231-o6` on a framework branch — it would have edited the wrong repo.

## Fix

For a worker in another repository use `--worktree new-top-level --repo id:<repo id> --name <name>` (no `--base-branch`;
the new worktree starts from that repo's default branch). The Ozon Seller repo id on the Mac is
`b32e4939-6a10-4f57-a377-2dee8b397ed4` (from an earlier `worker-start` receipt's `workspace`). Read the receipt's
`workspace` path before doing anything else; if it is under the wrong repo, close the terminal, `git worktree remove --force`
the path, delete the branch, and start again.

## Related

- [orca-removes-a-worker-worktree-when-its-branch-is-deleted-on-merge](orca-removes-a-worker-worktree-when-its-branch-is-deleted-on-merge.md)
