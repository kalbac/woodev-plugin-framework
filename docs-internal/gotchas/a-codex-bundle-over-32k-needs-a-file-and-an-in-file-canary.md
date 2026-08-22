# gotcha: a Codex bundle bigger than ~32 KB cannot be injected — use a file path plus a canary that lives *inside* the file

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s85 (2026-08-22)

## The two constraints that collide

**Constraint 1 — Codex must be given the code inline.** With its shell sandboxed, Codex does not
report that it could not read a file; it fabricates the contents and reviews the fabrication
(gotcha `codex-shell-sandbox-broken-windows`). So critic briefs in this project are built as
self-contained bundles: brief + full diff + relevant source, pasted in.

**Constraint 2 — the bundle is passed as an argv string, and Windows caps that at 32767
characters.** `orca orchestration task-create --spec "<bundle>"` and `orca terminal send --text
"<bundle>"` both hit it. There is no `--spec-file`. A 27 KB bundle squeaks through; a 55 KB one
cannot, and neither can anything with a full test file in it.

## ✅ The shape that satisfies both

Put the bundle in a file, pass only the path — and defeat fabrication with a canary token that
exists **only inside the file** and is never named in the dispatch:

```text
--spec "You are an adversarial CRITIC. Your entire brief, including the full diff, is in:
  <absolute path>/critic-456.md

STEP 1: read that file completely, with your own tools.
STEP 2: its FIRST line is a canary token. Reproduce that canary line VERBATIM as the very
        first line of your reply. It is deliberately not repeated here, so you can only
        produce it by actually reading the file. If any tool call fails and you cannot read
        the file, say exactly 'CANNOT READ BUNDLE' and stop — do NOT guess, summarise or
        reconstruct what you think the file says.
STEP 3: follow the brief in that file."
```

And the file's first line:

```text
CANARY: echo the exact line `CANARY-456-SELECT2-OK` as the FIRST line of your reply. If you
cannot see a line in this message reading exactly that, STOP and say so.
```

**The token must never appear in the dispatch text.** That is the whole mechanism: a model that
fabricated the file cannot produce a string it was never shown. Give it a different value per
bundle so one run's canary cannot be reused by the next.

Both s85 critics returned the correct canary, and the terminal buffer independently showed
`Explored └ Read critic-456.md`. Two independent confirmations of the same fact, which is what you
want before trusting a verdict.

## Also observed in the same run

- **The four-step Codex launch collapsed to three.** `dispatch --inject` submitted on its own; the
  brief did not sit as an unsubmitted `[Pasted Content]`, so step 4 (`terminal send --text ""
  --enter`) was unnecessary. The ESC for the update dialog was still required. Do not remove step 4
  from the recipe on this evidence — read the buffer and only send Enter if the paste is sitting
  there.
- **Codex's shell worked.** It read a file outside the repo with its own tools. That is contrary to
  `codex-shell-sandbox-broken-windows`, so the sandbox situation may have changed — but the canary
  costs nothing and is what proved the shell worked. Keep using it; do not go back to trusting the
  read.
- A stream of `PostToolUse hook (failed) — error: hook exited with code 1` lines is noise from a
  hook, not from the review. Both critics completed and delivered verdicts through it.

## Related

- [codex-shell-sandbox-broken-windows](codex-shell-sandbox-broken-windows.md) — why inline bundles exist at all
- [starting-codex-under-orca-needs-four-steps-not-one](starting-codex-under-orca-needs-four-steps-not-one.md) — the launch recipe this amends
- [dispatch-inject-reports-failure-after-succeeding](dispatch-inject-reports-failure-after-succeeding.md) — why the terminal buffer, not the receipt, is the proof
