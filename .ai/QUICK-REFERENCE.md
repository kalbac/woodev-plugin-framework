# Woodev Framework Agents & Skills — Quick Reference

**Version:** 2.0.0

---

## Quick Start

| I want to... | Use this Agent | Command |
|--------------|----------------|---------|
| Start development environment | `woodev-framework-dev-workflow-agent` | `wp-env start` |
| Write PHP code | `woodev-framework-backend-agent` | — |
| Run linting | `woodev-framework-dev-workflow-agent` | `composer phpcs` |
| Run tests | `woodev-framework-dev-workflow-agent` | `composer test:unit`, `composer test:integration` |
| Create commit | `woodev-framework-dev-workflow-agent` | Conventional Commits format |
| Create branch/PR | `woodev-framework-git-agent` | — |
| Review code | `woodev-framework-code-review-agent` | — |
| Write documentation | `woodev-framework-docs-agent` | — |

---

## Key Differences: Framework vs Plugin

| Aspect | Plugin | **Framework** |
|--------|--------|---------------|
| **Version** | `$version` property in main file | `VERSION` constant in `woodev/class-plugin.php` |
| **Release** | Manual tagging + release script | **Fully automatic** via GitHub Actions |
| **Changelog** | `pnpm changelog add` | **Auto-generated** by git-cliff from Conventional Commits |
| **Commands** | `pnpm lint:php`, `pnpm test:php` | `composer phpcs`, `composer test:unit`, `composer test:integration` |
| **Backward Compatibility** | Important | **Two different rules** — internal code is free to break on the v2 line; installed-site data contracts are release-blocking (ADR-005) |
| **Breaking Changes** | Avoid | **Internal APIs: break them cleanly, no shims.** Data contracts: never |

---

## Agents (5 total)

### woodev-framework-dev-workflow-agent

**Role:** Environment, Testing, Linting, Commits (merged from env + dev-cycle)

**When to use:**

- Starting/stopping wp-env
- Running linting and tests
- Writing commit messages (Conventional Commits)
- Checking environment status

**Key commands:** See [`AGENTS.md`](../AGENTS.md) → "Dev environment"

[`agents/woodev-framework-dev-workflow-agent.md`](agents/woodev-framework-dev-workflow-agent.md)

---

### woodev-framework-backend-agent

**Role:** Backend PHP Development

**When to use:**

- Creating new PHP classes
- Modifying framework code
- Adding hooks/filters
- Adding deprecation notices

**Key principles:** See [`AGENTS.md`](../AGENTS.md) → "Conventions" and "Coding Principles", plus the clean-break section below

[`agents/woodev-framework-backend-agent.md`](agents/woodev-framework-backend-agent.md)

---

### woodev-framework-git-agent

**Role:** Git & GitHub Operations

**When to use:**

- Creating branches
- Creating PRs
- **Releasing** (bump VERSION in `woodev/class-plugin.php`)

**Release workflow:** See [`AGENTS.md`](../AGENTS.md) → "Git workflow". ⚠ Raising `VERSION` on `main` PUBLISHES a release — do it deliberately

[`agents/woodev-framework-git-agent.md`](agents/woodev-framework-git-agent.md)

---

### woodev-framework-code-review-agent

**Role:** Code Review

**When to use:**

- Reviewing PRs
- Checking code standards
- **Validating backward compatibility** (CRITICAL)

[`agents/woodev-framework-code-review-agent.md`](agents/woodev-framework-code-review-agent.md)

---

### woodev-framework-docs-agent

**Role:** Documentation

**When to use:**

- Writing README.md and the gateway files (`AGENTS.md`, `CLAUDE.md`, `QWEN.md`)
- Editing `.md` files
- Developer docs: **English**, User docs: **Russian**
- **CHANGELOG.md is auto-generated** (do not edit manually)

[`agents/woodev-framework-docs-agent.md`](agents/woodev-framework-docs-agent.md)

---

## Skills

Skills provide detailed guidance for specific tasks. Agents reference skills internally.

| Skill | Location |
|-------|----------|
| Backend Development | [`skills/woodev-framework-backend-dev/`](skills/woodev-framework-backend-dev/SKILL.md) |
| Code Review | [`skills/woodev-framework-code-review/`](skills/woodev-framework-code-review/SKILL.md) |
| Dev Cycle (Testing, Linting, Commits) | [`skills/woodev-framework-dev-cycle/`](skills/woodev-framework-dev-cycle/SKILL.md) |
| Git | [`skills/woodev-framework-git/`](skills/woodev-framework-git/SKILL.md) |
| Markdown | [`skills/woodev-framework-markdown/`](skills/woodev-framework-markdown/SKILL.md) |

**Note:** The `woodev-framework-env` skill was merged into `woodev-framework-dev-cycle`.

---

## Development Workflow

