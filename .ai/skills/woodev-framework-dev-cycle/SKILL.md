---
name: woodev-framework-dev-cycle
description: Run tests, linting, and quality checks for Woodev Framework development. Use when running tests, fixing code style, or following the development workflow.
---

# Woodev Framework Development Cycle

## When to Use This Skill

**ALWAYS invoke this skill when:**

- Before committing any code changes
- Running linting on PHP/JS/Markdown files
- Running PHPUnit tests (Unit and Integration)
- Fixing code style issues
- **Writing commit messages in Conventional Commits format**

**DO NOT use this skill for:**

- Git operations (use `woodev-framework-git`)
- Writing PHP code (use `woodev-framework-backend-dev`)
- Code review (use `woodev-framework-code-review`)
- Managing wp-env environment (use `woodev-framework-env`)

---

This skill provides guidance for the Woodev Framework development workflow, including running tests, code quality checks, and troubleshooting.

---

## Instructions

Follow these guidelines for Woodev Framework development workflow:

1. **Running tests** — Ensure wp-env is running, then run PHPUnit tests
2. **Code quality** — See [code-quality.md](code-quality.md) for linting and code style fixes
3. **PHP linting patterns** — See [php-linting-patterns.md](php-linting-patterns.md) for common PHP linting issues and fixes
4. **Markdown linting** — See [markdown-linting.md](markdown-linting.md) for markdown file linting and formatting

---

## Development Workflow

The standard development workflow:

```
1. Start wp-env environment → wp-env start
2. Make code changes
3. Run linting → composer phpcs
4. Fix linting issues → composer phpcbf
5. Run tests → composer test:unit, composer test:integration
6. Update documentation → CLAUDE.md, README.md
7. Commit changes → Conventional Commits format (for git-cliff)
8. Push to main → GitHub Actions auto-releases if VERSION changed
9. Stop environment (optional) → wp-env stop
```

**IMPORTANT:** Always ensure wp-env is running before running integration tests!

---

## Pre-commit Checks

**Before committing PHP changes**, run these checks to avoid CI failures:

```bash
# Check all (PHPCS + PHPStan + Unit Tests)
composer check

# Lint (PHPCS)
composer phpcs

# Fix auto-fixable issues
composer phpcbf

# Lint specific file
composer phpcs -- woodev/path/to/File.php
```

**NEVER commit PHP changes without passing linting.** All code must follow WordPress PHP Code Standards.

---

## Key Principles

- **Always run linting after making changes** to verify code style
- **Fix linting errors** for code in your current branch only
- **Lint failures** provide detailed output showing the issue
- **The test environment** handles WordPress/WooCommerce setup automatically
- **Never lint the entire codebase** — only changed or specific files
- **Always start wp-env before running integration tests** — tests require Docker environment
- **Commits MUST follow Conventional Commits format** — for automatic CHANGELOG generation

---

## Testing Environment

### Three Test Levels

1. **Unit Tests** (Brain Monkey, no WordPress):
   ```bash
   TEST_SUITE=unit ./vendor/bin/phpunit --testsuite Unit
   # Or via composer:
   composer test:unit
   ```

2. **Integration Tests** (WP_UnitTestCase + wp-env):
   ```bash
   TEST_SUITE=integration ./vendor/bin/phpunit --testsuite Integration
   # Or via composer:
   composer test:integration
   ```

3. **Fixture Plugins** in `tests/_fixtures/`:
   - `woodev-test-plugin` (general)
   - `woodev-test-payment-gateway` (payment gateway)
   - `woodev-test-shipping-method` (shipping method)

### Running Tests

**IMPORTANT:** Ensure wp-env is running before executing integration tests!

```bash
# Check environment status
wp-env status

# Start environment if not running
wp-env start

# Run all unit tests
composer test:unit

# Run all integration tests
composer test:integration

# Run specific test class
TEST_SUITE=unit ./vendor/bin/phpunit --filter TestClassName

# Run tests with coverage (if configured)
TEST_SUITE=unit ./vendor/bin/phpunit --coverage-html ./coverage
```

### Test Environment Setup

```bash
# Start wp-env environment (REQUIRED for integration tests)
wp-env start

# Run integration tests
composer test:integration

# Stop environment after testing (optional, to save resources)
wp-env stop
```

---

## Code Quality Commands

### PHP Linting

Linting does NOT require wp-env to be running:

```bash
# Check all (PHPCS)
composer phpcs

# Fix auto-fixable issues
composer phpcbf

# Lint specific file
composer phpcs -- woodev/path/to/File.php
```

### PHPStan (Static Analysis)

```bash
# Run PHPStan
composer phpstan

# Analyze specific file
composer exec -- phpstan analyse woodev/path/to/File.php --memory-limit=2G
```

