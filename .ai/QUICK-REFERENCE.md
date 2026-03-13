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
| **Backward Compatibility** | Important | **CRITICAL** (10+ dependent plugins) |
| **Breaking Changes** | Avoid | **Require deprecation cycle + major version bump** |

---

## Agents (5 total)

### woodev-framework-dev-workflow-agent

**Role:** Environment, Testing, Linting, Commits (merged from env + dev-cycle)

**When to use:**

- Starting/stopping wp-env
- Running linting and tests
- Writing commit messages (Conventional Commits)
- Checking environment status

**Key commands:** See CLAUDE.md > Commands

[`agents/woodev-framework-dev-workflow-agent.md`](agents/woodev-framework-dev-workflow-agent.md)

---

### woodev-framework-backend-agent

**Role:** Backend PHP Development

**When to use:**

- Creating new PHP classes
- Modifying framework code
- Adding hooks/filters
- Adding deprecation notices

**Key principles:** See CLAUDE.md > Code Style, Backward Compatibility

[`agents/woodev-framework-backend-agent.md`](agents/woodev-framework-backend-agent.md)

---

### woodev-framework-git-agent

**Role:** Git & GitHub Operations

**When to use:**

- Creating branches
- Creating PRs
- **Releasing** (bump VERSION in `woodev/class-plugin.php`)

**Release workflow:** See CLAUDE.md > Commit & Release

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

- Writing README.md, CLAUDE.md
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
   └─> woodev-framework-docs-agent (README, CLAUDE.md)

7. Commit & Push
   └─> woodev-framework-dev-workflow-agent (Conventional Commits)
   └─> woodev-framework-git-agent (create PR)

8. Review
   └─> woodev-framework-code-review-agent (check standards + BC)
```

---

## Backward Compatibility Rules

**CRITICAL for framework used by 10+ plugins.** See CLAUDE.md > Backward Compatibility for full rules.

Summary:
1. NEVER delete public methods/classes without deprecation cycle
2. ALWAYS use `@deprecated` annotation + `_deprecated_function()` call
3. Breaking changes require major version bump (semver)

---

## Project Rules & Conventions

Rules that ALL AI agents must follow. When you discover new important rules or conventions during work, **add them here** so other agents benefit too.

### Code Navigation

- **Always use Serena MCP tools** (`find_symbol`, `get_symbols_overview`, `search_for_pattern`, `find_referencing_symbols`) for reading and navigating PHP source code. Never read `.php` files directly — Serena has the codebase indexed with LSP and provides semantic search, cross-referencing, and symbol lookup.
- Serena indexes only `woodev/` directory (configured in `.serena/project.yml`).

### Documentation Code Examples

- All PHP code examples in `docs/*.md` **must be verified** against the actual framework source code before writing or editing. Never write examples from memory or assumptions — use Serena to look up real method signatures, parameter types, return types, and visibility.
- All PHP code blocks must include the `<?php` opening tag.
- Markdown linting (`markdownlint-cli2`) проверяет `.md` файлы предназначенные для людей (`docs/`, `CHANGELOG.md`, `.github/`). AI-файлы исключены. Команда: `npx markdownlint-cli2 "**/*.md" "#node_modules" "#vendor" "#.ai" "#CLAUDE.md" "#QWEN.md"`.

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

- [CLAUDE.md](../CLAUDE.md) — Single source of truth for project knowledge
- [agents/README.md](agents/README.md) — Agent documentation
- [docs/README.md](../docs/README.md) — Project documentation
