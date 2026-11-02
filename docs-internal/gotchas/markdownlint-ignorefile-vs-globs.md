# markdownlint-cli2 ignores `.markdownlintignore` when globs are passed as CLI args

> [build/ci] — discovered 2026-06-08 fixing PR #20 Markdown Lint (427 errors).

## The trap

The repo has a `.markdownlintignore` (listing e.g. `docs-internal/SESSION-LOG.md`). The CI
workflow invokes:

```bash
markdownlint-cli2 "**/*.md" "#node_modules" "#vendor" "#.ai" "#CLAUDE.md"
```

(The command above is the shape at the time of discovery, 2026-06-08; the current one is under "Fix".)

When you pass **explicit globs as command-line arguments**, markdownlint-cli2 does **not**
apply `.markdownlintignore` — only the inline `#negation` glob args take effect. Proof:
`docs-internal/SESSION-LOG.md` is in `.markdownlintignore` yet still produced errors under
this invocation. Adding new entries to `.markdownlintignore` had **zero** effect on CI.

## Fix

Exclude paths via the **workflow glob** (the authoritative mechanism here), not the ignore file:

```bash
markdownlint-cli2 "**/*.md" "website/src/content/docs/**/*.mdx" "#node_modules" "#website/node_modules" \
  "#website/dist" "#vendor" "#.ai" "#.kiro" "#CLAUDE.md" "#AGENTS.md" "#docs-internal" "#.serena" "#CHANGELOG.md"
```

The markdown gate covers the **published** site content (`website/src/content/docs`) + root markdown. The
not-published operational docs (`docs-internal/`) and tooling dirs are excluded; the canonical command is
`.github/workflows/markdown-lint.yml`. `AGENTS.md` is excluded alongside `CLAUDE.md` (agent-instruction
doc, not publish-grade prose). `QWEN.md` and `PLANS.md` no longer exist (QWEN.md retired in #1312; PLANS.md is
`docs-internal/archive/PLANS.md`).

## Also

`MD051` (link-fragments) is disabled in `.markdownlint.json`: markdownlint's slugifier can't
reproduce this project's **Cyrillic** heading anchors (e.g. an archived PLANS.md `[§ 4.3](#43-…)`),
producing false positives. Don't hand-edit constitution docs (`AGENTS.md`) to
satisfy a linter — exclude/disable instead.

## How to apply

- If markdownlint-cli2 is invoked with glob args, manage exclusions in the **glob**, not
  `.markdownlintignore`. (The ignore file works in "autoglob"/no-args mode only.)
- Verify locally with the **exact** workflow glob, not a bare `markdownlint-cli2`.
- Remember `.kiro/` is gitignored → absent on CI, so they don't need
  glob exclusion for CI (only for local-run parity).

## Related

- [ci-failing-gate-skips-dependent-jobs](ci-failing-gate-skips-dependent-jobs.md) — other PR #20 CI root causes
