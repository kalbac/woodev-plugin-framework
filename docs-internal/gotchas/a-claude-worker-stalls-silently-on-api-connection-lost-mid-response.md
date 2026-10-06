# Gotcha: [tooling/orca] — a Claude worker that hits «API Error: Connection lost mid-response» just stops; no worker_done ever comes

> Tags: tooling, orca, claude, worker | Session: s143

## What happens

A Claude Code worker under Orca (Sonnet 5 and Fable 5, macOS, 28.09.2026) printed
`⏺ API Error: Connection lost mid-response. The response above may be incomplete.` and ended its turn — idle at the
prompt, dispatch still `live`, no `worker_done`. `check --wait` only times out. It happened three times in one session,
twice to two parallel workers within the same minute.

## Root cause

A dropped connection to the model API ends the agent's turn; nothing in the TUI resumes it.

## Fix

❌ Wait on `check --wait` again, or relaunch the task (repeats finished work).
✅ After a quiet wait, read the worker's terminal tail; on that line, nudge it in place:

```bash
orca terminal send --terminal <H> --text "The API connection dropped mid-response. Continue the same task from where \
you stopped: check git status and your last step, finish what is missing, run the gates, then send worker_done via \
/Applications/Orca.app/Contents/Resources/bin/orca." --enter
```

Every nudged worker in s143 resumed and reported correctly.

## s146: a background watchdog that greps the WRONG JSON field never fires

From s145 a scratchpad watchdog loops over the worker handles and nudges on that line. In s146 it was written to read
`result.tail` — **`orca terminal read --json` (Orca 1.4.217) puts the lines in `result.terminal.tail`**, so the
watchdog read an empty string forever, logged nothing, and two workers sat stalled from 03:15 to 03:53 while every check
looked green. Parse the right field, and look at the LAST lines only (an old «Connection lost» higher up re-fires):

```bash
orca terminal read --terminal "$H" --screen --json | python3 -c "import json,sys
d=json.load(sys.stdin); print('\n'.join(d['result']['terminal']['tail'][-8:]))" | grep -q "Connection lost"
```

Prove a watchdog on a known stalled screen once before trusting its silence — an empty log is not evidence.

## s155: two more stall texts, and a watchdog loop that never ran

Two further ways an agent stops with no `worker_done`, both seen on macOS, Orca 1.4.220, 06.10.2026:

- **Codex: `■ Selected model is at capacity. Please try a different model.`** — the turn ends mid-gate-run and the
  composer comes back. A `terminal send … "Continue the same task …" --enter` resumed it in the SAME dispatch (it had not
  sent `worker_done`, so this is not the settled-worker trap).
- **Claude: `Please run /login · API Error: 403 Request not allowed`.** Transient: the account limits were fine. A first
  nudge made the CLI open its OAuth login prompt (send ESC to cancel it — never complete a login on the operator's behalf);
  a second plain «Continue» resumed the review.

Add both to the pattern list: `hit your usage limit|API Error|Connection lost|at capacity|403 Request not allowed|Please run /login`,
plus the dialog texts (`Update available`, `Hooks need review`).

**And the loop itself:** watching N terminals with `for h …; do watch $h & done; wait -n` does NOT work here — zsh rejects
`wait -n` and macOS `/bin/bash` is **3.2** (`wait: -n: invalid option`); the wrapper exits at once while the children keep
running with nobody listening. Use ONE loop that iterates over all handles and exits on the first alert. Note it also
fires on a terminal YOU closed (`status=exited`) — restart it with the new handle set after every release.

## Related

- [codex-app-server-drops-mid-task-under-orca-resume-by-session-id](codex-app-server-drops-mid-task-under-orca-resume-by-session-id.md) — the Codex equivalent
- [../wiki/orchestrating-agents-with-orca.md](../wiki/orchestrating-agents-with-orca.md)
