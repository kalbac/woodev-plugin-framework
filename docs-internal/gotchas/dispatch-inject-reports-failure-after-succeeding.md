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

## Related

- [input-accepted-is-not-proof-a-worker-started](input-accepted-is-not-proof-a-worker-started.md) — the receipt lying in the opposite direction
- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — where the `[Pasted Content]` behaviour comes from
- `../wiki/orchestrating-agents-with-orca.md` — the launch recipe
