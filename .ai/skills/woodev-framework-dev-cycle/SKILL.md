---
name: woodev-framework-dev-cycle
description: Run tests, linting, quality checks, and manage the wp-env development environment for Woodev Framework. Use when running tests, fixing code style, managing the Docker environment, or following the development workflow.
---

# Woodev Framework Development Cycle

## When to Use This Skill

**ALWAYS invoke this skill when:**

- Starting or stopping the local development environment
- Running linting on PHP or Markdown files
- Running PHPUnit tests (Unit and Integration)
- Fixing code style issues
- Writing commit messages in Conventional Commits format
- Troubleshooting wp-env or Docker issues

**DO NOT use this skill for:**

- Git operations (use `woodev-framework-git`)
- Writing PHP code (use `woodev-framework-backend-dev`)
- Code review (use `woodev-framework-code-review`)

---

## Instructions

1. **Environment management** -- start/stop wp-env, troubleshoot Docker
2. **Running tests** -- see [running-tests.md](running-tests.md) for PHPUnit commands
3. **Testing guide** -- see [testing-guide.md](testing-guide.md) for Brain Monkey/Mockery patterns and test writing conventions
4. **Code quality** -- see [code-quality.md](code-quality.md) for linting and code style fixes
5. **PHP linting patterns** -- see [php-linting-patterns.md](php-linting-patterns.md) for common PHP linting issues and fixes
6. **Markdown linting** -- see [markdown-linting.md](markdown-linting.md) for markdown file linting and formatting

---

## Environment Configuration

The project uses `@wordpress/env` (wp-env) to run WordPress + WooCommerce in Docker.

### `.wp-env.json`

```json
{
  "core": null,
  "phpVersion": "7.4",
  "plugins": ["https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip"],
  "mappings": {
    "woodev-framework": ".",
    "wp-content/plugins/woodev-test-plugin": "./tests/_fixtures/woodev-test-plugin",
    "wp-content/plugins/woodev-test-plugin/woodev": "./woodev",
    "wp-content/plugins/woodev-test-payment-gateway": "./tests/_fixtures/woodev-test-payment-gateway",
    "wp-content/plugins/woodev-test-payment-gateway/woodev": "./woodev",
    "wp-content/plugins/woodev-test-shipping-method": "./tests/_fixtures/woodev-test-shipping-method",
    "wp-content/plugins/woodev-test-shipping-method/woodev": "./woodev"
  },
  "env": {
    "tests": { "config": { "WP_DEBUG": true, "WP_DEBUG_LOG": true, "SCRIPT_DEBUG": true } },
    "development": { "config": { "WP_DEBUG": true, "SCRIPT_DEBUG": true } }
  }
}
```

**Key points:**

- `core: null` -- uses latest stable WordPress from WordPress.org
- `phpVersion: "7.4"` -- matches compatibility requirements (platform target is PHP 8.1)
- WooCommerce installed from wordpress.org ZIP (latest stable)
- Fixture plugins mapped individually; each gets the shared `woodev/` directory mapped in
- Separate `tests` and `development` environment configs

### wp-env Commands

```bash
# Install wp-env globally (one-time)
npm install -g @wordpress/env

# Start environment
npx wp-env start

# Stop environment
npx wp-env stop

# Check status / URLs
npx wp-env status

# Clean and rebuild
npx wp-env destroy && npx wp-env start

# Run WP-CLI inside the container
npx wp-env run cli "wp plugin list"

# Check PHP version
npx wp-env run cli "php -v"
```

**Default URLs:**

- Development: `http://localhost:8888` (admin: `admin`/`password`)
- Tests: `http://localhost:8889`

---

## Development Workflow

```text
1. Start wp-env           --> npx wp-env start
2. Make code changes
3. Run linting            --> composer phpcs
4. Fix linting issues     --> composer phpcbf
5. Run tests              --> composer test:unit / composer test:integration
6. Commit changes         --> Conventional Commits format
7. Stop environment       --> npx wp-env stop (optional)
```

**IMPORTANT:** Always ensure wp-env is running before running integration tests.

---

## Pre-commit Checks

```bash
# Check all (PHPCS + PHPStan + Unit Tests)
composer check

# Lint (PHPCS)
composer phpcs

# Fix auto-fixable issues
composer phpcbf

# Lint specific file
composer phpcs -- woodev/path/to/File.php

# Static analysis
composer phpstan
```

**NEVER commit PHP changes without passing linting.**

---

## Code Quality Commands

### PHP Linting

Linting does NOT require wp-env to be running:

```bash
composer phpcs                              # check all
composer phpcbf                             # auto-fix
composer phpcs -- woodev/path/to/File.php   # specific file
```

### PHPStan

```bash
composer phpstan
composer exec -- phpstan analyse woodev/path/to/File.php --memory-limit=2G
```

### Markdown Linting

```bash
npx markdownlint "**/*.md"
npx markdownlint --fix "**/*.md"
```

---

## Testing

### Three Test Levels

1. **Unit Tests** (Brain Monkey, no WordPress):

   ```bash
   composer test:unit
   ```

2. **Integration Tests** (WP_UnitTestCase + wp-env):

   ```bash
   composer test:integration
   ```

3. **Fixture Plugins** in `tests/_fixtures/`:
   - `woodev-test-plugin` (general)
   - `woodev-test-payment-gateway` (payment gateway)
   - `woodev-test-shipping-method` (shipping method)

### Running Specific Tests

```bash
# Specific test file
./vendor/bin/phpunit tests/unit/BootstrapTest.php

# Filter by class or method name
TEST_SUITE=unit ./vendor/bin/phpunit --filter BootstrapTest
```

---

## Conventional Commits

All commits MUST follow Conventional Commits format for `git-cliff` CHANGELOG generation.

```text
{type}: {description}

[optional body]

[optional footer]
```

| Type | Description | CHANGELOG Section |
|------|-------------|-------------------|
| `feat:` | New feature | Features |
| `fix:` | Bug fix | Bug Fixes |
| `docs:` | Documentation | Documentation |
| `refactor:` | Code refactoring | Refactoring |
| `test:` | Tests | Tests |
| `chore:` | Auxiliary tasks | Chores |
| `ci:` | CI/CD changes | CI/CD |

Breaking changes: add `!` after type (`feat!:`) and `BREAKING CHANGE:` footer.

---

## Troubleshooting

### wp-env Won't Start

1. Ensure Docker Desktop is running: `docker ps`
2. Check ports 8888/8889 are free
3. Destroy and rebuild: `npx wp-env destroy && npx wp-env start`
4. Update wp-env: `npm install -g @wordpress/env`

### Tests Fail Unexpectedly

1. Ensure wp-env is running: `npx wp-env status`
2. Clean database: `npx wp-env clean database`
3. Rebuild: `npx wp-env destroy && npx wp-env start`
4. Check test isolation -- tests must not depend on each other

### Linting Issues

1. File encoding must be UTF-8
2. Line endings must be Unix (LF)
3. Indentation must use tabs
4. Run verbose: `composer phpcs -- -v woodev/path/to/File.php`

---

## Key Principles

- **Always run linting after making changes** to verify code style
- **Fix linting errors** for code in your current branch only
- **Never lint the entire codebase** -- only changed or specific files
- **Always start wp-env before integration tests**
- **Commits MUST follow Conventional Commits format**
