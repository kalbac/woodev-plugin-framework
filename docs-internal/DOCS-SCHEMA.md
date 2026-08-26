# Docs Schema — Woodev Plugin Framework
> Format and lint rules for all agent-facing documentation. Read before writing or updating any doc.
> Applies to ALL agents. Last updated: 2026-08-16 (docs cleanup: sessions/ split, index rules, `npm run lint:docs` gate).

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
| `AGENTS.md` | English |

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
| `SESSION-LOG.md` | Index of sessions — one line each | Agent at session end |
| `sessions/sNN.md` | The session's own write-up | Agent at session end |
| `GOTCHAS.md` | Topic-indexed gotcha index | Agent (compilation step) |
| `next-session-prompt.md` | Per-session handoff — REPLACED at every session end | Agent writes |
| `FUTURE-BACKLOG.md` | Deferred features and future work | FROZEN 2026-07-23 — do not append; backlog lives on GitHub board №6 (AGENTS.md → Backlog rule) |
| `adr/README.md` | ADR index | Agent when creating new ADR |
| `adr/NNN-slug.md` | Individual ADR | Agent on major decisions |
| `gotchas/{slug}.md` | Individual gotcha detail | Agent (compilation step) |
| `wiki/{topic}.md` | Topic deep-dive | Agent (compilation step) |
| `specs/*.md` | Feature specifications | Agent when specifying a feature |
| `plans/*.md` | Implementation plans | Agent when planning multi-step work |
| `research/*.md` | Research notes | Agent during investigations |
| `reviews/*.md` | Review reports | Agent after review passes |
| `migration/*.md` | Per-plugin v2 migration docs (incl. data-preservation checklists) | Agent at plugin-rewrite time |
| `archive/*.md` | Superseded documents | Agent when archiving |

---

## Session-start reading budget

