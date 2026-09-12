# A worker started without `--model` inherits the COORDINATOR's model

**Discovered:** s132 (12.09.2026), dispatching the #855 worker.

## The trap

`orca orchestration worker-start --agent claude` with no `--model` does not fall back to the
project's worker model — it takes the session default, which is whatever the **coordinator** is
running. This project's rule is worker = Sonnet 5, coordinator = Opus 5; the launch receipt reports
the omission honestly and it still reads as harmless:

```json
"launch": { "requested": { "agent": "claude", "model": null, "effort": null },
            "effective": { "agent": "claude", "model": null, "effort": null } }
```

`model: null` on BOTH sides looks like «no override», not like «Opus». The only place the truth
appears is the worker terminal's own status line:

```text
Opus 5 (1M context) | D:\…\.orca\worktrees\…\sp10-855-period | kalbac/sp10-855-period
```

That worker then ran an hour of routine implementation on the coordinator's model — token budget
being the operator's standing constraint, this is a real cost and it is invisible in every
orchestration surface.

## ✅ Correct

Name the model on every dispatch, even when it is «the default»:

```text
ORCA orchestration worker-start --spec "…" --worktree <selector> --agent claude --model sonnet --json
```

And verify it the same way the project verifies that a worker started at all — by reading the
terminal, not the receipt:

```text
ORCA terminal read --terminal <handle> --screen --json     # the status line names the model
```

## Related

- [input-accepted-is-not-proof-a-worker-started](input-accepted-is-not-proof-a-worker-started.md) — the same lesson about the same receipt
- [codex-model-terra-is-a-400-that-looks-like-a-warning](codex-model-terra-is-a-400-that-looks-like-a-warning.md) — the other half: an EXPLICIT model can be rejected and look like a warning
- [three-agents-is-the-concurrency-cap-on-this-machine](three-agents-is-the-concurrency-cap-on-this-machine.md)
