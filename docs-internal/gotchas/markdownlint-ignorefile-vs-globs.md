# markdownlint-cli2 ignores `.markdownlintignore` when globs are passed as CLI args

> [build/ci] — discovered 2026-06-08 fixing PR #20 Markdown Lint (427 errors).

## The trap

The repo has a `.markdownlintignore` (listing e.g. `docs-internal/SESSION-LOG.md`). The CI
workflow invokes:

```bash
markdownlint-cli2 "**/*.md" "#node_modules" "#vendor" "#.ai" "#CLAUDE.md" "#QWEN.md"
```

When you pass **explicit globs as command-line arguments**, markdownlint-cli2 does **not**
apply `.markdownlintignore` — only the inline `#negation` glob args take effect. Proof:
`docs-internal/SESSION-LOG.md` is in `.markdownlintignore` yet still produced errors under
this invocation. Adding new entries to `.markdownlintignore` had **zero** effect on CI.

## Fix

Exclude paths via the **workflow glob** (the authoritative mechanism here), not the ignore file:

```bash
markdownlint-cli2 "**/*.md" "#node_modules" "#vendor" "#.ai" "#.kiro" \
  "#CLAUDE.md" "#QWEN.md" "#AGENTS.md" "#docs-internal" "#.autodev" "#.serena"
```

The markdown gate now covers the **published** `docs/` tree + root markdown. The not-published
operational docs (`docs-internal/`, the `.autodev/` loop blackboard) and tooling dirs are
excluded. `AGENTS.md` is excluded alongside `CLAUDE.md`/`QWEN.md` (agent-instruction /
constitution doc, not publish-grade prose).

## Also

`MD051` (link-fragments) is disabled in `.markdownlint.json`: markdownlint's slugifier can't
reproduce this project's **Cyrillic** heading anchors (e.g. `PLANS.md` `[§ 4.3](#43-…)`),
producing false positives. Don't hand-edit constitution docs (`PLANS.md`, `AGENTS.md`) to
satisfy a linter — exclude/disable instead.

## How to apply

- If markdownlint-cli2 is invoked with glob args, manage exclusions in the **glob**, not
  `.markdownlintignore`. (The ignore file works in "autoglob"/no-args mode only.)
- Verify locally with the **exact** workflow glob, not a bare `markdownlint-cli2`.
- Remember `.kiro/` and `.autodev/runtime/` are gitignored → absent on CI, so they don't need
  glob exclusion for CI (only for local-run parity).

## Related

- [[ci-failing-gate-skips-dependent-jobs]] — other PR #20 CI root causes
