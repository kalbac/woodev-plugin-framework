# Gotcha: [tooling/orca] — take a dispatch id from the start receipt, never from the order of `worker-list`

> Tags: tooling, orca, worker, codex | Session: s146

## What happens

s146 ran a Codex critic (report-file path, no `worker_done`) next to a Claude worker. To clean the critic up
afterwards, the coordinator picked "the newest row" of `orchestration worker-list` as the critic's dispatch
and ran `worker-stop` + `terminal close` on it. The newest row was the Claude WORKER on #1037, launched a
minute later — it was killed mid-task with ~760 uncommitted lines in its worktree. `worker-stop` succeeded
silently; nothing said "this is not the dispatch you meant".

## Root cause

`worker-list` rows are newest-first across every dispatch of the Run. With several launches in flight, row
order says nothing about which one is the critic. The id was inferred, not recorded.

## Fix

❌ `orca orchestration worker-list --json | … workers[0]` to find "the one I just started".

✅ Save the start receipt and read `result.dispatchId` from it; resolve the terminal handle by matching that id:

```bash
orca orchestration worker-start --spec "…" --agent codex --json > start.json
D=$(python3 -c "import json;print(json.load(open('start.json'))['result']['dispatchId'])")
H=$(orca orchestration worker-list --json | python3 -c "import json,sys
d=json.load(sys.stdin);print([w['agentTerminalHandle'] for w in d['result']['workers'] if w['dispatchId']=='$D'][0])")
```

Before any `worker-stop` / `terminal close`, read that terminal's screen and confirm it is the agent you
think it is. If one was stopped by mistake: commit its worktree as a WIP commit at once, then start a
continuation worker on the same worktree whose brief says the WIP is inherited and ungated (s146: the
continuation found a placeholder `if ( false )` the first worker had left).

## Related

- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — why Codex dispatches need manual cleanup at all
- [../wiki/orchestrating-agents-with-orca.md](../wiki/orchestrating-agents-with-orca.md)
