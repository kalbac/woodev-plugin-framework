# Gotcha: [tooling/docs-gate] — a docs gate checks what is LINKED, never what is LISTED
> Tags: tooling, docs-gate, audit | Session: s119

## What happens

`npm run lint:docs` has caught real defects since s115: a link to a file nobody wrote, a gotcha
counter that had drifted, a session-start budget overrun. It reports `docs-internal structure: OK`
and the tree looks healthy.

Meanwhile an index document can be missing entries for files that exist, indefinitely, and no gate
will ever say so. Measured in s119, all three in a green tree:

| Index | Listed | On disk | Missing |
|---|---|---|---|
| `adr/README.md` | 001–010, 012 | 001–012 | **ADR-011** — the index jumps 010 → 012 |
| `wiki/README.md` | 5 articles | 8 articles | `architecture.md`, `local-rig.md`, `orchestrating-agents-with-orca.md` |
| `DOCS-INDEX.md` | 3 specs, 0 plans | 13 specs, 7 plans | 10 specs and every plan |

The `DOCS-INDEX.md` row had been wrong for four months and was explicitly flagged-and-left by the
s104 audit; ADR-011's omission is a skipped step in `adr/README.md`'s own "Creating an ADR" list,
whose item 3 is "Add entry to this index".

## Root cause

A link gate can only follow links that are written. **An absent row writes no link**, so it produces
no work for the gate and no error. The failure is symmetrical to the one the gate was built for — a
link to a file that does not exist — and it is the half that no amount of link-checking reaches.

The same asymmetry explains a second blind spot found in the same pass: `.ai/QUICK-REFERENCE.md`
pointed at four sections of `CLAUDE.md` that no longer exist (`CLAUDE.md > Commands`, `> Code Style`,
`> Backward Compatibility`, `> Commit & Release`). They are **prose references, not markdown links**,
so the gate never saw them either.

## Fix

❌ Trusting a green gate as evidence that the docs are internally consistent. It proves that every
link that exists resolves — a narrower claim than it feels like.

✅ When auditing, compare each index against the **filesystem**, not against itself. One line per
index, and it takes seconds:

```bash
# ADRs listed vs ADRs on disk
diff <(grep -oE '^\| [0-9]{3}' docs-internal/adr/README.md | tr -d '| ') \
     <(ls docs-internal/adr/[0-9]*.md | sed 's#.*/\([0-9]\{3\}\).*#\1#')

# wiki articles listed vs on disk
diff <(grep -oE '\]\([a-z0-9-]+\.md\)' docs-internal/wiki/README.md | tr -d ']()') \
     <(ls docs-internal/wiki/*.md | xargs -n1 basename | grep -v README)
```

✅ Prefer a real markdown link over a prose cross-reference (`see X → Y`), so the existing gate can
at least see it. A prose pointer is invisible to every check we have.

⚠ And a fair warning about the gate's own scope: it excludes fenced code blocks and HTML comments,
but **not inline code spans** — a sentence that merely names the markdown link syntax inside
backticks trips it. That happened while writing the s119 audit report. One false positive is not a
reason to loosen a gate that is doing its job.

## Related

- [the-fixture-docblock-asserted-an-api-parameter-that-does-not-exist](the-fixture-docblock-asserted-an-api-parameter-that-does-not-exist.md) — the same family: a confident-looking artefact that nothing checks, believed for months
- [../DOCS-SCHEMA.md](../DOCS-SCHEMA.md) — "Relative links must resolve", the gate this one bounds
- [../reviews/2026-09-05-644-part1-contradiction-map.md](../reviews/2026-09-05-644-part1-contradiction-map.md) — the audit that measured all three gaps
