# #644 part 1 — contradiction map, s119 (2026-09-05)

**Scope:** the rule-bearing and gateway documents — `CLAUDE.md`, `AGENTS.md`, `QWEN.md`,
`.ai/QUICK-REFERENCE.md`, `docs-internal/{AGENT-RULES,DOCS-INDEX,DOCS-SCHEMA,CURRENT-STATE,
next-session-prompt}.md`, `platform-v2-execution-protocol.md`, `adr/`, `wiki/`, and the live
`specs/`, `plans/`, `research/`, `reviews/` directories.

**Method:** s39 — build the map before making a single fix. Every claim below was checked against
the artefact it describes (a config file, the filesystem, `composer.json`, the code), never against
another document. Nothing here rests on memory.

**Relationship to the earlier passes.** s104 (`2026-08-29-docs-and-board-audit.md`) built the first
map and fixed most of it; s115 added the mechanical gates (`lint:docs` now checks relative links,
the gotcha counter and the session-start budget) and reported the `## Related` sweep clean. Two
things those passes did not cover, and both turned out to be where the defects were:

1. **`.ai/QUICK-REFERENCE.md` and `QWEN.md` were never in scope.** s104's map states that the
   gateway files "do not contradict each other" — it names `CLAUDE.md`, `AGENTS.md` and
   `AGENT-RULES.md` only. The two gateways it did not read carry the most serious contradictions
   found in this pass, including a superseded backward-compatibility policy stated as current.
2. **The mechanical gates check what is *linked*, not what is *listed*.** Three index documents are
   missing entries for files that exist. A missing row breaks no link, so no gate sees it.

---

## A. Load-bearing contradictions — a doc states a rule that another doc has superseded

### A-1. `.ai/QUICK-REFERENCE.md` teaches the pre-ADR-005 deprecation policy as CRITICAL

`.ai/QUICK-REFERENCE.md` says, in two places:

> **Breaking Changes** | Avoid | **Require deprecation cycle + major version bump**

> 1. NEVER delete public methods/classes without deprecation cycle
> 2. ALWAYS use `@deprecated` annotation + `_deprecated_function()` call

`AGENT-RULES.md` Rule 0 and `adr/005-platform-v2-clean-break-policy.md` say the opposite for
internal code, in the imperative: *"Do **NOT** add `@deprecated` shims, `class_alias` files, or
`_deprecated_function()` wrappers for moved/renamed internal APIs — delete existing ones."*

This is the worst shape a stale doc can take: it does not merely fail to mention the new rule, it
instructs the reader to do the thing the new rule forbids, and it labels that instruction CRITICAL.
`QWEN.md` carries the corrected clean-break text; `.ai/QUICK-REFERENCE.md` was left behind.

**Severity: high.** `.ai/` is the shared surface — its own "Knowledge Persistence" section tells
every agent to record project rules there "so all agents (Claude, Qwen, Cursor, etc.) share the same
knowledge."

### A-2. `QWEN.md` gives the `@since` rule that operator decision #409 overturned

> **@since Annotation:** Use version from `VERSION` constant in `woodev/class-plugin.php`

`AGENT-RULES.md` Rule 5 says the opposite and names the decision: `@since` is the **planned
release** (`2.0.2`), *not* the `VERSION` constant (`2.0.1`), because raising `VERSION` on `main`
publishes a release. The machine-readable authority is `composer.json` →
`extra.woodev.planned-release` (verified: `2.0.2`), gated by `tests/unit/SinceTagCeilingTest.php`.

An agent following `QWEN.md` writes `@since 2.0.1` and the gate rejects it.

### A-3. `QWEN.md` names the loader-definition field that the validator does not accept

> Every loader definition must set `version` (the framework version the plugin bundles)

`AGENT-RULES.md` Rule 3 was corrected in s115 on exactly this point: the field is
**`framework_version`**; `version` is only the name it is mapped to internally
(`class-framework-plugin-loader-definition.php:258`). The correction was applied to `AGENT-RULES.md`
and not to `QWEN.md`, so the wrong name survives in a gateway doc.

### A-4. `DOCS-SCHEMA.md` states an i18n rule the operator replaced on 29.08.2026

> **PHP source code strings** (i18n, admin notices) stay in **Russian**

`AGENTS.md` → Conventions carries the operator's four rules (#567, 29.08.2026), and the first of
them is the opposite: **a storefront msgid is ENGLISH**, with the Russian arriving from the
catalogue, because a shop's frontend may run an English locale. Admin Russian is fine; logs and
exception texts may be either and need not be wrapped at all. The blanket "stay in Russian" is the
rule as it stood before that decision.

