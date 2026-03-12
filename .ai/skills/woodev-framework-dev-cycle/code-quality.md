# Code Quality

Commands and workflows for maintaining code quality in Woodev Framework.

---

## PHP Code Quality

### Linting

```bash
# Check all (PHPCS + PHPStan + Unit Tests)
composer check

# Check with PHPCS
composer phpcs

# Fix auto-fixable issues
composer phpcbf

# Lint specific file
composer phpcs -- woodev/path/to/File.php
```

### PHPStan (if configured)

```bash
# Run PHPStan
composer phpstan

# Analyze specific file
composer exec -- phpstan analyse woodev/path/to/File.php --memory-limit=2G

# Analyze entire project (use sparingly)
composer exec -- phpstan analyse --memory-limit=2G
```

### Common Issues

See [php-linting-patterns.md](php-linting-patterns.md) for common linting issues and fixes.

---

## Markdown Code Quality

Markdown files can be linted with the `markdownlint` CLI:

```bash
# Check markdown files
npx markdownlint "**/*.md"

# Fix auto-fixable issues
npx markdownlint --fix "**/*.md"

# Lint specific file
npx markdownlint path/to/file.md
```

### Rules

- All `.md` files must follow `woodev-framework-markdown` skill
- Use proper heading hierarchy (`#` then `##` then `###`)
- Code blocks must have language specifier
- Links must be descriptive (no "click here")

---

## Pre-commit Checklist

Before committing code:

1. **Run linting** for changed files
2. **Fix all auto-fixable issues** with `composer phpcbf`
3. **Manually fix remaining issues** (translators comments, etc.)
4. **Verify linting passes** for changed files
5. **Run tests** (if applicable)
6. **Write commit message in Conventional Commits format**

---

## CI/CD Checks

The following checks run automatically on PRs and pushes:

- PHP linting (WordPress Coding Standards)
- PHPStan (if configured)
- PHPUnit tests (Unit + Integration)
- Conventional Commits format (for CHANGELOG generation)

**Ensure all checks pass before requesting review.**
