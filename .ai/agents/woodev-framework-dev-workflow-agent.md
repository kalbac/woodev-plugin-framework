# Woodev Framework: Development Workflow Agent

**Role:** Development Workflow (Environment, Testing, Linting, Commits)
**Version:** 2.0
**Scope:** Woodev Plugin Framework (`woodev/plugin-framework`)

## When to Use

- Starting/stopping the development environment (`wp-env`)
- Running tests (unit or integration)
- Running linting and static analysis
- Writing commit messages

## Environment

### wp-env (Docker-based WordPress)

```bash
npx wp-env start          # start WordPress + MySQL containers
npx wp-env stop           # stop containers
npx wp-env destroy        # remove containers and volumes
npx wp-env run tests-cli wp test    # run WP-CLI inside test container
```

WordPress is available at `http://localhost:8888` (admin: `admin`/`password`).

### Prerequisites

- Docker Desktop running
- Node.js 18+ (for wp-env)
- Composer dependencies installed (`composer install`)

## Commands Quick Reference

| Command                    | What it does                                      |
|----------------------------|---------------------------------------------------|
| `composer install`         | Install all dev dependencies                      |
| `composer phpcs`           | Check WordPress Coding Standards                  |
| `composer phpcbf`          | Auto-fix coding standards violations              |
| `composer phpstan`         | Static analysis (level 3, PHP 8.1+)              |
| `composer test`            | Run all tests                                     |
| `composer test:unit`       | Unit tests only (Brain Monkey, no WP needed)      |
| `composer test:integration`| Integration tests (requires wp-env or WP_TESTS_DIR)|
| `composer check`           | Run phpcs + phpstan + unit tests together          |

### Running a Single Test

```bash
./vendor/bin/phpunit tests/unit/BootstrapTest.php
```

## Conventional Commits

All commits MUST follow Conventional Commits format:

```
<type>(<scope>): <description>
```

| Type       | When to use                          |
|------------|--------------------------------------|
| `feat`     | New feature                          |
| `fix`      | Bug fix                              |
| `refactor` | Code change (no feature/fix)         |
| `chore`    | Build, deps, config changes          |
| `docs`     | Documentation only                   |
| `test`     | Adding or fixing tests               |
| `perf`     | Performance improvement              |
| `style`    | Code style (formatting, whitespace)  |
| `ci`       | CI/CD configuration                  |

**Scopes:** `bootstrap`, `api`, `gateway`, `shipping`, `settings`, `lifecycle`, `admin`, `rest`, `deps`

**Breaking changes:** Add `!` after type/scope and a `BREAKING CHANGE:` footer.

```
feat(api)!: require API key for all requests

BREAKING CHANGE: All API requests now require authentication.
```

See `CLAUDE.md > Code Style` for coding standards details.

## Linting Workflow

1. Run `composer phpcs` to identify violations
2. Run `composer phpcbf` to auto-fix what it can
3. Manually fix remaining violations
4. Run `composer phpstan` for type-safety issues
5. Run `composer check` before committing

## Troubleshooting

- **wp-env won't start:** Ensure Docker Desktop is running. Try `npx wp-env destroy` then `npx wp-env start`.
- **Integration tests fail:** Verify `WP_TESTS_DIR` is set or wp-env is running.
- **phpcs errors after phpcbf:** Some violations require manual fixes (e.g., naming conventions).
- **phpstan baseline issues:** Run `composer phpstan -- --generate-baseline` to update the baseline.
- **Composer autoload issues:** Run `composer dump-autoload` after adding new classes.

## References

- See `CLAUDE.md` for project architecture and full command reference
- See `skills/woodev-framework-backend-dev/` for coding conventions and patterns
