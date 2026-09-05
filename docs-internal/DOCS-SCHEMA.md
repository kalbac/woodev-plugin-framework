# Docs Schema — Woodev Plugin Framework
> Format and lint rules for all agent-facing documentation. Read before writing or updating any doc.
> Applies to ALL agents.

---

## Freshness is git's answer, not a hand-written field (s115)

Rule documents used to carry a `Last updated:` stamp in their header. It was written by hand, so it
drifted: **all six were stale**, by 9 days to 3 weeks — and the lint checklist meanwhile demanded
the stamp be current in two files that never had one. A field that lies is worse than no field,
because a reader treats it as fact.

The stamps are gone. Ask git, which cannot drift:

```bash
git log -1 --format=%ad --date=short -- docs-internal/AGENT-RULES.md
```

**What is still stamped, and why:** `sessions/sNN.md` and the handoff carry dates because those are
statements about WHEN something was measured, not about when a file was edited — a measurement
without its date is the trap `CURRENT-STATE.md` warns about.

## Language Rule

**Prose an agent writes for agents is English. What that prose QUOTES keeps its own language.**

⚠ Corrected s115. The rule used to read "exclusively in English", and it was measured against the
tree: **99 of 305 agent-facing files contain Cyrillic.** They are not drift. Classify by what the
text IS, the same way `AGENTS.md` classifies an i18n string by its render path rather than by its
directory.

| Scope | Language |
|-------|----------|
| `docs-internal/gotchas/*.md`, `wiki/*.md`, `adr/*.md` — the agent's own explanation | English |
| `CLAUDE.md`, `QWEN.md`, `AGENTS.md`, `AGENT-RULES.md`, `DOCS-*.md` | English |
| **The operator's own words, quoted** | **His, verbatim** — a translated quote is no longer evidence of what he decided, and this repo settles arguments by quoting him |
| **Domain data** (DaData answers, settlement names, method labels, UI captions) | **As it really is** — «Нижегородская обл, г Бор» translated is a false record |
| **Source strings shown as examples** (i18n msgids, admin notices) | As in the code |
| `next-session-prompt.md`, `CURRENT-STATE.md`, `SESSION-LOG.md`, `sessions/*.md` | **Either** — the operator reads these himself, and they carry most of the Cyrillic in the tree by design |

The point the old wording was protecting is still in force: **an agent must never have to translate
to understand the reasoning.** Explanation in English; the evidence it rests on stays untouched.

