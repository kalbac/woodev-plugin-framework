# Gotcha: [tooling/claude] — auto mode refuses a merge on the coordinator's own review and a branch overlay on the shared primary checkout

> Tags: auto-mode, merge, primary-checkout, rig | Session: s184

## What happens

Two coordinator actions were blocked by the auto-mode classifier in s184:

1. **Merging a PR on the coordinator's own review** — the rule says an independent critic (another lineage) clears every merge;
   the classifier enforces it.
2. **Overlaying a PR branch into the shared primary checkout** to verify it on the rig (the primary tree is shared by every
   worktree and the rig mounts it).

What WAS allowed: building bundles in the primary checkout with a detached HEAD at the PR head, copying the 2 built files into the
worktree, committing there and switching the primary back.

## Fix

❌ Rewording the command to slip past the classifier, or merging after only a self-review.

✅ Get the critic's verdict first, then merge. For rig verification of a framework branch park the PR for the operator (the primary
tree must serve it), or use the detached-HEAD build-and-copy route for generated bundles only.

## Related

- [auto-mode-refuses-orca-commands-prompted-by-a-worker-message](auto-mode-refuses-orca-commands-prompted-by-a-worker-message.md) — the other classifier refusal
- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — why bundles are built in the primary
- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
