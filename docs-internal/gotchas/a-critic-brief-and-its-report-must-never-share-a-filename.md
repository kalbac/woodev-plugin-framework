# Gotcha: [tooling/orchestration] — a critic brief and its report must never share a filename
> Tags: critic, brief, report-file, coordinator | Session: s227

## What happens

In F6 round 2 the critic wrote its report to the same scratchpad path the round-1 brief lived in. The coordinator then templated the
round-2 brief from that file, which by then held the round-1 REPORT, so the critic was handed the wrong task and its verdict was invalid.

## Root cause

One path used for two roles; the second write silently replaced the first and nothing flagged it.

## Fix

Name by role and round: `brief-<card>-r<N>.md` for the brief, `report-<card>-r<N>.md` for the report, in the scratchpad. A brief is
only ever templated from a `brief-*` file.

## Related

- [a-codex-critic-can-run-an-older-brief-with-a-similar-name](a-codex-critic-can-run-an-older-brief-with-a-similar-name.md)
- [a-codex-critic-writes-a-relative-report-path-into-its-own-worktree](a-codex-critic-writes-a-relative-report-path-into-its-own-worktree.md)
