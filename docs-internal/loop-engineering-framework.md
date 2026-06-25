# Loop Engineering framework (from a video) — independent review input

This is the conceptual framework to assess our `tools/autodev/` loop against.
Do NOT assume anything about our loop from this file — read the actual scripts.

## Core thesis
Manual "write prompt -> wait -> review -> next prompt" does not scale; the developer
degenerates into an "LLM operator" instead of designing the system. Loop Engineering =
designing the OUTER loop around the agent (triggers, schedule, isolation, decomposition,
iteration control, success criteria, limits, stop conditions), not a single better prompt.
Inner loop = what the agent does itself (gather context -> act -> get feedback -> fix).

## 6 components
1. **Automations** — process that starts the loop on schedule/event, does first-pass triage,
   decides if there is work to hand the agent.
2. **Git worktrees** — isolated working dirs so parallel agents don't clobber each other's
   files (does NOT remove merge conflicts at integration time; removes chaos during work).
3. **Skill: triage** — pulls bugs from CI logs, checks open tickets, fills a `todo` file for
   agents to process.
4. **Plugins/connectors (often MCP)** — wire agents to real tools: CI/CD, trackers, DBs,
   alerting/monitoring, GitHub. Close the loop ticket -> PR -> notification.
5. **Subagents** — role separation. Maker/Checker: one writes, another INDEPENDENTLY verifies
   (a model that just wrote code is too lenient to its own output).
6. **Memory** — external source of truth outside the context window; survives between sessions.

## Ralph technique
Atomic task -> commit -> save state -> end iteration. Next iteration = fresh session, clean
context (long sessions degrade: context overflow, stale files, bad reasoning). State lives
OUTSIDE the model (plan.md / status.md or equivalent).

## Task contract (success criteria)
Measurable goal ("coverage to 80%"), machine-checkable criteria (exact command + expected
result, e.g. `npm test` exit 0), boundaries (what must NOT be touched: prod config, DB
schema), limits (<=N iterations / <=2h), stop-factors (integration tests start failing ->
stop, hand to human). No vague "make it good / improve quality / perfect it".

## Maker/Checker
Separate checker runs tests, reads diff, checks against architecture rules + task criteria.
Can't trust the author-agent to self-verify. Stronger checkers (better models) on
audit/architecture/verification; cheaper models on research/context/simple changes.

## Maturity matrix (L1->L5)
- L1: all manual (prompt, review by hand, re-prompt).
- L2: system triages tasks from tracker -> todo; human still writes all code.
- L3: agent edits in isolated worktree; human reviews diff, runs tests, merges by hand.
- L4: a checker (agent/model/rules) validates the PR; human gives final approval.
- L5: system auto-merges after green tests/lints/checks; human controls via logs, alerts,
  periodic code review, not every change.
Do NOT jump straight to L5. Roll out gradually.

## 5 risks (more visible as autonomy grows)
1. Token/budget burn — poorly bounded loop spins empty iterations, runs expensive models.
2. Tests = false security — agent may write tests to confirm its own solution, not real
   requirements; passing tests != correct. Tests are part of verification, not sole truth.
3. Architectural degradation — agent solves locally but breaks overall design (dup code,
   broken imports, needless deps, bypassed abstractions). Checker may miss this.
4. Bottleneck — code-generation speed != engineering-decision speed; cognitive debt grows;
   you lose touch with the project. Code review stays mandatory.
5. Temptation to switch off critical thinking — "app runs, tests pass, checker found
   nothing" feels done but isn't. Automation reduces load, not responsibility.

## Bottom line
Atomic tasks + concrete measurable verifiable criteria; loop with limits, logs, stop
conditions, clear hand-to-human; prompt/context engineering still matter inside the loop;
final responsibility stays with the developer.
