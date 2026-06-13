# Docs Index — Woodev Plugin Framework
> Navigation hub for AI agents. Read this FIRST in every new session. ~2 min read.
> `docs-internal/` — internal technical documentation (not published).
> Last updated: 2026-06-13 (session 13 docs audit).

---

## Session Start (for AI agents)

1. **Read `CURRENT-STATE.md`** — phase status, open bugs, next actions (lean).
2. **Read `platform-v2-program-tracker.md`** — program-level status (stage map, gate board). Per `platform-v2-execution-protocol.md` §0.
3. **Read `GOTCHAS.md`** — scan `[topic/*]` tags relevant to the current task.
4. **Read task-specific docs** — pick from the tables below.

## Session End

1. Update `CURRENT-STATE.md` (lean — phase status, bugs, next actions).
2. Append to `SESSION-LOG.md` (newest on top; 10–20 line summary — this is where detail lives).
3. Compilation step — scan the new entry for gotchas → add to `GOTCHAS.md` + create `gotchas/{slug}.md`.
4. See `DOCS-SCHEMA.md` for the full compilation protocol and format rules.

---

## Operational Docs (live)

| File | Purpose |
|------|---------|
| `CURRENT-STATE.md` | Phase status, open bugs, next actions — read every session start |
| `platform-v2-program-tracker.md` | **Live** program status: stage map (S0–S6), S0 phase board, decisions |
| `platform-v2-execution-protocol.md` | Operating rulebook + resume protocol + authority chain |
| `SESSION-LOG.md` | Chronological session history — newest at top (full detail) |
| `GOTCHAS.md` | Topic-indexed cross-session gotchas (46) → `gotchas/{slug}.md` |
| `FUTURE-BACKLOG.md` | Deferred features, technical debt, triggered findings (B-x) |
| `AGENT-RULES.md` | Workflow + architecture rules (Rule 0 = clean-break policy / ADR-005) |
| `DOCS-SCHEMA.md` | Doc format rules, lint checklist, compilation protocol |

## Architecture & Direction

| File | Purpose |
|------|---------|
| `platform-v2-direction-audit-2026-06-03.md` | Direction source of truth — decisions D-1…D-5 |
| `platform-v2-implementation-spec.md` | Architecture reference — resolver, loader API, platform boundaries |
| `platform-v2-cleanbreak-plan.md` | S0 clean-break plan — **SUPERSEDED/COMPLETE** (history; tracker is authoritative) |
| `platform-v2-base-decomposition-subplan.md` | Phase 4 base-decomposition detail |
| `platform-v2-next-analysis.md` · `platform-v2-strategy-alignment.md` · `platform-v2-roadmap-reconciliation.md` · `platform-v2-dependency-matrix.md` | Earlier planning analysis (still cited) |

## Stage Specs & Plans (record)

| File | Purpose |
|------|---------|
| `platform-v2-s1-shipping-spec.md` (+ `-queue-manifest`, `s1-shipping-spec-planning-brief`) | S1 shipping |
| `platform-v2-s2-boxpacker-spec.md` · `platform-v2-s3-shipping-rate-packing-spec.md` | S2 box-packer + rate/packing seam |
| `platform-v2-s3-licensing-need-license-spec.md` (+ `-plan`) | S3.1 need-license |
| `platform-v2-s3-licensing-ui-spec.md` (+ `-plan`) | S3.2 React license page |
| `platform-v2-s3-licensing-webhooks-spec.md` (+ `-plan`) | S3.3 webhooks + Ed25519 signing |

## Architecture Decision Records

| File | Purpose |
|------|---------|
| `adr/README.md` | ADR index |
| `adr/001` … `adr/007` | Bootstrap loader · plugin-type inheritance (002 superseded by 005) · minimal resolver · loader API · **005 clean-break policy** · capability-gated feature seam · React admin stack |

## Migration / Reviews / Wiki / Autodev

| File | Purpose |
|------|---------|
| `migration/edostavka-data-preservation-checklist.md` · `migration/yandex-...` | Per-plugin release-blocking data contracts (enforced at rewrite time) |
| `reviews/fable5-architecture-review-2026-06-10.md` | Fresh-eyes review → B-1…B-12 triaged into FUTURE-BACKLOG |
| `reviews/autodev-loop-review-2026-06-11.md` · `reviews/remote-deactivation-ux-findings-2026-06-13.md` | Recent reviews |
| `wiki/` | Deep-dive topic references (capability-gated seam, echeck-ach audit, v2 extension point) |
| `autodev-loop-runbook.md` · `autodev-loop-implementation-prompt.md` · `fable5-autodev-orchestrator-prompt.md` · `fable5-architecture-review-prompt.md` | Autodev-loop tooling/prompts |

## Historical reference (kept in place — still cited by active docs)

| File | Note |
|------|------|
| `platform-v2-epic1-spec.md` | Stale bridge-first parts superseded by the implementation-spec; cited by ADRs/analysis |
| `platform-v2-phase6a-*reference-contract-draft.md` · `platform-v2-phase6a-reference-gap-analysis.md` | Non-production Phase 6A reference drafts |
| `audit-2026-06-01.md` | Independent audit; all release-blocker findings resolved (2026-06-02) |
| `platform-v2-migration-contract-template.md` | Phase 6 contract template |

## Archive (`archive/` — passed-gate / completed, no active inbound links)

`p2-pilot-audit-packet` · `p3-cleanbreak-audit-packet` · `p4-decomposition-audit-packet` · `p6-split-done-audit-packet` · `s1-holistic-integration-review-2026-06-07` · `shipping-pattern-conformance-audit-2026-06-10`

## Public Docs

`docs/` (repo root) → GH Pages, public-facing. ⚠️ Registration examples currently teach the v2-tombstoned `register_plugin()` positional API — see `CURRENT-STATE.md` → "Public-docs API staleness".

---

## Related

- `CLAUDE.md` — project overview, commands, architecture, coding conventions (Claude Code)
- `AGENTS.md` — shared project rules (session start/end, coding principles)
- `QWEN.md` — Qwen-specific agent instructions
- `.ai/QUICK-REFERENCE.md` — shared project rules and conventions for all AI agents
