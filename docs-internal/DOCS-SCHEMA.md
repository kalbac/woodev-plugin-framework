# Docs Schema — Woodev Plugin Framework
> Format and lint rules for all agent-facing documentation. Read before writing or updating any doc.
> Applies to ALL agents. Last updated: 2026-05-09.

---

## Language Rule

**All files in `docs-internal/` that agents read must be exclusively in English.**

| Scope | Language |
|-------|----------|
| `docs-internal/*.md` | English |
| `docs-internal/gotchas/*.md` | English |
| `docs-internal/wiki/*.md` | English |
| `docs-internal/adr/*.md` | English |
| `CLAUDE.md`, `QWEN.md` | English |

**PHP source code strings** (i18n, admin notices) stay in **Russian**. The language rule applies only to agent-facing documentation.

---

## File Structure

All expected files in `docs-internal/`:

| File | Purpose | Who writes |
|------|---------|------------|
| `DOCS-INDEX.md` | Navigation hub — session start/end protocol, doc map | Maintained manually |
| `DOCS-SCHEMA.md` | This file — format rules and lint checklist | Maintained manually |
| `AGENT-RULES.md` | Workflow + architecture rules for AI agents | Maintained manually |
| `CURRENT-STATE.md` | Phase status, bugs, next actions | Agent at session end |
| `SESSION-LOG.md` | Chronological work history | Agent at session end |
| `GOTCHAS.md` | Topic-indexed gotcha index | Agent (compilation step) |
| `FUTURE-BACKLOG.md` | Deferred features and future work | Agent when deferring work |
| `adr/README.md` | ADR index | Agent when creating new ADR |
| `adr/NNN-slug.md` | Individual ADR | Agent on major decisions |
| `gotchas/{slug}.md` | Individual gotcha detail | Agent (compilation step) |
| `wiki/{topic}.md` | Topic deep-dive | Agent (compilation step) |
| `archive/*.md` | Superseded documents | Agent when archiving |

---

## GOTCHAS.md Format

One line per gotcha:

```
- [topic/slug] one-sentence summary → [gotchas/slug.md](gotchas/slug.md) (s{N})
```

Rules:
- `[topic/slug]` tag is **required** — used for topic scanning
- **Max 1 line** per entry — all detail goes in the individual file
- Relative link to the detail file is required
- Session number in parentheses at the end
- If superseded: `~~strikethrough~~` old, add new below

### Valid Topic Namespaces

| Namespace | Covers |
|-----------|--------|
| `[php/*]` | PHP language features, type system |
| `[wp/*]` | WordPress core APIs, hooks, WP_Query |
| `[wc/*]` | WooCommerce compatibility, HPOS |
| `[bootstrap/*]` | Multi-version loading, plugin registration |
| `[lifecycle/*]` | Install/upgrade routines, version bumps |
| `[licensing/*]` | License validation, EDD SL integration |
| `[api/*]` | HTTP API layer, JSON/XML requests |
| `[settings/*]` | Settings API, WooCommerce settings pages |
| `[gateway/*]` | Payment gateway base, admin handlers |
| `[shipping/*]` | Shipping method plugin pattern |
| `[testing/*]` | Brain Monkey, PHPUnit, wp-env |
| `[compat/*]` | Backward compatibility, deprecation cycle |
| `[naming/*]` | Class/method/function naming conventions |

---

## Gotcha Detail File Format (`gotchas/{slug}.md`)

```markdown
# Gotcha: [topic/slug] — Short descriptive title
> Tags: tag1, tag2 | Session: sN

## What happens
[1-3 sentences: the symptom, what goes wrong]

## Root cause
[why it happens — the underlying reason]

## Fix
[❌ wrong / ✅ correct code examples]

## Related
- [link] — why related
```

Rules:
- Filename: kebab-case matching the `[topic/slug]` tag
- Must have a `## Related` section with at least one cross-link
- Code examples: show ❌ wrong and ✅ correct side by side
- Session tag: when it was first discovered

