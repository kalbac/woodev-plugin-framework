# Gotcha: [tooling/parallel-agents] — a critic cannot be given a fresh worktree for the branch it is reviewing

> Tags: tooling, parallel-agents, orca, review | Session: s121

## What happens

The standard review shape here is *worker writes on a branch → critic reviews it, nobody accepts
their own work*. The obvious dispatch is: give the critic its own worktree (so it cannot disturb the
author) and have it check out the author's branch there.

**Git refuses.** In s121:

```
fatal: 'fix/i18n-sources-gate' is already used by worktree at
'D:/Projects/woodev_framework/.orca/worktrees/woodev_framework/i18n-sources-gate'
```

The critic adapted on its own — it went and worked **inside the author's worktree** instead,
reporting that it had done so. The review was sound and it left the tree clean, but that is luck
rather than process: had the author still been running, two agents would have been editing one
checkout, which is the exact loss recorded in `two-agents-one-file-is-the-orchestrator-s-bug`. And a
critic that runs adversarial probes — this one deliberately added a throwaway string to
`woodev/bootstrap.php` to prove the gate could go red — is *writing* to that tree, not just reading it.

## Root cause

Git allows a branch to be checked out in exactly one worktree at a time; every other worktree is
refused. This is not an Orca limitation and no Orca flag works around it. The orchestrator's mental
model — "a fresh worktree isolates the critic" — is simply false for the one branch the critic
exists to look at.

## Fix

❌ Dispatching a critic with `--worktree new-top-level` and a brief that says "check out the
author's branch". It cannot, and what it does instead is unspecified.

✅ Give the critic a **detached checkout of the commit**. Detached HEAD is not a branch, so git
permits it in any number of worktrees at once. Verified in s121 on the very worktree that had just
been refused the branch:

```bash
git checkout fix/i18n-sources-gate     # fatal: already used by worktree at …
git checkout --detach a1ca03e          # succeeds — HEAD is now at a1ca03e
```

So the brief should name the **commit SHA**, not the branch, and its step 0 should be
`git checkout --detach <sha>` followed by `git log --oneline -1` to prove which commit is under
review. That also makes the review reproducible: a branch moves, a SHA does not.

✅ The alternative, when the author is definitively settled: hand the critic **the author's own
worktree** explicitly with `--worktree id:<repoId>::<path> --terminal <handle>` and say so in the
brief. Acceptable only after the author's dispatch has reported `worker_done` — never while it is in
flight.

⚠ Either way, a critic that writes probes must revert them and prove it with `git status --porcelain`.
Expect (on a commit before s135) the seven CRLF-only files every fresh worktree was born with to show up there and not be its
doing — see the Related link.

## Related

- [two-agents-one-file-is-the-orchestrator-s-bug](two-agents-one-file-is-the-orchestrator-s-bug.md)
  — what happens when two agents do end up in one checkout; placement is the orchestrator's job.
- [an-orca-worktree-starts-dirty-with-crlf-churn](an-orca-worktree-starts-dirty-with-crlf-churn.md)
  — the dirty files a critic's `git status` will show that are not its own.
- [reusing-a-worker-terminal-needs-its-worktree-too](reusing-a-worker-terminal-needs-its-worktree-too.md)
  — the `--terminal` + `--worktree` pairing the second fix above depends on.
- [serena-activate-path-must-be-the-worker-s-worktree](serena-activate-path-must-be-the-worker-s-worktree.md)
  — the related trap: whichever tree the critic ends up in, its Serena activation must point there.