```
1. Start task
   └─> woodev-framework-git-agent (create branch)

2. Start environment
   └─> woodev-framework-dev-workflow-agent (wp-env start)

3. Write code
   └─> woodev-framework-backend-agent (follow standards, maintain BC)

4. Write tests
   └─> woodev-framework-backend-agent

5. Check code
   └─> woodev-framework-dev-workflow-agent (composer check)

6. Documentation
   └─> woodev-framework-docs-agent (README, gateway files, docs-internal/)

7. Commit & Push
   └─> woodev-framework-dev-workflow-agent (Conventional Commits)
   └─> woodev-framework-git-agent (create PR)

8. Review
   └─> woodev-framework-code-review-agent (check standards + BC)
```

---

## Backward Compatibility Rules — clean-break policy (ADR-005)

> ⚠ **This section used to teach the opposite.** Until 2026-09-05 it said "NEVER delete a public
> method without a deprecation cycle; ALWAYS add `@deprecated` + `_deprecated_function()`". That was
> the pre-v2 rule and it was **superseded on 2026-06-03** by ADR-005. An agent following the old
> text would add exactly the shims the current policy tells it to delete.

Two different rules apply, depending on what you are changing:

1. **Internal code — FREE TO BREAK on the v2 line (`main`):** class names, method signatures, the
   plugin entry/registration shape, namespacing, file layout. Do **NOT** add `@deprecated` shims,
   `class_alias` files, or `_deprecated_function()` wrappers for moved or renamed internal APIs —
   delete the ones you find.
2. **Installed-site data contracts — RELEASE-BLOCKING, never break:** option keys, license/instance
   IDs, updater identity, WC gateway and shipping-method IDs + instance setting keys, public
   action/filter names, cron hooks and payloads, custom tables, REST namespaces, AJAX actions, admin
   page slugs, log source names, background-job IDs, order/session meta keys. Preserve byte-for-byte.

The remaining legitimate `_deprecated_function()` / `_doing_it_wrong()` calls are misuse markers and
clone/wakeup guards — **not** internal-API move shims. Those stay.

Full policy: [`docs-internal/adr/005-platform-v2-clean-break-policy.md`](../docs-internal/adr/005-platform-v2-clean-break-policy.md).
Operational form: [`docs-internal/AGENT-RULES.md`](../docs-internal/AGENT-RULES.md) → Rule 0.

---

## Project Rules & Conventions

Rules that ALL AI agents must follow. When you discover new important rules or conventions during work, **add them here** so other agents benefit too.

### Code Navigation

- **Always use Serena MCP tools** (`find_symbol`, `get_symbols_overview`, `search_for_pattern`, `find_referencing_symbols`) for reading and navigating PHP source code. Never read `.php` files directly — Serena has the codebase indexed with LSP and provides semantic search, cross-referencing, and symbol lookup.
- Serena indexes the tree EXCEPT the paths `.serena/project.yml` lists under `ignored_paths`: **`tests/**`, `docs/**`, `.github/**`, `.ai/**`, `.serena/**`, `.claude/**`**. A symbolic call against any of them fails with `… while the path is ignored`, so in those directories the built-in `Read`/`Grep`/`Glob` are the ONLY tools and using them is **not** a rule violation. Say this in any brief that touches test files — omitting it has cost a worker a pointless detour twice (s105, s112).

### Documentation Code Examples

- All PHP code examples in `docs/*.md` **must be verified** against the actual framework source code before writing or editing. Never write examples from memory or assumptions — use Serena to look up real method signatures, parameter types, return types, and visibility.
- All PHP code blocks must include the `<?php` opening tag.
- Markdown linting (`markdownlint-cli2`) covers the `.md` files written for HUMANS — `docs/`, `CHANGELOG.md`, `.github/`. Agent-facing files are excluded, `docs-internal/` included, because formatting is not the point there. The authoritative exclusion list is `.markdownlintignore` plus the inline negations in `.github/workflows/markdown-lint.yml`; read it there rather than copying a command line, which is what went stale here.

### Documentation Site

- Docs site: MkDocs Material, config in `mkdocs.yml`, source in `docs/`.
- Custom landing page template: `docs/overrides/home.html`.
- `%%FRAMEWORK_VERSION%%` placeholder in docs is injected by CI (from `Woodev_Plugin::VERSION`) before `mkdocs build`.
- CHANGELOG.md is **auto-generated** by git-cliff — do not edit manually (except formatting fixes for linting).

### Knowledge Persistence

- When you discover important project rules, conventions, or patterns during your work — **always document them here** in this section so all agents (Claude, Qwen, Cursor, etc.) share the same knowledge.
- Do not add personal preferences or user-specific info here — only project-level rules and conventions.

---

## Related Documentation

- [AGENTS.md](../AGENTS.md) — **the single source of truth for project knowledge**, read by every agent
- [CLAUDE.md](../CLAUDE.md) — Claude Code only: Serena/Context7/Orca tooling. It restates nothing from `AGENTS.md`
- [QWEN.md](../QWEN.md) — the Qwen-facing gateway
- [docs-internal/DOCS-INDEX.md](../docs-internal/DOCS-INDEX.md) — navigation hub for internal docs
- [agents/README.md](agents/README.md) — Agent documentation
- [docs/README.md](../docs/README.md) — Project documentation
