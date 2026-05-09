# Docs Index — Woodev Plugin Framework
> Navigation hub for AI agents. Read this FIRST in every new session. ~2 min read.
> `docs-internal/` — internal technical documentation
> Last updated: 2026-05-09

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

---

## Architecture Decision Records

| File | Purpose |
|------|---------|
| `adr/README.md` | ADR index — list of all architecture decisions |

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