Five files are read before any work begins, and `npm run lint:docs` caps both each one and their
sum. **The 176 KB budget is an OPERATOR DECISION (#554, 27.08.2026) — not a derived limit. Do not
cite it as one.**

| File | Cap |
|------|-----|
| `AGENTS.md` | 28 KB |
| `CLAUDE.md` | 12 KB |
| `docs-internal/next-session-prompt.md` | 16 KB |
| `docs-internal/CURRENT-STATE.md` | 28 KB |
| `docs-internal/GOTCHAS.md` | 96 KB |
| **Sum — the binding limit** | **176 KB** |

**The sanity check that sized it, and what is assumption in it.** A 200k-token context, of which
25 % is judged acceptable to spend on session-start reading = 50k tokens; at roughly 3.5 bytes per
token for mixed RU/EN markdown that lands near 176 KB. **Both steps are assumptions:** the 25 %
share is a judgement call with no source behind it, and the bytes-per-token figure is an estimate —
no tokenizer was run. The check says the number is not absurd; it does not prove it. The previous
120 KB had no derivation at all, which is the only sense in which this is better.

**What WAS measured** — byte sizes across s86 (`a0bcace`) → s96 (`5630663`), ten sessions:

| File | s86 | s96 | Delta | Per session |
|---|---|---|---|---|
| `GOTCHAS.md` | 46 810 | 55 323 | **+8 513** | **+851 B** |
| `AGENTS.md` | 21 914 | 23 192 | +1 278 | +128 B |
| `CLAUDE.md` | 7 224 | 7 983 | +759 | +76 B |
| `next-session-prompt.md` | 10 920 | 10 532 | −388 | shrank |
| `CURRENT-STATE.md` | 24 357 | 23 907 | −450 | shrank |
| **whole set** | 111 225 | 120 937 | **+9 712** | **+971 B** |

`GOTCHAS.md` is 88 % of the growth, so it gets the slack. But **the two gateway files do grow** —
only the two under "state only, never history" shrink, and that discipline governs those two and
nothing else. At the whole-set rate, 176 KB is ~61 sessions of headroom.

**If it binds again, do not raise it a third time.** The structural fix is the one #554 also
proposed: split `GOTCHAS.md` into per-tag indexes (`gotchas/INDEX-{tag}.md`), read only the tag map
at session start, and open a tag under the task — which is what the protocol already tells you to do.

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

Topic namespaces are **defined by the section headers of `GOTCHAS.md`** — the index is the source of truth; do not maintain a second list here. (`[js/*]` was added in s59.)

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

## SESSION-LOG.md + `sessions/sNN.md` Format

**The session's write-up is its own file; `SESSION-LOG.md` is only an index.** One line per
session, newest at top:

```markdown
- **[s{N}](sessions/s{N}.md)** — YYYY-MM-DD — {summary}
```

The detail goes in `sessions/s{N}.md`:

```markdown
# s{N} — YYYY-MM-DD — {summary}

**Итог:** merged PRs, test counts, phpcs/phpstan result, commit hash.

- what was done (fact, not "I tried to...")
- key decision with brief reason
- **Bug fixed** — root cause → fix
```

Rules:
- 10–90 lines per session file (guideline — match the session's actual weight)
- Index row and file H1 carry the same summary; the row never grows past one line
- Date in ISO format: `YYYY-MM-DD`; new rows at the **top** of the index
- Include PHPStan result + commit hash in the session file
- No "attempted", "tried to" language — only actual outcomes
- Entries from before session numbering live in
  `sessions/platform-v2-daily-2026-05-28--06-08.md`; s6 and s20 never existed (the numbering
  skipped them) — do not go looking for them

---

## CURRENT-STATE.md Format

Fixed sections, always in this order:
1. `## Phase Status` — table: Phase / Status / Notes
2. `## Known Bugs (open)` — icons: `[⚠️]` open, `[✅]` fixed (remove after 2 sessions)
3. `## Next Actions (priority order)` — numbered list, top = highest priority

**Hard rule:** this file describes the CURRENT state and nothing else. No `Prior:` chain, no
"lessons from session N" section, no history. A lesson is a gotcha (`gotchas/{slug}.md`) when it
is about code or a mechanism, and part of `sessions/sNN.md` otherwise — never a third copy here.
**Enforced by `npm run lint:docs`.**

Optional: `## Infrastructure Reference` section with operational data (build commands, test commands, plugin fixture list).

---

## Handoff Format (`next-session-prompt.md`)

**What it is:** a contract between this session and the next one. Not a summary — that is
`sessions/sNN.md`. Not state — that is `CURRENT-STATE.md`. It answers exactly one question:
**what must the next session know before it can act, and what is it obliged to finish?**

It is the only document read first, every session, without exception. Until 25.08.2026 it was
also the only one with no schema, and the cost was specific: **an unfinished commitment left the
project by simply not being mentioned again.** Nobody noticed, because nothing was broken — a
sentence was just absent. The operator's own framing: *«сегодня в хендофф у нас стоит задача X, мы
её не успели… значит нужно обязательно в следующий хендофф передать, что в сессии X мы обсудили
задачу Y, но в сессии Z не успели»*.

### Required sections, in this order

| # | Section | Carries |
|---|---|---|
| 0 | Header (before the first `#`) | Which BRANCH the working tree is left on and why, open PRs and what each is waiting for, gate numbers **with the date they were measured** |
| 1 | `Ждёт кнопки оператора` | Everything blocked on him, each saying exactly what to look at and where. Empty → write "пусто" explicitly, never omit the section |
| 2 | `Обязательства (перенос)` | Every commitment made and not delivered. **See the rule below — this is the section the whole schema exists for** |
| 3 | `С чего начать` | Ranked. The first item must be unambiguous enough to start on without a decision |
| 4 | `Что доказано замером` | ONLY measured facts, each with the measurement that proves it. An inference goes in the session file, not here |
| 5 | `Ловушки` | New traps only, each pointing at its gotcha. Never a re-listing of old ones |
| 6 | `Состояние на входе` | Table: tree, open PRs, gotcha count, gates |

Wording inside a section is the author's; only the contract is fixed.

### The rule this exists for

**A commitment leaves «Обязательства» in exactly two ways: it is DONE, or the operator explicitly
drops it. Never by silence.**

Each carry-over line must name:

- the issue — `#N`, so it is findable;
- the session that decided it — `sNN`, so the next reader can see it was already settled and does
  not re-open it or, worse, re-ask the operator;
- why it did not ship, and whether it is still committed.

```markdown
- **#518** — выбор ПВЗ снимает «неявность». Решено в **s92**, не начато: сессия ушла на #526 и
  #530. В силе.
```

### Enforced by `npm run lint:docs`

Text alone did not hold for the other formats in this file and will not hold here. The gate checks:

1. all required sections present and in order;
2. every carry-over line carries `#N` **and** `sNN`;
3. gate numbers are quoted with a `DD.MM.YYYY` measurement date — a figure copied forward from a
   previous handoff is an INFERENCE, and s93 lost real time to exactly that (two of s92's baselines
   were wrong and rode into the next session unchallenged);
4. **the drop check, which is the load-bearing one:** every `#N` that appeared in the PREVIOUS
   committed handoff's carry-over section must still be mentioned somewhere in the new one. This is
   a diff against `git show HEAD:` — not a property of the current file — because silence is the
   failure mode, and silence is invisible to any check that only reads what is there.

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

Run at session end, **after** writing the session file, **before** committing:

1. **Scan the new `sessions/sNN.md`** for unrecorded gotchas
2. **For each unrecorded gotcha** — classify → dedup against `GOTCHAS.md` → create `gotchas/{slug}.md` → add index line
3. **Wiki update** — if a pattern was clarified, update the relevant `wiki/{topic}.md`
4. **Keep the gotcha count in the `GOTCHAS.md` header accurate** — the header is 8 lines and holds no changelog; what changed when belongs in `sessions/sNN.md`

---

## Sync Rule

`CLAUDE.md`, `QWEN.md`, `AGENTS.md`, and `AGENT-RULES.md` must NOT duplicate information that lives in `docs-internal/` files:
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
- [ ] `SESSION-LOG.md` new index row is at the **top**, and links an existing `sessions/sNN.md`
- [ ] `sessions/sNN.md` includes PHPStan result + commit hash
- [ ] `npm run lint:docs` passes (session-start budget, index integrity, no history in CURRENT-STATE)
- [ ] `CURRENT-STATE.md` `Last updated:` date is today
- [ ] No `[✅]` bugs older than 2 sessions (remove them)
- [ ] New wiki articles have a `## Related` section
- [ ] No new items appended to `FUTURE-BACKLOG.md` (frozen — backlog lives on GitHub board №6)
- [ ] A board card exists and was moved for the session's work (`В работе` → `Готово`)
