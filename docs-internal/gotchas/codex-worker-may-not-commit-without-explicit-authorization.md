# Gotcha: [tooling/codex] — a Codex worker under Orca may leave its changes uncommitted
> Tags: codex, orca, commit, authorization | Session: s178

An Orca-dispatched Codex worker may refuse to commit, push or open a PR because its injected preamble treats external posting as forbidden. This happened in four s178 first-round workers. The task can look complete while the branch still has uncommitted or unpublished changes.

❌ **Wrong:** assume the worker will commit because the task asks it to deliver a PR, or trust a prose report without checking the branch and remote.

✅ **Correct:** explicitly authorize commit and push in the worker brief when intended. Otherwise, the coordinator checks the diff and owns the commit/push. Confirm the resulting commit and remote branch before declaring delivery complete.

## Related

- [a-codex-worker-refuses-the-full-path-orca-binary-unless-the-brief-authorizes-it.md](a-codex-worker-refuses-the-full-path-orca-binary-unless-the-brief-authorizes-it.md) — another Orca preamble authorization trap
- [../gotcha-index/tooling.md](../gotcha-index/tooling.md) — tooling index

## s186 (17.10.2026): even an explicit «the coordinator delegates the commit» did not help — 3 of 3

Three Codex luna workers (CDEK #1269, framework #1289, and one fix that did commit — yandex#120 — was the exception, 1 of 4
committed) were told in the brief «The coordinator delegates the commit: commit, push, `gh pr create`». Two left the work
uncommitted and reported «commit and PR remain with the coordinator». Plan for it: on a Codex worker_done, check `git status`
in its worktree first; when the diff is there and uncommitted, the coordinator commits, pushes and opens the PR itself (costs
~1 min) rather than nudging the worker for another round.
