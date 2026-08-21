# gotcha: starting a Codex worker under Orca takes four steps — `worker-start --agent codex` alone lands in a shell

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s84 (2026-08-21)

## What happens

`orca orchestration worker-start --agent codex` exits 0 with `stage: input_accepted` and an effect
list that looks correct. Three times out of four in s84 the terminal it produced was **PowerShell**,
not Codex, and the injected brief was executed by the shell as a here-string:

```text
>> ## RULE 2 — Gates you MUST run before reporting
>>     npm run test:js -- --roots "<rootDir>/tests/js"
ParserError:
     | Missing closing ')' in expression.
PS D:\Projects\woodev_framework\.orca\worktrees\woodev_framework\fix-411-truncated-list>
```

`orca terminal list --json` confirms it: the title is the `pwsh.exe` path, and the worktree has
exactly one terminal. `worker-show` still reports `ready` and `dispatched`.

The same launch works for Claude every time. It also worked for Codex on the very first
new-top-level create of the session and then stopped working — including on a fresh
`new-top-level`, so it is not specific to reusing an existing worktree.

## ✅ The four-step launch that works every time

```bash
# 1. Real Codex terminal, launched with an explicit command
H=$(orca terminal create --worktree "id:<repoId>::<path>" --title codex-x --command "codex" --json \
      | jq -r .result.terminal.handle)

# 2. Clear the update dialog. `tui-idle` reports satisfied:false with
#    blockedReason "codex-update-prompt" until you do.
orca terminal wait --terminal $H --for tui-idle --timeout-ms 120000 --json
orca terminal send --terminal $H --text $'\033' --json     # ESC
orca terminal wait --terminal $H --for tui-idle --timeout-ms 60000 --json   # now satisfied:true

# 3. Deliver the task
orca orchestration dispatch --task <task_id> --to $H --inject --json

# 4. SUBMIT it — the brief arrives as "[Pasted Content N chars]" and sits there
orca terminal send --terminal $H --text "" --enter --json
orca terminal read --terminal $H --json     # look for "Working (Ns • esc to interrupt)"
```

Step 4 needed a second `--enter` more than once: if the paste has not finished rendering, the first
Enter lands on an empty composer and does nothing. Always read the buffer back.

## Recovering a botched launch

`worker-stop` moves the task to `blocked`, and `dispatch` then refuses it:

```text
Task task_… is blocked; only ready tasks can be dispatched
```

So the recovery is:

```bash
orca orchestration worker-stop --dispatch <ctx_id> --json
orca orchestration task-update --id <task_id> --status ready --json
# … then the four steps above
```

## Also worth knowing

`orca orchestration worker-release` returns `state: "retained"`, `reason: "user_takeover"` for any
terminal the coordinator ever wrote to with `terminal send` — which, given step 4, is every Codex
worker. Those must be closed with `orca terminal close --terminal <handle>`.

## Related

- [input-accepted-is-not-proof-a-worker-started](input-accepted-is-not-proof-a-worker-started.md) — the s83 half of this: the receipt lies about delivery
- [codex-shell-sandbox-broken-windows](codex-shell-sandbox-broken-windows.md) — why Codex must run in an Orca terminal at all
- `../wiki/orchestrating-agents-with-orca.md` — the launch recipe
