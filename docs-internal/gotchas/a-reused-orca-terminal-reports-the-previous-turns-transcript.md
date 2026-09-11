# A reused Orca terminal reports the PREVIOUS turn's transcript, so a delivered brief reads as never delivered

**Discovered:** s130 (11.09.2026), dispatching a follow-up into a worker that had already reported.

## The trap

`worker-start --terminal <handle>` sends a follow-up brief into a terminal whose worker already
sent `worker_done`. Checking that it arrived with `orchestration worker-read --dispatch <new id>`
returned, eight polls in a row, the tail of the PREVIOUS turn:

```text
assistant turns: 4
  last TEXT: Done. Reported to the coordinator; branch `feat/sp10-menu-badge` pushed at `246464a`.
```

Searching the returned transcript for a distinctive phrase from the new brief found nothing. Every
signal said the dispatch had stalled — which is a real state (`agent_prompt_stalled`, gotcha
`dispatch-inject-reports-failure-after-succeeding`), and the remedy for it is an Enter keystroke.
Sending one would have submitted something into a terminal that was already working.

The rendered screen told the truth:

```text
orca terminal read --terminal <handle> --screen --json
→  … the full brief text …
   ✽ Caramelizing… (57s · almost done thinking with high effort)
```

The agent had the brief and was thinking about it. `worker-read`'s transcript simply lags, and on a
REUSED terminal the lag is indistinguishable from non-delivery, because the previous turn's
transcript is a perfectly plausible "nothing happened yet".

## ✅ Correct

Verify a follow-up dispatch against the SCREEN, not the transcript:

```bash
orca terminal read --terminal <handle> --screen --json
```

`--screen` renders the current frame; the default read returns accumulated output with repaints
stacked, which is unreadable for a TUI. The payload also carries `draft` — UI-only composer text
excluded from the tail — and a non-empty `draft` IS the genuine stalled-prompt signal. Absence of
the brief from the transcript is not.

## Two smaller facts from the same dispatch

- **`--terminal` cannot be combined with `--agent`**: `--terminal reuses an existing agent and
  cannot combine with --agent`. Reusing a terminal means the agent is already chosen.
- **Reuse also needs `--worktree`**, as the full `id:<repo-id>::<path>` selector — see
  `reusing-a-worker-terminal-needs-its-worktree-too`.

## Related

- [dispatch-inject-reports-failure-after-succeeding](dispatch-inject-reports-failure-after-succeeding.md) — the real stalled-prompt state this masquerades as
- [input-accepted-is-not-proof-a-worker-started](input-accepted-is-not-proof-a-worker-started.md) — why the receipt is never the evidence
- [reusing-a-worker-terminal-needs-its-worktree-too](reusing-a-worker-terminal-needs-its-worktree-too.md) — the other flag reuse needs
- [orchestration-mail-is-not-read-unless-the-worker-calls-check](orchestration-mail-is-not-read-unless-the-worker-calls-check.md) — the other way a message does not reach a worker
