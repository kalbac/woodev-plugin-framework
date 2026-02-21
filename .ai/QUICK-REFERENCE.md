# Woodev Framework Agents & Skills — Quick Reference

**Version:** 1.0.0

---

## Quick Start

| I want to... | Use this Agent | Command |
|--------------|----------------|---------|
| Start development environment | `woodev-framework-env-agent` | `wp-env start` |
| Write PHP code | `woodev-framework-backend-agent` | — |
| Run linting | `woodev-framework-dev-cycle-agent` | `composer phpcs` |
| Run tests | `woodev-framework-dev-cycle-agent` | `composer test:unit`, `composer test:integration` |
| Create commit | `woodev-framework-dev-cycle-agent` | Conventional Commits format |
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

## Agents

### woodev-framework-env-agent

**Role:** Environment Management

**When to use:**

- Starting/stopping wp-env
- Checking environment status
- Cleaning/rebuilding environment

**Key commands:**

```bash
wp-env start
wp-env stop
wp-env status
wp-env clean all
```

📄 [`agents/woodev-framework-env-agent.md`](agents/woodev-framework-env-agent.md)

---

### woodev-framework-backend-agent

**Role:** Backend PHP Development

**When to use:**

- Creating new PHP classes
- Modifying framework code
- Adding hooks/filters
- Adding deprecation notices

**Key principles:**

- OOP only (no standalone functions)
- Type declarations required
- **Backward compatibility critical**
- Use `@deprecated` + `_deprecated_function()` for deprecated code

📄 [`agents/woodev-framework-backend-agent.md`](agents/woodev-framework-backend-agent.md)

---

### woodev-framework-dev-cycle-agent

**Role:** Testing, Linting, Code Quality

**When to use:**

- Running linting
- Running tests
- Writing commit messages (Conventional Commits)

**Key commands:**

```bash
composer check           # PHPCS + PHPStan + Unit Tests
composer phpcs           # Lint
composer phpcbf          # Fix auto-fixable
composer test:unit       # Unit tests
composer test:integration # Integration tests (requires wp-env)
```

**Conventional Commits:**

```bash
feat: add new feature
fix: fix bug
docs: update docs
refactor: refactor code
chore: release version 2.1.0
feat!: breaking change (with BREAKING CHANGE: footer)
```

📄 [`agents/woodev-framework-dev-cycle-agent.md`](agents/woodev-framework-dev-cycle-agent.md)

---

### woodev-framework-git-agent

**Role:** Git & GitHub Operations

**When to use:**

- Creating branches
- Writing commit messages
- Creating PRs
- **Releasing** (bump VERSION in `woodev/class-plugin.php`)

**Release workflow — AUTOMATIC:**

1. Update `VERSION` in `woodev/class-plugin.php`
2. Commit: `git commit -m "chore: release version 2.1.0"`
3. Push: `git push origin main`
4. GitHub Actions does the rest (tests → tag → CHANGELOG → ZIP → Release)

📄 [`agents/woodev-framework-git-agent.md`](agents/woodev-framework-git-agent.md)

---

### woodev-framework-code-review-agent

**Role:** Code Review

**When to use:**

- Reviewing PRs
- Checking code standards
- **Validating backward compatibility** (CRITICAL)

**Critical violations:**

- Breaking changes without deprecation
- Missing `@deprecated` annotation
- Missing `_deprecated_function()` call
- Changes in `woodev/` without enhanced review

📄 [`agents/woodev-framework-code-review-agent.md`](agents/woodev-framework-code-review-agent.md)

---

### woodev-framework-docs-agent

**Role:** Documentation

**When to use:**

- Writing README.md
- Creating CLAUDE.md
- Editing `.md` files

**Key notes:**

- Developer docs: **English**
- User docs: **Russian**
- **CHANGELOG.md is auto-generated** (do not edit manually)

📄 [`agents/woodev-framework-docs-agent.md`](agents/woodev-framework-docs-agent.md)

---

## Skills

Skills provide detailed guidance for specific tasks. Agents use skills internally.

