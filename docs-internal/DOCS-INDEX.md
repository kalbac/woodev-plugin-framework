# Docs Index — Woodev Plugin Framework
> Navigation hub for AI agents — open it when you need to find a document, not at session start.
> `docs-internal/` — internal technical documentation (not published).
> Freshness: `git log -1 --format=%ad --date=short -- <file>`.

---

## Session start and end

**The checklists live in `AGENTS.md` → "Session Start" / "Session End" only** — it starts with
`next-session-prompt.md`, not with this file. The mirrored copies that used to sit here had drifted
from it (s135 audit), so they were removed rather than re-synced.

(`platform-v2-program-tracker.md` is a **program-history snapshot**, not a session-start read.)

---

## Operational Docs (live)

| File | Purpose |
|------|---------|
| `next-session-prompt.md` | Prepared entry prompt for the next session — read first |
| `CURRENT-STATE.md` | Live status: phase/track state, open bugs, next actions — read every session start |
| `SESSION-LOG.md` | Index of sessions — one line each, newest at top |
| `sessions/sNN.md` | Per-session detail — the full write-up |
| `GOTCHAS.md` | Topic map (count in its header) → `gotcha-index/{topic}.md` one-line indexes → `gotchas/{slug}.md` |
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

> The table below was three rows for four months while the directories grew to 13 specs and 7 plans
> (flagged by the s104 audit, completed in s119). A design document stays here after its work ships:
> it records WHY the shape is what it is, and the `Shipped` column says which ones that applies to.

| Spec | Purpose | Shipped |
|------|---------|---------|
| `specs/2026-06-25-shipping-module-decisions.md` | **Authoritative map of the active shipping SP-track (SP-1…SP-11)** — program-level decisions locked with the operator | live map |
| `specs/2026-08-06-sp5-pickup-selection-mechanism-design.md` | SP-5 pickup-point selection mechanism | ✅ |
| `specs/2026-08-09-238-cart-change-verdict-invalidation.md` | #238 — wire cart-change verdict invalidation to a real cart-change signal; supersedes the issue's own framing on two points | ✅ |
| `specs/2026-08-09-sp5-viewport-point-accumulation-design.md` | SP-5 viewport point accumulation (#234) | ✅ |
| `specs/2026-08-10-embedded-map-provider-adapter-seam.md` | #251 — make the embedded carrier-widget seam reachable and prove it with a real carrier | ✅ |
| `specs/2026-08-11-sp5-pickup-selection-persistence-design.md` | SP-5 — pickup selection persistence across checkout requests (#176) | ✅ |
| `specs/2026-08-12-location-provider-design.md` | The Location Provider layer — the provider seam itself | ✅ |
| `specs/2026-08-15-location-chain-design.md` | The location chain (#334 + #330) — both cards share one root | ✅ |
| `specs/2026-08-18-location-and-field-settings-brainstorm-input.md` | INPUT to a brainstorm, deliberately not a design — read it as the question, not the answer | n/a |
| `specs/2026-08-18-shipping-settings-v2-design.md` | The «Доставка» tab: Location / Fields / Map | ✅ |
| `specs/2026-08-21-settlement-search-design.md` | Settlement search replacing the preset list — decision 1 shipped in s109, the rest is NOT part of #437 | partial |
| `specs/2026-08-24-popular-settlements-design.md` | Popular settlements — where the list lives and how it is scoped | ✅ |
| `specs/2026-08-25-shipping-tools-section.md` | The «Инструменты» section of the «Доставка» tab | ✅ |
| `specs/2026-09-07-sp10-orders-page-design.md` | SP-10 «Заказы доставки» — the orders page: columns, filters, actions left open by #694 | ✅ |

| Plan | Implements |
|------|------------|
| `plans/2026-08-06-sp5-pickup-selection-mechanism-plan.md` | the SP-5 selection-mechanism spec |
| `plans/2026-08-09-sp5-viewport-point-accumulation-plan.md` | the viewport-accumulation spec (#234) |
| `plans/2026-08-11-sp5-pickup-selection-persistence-plan.md` | the selection-persistence spec (#176) |
| `plans/2026-08-12-location-provider-plan.md` | the Location Provider design |
| `plans/2026-08-18-shipping-settings-v2-plan.md` | the «Доставка» tab design |
| `plans/2026-08-20-shipping-tab-admin-polish.md` | the «Доставка» admin polish batch (#375 #380 #377 #376 #373 #378) |
| `plans/2026-08-24-popular-settlements-slice-3-plan.md` | popular settlements, slice 3 (#488) |

⚠ **Four of those plans open with "REQUIRED SUB-SKILL: `superpowers:subagent-driven-development`".**
That instruction is superseded — parallel work runs through Orca orchestration now
(`AGENT-RULES.md`). The plans are kept as the record of what was built, not as live instructions.

Shipped-work specs/plans are moved to `archive/specs/` and `archive/plans/`.

⚠ **`research/` and `reviews/` are NOT archived** — this line used to say they were, and it was wrong:
both are live directories an agent writes to, and `archive/` holds only the pre-s60 ones. No file count
here: the last two counts written into this line went stale. **The earlier docs audits live in
`reviews/`** — `2026-08-29-docs-and-board-audit.md` (s104) and `2026-09-05-644-part1-contradiction-map.md`
(s119); read them before starting another, so a fixed contradiction is not re-litigated.

## Architecture Decision Records

| File | Purpose |
|------|---------|
| `adr/README.md` | ADR index |
| `adr/001` … `adr/012` | Bootstrap loader · plugin-type inheritance (002 superseded by 005) · minimal resolver · loader API · **005 clean-break policy** · capability-gated feature seam · React admin stack · conditional-fields operator set · map-provider seam (source, not library) · Yandex Maps JS API 2.1 not 3.0 · vendored IMask + generated phone masks · shipping `includes()` stays authoritative |

## Migration / Wiki / Autodev

| File | Purpose |
|------|---------|
| `migration/edostavka-data-preservation-checklist.md` · `migration/yandex-...` | Per-plugin release-blocking data contracts (enforced at rewrite time) |
| `wiki/` | Deep-dive topic references — the full list with a line each is [wiki/README.md](wiki/README.md) |
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
- `wiki/pickup-trigger-placement-and-text.md` — who decides where the checkout pickup button is drawn (the framework) and what it says (the carrier)
- `QWEN.md` — the Qwen gateway; a pointer to `AGENTS.md`
- `.ai/QUICK-REFERENCE.md` — shared project rules and conventions for all AI agents