This one is now also enforced against: `npm run lint:i18n` (added by #771 in s118) fails on an
English msgid with no translation — an agent obeying `DOCS-SCHEMA.md` would be writing against a
live gate.

### A-5. `platform-v2-execution-protocol.md` — three stale rules in a doc `DOCS-INDEX.md` lists as live

`DOCS-INDEX.md` lists it under **Operational Docs (live)** as "Operating rulebook + resume protocol
+ authority chain", and the file calls itself *"the single rulebook every session and every
sub-agent follows"*. Three of its rules are superseded:

| § | It says | Current rule |
|---|---|---|
| §0 Resume protocol | Start with `CURRENT-STATE.md`, then `GOTCHAS.md` | `AGENTS.md` → Session Start is canonical and starts with **`next-session-prompt.md`**, which this protocol never mentions |
| §5 Sub-agent strategy | Use `superpowers:subagent-driven-development`; fan out `Explore` agents | `AGENT-RULES.md` → "Subagent-Driven Execution": run it through **Orca orchestration, not in-process subagents** |
| §6 Review | External critic is **GPT-5.5**, packets handed to the operator | The critic is **Codex** (subscription renewed 29.08.2026); kilo is the documented fallback |

The same `superpowers:subagent-driven-development` instruction appears verbatim in the header of
four `plans/*.md` files. Those are historical plans for shipped work, so they mislead less — but
they are in a live directory and say "REQUIRED SUB-SKILL".

---

## B. Factual errors — a doc asserts something the artefact contradicts

### B-1. `.ai/QUICK-REFERENCE.md`: "Serena indexes only `woodev/` directory"

`.serena/project.yml` does the reverse — it lists `ignored_paths` (`tests/**`, `docs/**`,
`.github/**`, `.ai/**`, `.serena/**`, `.claude/**`) and indexes everything else, `src/`, `scripts/`
and `bin/` included. The practical consequence is the one recorded twice as a cost (s105, s112): an
agent told "Serena covers only `woodev/`" does not learn the rule it actually needs — that Serena
**refuses** those six paths, so `Read`/`Grep` there is the only tool and is not a rule violation.

### B-2. Four dangling cross-references in `.ai/QUICK-REFERENCE.md`

It points at `CLAUDE.md > Commands`, `CLAUDE.md > Code Style`, `CLAUDE.md > Backward
Compatibility`, `CLAUDE.md > Commit & Release`, and closes with "CLAUDE.md — Single source of truth
for project knowledge". Today's `CLAUDE.md` has none of those sections: it is a thin Claude-Code
gateway whose own header says it "adds only what is specific to Claude Code" and "restates nothing"
from `AGENTS.md`. The single source of truth is `AGENTS.md`.

These are prose references, not markdown links, which is exactly why the s115 link gate does not
catch them.

### B-3. The fixture count is one short in three documents

`AGENTS.md`, `AGENT-RULES.md` and `QWEN.md` disagree with each other *and* with the tree:

| Document | Says |
|---|---|
| `AGENTS.md` | "7 test plugins" |
| `AGENT-RULES.md` | "seven plugins", then enumerates seven |
| `QWEN.md` | "Three minimal plugins in `tests/_fixtures/`" |
| **The tree** | **eight directories carry a `Plugin Name:` header** |

The eighth is `woodev-entry-path-fixture`, added 04.09.2026 by `b1fe9bf` (#763) — the day before
this audit. (`tests/_fixtures/dadata` is JSON response data, not a plugin, and is correctly absent
from every count.)

### B-4. `DOCS-INDEX.md`: "`research/` and `reviews/` are fully archived"

`docs-internal/research/` holds 2 files and `docs-internal/reviews/` holds 10 — including
`2026-08-31-644-prioritisation-material.md`, which is material for this very card. The statement was
true at the s60 sweep and has been overtaken by three years' worth of sessions writing into both
directories. `DOCS-SCHEMA.md`'s File Structure table, correctly, still lists both as live dirs an
agent writes to — so the two documents contradict each other as well.

### B-5. `QWEN.md` gives the vendor-default rig ports

> **Development URL:** http://localhost:8888 · **Test URL:** http://localhost:8889

`CURRENT-STATE.md` records dev `:8973` / tests `:8974`, set in the gitignored
`.wp-env.override.json` — verified, the file exists and contains `"port": 8973`. An agent following
`QWEN.md` browses to a port nothing is listening on.

### B-6. `QWEN.md` still describes `SESSION-LOG.md` as the write target

It calls `SESSION-LOG.md` "full session history" and its Knowledge Persistence section says to
"append to `docs-internal/SESSION-LOG.md`". Since the split, that file is an **index only** — one
line per session — and the write-up goes to `sessions/sNN.md`. `AGENTS.md`, `AGENT-RULES.md`,
`DOCS-INDEX.md` and `DOCS-SCHEMA.md` all agree on the split; `QWEN.md` alone did not get it.

`QWEN.md`'s Knowledge Persistence section also omits the backlog rule entirely, so an agent
following it files no card at all. That is a gap rather than a contradiction, but the backlog rule
is marked MANDATORY in `AGENTS.md`.

### B-7. `QWEN.md`'s directory map omits eight of `woodev/`'s top-level entries

Missing: `account/`, `assets/`, `competitor/`, `http/`, `languages/`, `licensing/`,
`settings-page/`, `setup/`. `AGENTS.md`'s repository map is better but also omits `account/`,
`assets/`, `competitor/`, `http/`, `languages/`, `settings-page/` and `setup/`.

---

## C. Index documents missing entries for files that exist

No gate sees these: an absent row breaks no link.

| Index | Lists | On disk | Missing |
|---|---|---|---|
| `adr/README.md` | 001–010, 012 | 001–012 | **ADR-011** (vendored IMask + generated phone masks, accepted 2026-08-XX). The index jumps 010 → 012 |
| `wiki/README.md` | 5 articles | 8 articles | `architecture.md`, `local-rig.md`, `orchestrating-agents-with-orca.md` — including the two most frequently opened |
| `DOCS-INDEX.md` | 3 specs, 0 plans | 13 specs, 7 plans | 10 specs and all 7 plans. **This is the item s104 flagged and consciously left** ("writing an accurate one-line purpose for each of 10 specs needs a read of each one; out of this lap's budget") |
| `DOCS-INDEX.md` | `adr/001 … adr/010` | 012 | fixed at the start of this session |

ADR-011's omission is a protocol miss, not an oversight of judgement: `adr/README.md`'s own
"Creating an ADR" list has "3. Add entry to this index" as an explicit step.

---

## D. A schema that one file in eight obeys

`DOCS-SCHEMA.md` → "Wiki Article Format" mandates two things:

- a title with the suffix `" — Woodev Framework Wiki"`;
- a `> Compiled reference. Last compiled: YYYY-MM-DD.` line.

Measured across the eight articles:

| Requirement | Conforming |
|---|---|
| Title suffix | **1 of 8** — only `orchestrating-agents-with-orca.md` |
| `Compiled reference` line | **3 of 8** — plus `architecture.md`, `local-rig.md` |
| `## Related` section | 8 of 8 ✅ (the part s115 measured) |

The s115 sweep checked `## Related` and reported the wiki clean; the rest of the format was never
measured. A schema that the corpus does not follow misleads precisely the agent who does follow it —
it will produce a file shaped unlike every neighbour and believe it is the conforming one.

**This is a fork, not a defect with an obvious fix**, and it goes to the operator (§F).

## E. Two template forms for the same artefact

`DOCS-SCHEMA.md` → ADR Format specifies a blockquote header:

```markdown
> Status: {…}
> Date: {YYYY-MM-DD}
```

`adr/README.md` → Template specifies bold fields:

```markdown
**Status:** proposed | accepted | deprecated | superseded
**Date:** YYYY-MM-DD
```

The corpus splits **11 to 1**: ADR-001…011 use the bold form, ADR-012 (written 05.09.2026, from
`DOCS-SCHEMA.md`) uses the blockquote form. The newest file is the odd one out, which is what
happens when the schema and the index disagree and an author reads only one of them.

Minor, adjacent: `adr/README.md` and `wiki/README.md` both close with Obsidian-style
`[[../GOTCHAS.md]]` wiki-links, which render as literal text here and are invisible to the s115
relative-link gate, because that gate only parses the standard markdown link form.

> Noted while writing this file, not acted on: the gate excludes fenced code blocks and HTML
> comments but **not inline code spans**, so a sentence that merely *names* the markdown link syntax
> inside backticks trips it. It caught this very document. One false positive is not evidence enough
> to loosen a gate that is doing its job — recorded here so the next person who hits it knows it is
> the gate's scope, not their link.

---

## F. What goes to the operator, and what does not

**Decided here and fixed** (A-1…A-5, B-1…B-7, C, E): every one of them is a document disagreeing
with a verifiable artefact — the config file, the tree, `composer.json`, or an operator decision
already recorded elsewhere. There is no judgement in reconciling them, only work.

**For the operator** — one item, because it is a taste call about how much ceremony the wiki
carries:

> **§D — the wiki format.** Either seven articles get a title suffix and a `Last compiled:` line
> they have never had, or `DOCS-SCHEMA.md` drops the two requirements and keeps only `## Related`,
> which is the part every file already satisfies and the part that has actual value (it is what
> makes the corpus navigable). A third option is to keep the suffix rule for new articles only and
> say so explicitly.

**Already answered, recorded here so it is not re-asked:** s115 left an open question — *"should the
`Last updated` field stay in rule headers?"* `DOCS-SCHEMA.md`'s opening section answers it: the
stamps are gone, freshness is `git log -1`, and the only dates that survive are the ones that state
**when something was measured** (`sessions/sNN.md`, the handoff). Nothing to decide.

## Related

- [2026-08-29-docs-and-board-audit.md](2026-08-29-docs-and-board-audit.md) — the s104 pass this one
  continues; its item 7 (the `DOCS-INDEX.md` specs table) is closed here
- [../DOCS-SCHEMA.md](../DOCS-SCHEMA.md) — the format rules §D and §E measure against
- [../AGENT-RULES.md](../AGENT-RULES.md) — Rule 0, Rule 3 and Rule 5, the three rules the gateway
  docs had fallen behind on
- [../adr/005-platform-v2-clean-break-policy.md](../adr/005-platform-v2-clean-break-policy.md) — the
  policy A-1 contradicts
