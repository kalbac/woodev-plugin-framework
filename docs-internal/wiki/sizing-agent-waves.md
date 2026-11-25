# Sizing a wave of Claude workers

> Compiled reference. Last compiled: 2026-11-25 (s245). Operator decision, 25.11.2026: there is no fixed worker count.
> On the Mac the only brake is the shared Claude limit (5-hour and weekly). This replaces the «at most 3 workers» rule
> of 10.11.2026 (s224).

**This page is the one place the procedure lives.** Every other document points here.

## The rule

- Size every wave from a **live** limit reading, the agents **already running** and the **measured** cost per worker.
- **At the wall** (session or weekly limit hit): launch no new worker or critic, no retry loops, wait for the reset the
  message names (operator, 14.11.2026, global rules — unchanged). Never hold work back «to be safe» before that.
- **Never two Fable workers at once** (operator, s199); check the Fable weekly figure before launching one.
- **RAM caps are a separate matter and stay:** Windows desktop 2–3 agents
  ([gotcha](../gotchas/three-agents-is-the-concurrency-cap-on-this-machine.md)); Homelab (14 GB) at most 2–3 of ours
  (froze on 22.10.2026).
- Parallel workers still need disjoint files.
- At a weekly figure of about 95 % or on the operator's word, the coordinator-only mode applies instead of this
  formula: at most two workers, none of them Claude (coordinator memory `low-claude-limit-means-coordinator-only`).

## 1. Read the limit

```bash
claude -p "/usage" 2>/dev/null | grep '^Current'
```

It prints the 5-hour («Current session»), weekly and Fable weekly percentages **used**, each with its reset time.

| Source | Freshness (measured 25.11.2026, Mac) | Use it |
|---|---|---|
| `claude -p "/usage"` | answers at call time with a figure newer than Orca's cache; prints no timestamp. No model call: 0 turns, 0 tokens, about 5 s (Claude Code 2.1.332) | **primary** |
| `orca account list --json` → `result.rateLimits.claude` (`session`, `weekly`, `fableWeekly`, `updatedAt`) | **cached**: read 100–140 s old. Orca's usage-tracking docs: refreshed about every 15 minutes while its window is visible and focused, and on refocus after 5 minutes — an unattended run can be much older | fallback, only after checking `updatedAt` ([gotcha](../gotchas/orca-account-list-serves-a-cached-rate-limit.md)) |
| a session's status line (`rate_limits.five_hour` / `seven_day` `.used_percentage`) | what that session last received; absent before its first API response, stale while it idles | a glance only |
| Codex rollout `rate_limits` | Codex's own limit | not a Claude source |

`claude -p "/usage"` as a scripted command was not found in the documentation (only the status-line page was read), so
treat its output format as unstable. Both computers draw **one** limit: at 22:01 on 25.11.2026 the Mac and Homelab
printed the same percentages. One reading answers for both.

Read the **reset time first**: 39 % left with five hours to go is a lot (s191).

## 2. Count who is already spending it

On this Mac call the CLI through the app bundle ([why](two-machine-setup.md)):

```bash
ORCA=/Applications/Orca.app/Contents/Resources/bin/orca
$ORCA agent list --state working                          # this Mac, every project
$ORCA agent list --environment Homelab --state working    # Homelab
```

- Count the `claude` rows only; Codex and Qwen draw other limits. Check `--state waiting` and `--state blocked` too:
  those resume. `done` means idle at its prompt — it spends nothing until woken, and a coordinator waiting for workers
  may read that way, so count your own coordinator.
- `$ORCA worktree ps` (add `--environment Homelab`) shows which project and bot each agent belongs to.
  `$ORCA orchestration worker-list --include-remote --terminal-state active` lists supervised workers only; there is no
  top-level `orca worker-list`.
- **Not visible to Orca:** headless `claude -p` jobs (cron, n8n), Claude sessions outside Orca (a plain terminal, the
  desktop app, claude.ai chat) and the Windows desktop. Only the percentage shows them: if it climbs faster than your
  count explains, size down.
- Homelab unreachable → say so and treat its load as unknown.

## 3. Compute

```text
free     = 100 − used_5h − margin − (agents already working × cost of each)
workers  = floor(free ÷ cost of one new worker)
```

- **Cost** is percentage points of the 5-hour window per worker per task, from the table below, matched by model and
  task size. Count a running agent at its full cost even if it is half done.
- **Margin: 10 points** — the coordinator's own work, the reports it still has to read, and the ±1 of a whole-number
  reading. A starting value proposed in s245, not an operator decision; correct it from the table.
- **Weekly check.** The week does not bind while `100 − used_week` ≥ days to the weekly reset × 15 (an intensive day
  costs under 20 points, about 15 on average — operator, s176). When it binds, run the same formula on weekly figures
  and take the smaller count.
- **No figure for this model yet:** launch, read again 15 minutes later, use `delta ÷ workers` as the provisional burn,
  add workers only while it fits, and record the row when the wave settles.
- **Result 0** is not the wall: launch at most one worker whose work is pushed in small steps (accepted near the cap in
  s226), or wait if the reset is close.

## 4. Measure every wave

1. Before the launch: read the limit, note the time, the reset time and the agents already working.
2. After the last worker of the wave settles: read again.
3. **Add a row.** Per worker = delta ÷ workers; a mixed-model wave stays one mixed row. A reset inside the interval
   voids that window's delta. Write down every other consumer — it inflates the figure.

Figures belong to the current subscription plan and to effort `high` or lower (one Ultracode run once spent a whole
5-hour window in five minutes). A plan or model-generation change voids them.

| Date | Model | Wave (workers × task) | Δ 5 h | Δ weekly | Per worker | Also spending | Source |
|---|---|---|---|---|---|---|---|
| — | Haiku 5.5 | — | | | | | not measured |
| 25.11.2026 | Sonnet 5.5 | 1 × S docs + research (first 7 min only, before commit and CI) | 2 pp (12 → 14 %) | < 1 pp (5 → 5 %) | ≥ 2 pp (5 h) — lower bound | no other Claude agent `working` at the 4 checks; idle coordinator not excluded | measured s245, this page's own task |
| — | Opus 5.5 | — | | | | | not measured |
| 10.11.2026 | Fable 5.2, then a Sonnet fix round | 1 × L (F5, ~17 files, 114 tests) | not recorded | ≈ 3 pp (93 → 96 %); Fable weekly ≈ 4 pp | ≈ 3 pp weekly | not recorded | [s226](../sessions/s226.md) |
| 26.10.2026 | 2 × Fable | — | limit hit early, no figure | not recorded | — | not recorded | s199, coordinator memory |
| 13.10.2026 | whole day, all agents | an intensive day | — | < 20 pp, ~15 average | per day, not per worker | — | operator, s176, coordinator memory |

## Related

- [Orchestrating agents with Orca](orchestrating-agents-with-orca.md) — launch recipes, briefs, worktrees
- [orca-account-list-serves-a-cached-rate-limit](../gotchas/orca-account-list-serves-a-cached-rate-limit.md)
- [three-agents-is-the-concurrency-cap-on-this-machine](../gotchas/three-agents-is-the-concurrency-cap-on-this-machine.md) — the RAM cap of the Windows desktop
- [Two machines](two-machine-setup.md) — per-machine facts
- [CLAUDE.md](../../CLAUDE.md) → Orca — which model takes which task