### Markdown Linting

```bash
# Check markdown files
pnpm lint:md

# Fix auto-fixable issues
pnpm lint:md:fix
```

---

## Conventional Commits

**All commits MUST follow Conventional Commits format.** This is critical because:

- `git-cliff` uses commit messages to generate `CHANGELOG.md` automatically
- Release workflow is fully automated via GitHub Actions

### Commit Format

```text
{type}: {description}

[optional body]

[optional footer]
```

### Types

| Type | Description | CHANGELOG Section |
|------|-------------|-------------------|
| `feat:` | New feature | ✨ Features |
| `fix:` | Bug fix | 🐛 Bug Fixes |
| `docs:` | Documentation | 📝 Documentation |
| `refactor:` | Code refactoring (no functionality change) | 🔧 Refactoring |
| `test:` | Tests | ✅ Tests |
| `chore:` | Auxiliary tasks (CI/CD, config) | ⚙️ Chores |
| `ci:` | CI/CD changes | 🚀 CI/CD |

### Breaking Changes

**Breaking changes require:**

1. Add `!` after type: `feat!:`, `fix!:`, etc.
2. Add `BREAKING CHANGE:` footer in commit message

Example:

```text
feat!: remove deprecated old_method()

BREAKING CHANGE: old_method() has been removed. Use new_method() instead.
```

This adds ⚠️ BREAKING CHANGE section to CHANGELOG.

### Examples

```bash
# Feature
git commit -m "feat: add shipping calculation by volume"

# Bug fix
git commit -m "fix: correct rounding in total calculation"

# Documentation
git commit -m "docs: update installation instructions in README"

# Refactor
git commit -m "refactor: extract validation logic to separate method"

# Breaking change
git commit -m "feat!: remove deprecated old_method()

BREAKING CHANGE: old_method() has been removed. Use new_method() instead."
```

---

## Quick Command Reference

### Most Common Commands

```bash
# Start development environment
wp-env start

# Check environment status
wp-env status

# Check all (PHPCS + PHPStan + Unit Tests)
composer check

# Lint (PHPCS)
composer phpcs

# Fix auto-fixable issues
composer phpcbf

# Run unit tests
composer test:unit

# Run integration tests (requires wp-env)
composer test:integration

# Stop environment
wp-env stop
```

### Full Workflow Command

```bash
# Complete dev cycle in one command
wp-env start && \
composer check && \
composer test:integration && \
wp-env stop
```

---

## Troubleshooting

### Linting Issues

If linting failed unexpectedly:

1. **Check file encoding** — must be UTF-8
2. **Check line endings** — must be Unix (LF), not Windows (CRLF)
3. **Check indentation** — must be tabs, not spaces
4. **Run with verbose output** — `composer phpcs -- -v woodev/path/to/File.php`

### Test Issues

If tests failed unexpectedly:

1. **Ensure environment is running** — `wp-env status`
2. **Start environment if needed** — `wp-env start`
3. **Clear caches** — `wp-env clean all && wp-env start`
4. **Rebuild environment** — `wp-env destroy && wp-env start`
5. **Check test isolation** — ensure tests don't depend on each other

### Environment Issues

If wp-env commands fail:

1. **Check Docker is running** — `docker ps`
2. **Check ports available** — 8888 and 8889 should be free
3. **Destroy and rebuild** — `wp-env destroy && wp-env start`
4. **Update wp-env** — `npm install -g @wordpress/env`

---

## Integration with woodev-framework-env

This skill works closely with `woodev-framework-env`:

| Task | Skill to Use |
|------|--------------|
| Start/stop environment | `woodev-framework-env` |
| Check environment status | `woodev-framework-env` |
| Clean/rebuild environment | `woodev-framework-env` |
| Run linting | This skill (`woodev-framework-dev-cycle`) |
| Run tests | This skill (`woodev-framework-dev-cycle`) |
| Write commit messages | This skill (Conventional Commits) |

### Recommended Workflow

1. **Start coding session:**

   ```bash
   wp-env start
   ```

2. **During development:**

   ```bash
   composer check
   composer test:integration
   ```

3. **Commit:**

   ```bash
   # Conventional Commits format
   git commit -m "feat: add new shipping method"
   ```

4. **End coding session:**

   ```bash
   wp-env stop
   ```

---

## Notes

- All detailed standards are in the `woodev-framework-backend-dev` and `woodev-framework-code-review` skills
- Consult those skills for complete context and examples
- When in doubt, refer to the specific skill documentation
- **Always run linting before committing**
- **Always start wp-env before running integration tests**
- **WooCommerce is auto-installed** via `.wp-env.json` configuration
- **Commits MUST follow Conventional Commits format** — for automatic CHANGELOG generation