**PHP source strings are NOT covered by this rule at all** — they follow the operator's four i18n
rules in `AGENTS.md` → Conventions (#567, 29.08.2026), and those do not say "Russian". In short: a
**storefront** msgid is **ENGLISH** (the Russian arrives from the catalogue, because a shop's
frontend may run an English locale even when its admin does not); an **admin** msgid in Russian is
fine and stays; **logs and exception texts** may be either and need not be wrapped in `__()` at all.
Classify by the RENDER PATH, never by the file's directory.

⚠ This paragraph used to read "PHP source code strings stay in **Russian**" — the pre-#567 rule. It
is now also enforced against: `lint:i18n` (#771, s118) fails on an English msgid with no
translation, so an agent obeying the old text was writing against a live gate.

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

`GOTCHAS.md` is 88 % of the growth, so it gets the slack. The other four are **not
interchangeable**, and what bounds each is a different thing:

| File | What bounds it |
|---|---|
| `CURRENT-STATE.md` | Its own hard rule, "state only, never history" (see [CURRENT-STATE.md Format](#current-statemd-format)). That rule names **this file and no other**. |
| `next-session-prompt.md` | REPLACED wholesale at every session end, so it cannot accumulate — a different mechanism, not that rule. |
| `AGENTS.md`, `CLAUDE.md` | Nothing but the caps above. Which is why these two are the ones that grow. |

At the whole-set rate, 176 KB is ~61 sessions of headroom.

**Why each per-file cap moved, and why one did not.** The card and the operator's decision were
about the SUM. The per-file caps were raised by the agent, not by the operator — recorded here so
nobody reads them as his call. The justification is per file, measured, not a uniform bump:

| File | Was at / old cap | Rate | Sessions to red | Raised? |
|---|---|---|---|---|
| `GOTCHAS.md` | 55 323 / 57 344 | +851 B | **~2** | → 96 KB |
| `CLAUDE.md` | 7 983 / 8 192 | +76 B | **~3** | → 12 KB |
| `AGENTS.md` | 23 192 / 24 576 | +128 B | **~11** | → 28 KB |
| `CURRENT-STATE.md` | 23 907 / 24 576 | −45 B | not growing, but sat **219 B** under at s86 | → 28 KB |
| `next-session-prompt.md` | 10 532 / 16 384 | replaced each session | never | **no** |

The last row is the point: a cap with 5.8 KB of slack on a file that cannot accumulate did not
move. Raising the other four is the same defect #554 names — a red gate at session save, in the
worst possible moment — caught at file scope instead of at the sum.

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

⚠ This block used to specify a `> Status:` / `> Date:` blockquote header while `adr/README.md`'s own
template specified bold fields. The corpus split 11 to 1 in favour of the bold form — and the odd
one out was the NEWEST file, written from this schema. The bold form wins; keep the two documents
saying the same thing.

```markdown
# ADR-NNN: {Title}

**Status:** proposed | accepted | deprecated | superseded

**Date:** YYYY-MM-DD

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
- An H1 title and at least one `## Related` section at the bottom. **That is the whole mandate**, and
  the corpus satisfies it 8 of 8.

Not required, decided s119 after measuring the corpus against the older wording:

- **The `" — Woodev Framework Wiki"` title suffix is dropped.** One article in eight carried it. It
  is ceremony with no reader value — the file's own directory already says it is a wiki article —
  and a rule that the corpus ignores 7 times out of 8 misleads exactly the author who obeys it: they
  produce the file that looks unlike every neighbour and believe theirs is the conforming one.
- **`> Compiled reference. Last compiled: DATE.` is RECOMMENDED, not mandatory** (3 of 8 carry it).
  Use it when the article is genuinely recompiled in passes and the date says *when that pass
  measured things* — that is the same exception the "Freshness is git's answer" section at the top
  of this file grants `sessions/sNN.md` and the handoff. Do NOT add it to an article nobody
  recompiles: there it degrades into the hand-written stamp s115 deleted everywhere else, and a
  field that lies is worse than no field.

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

## Relative links must resolve (gate, s115)

`npm run lint:docs` fails on a link to a file that does not exist. It was added because this
happened **twice in one session**: the handoff promised `s112-706-branch-sweep.md`, which was never
written, and a gotcha's `## Related` pointed at a gotcha slug nobody had created. Both read as
authoritative, and the second session paid for it by rebuilding the method the first link claimed
was already recorded.

**Scope, and why it is narrow:**

- `archive/` is EXCLUDED. It is a historical snapshot whose links point at the layout of their own
  time; rewriting them would falsify the record.
- Fenced code blocks and HTML comments are EXCLUDED, because format documents (this file,
  `GOTCHAS.md`) *show* the link shape as an example rather than using it.
- External links (`http:`, `https:`, `mailto:`) and in-page anchors (`#…`) are not checked.

So the rule is: **in a live document, a link is a promise that the file exists.** If you want to
name a document that is not written yet, say so in prose — do not link it.

## Lint Checklist

Before every commit touching docs:

- [ ] Every new `GOTCHAS.md` entry has `[topic/slug]` prefix, 1-line summary, and link to detail file
- [ ] Every new gotcha has a corresponding `gotchas/{slug}.md` detail file
- [ ] Every gotcha detail file has a `## Related` section
- [ ] Agent-facing PROSE in new/edited `docs-internal/*.md` is English; quotes of the operator, domain data and source strings keep their own language (see Language Rule)
- [ ] `SESSION-LOG.md` new index row is at the **top**, and links an existing `sessions/sNN.md`
- [ ] `sessions/sNN.md` includes PHPStan result + commit hash
- [ ] `npm run lint:docs` passes (session-start budget, index integrity, no history in CURRENT-STATE,
      **and every relative link resolves** — added s115)
- [ ] No `[✅]` bugs older than 2 sessions (remove them)
- [ ] New wiki articles have a `## Related` section
- [ ] No new items appended to `FUTURE-BACKLOG.md` (frozen — backlog lives on GitHub board №6)
- [ ] A board card exists and was moved for the session's work (`В работе` → `Готово`)

---

## Public docs (`docs/`) — how to work with them

Moved here from `AGENTS.md` in s115: it is reference material, not a session-start rule, and
`AGENTS.md` is read at the start of every session against a 28 KB gate.

⚠ **Right now the operator's standing decision is DO NOT TOUCH public docs** — they teach the v1
positional `register_plugin()` (a v2 tombstone) and hardcode versions in five files. He is their
only consumer today; they get rewritten once v2 is ready. See `CURRENT-STATE.md` →
"Public-docs API staleness".

**How to edit**

1. Edit `.md` files directly in `docs/`.
2. Preview locally: `mkdocs serve` (needs Python + mkdocs-material).
3. Use the `%%FRAMEWORK_VERSION%%` placeholder for version numbers — **never hardcode a version**.
   CI injects the real one from `Woodev_Plugin::VERSION` at deploy time.
4. Lint: `npx markdownlint-cli2 "docs/**/*.md"`.

**What belongs here:** API reference, usage guides, getting-started tutorials, module
documentation (settings-api, payment-gateway, shipping-method …), and code examples verified
against actual source.

**What does NOT:** session logs, gotchas, ADRs, bug tracking, phase status, internal architecture
decisions, deferred features, AI-agent workflow rules — all of those live in `docs-internal/`.

**Deploy:** push to `main` → GitHub Actions (`docs.yml`) builds mkdocs → GitHub Pages at
<https://kalbac.github.io/woodev-plugin-framework/>. Triggers on changes to `docs/**`,
`mkdocs.yml` or `woodev/class-plugin.php`.

## Related

- [AGENTS.md](../AGENTS.md) — the two-tier documentation table this detail was lifted out of
- [CURRENT-STATE.md](CURRENT-STATE.md) — the standing "do not touch public docs" decision
