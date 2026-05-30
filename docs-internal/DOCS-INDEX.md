# Docs Index — Woodev Plugin Framework
> Navigation hub for AI agents. Read this FIRST in every new session. ~2 min read.
> `docs-internal/` — internal technical documentation
> Last updated: 2026-05-30

---

## Session Start (for AI agents)

1. **Read `CURRENT-STATE.md`** — open tickets, bugs, next actions
2. **Read `GOTCHAS.md`** — scan `[topic/*]` tags relevant to current task
3. **Read `DOCS-INDEX.md`** (this file) — identify task-specific docs to load
4. **Read relevant task docs** — pick from the tables below based on current work

---

## Session End

1. Update `CURRENT-STATE.md` — phase status, bugs, next actions
2. Append to `SESSION-LOG.md` — 10–20 line summary
3. Compilation step — scan SESSION-LOG for new gotchas → add to `GOTCHAS.md` + create `gotchas/{slug}.md`
4. See `DOCS-SCHEMA.md` for full compilation protocol and format rules

---

## Operational Docs

| File | Purpose |
|------|---------|
| `CURRENT-STATE.md` | Phase status, known bugs, next actions — read every session start |
| `SESSION-LOG.md` | Chronological session history — newest at top |
| `GOTCHAS.md` | Topic-indexed cross-session gotchas — scan relevant topics |
| `AGENT-RULES.md` | Workflow rules, architecture rules, testing rules |
| `DOCS-SCHEMA.md` | Doc format rules, lint checklist, compilation protocol |
| `platform-v2-dependency-matrix.md` | Platform decoupling audit — module WC deps, P0/P1/P2, open questions A/B |
| `platform-v2-strategy-alignment.md` | Platform v2 strategic alignment — hybrid roadmap, rewrite-first migration, minimal resolver |
| `platform-v2-next-analysis.md` | Platform v2 deep analysis — resolver, loader API, migration contracts, ADR/spec revision plan |
| `platform-v2-implementation-spec.md` | Active Platform v2 implementation source — minimal resolver, explicit loader API, platform boundaries, migration gates |
| `platform-v2-migration-contract-template.md` | Phase 6 template for production plugin migration contracts and installed-site gates |
| `platform-v2-phase6a-reference-gap-analysis.md` | Phase 6A reference-plugin validation of the migration-contract template and workflow gaps |
| `platform-v2-phase6a-edostavka-reference-contract-draft.md` | Phase 6A non-production reference draft that exercises the migration-contract template against copied Edostavka evidence |
| `adr/001-bootstrap-platform-aware-loader.md` | ADR: keep bootstrap as platform-aware loader (accepted) |
| `adr/002-plugin-type-inheritance-with-metadata-bridge.md` | ADR: inheritance + deprecated metadata bridge (accepted) |
| `adr/003-platform-v2-minimal-framework-resolver.md` | ADR draft: minimal resolver behind bootstrap compatibility entry point |
| `adr/004-platform-v2-plugin-loader-api.md` | ADR draft: explicit plugin loader API and metadata limits |
| `platform-v2-epic1-spec.md` | Previous Epic 1 spec — useful evidence, but stale bridge-first parts are superseded by `platform-v2-implementation-spec.md` |

---

## Architecture Decision Records

| File | Purpose |
|------|---------|
| `adr/README.md` | ADR index — list of all architecture decisions |
| `adr/003-platform-v2-minimal-framework-resolver.md` | Proposed ADR: minimal framework resolver for v2.0 |
| `adr/004-platform-v2-plugin-loader-api.md` | Proposed ADR: explicit plugin loader API for v2.0 |

---

## Gotchas

| File | Purpose |
|------|---------|
| `GOTCHAS.md` | Topic-index of all gotchas — one line per gotcha, links to detail files |
| `gotchas/` | One detail file per gotcha — root cause, wrong/correct code, `## Related` |

---

## Wiki Articles

| File | Purpose |
|------|---------|
| `wiki/` | Deep-dive topic reference articles |

---

## Backlog & Planning

| File | Purpose |
|------|---------|
| `FUTURE-BACKLOG.md` | Deferred features and future work |

---

## Archive

| File | Purpose |
|------|---------|
| `archive/` | Superseded or completed plans, historical reference |

---

## Public Docs

| File | Purpose |
|------|---------|
| `docs/` (repo root) | GH Pages documentation — public-facing API docs, getting-started guides |

---

## Related

- `CLAUDE.md` — project overview, commands, architecture, coding conventions
- `QWEN.md` — Qwen-specific agent instructions
- `.ai/QUICK-REFERENCE.md` — shared project rules and conventions for all AI agents