| Skill | Location |
|-------|----------|
| Backend Development | [`skills/woodev-framework-backend-dev/`](skills/woodev-framework-backend-dev/SKILL.md) |
| Code Review | [`skills/woodev-framework-code-review/`](skills/woodev-framework-code-review/SKILL.md) |
| Dev Cycle | [`skills/woodev-framework-dev-cycle/`](skills/woodev-framework-dev-cycle/SKILL.md) |
| Environment | [`skills/woodev-framework-env/`](skills/woodev-framework-env/SKILL.md) |
| Git | [`skills/woodev-framework-git/`](skills/woodev-framework-git/SKILL.md) |
| Markdown | [`skills/woodev-framework-markdown/`](skills/woodev-framework-markdown/SKILL.md) |

---

## Command Reference

### Composer Commands (replaces pnpm)

| Old (Plugin) | New (Framework) |
|--------------|-----------------|
| `pnpm lint:php:changes` | `composer phpcs` |
| `pnpm lint:php:fix` | `composer phpcbf` |
| `pnpm lint:php -- file.php` | `composer phpcs -- woodev/path/to/file.php` |
| `pnpm test:php` | `composer test:unit` |
| `pnpm test:php:integration` | `composer test:integration` |
| `pnpm changelog add` | **NOT NEEDED** (git-cliff auto-generates) |
| — | `composer check` (phpcs + phpstan + unit) |

### wp-env Commands

```bash
wp-env start              # Start environment
wp-env stop               # Stop environment
wp-env status             # Check status
wp-env info               # View URLs and connection info
wp-env clean all          # Clean everything
wp-env run cli "wp ..."   # Run WP-CLI command
```

### Git Commands

```bash
# Branch
git checkout -b feature/my-feature

# Commit (Conventional Commits)
git commit -m "feat: add new shipping method"

# Push
git push -u origin feature/my-feature

# Release (automatic)
# 1. Update VERSION in woodev/class-plugin.php
# 2. git commit -m "chore: release version 2.1.0"
# 3. git push origin main
```

---

## Development Workflow

```
1. Start task
   └─> woodev-framework-git-agent (create branch)

2. Start environment
   └─> woodev-framework-env-agent (wp-env start)

3. Write code
   └─> woodev-framework-backend-agent (follow standards, maintain BC)

4. Write tests
   └─> woodev-framework-backend-agent

5. Check code
   └─> woodev-framework-dev-cycle-agent (composer phpcs)
   └─> woodev-framework-dev-cycle-agent (composer test:unit)
   └─> woodev-framework-dev-cycle-agent (composer test:integration)

6. Documentation
   └─> woodev-framework-docs-agent (README, CLAUDE.md)

7. Commit
   └─> woodev-framework-dev-cycle-agent (Conventional Commits format)

8. Push and PR
   └─> woodev-framework-git-agent (create PR)

9. Review
   └─> woodev-framework-code-review-agent (check standards + BC)

10. Stop environment (optional)
    └─> woodev-framework-env-agent (wp-env stop)
```

---

## Backward Compatibility Rules

**CRITICAL for framework used by 10+ plugins:**

1. **NEVER delete public methods/classes** without deprecation cycle
2. **NEVER rename public methods/classes** without deprecation cycle
3. **ALWAYS use `@deprecated` annotation:**

   ```php
   /**
    * @deprecated 2.0.0 Use new_method() instead.
    */
   ```

4. **ALWAYS call `_deprecated_function()`:**

   ```php
   _deprecated_function( __METHOD__, '2.0.0', __CLASS__ . '::new_method()' );
   ```

5. **Breaking changes require major version bump** (semver)

---

## Related Documentation

- [CLAUDE.md](../CLAUDE.md) — Main project documentation
- [agents/README.md](agents/README.md) — Detailed agent documentation
- [docs/README.md](../docs/README.md) — Project documentation
- [.github/CONTRIBUTING.md](../.github/CONTRIBUTING.md) — Contributing guide
