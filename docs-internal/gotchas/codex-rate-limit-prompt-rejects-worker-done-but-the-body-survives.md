# Gotcha: [tooling/parallel-agents] — Codex's «Approaching rate limits» prompt REJECTS `worker_done` — and the report body survives inside the rejection
> Tags: tooling, orca, codex, parallel-agents | Session: s136
> **Measured on:** macOS laptop, Orca 1.4.200, codex session at 87 %, 13.09.2026.

## What happens

A Codex critic finished a full review and called `worker_done`. Orca delivered this instead:

```text
type: worker_done
payload: {"taskId":"…","dispatchId":"…","outcome":"succeeded",
          "_orcaLifecycleRejection":{"code":"dispatch_capability_invalid",
                                     "reason":"The Dispatch capability is invalid."}}
subject: Rejected worker_done: DO NOT MERGE: …
body:    Orca rejected this worker_done: The Dispatch capability is invalid.

         Original body:
         KEDROVNIK
         VERDICT: DO NOT MERGE.
         …the entire review…
```

⚠ **The report is NOT lost, twice over.** Orca wraps the original body under an `Original body:`
line, so the finding — and the anti-fabrication canary — are both there. And the rejection is
TRANSIENT: measured in the same session, the identical `worker_done` was re-delivered CLEANLY a few
minutes later, with no rejection wrapper and the right `taskId`/`dispatchId`. So a later delivery
can look like a brand-new report from a worker you already released — check the payload's `taskId`
before acting on it, or you will mistake a replay for the next worker's answer. Either way, never
relaunch the critic and pay for the review twice.

The terminal, meanwhile, sits on an interactive prompt:

```text
  Approaching rate limits
  Switch to gpt-5.6-luna for lower credit usage?
› 1. Switch to gpt-5.6-luna                 Fast and affordable agentic coding model.
  2. Keep current model
  3. Keep current model (never show again)
  Press enter to confirm or esc to go back
```

## Root cause

This is a THIRD Codex dialog, distinct from the two already recorded (the update prompt and «Hooks
need review»): Codex raises a **model-switch suggestion when the session rate limit gets close**. It
appears mid-run, not at launch, so no launch-time recipe prevents it. While it is up, the dispatch
capability the agent holds is no longer valid, and the lifecycle message is refused.

⚠ **`terminal send` cannot clear it.** It answers

```text
ok: false
error: agent_prompt_blocked … Re-issue the exact command with --retry-request <id> --wait-submit <seconds>
```

and re-issuing exactly as instructed answers `agent_prompt_blocked` again. Do not loop on it.

## Fix

The task settles anyway — `task-list` showed the task `completed` — so the correct move is cleanup,
not recovery:

```sh
# ❌ wrong — relaunching the critic pays for the same review twice
orca orchestration worker-start --spec "review it again" …

# ❌ wrong — the prompt refuses input, with or without --retry-request
orca terminal send --terminal <handle> --text 3 --enter

# ✅ correct — read the body out of the rejection, then release and close
orca orchestration worker-release --dispatch <dispatch_id>
orca terminal close --terminal <handle>
```

**Prevention:** check `orca account list` before launching, and when a provider's SESSION window is
near its cap, either wait for its reset or launch on the other provider. The prompt is the provider
telling you what the rate-limit numbers already said.

## Related

- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — the launch-time dialogs; this one is different because it fires MID-RUN
- [orca-account-list-serves-a-cached-rate-limit](orca-account-list-serves-a-cached-rate-limit.md) — why the number you checked before launching may already be stale
- [a-codex-bundle-over-32k-needs-a-file-and-an-in-file-canary](a-codex-bundle-over-32k-needs-a-file-and-an-in-file-canary.md) — the canary that proves a report is real, and which survives this wrapping
