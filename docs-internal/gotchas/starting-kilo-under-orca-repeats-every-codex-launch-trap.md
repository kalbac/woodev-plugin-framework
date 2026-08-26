# Gotcha: [tooling/orca] — Launching kilo under Orca repeats every Codex launch trap, plus one of its own
> Namespace: `tooling/orca` — added s97 (2026-08-27)

## What happens

`orca orchestration worker-start --agent kilocode` is rejected outright, and once you get the name
right the worker still fails twice with `agent_prompt_stalled`, then answers the injected brief
with `Provided authentication token is expired` — while the very same model, from the very same
account, answers a `kilo run` on the command line one minute later.

Three separate causes wearing one error each.

## Root cause

**1. The agent id is `kilo`, not `kilocode`.** Orca's settings list the agent as *Kilocode*, but
the CLI takes the COMMAND, which is `kilo`. `--agent kilocode` →
`agent_unconfigured: A configured --agent is required when worker-start creates a terminal.`

**2. kilo shows a version-update dialog on first launch** — *"A new release v7.5.5 is available.
Would you like to update now? Skip / Confirm"* — and the injected brief queues behind it. This is
the same shape as Codex's update prompt, and it carries the same danger: **Enter means Confirm,
i.e. "update now"**, not "submit the brief". Dismiss it with a raw ESC byte; `orca terminal send`
has no `--key` flag, so send the escape character as text:

```bash
orca terminal send --terminal <handle> --text $'\x1b' --json
```

**3. The TUI routes the model through the WRONG PROVIDER, and the error blames authentication.**
The worker answered every brief with `Provided authentication token is expired` while a `kilo run`
of what looked like the same model succeeded seconds later on the same account. Two wrong
explanations were tried and disproved before the real one (a stale TUI session; a stale
credential file). The status bar had it all along:

    Code · GPT-5.6 Luna OpenAI · high        <- provider OpenAI, oauth, token expired
    Code · OpenAI: GPT-5.6 Luna Kilo Gateway <- provider Kilo Gateway, works

The same model is reachable by two routes and kilo picks the one it used last (operator-confirmed
behaviour: with no explicit model, kilo starts on the LAST USED one). Control that settles it in
one line:

```bash
kilo run --model openai/gpt-5.6-luna       "тест"   # -> token is expired
kilo run --model kilo/openai/gpt-5.6-luna  "тест"   # -> answers
```

Always name the provider prefix. "The model" is not an address; `provider/model` is.

## Fix

Start the worker and drive it promptly; do not leave a launched TUI parked.

```bash
orca orchestration task-create --spec "<task>" --json
orca orchestration worker-start --task <task_id> --worktree <selector> --agent kilo --json
# if the receipt says agent_prompt_stalled, read the buffer before doing anything:
orca terminal read --terminal <handle> --json
#   update dialog on screen  -> send ESC (above), never Enter
#   brief sitting unsubmitted -> orca terminal send --terminal <handle> --text "" --enter
```

If the session has gone stale, **release and relaunch** rather than retrying into it:

```bash
orca orchestration worker-release --dispatch <dispatch_id> --json
```

**The model must be set in Orca's Agent Arguments — there is no CLI route.** This is not a
preference; it is the only configuration that works, and s97 proved it by elimination:

| Route | Model reaches kilo? | `--inject` / `worker_done`? |
|---|---|---|
| `worker-start --agent kilo --model <id>` | **No** — `--help` says the flag serves Claude, Codex and Cursor only; the receipt comes back `launch.effective.model: null` | yes |
| `terminal create --command "kilo --model <id>"` | yes | **No** — `dispatch --inject` answers `no recognized agent detected`; Orca only recognises agents it launched with `--agent` |
| `--model <id>` in Settings → Agents → Kilocode → **Arguments** | yes | yes |

Only the third gives both. Set it to a **provider-qualified** id — `kilo/…`, never a bare model
name — for the reason in cause 3 above. Operator rule (27.08.2026): prefer a **discounted**
variant; `kilo models | grep -i discount` lists what is on offer, and on that date it was exactly
one. So the value to put in Arguments is:

    --model kilo/openai/gpt-5.6-sol-discounted

kilo needs no permission flags on Windows: `~/.config/kilo/kilo.jsonc` already carries
`"bash": "allow"` and friends. `--auto` is not required, and `external_directory: "ask"` is worth
keeping — in headless use it becomes auto-reject, which is what stops a critic from reading its way
into the primary checkout.

## Related

- [[starting-codex-under-orca-needs-four-steps-not-one]] — the same launch-dialog trap for Codex;
  kilo proves it is a class, not a Codex quirk.
- [[dispatch-inject-reports-failure-after-succeeding]] — where `agent_prompt_stalled` is defined:
  the brief sits unsubmitted and needs an Enter, not a retry.
- [[orca-terminal-command-bash-lands-in-wsl-on-windows]] — the placement mistake that makes all of
  the above look like an authentication problem instead.
