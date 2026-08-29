# Docs Index — Woodev Plugin Framework
> Navigation hub for AI agents. Read this FIRST in every new session. ~2 min read.
> `docs-internal/` — internal technical documentation (not published).
> Last updated: 2026-08-09 (s60 docs audit).

---

## Session Start (for AI agents)

> Canonical list: `AGENTS.md` → "Session Start". This checklist mirrors it — do not let them diverge.

1. **Read `next-session-prompt.md`** — the prepared entry point for the next session.
2. **Read `CURRENT-STATE.md`** — live status: phase/track state, open bugs, next actions.
3. **Read `GOTCHAS.md`** — scan `[topic/*]` tags relevant to the current task.
4. **Read task-specific docs** — pick from the tables below.

(`platform-v2-program-tracker.md` is a **program-history snapshot**, not a session-start read.)

## Session End

1. Update `CURRENT-STATE.md` (lean — phase status, bugs, next actions).
2. Write `sessions/sNN.md` (this is where detail lives), then add one index line to `SESSION-LOG.md` (newest on top).
3. Compilation step — scan the new entry for gotchas → add to `GOTCHAS.md` + create `gotchas/{slug}.md`.
4. See `DOCS-SCHEMA.md` for the full compilation protocol and format rules.

---

## Operational Docs (live)

| File | Purpose |
|------|---------|
| `next-session-prompt.md` | Prepared entry prompt for the next session — read first |
| `CURRENT-STATE.md` | Live status: phase/track state, open bugs, next actions — read every session start |
| `SESSION-LOG.md` | Index of sessions — one line each, newest at top |
| `sessions/sNN.md` | Per-session detail — the full write-up |
| `GOTCHAS.md` | Topic-indexed cross-session gotchas (count → see `GOTCHAS.md` header) → `gotchas/{slug}.md` |
| `AGENT-RULES.md` | Workflow + architecture rules (Rule 0 = clean-break policy / ADR-005) |
| `DOCS-SCHEMA.md` | Doc format rules, lint checklist, compilation protocol |
| `platform-v2-execution-protocol.md` | Operating rulebook + resume protocol + authority chain |

## Architecture & Direction

| File | Purpose |
|------|---------|
| `platform-v2-direction-audit-2026-06-03.md` | Direction source of truth — decisions D-1…D-5 |
| `platform-v2-implementation-spec.md` | Architecture reference (§5/§9/§10/§12) — resolver, loader API, platform boundaries; sequencing superseded by the direction audit |
| `platform-v2-program-tracker.md` | **Program-history snapshot** (v2 program S0–S6, rewritten s60) — live status is `CURRENT-STATE.md` |
| `platform-v2-s3-licensing-webhooks-spec.md` | S3.3 webhooks + Ed25519 signing — §5 is the FROZEN wire contract, pinned by `LicenseCommandContractParityTest` |

## Specs / Plans / Research (live dirs — what remains after the s60 sweep)

| File | Purpose |
|------|---------|
| `specs/2026-06-25-shipping-module-decisions.md` | **Authoritative map of the active shipping SP-track (SP-1…SP-11)** |
| `specs/2026-08-06-sp5-pickup-selection-mechanism-design.md` (+ `plans/2026-08-06-...-plan.md`) | SP-5 pickup-selection mechanism (current) |
| `specs/2026-08-09-sp5-viewport-point-accumulation-design.md` (+ `plans/2026-08-09-...-plan.md`) | SP-5 viewport point accumulation (current) |

Shipped-work specs/plans are moved to `archive/specs/` and `archive/plans/`; `research/` and `reviews/` are fully archived (see `archive/README.md`).

## Architecture Decision Records

| File | Purpose |
|------|---------|
| `adr/README.md` | ADR index |
| `adr/001` … `adr/010` | Bootstrap loader · plugin-type inheritance (002 superseded by 005) · minimal resolver · loader API · **005 clean-break policy** · capability-gated feature seam · React admin stack · conditional-fields operator set · map-provider seam (source, not library) · Yandex Maps JS API 2.1 not 3.0 |

## Migration / Wiki / Autodev

| File | Purpose |
|------|---------|
| `migration/edostavka-data-preservation-checklist.md` · `migration/yandex-...` | Per-plugin release-blocking data contracts (enforced at rewrite time) |
| `wiki/` | Deep-dive topic references (capability-gated seam, echeck-ach audit, v2 extension point) |
| `autodev-loop-runbook.md` | Autodev loop runbook — implemented (`tools/autodev/`, `.autodev/`), dormant since 2026-06-18 |

## Historical reference (kept in place — still cited by active docs)

| File | Note |
|------|------|
| `audit-2026-06-01.md` | Independent audit; all release-blocker findings resolved (2026-06-02). Still linked from gotchas — kept in place |
| `FUTURE-BACKLOG.md` | **Frozen 2026-07-23** — backlog lives on GitHub board №6; kept for B-x history only |

## Archive (`archive/`)

Passed-gate audits, the completed platform-v2 program docs (plans/specs/prompts), triaged reviews (`archive/reviews/`), and shipped-work `archive/plans/` + `archive/specs/`. Full annotated listing: [archive/README.md](archive/README.md).

## Public Docs

`docs/` (repo root) → GH Pages, public-facing. ⚠️ Registration examples currently teach the v2-tombstoned `register_plugin()` positional API — see `CURRENT-STATE.md` → "Public-docs API staleness".

---

## Related

- `CLAUDE.md` — Claude Code entry point: Serena/Context7 tooling + lookup table (Claude Code)
- `wiki/architecture.md` — subsystems, base classes, seams (opened on demand, not at session start)
- `wiki/rig-pickup-walkthrough.md` — the rig walkthrough for the pickup layer, in the order that actually works (s75); moved here from `CURRENT-STATE.md` in s87
- `wiki/orchestrating-agents-with-orca.md` — how multi-agent work is run here: worker Sonnet / critic Codex, worktree placement, what we did not adopt
- `wiki/local-rig.md` — why the rig's fixtures and options are set the way they are (the pickup mu-plugin, the company field, the two location providers, the live-Yandex switch); moved here from `CURRENT-STATE.md` in s91
- `AGENTS.md` — shared project rules (session start/end, coding principles)
- `QWEN.md` — Qwen-specific agent instructions
- `.ai/QUICK-REFERENCE.md` — shared project rules and conventions for all AI agents
