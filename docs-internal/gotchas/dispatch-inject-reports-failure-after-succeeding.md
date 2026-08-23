# gotcha: `dispatch --inject` reports failure after it has already succeeded — and the retry costs you the worker

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s85 (2026-08-21)

## What happens

`orca orchestration dispatch --task <id> --to <handle> --inject` returned a receipt with
`ok: false` on the **first** attempt, three times out of three in s85 — while having actually
injected the brief **and** submitted it. The worker was already working when the receipt said the
dispatch had failed.

The natural reaction — retry the identical command — is what does the damage. The retry injects a
second copy, which lands in the composer of an agent that is now mid-turn, and sits there as a
second `[Pasted Content N chars]`. Worse, the retry mints a **new** dispatch context and revokes the
first one. The worker is still holding the revoked one, so its eventual `worker_done` is rejected:

```text
Dispatch ctx_79b0c12df724 capability is revoked
```

At that point the work is finished and the report is written, but the orchestration layer will not
accept it — the verdict has to be recovered from the body of the rejection message, and the task
closed by hand with `task-update --status completed`.

## ✅ What to do instead

**Never retry `dispatch --inject` on a failure receipt. Read the terminal first.**

```bash
orca orchestration dispatch --task <task_id> --to $H --inject --json    # ignore ok:false for now
sleep 3
orca terminal read --terminal $H --json                                  # ← the actual proof
```

If the buffer shows the agent reading files or `Working (Ns • esc to interrupt)`, the dispatch
landed. Only if the buffer is unchanged — no paste, no activity — is a retry the right move.

This is the same lesson as `input-accepted-is-not-proof-a-worker-started`, pointing the other way:
there the receipt claimed success that had not happened, here it claims failure that had. **Neither
direction of the receipt is evidence.** The terminal buffer is the only thing that is.

## If you already retried

```bash
# the verdict is in the rejection body — copy it out before doing anything else
orca orchestration task-update --id <task_id> --status completed --json
```

Do not restart the worker to "do it properly". The work exists; only the capability token is dead.

## s87 amendment — an `ok:false` inject costs the worker its `worker_done`, not its work

Confirmed again in s87 while dispatching a Codex critic. `dispatch --inject` returned `ok:false`
while the terminal buffer already showed `[Pasted Content 6162 chars]`, so the brief HAD landed. No
retry was made (per this file), the run was submitted with `terminal send --text "" --enter`, and
Codex produced a full, correct verdict — canary quoted verbatim, seven checkpoints answered, five of
them by execution.

**But its `worker_done` was rejected:** `Dispatch ctx_… capability is revoked.` The `ok:false`
inject leaves the dispatch without a live capability token even though the prompt was delivered, so
the structured report can never come back through orchestration.

Practical consequence — plan for it rather than being surprised by it:

- **Tell the critic in its brief to print the verdict IN THE TERMINAL**, and to not retry
  `orca orchestration send` if it is rejected. A critic that spends its last turns fighting a dead
  capability is a wasted round.
- **Read the verdict with `orca terminal read`**, and treat the canary as the proof that the bundle
  was really read. Do not wait on `check --wait` for a dispatch whose inject reported `ok:false` —
  nothing will ever arrive.
- The same run's `orca orchestration check --terminal <handle>` returns `No messages.`, which is not
  a signal about the work either.

A second Codex launched the same way in the same session got `ok:true` from the inject and ran
without any of this, so the failure is per-attempt, not a property of Codex.

## Related

- [input-accepted-is-not-proof-a-worker-started](input-accepted-is-not-proof-a-worker-started.md) — the receipt lying in the opposite direction
- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — where the `[Pasted Content]` behaviour comes from
- `../wiki/orchestrating-agents-with-orca.md` — the launch recipe
