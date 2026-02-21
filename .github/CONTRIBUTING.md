# Contributing to Woodev Plugin Framework

Thank you for considering contributing to the Woodev Plugin Framework! Your help making it even better is greatly appreciated.

## ⚠️ Important: This is a Framework

**CRITICAL:** This is a **base framework** used by 10+ dependent plugins. Backward compatibility is the highest priority.

**Key implications:**

- **Never delete or rename public API** without a deprecation cycle (minimum one version)
- **Always use `@deprecated` annotation** for deprecated code
- **Always call `_deprecated_function()`** in deprecated methods
- **Breaking changes require major version bump** (semver)
- **Any change in `woodev/` requires enhanced review**

## Ways to Contribute

There are many ways to contribute to the project:

- **Reporting bugs** — Open an issue with detailed reproduction steps
- **Answering questions** in the community
- **Testing** open [issues](../../issues) or [pull requests](../../pulls) and sharing your findings
- **Submitting fixes, improvements, and enhancements**
- **Security issues:** Please report security issues responsibly

## How to Submit Code

If you wish to contribute code:

1. [Fork](https://docs.github.com/en/get-started/quickstart/fork-a-repo) the repository
2. Create a feature branch (see [Git Flow](#git-flow))
3. Make your changes following our [Coding Guidelines](#coding-guidelines)
4. Run linting and tests
5. **Write commits in Conventional Commits format** (for automatic CHANGELOG)
6. [Submit a pull request](../../pulls) 🎉

## Environment Setup

After cloning the repository, AI tools automatically find skills and agents through reference files in `.claude/`, `.codex/`, `.qwen/`, and `.cursor/`. All these files are already in the repository — no additional commands are required after cloning.

Real files are stored only in `.ai/skills/` and `.ai/agents/`. If you need to update skills or agents, edit files only there.

| Tool | Reference File | Points To |
|------|----------------|-----------|
| Claude Code | `.claude/skills` | `.ai/skills/` |
| Claude Code | `.claude/agents` | `.ai/agents/` |
| Codex | `.codex/skills` | `.ai/skills/` |
| Qwen Code | `.qwen/skills` | `.ai/skills/` |
| Cursor | `.cursor/rules/skills.mdc` | `.ai/skills/` |

### Good First Issues

We use the `good first issue` label to mark issues that are suitable for new contributors. Filter issues by this label to find a good starting point.

## Git Flow

### Branch Naming

Use descriptive branch names:

```
{type}/{description}
```

**Types:**

- `feature/` — новые функции
- `fix/` — исправления багов
- `hotfix/` — срочные исправления
- `docs/` — документация
- `refactor/` — рефакторинг
- `chore/` — вспомогательные задачи

**Examples:**

```
feature/shipping-by-volume
fix/calculation-rounding-error
docs/update-installation-guide
```

### Commit Messages

**All commits MUST follow Conventional Commits format.** This is critical because `git-cliff` uses commit messages to auto-generate `CHANGELOG.md`.

```
{type}: {description}
```

**Types:**

| Type | Description | CHANGELOG Section |
|------|-------------|-------------------|
| `feat:` | Новая функция | ✨ Features |
| `fix:` | Исправление бага | 🐛 Bug Fixes |
| `docs:` | Документация | 📝 Documentation |
| `style:` | Форматирование (не влияет на логику) | — |
| `refactor:` | Рефакторинг | 🔧 Refactoring |
| `test:` | Тесты | ✅ Tests |
| `chore:` | Вспомогательные задачи | ⚙️ Chores |
| `ci:` | CI/CD изменения | 🚀 CI/CD |

**Breaking changes:** Add `!` after type (e.g., `feat!:`) and include `BREAKING CHANGE:` footer.

```
feat!: remove deprecated old_method()

BREAKING CHANGE: old_method() has been removed. Use new_method() instead.
```

## Coding Guidelines

### PHP Code

- Follow [WordPress PHP Coding Standards](https://make.wordpress.org/core/handbook/best-practices/coding-standards/php/)
- Use **class methods**, not standalone functions
- Use **type declarations** (PHP 7.4+)
- Use **DTOs or contracts** for data transfer between layers
- Follow **SOLID principles**
- Add comprehensive **docblocks** with `@since`, `@param`, `@return`
- **Maintain backward compatibility** (or use proper deprecation cycle)

### JavaScript Code

- Follow [WordPress JavaScript Coding Standards](https://make.wordpress.org/core/handbook/best-practices/coding-standards/javascript/)
- Use `snake_case` for properties/methods/functions
- Use `PascalCase` for class names
- Do **not** use semicolons at end of lines
- Prefer built-in WordPress/WooCommerce scripts

### Documentation

- All `.md` files must follow `woodev-framework-markdown` skill
- Developer documentation in **English**
- User documentation in **Russian**
- **CHANGELOG.md is auto-generated** by git-cliff (do not edit manually)

## Development Workflow

1. **Make code changes**
2. **Run linting** — `composer phpcs`
3. **Fix issues** — `composer phpcbf -- path/to/file.php`
4. **Run tests** (if applicable) — `composer test:unit`, `composer test:integration`
5. **Commit with Conventional Commits format**
6. **Create PR** — only after all checks pass

### Pre-commit Checklist

Before committing:

- [ ] Linting passes: `composer phpcs`
- [ ] Tests pass (if applicable): `composer test:unit`, `composer test:integration`
- [ ] **Commits follow Conventional Commits format**
- [ ] **Backward compatibility maintained** (or proper deprecation cycle)
- [ ] CLAUDE.md updated (if architecture changed)
- [ ] README.md updated (if user-facing changes)

## Testing

### Environment Setup

```bash
# Install wp-env
npm install -g @wordpress/env

# Start environment
wp-env start
```

### Running Tests

```bash
# All unit tests (no WordPress required)
composer test:unit

# All integration tests (requires wp-env)
composer test:integration

# Specific test class
TEST_SUITE=unit ./vendor/bin/phpunit --filter TestClassName
```

See `woodev-framework-dev-cycle` skill for detailed testing instructions.

## Release Workflow — Fully Automatic

**No manual release steps needed!**

1. Update `VERSION` constant in `woodev/class-plugin.php`
2. Commit: `git commit -m "chore: release version 2.1.0"`
3. Push to main: `git push origin main`

**GitHub Actions automatically:**

- Runs all tests (PHPCS, PHPStan, Unit, Integration)
- Creates git tag `v2.1.0`
- Generates CHANGELOG via `git-cliff`
- Builds release ZIP
- Publishes GitHub Release

## Code Review

All PRs will be reviewed against:

- WordPress Coding Standards compliance
- Architecture and design patterns
- Test coverage (if applicable)
- Documentation completeness
- Security best practices
- **Backward compatibility** (CRITICAL for framework)

Reviewers will use the `woodev-framework-code-review` skill as a reference.

## License

All contributions are released under the same license as the project (GPLv3+).

## Questions?

If you have questions about contributing, please:

- Open a [Discussion](../../discussions)
- Check existing [Issues](../../issues)
- Review the [CLAUDE.md](../CLAUDE.md) documentation

---

**Thank you for contributing!** 🎉