---

## SESSION-LOG.md Format

Chronological, **newest at top**. Each entry:

```markdown
## s{N} ({YYYY-MM-DD}): {1-line summary}

- bullet: what was done (fact, not "I tried to...")
- bullet: key decision with brief reason
- **Bug fixed** — root cause → fix (1 line each)
- PHPStan: ✅/❌ result + commit hash
```

Rules:
- 10–20 lines per session
- Date in ISO format: `YYYY-MM-DD`
- New entries at the **top** of the file
- Include PHPStan result + commit hash
- No "attempted", "tried to" language — only actual outcomes

---

## CURRENT-STATE.md Format

Fixed sections, always in this order:
1. `## Phase Status` — table: Phase / Status / Notes
2. `## Known Bugs (open)` — icons: `[⚠️]` open, `[✅]` fixed (remove after 2 sessions)
3. `## Next Actions (priority order)` — numbered list, top = highest priority

**Hard rule:** Maximum 3 lines of "last session" context. All session details → `SESSION-LOG.md`.

Optional: `## Infrastructure Reference` section with operational data (build commands, test commands, plugin fixture list).

---

## ADR Format

```markdown
# ADR-NNN: {Title}
> Status: {Proposed | Accepted | Deprecated | Superseded}
> Date: {YYYY-MM-DD}

## Context
[What problem are we solving? What constraints exist?]

## Decision
[What did we decide? Be specific.]

## Alternatives Considered
- **Option A:** [description] — rejected because [reason]
- **Option B:** [description] — rejected because [reason]

## Consequences
[What becomes easier/harder? What follow-up work is needed?]

## Related
- [link] — why related
```

---

## Wiki Article Format (`wiki/{topic}.md`)

```markdown
# {Topic Name} — Woodev Framework Wiki
> Compiled reference. Last compiled: YYYY-MM-DD.

## {Section 1}
{content}

## Related
- [{filename}](path) — why it's related
```

Required:
- Title with " — Woodev Framework Wiki" suffix
- `> Compiled reference. Last compiled: DATE.` line
- At least one `## Related` section at the bottom

---

## Compilation Protocol

Run at session end, **after** writing SESSION-LOG, **before** committing:

1. **Scan new SESSION-LOG entry** for unrecorded gotchas
2. **For each unrecorded gotcha** — classify → dedup against `GOTCHAS.md` → create `gotchas/{slug}.md` → add index line
3. **Wiki update** — if a pattern was clarified, update the relevant `wiki/{topic}.md`
4. **Update `Last updated:`** date in `GOTCHAS.md` header

---

## Sync Rule

`CLAUDE.md`, `QWEN.md`, and `AGENT-RULES.md` must NOT duplicate information that lives in `docs-internal/` files:
- **Sprint status** → only in `CURRENT-STATE.md`. Gateway files point to it.
- **Gotcha details** → only in `gotchas/*.md`. Gateway files point to `GOTCHAS.md`.
- **Architecture decisions** → only in `adr/*.md`.

When editing any gateway file, ask: "does another gateway file need the same update?" If yes — the fact should live in `docs-internal/`.

---

## Lint Checklist

Before every commit touching docs:

- [ ] Every new `GOTCHAS.md` entry has `[topic/slug]` prefix, 1-line summary, and link to detail file
- [ ] Every new gotcha has a corresponding `gotchas/{slug}.md` detail file
- [ ] Every gotcha detail file has a `## Related` section
- [ ] `GOTCHAS.md` `Last updated:` date is today
- [ ] All new/edited `docs-internal/*.md` files are in English (no Russian text)
- [ ] `SESSION-LOG.md` new entry is at the **top** of the file
- [ ] `SESSION-LOG.md` entry includes PHPStan result + commit hash
- [ ] `CURRENT-STATE.md` `Last updated:` date is today
- [ ] No `[✅]` bugs older than 2 sessions (remove them)
- [ ] New wiki articles have a `## Related` section
