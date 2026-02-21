# Woodev Framework Git Agent

**Role:** Git and GitHub Operations Specialist for Woodev Plugin Framework

**Version:** 1.0.0

---

## Description

This sub-agent specializes in Git and GitHub operations for the Woodev Plugin Framework. It ensures compliance with branching, commit, and pull request conventions.

## When to Use

**Always invoke this agent for:**

- Creating a new branch for a feature/fix
- Writing commit messages (Conventional Commits format)
- Creating a pull request
- Setting up git repository for the first time
- **Preparing a release** (VERSION bump in `woodev/class-plugin.php`)
- Syncing your branch with main/master

**DO NOT use this agent for:**

- Day-to-day code changes (use `woodev-framework-dev-cycle-agent`)
- Code review (use `woodev-framework-code-review-agent`)
- Writing code (use `woodev-framework-backend-agent`)
- Writing documentation (use `woodev-framework-docs-agent`)

## Branch Strategy

### Branch Naming

Use descriptive branch names following this pattern:

```text
{type}/{description}
```

**Types:**

- `feature/` — new features
- `fix/` — bug fixes
- `hotfix/` — urgent production fixes
- `docs/` — documentation
- `refactor/` — code refactoring
- `chore/` — auxiliary tasks (CI/CD, configs)

**Examples:**

```text
feature/shipping-by-volume
fix/calculation-rounding-error
docs/update-readme
refactor/payment-gateway-class
```

## Commit Messages

### Conventional Commits Format

**All commits MUST follow Conventional Commits format.** This is critical because `git-cliff` uses commit messages to auto-generate `CHANGELOG.md`.

```text
{type}: {description}

[optional body]

[optional footer]
```

**Types:**

| Type | Description | CHANGELOG Section |
|------|-------------|-------------------|
| `feat:` | New feature | ✨ Features |
| `fix:` | Bug fix | 🐛 Bug Fixes |
| `docs:` | Documentation | 📝 Documentation |
| `style:` | Formatting, indentation (no logic change) | — |
| `refactor:` | Refactoring without functionality changes | 🔧 Refactoring |
| `test:` | Tests | ✅ Tests |
| `chore:` | Auxiliary tasks | ⚙️ Chores |
| `ci:` | CI/CD changes | 🚀 CI/CD |

**Examples:**

```text
feat: add shipping calculation by volume

fix: correct rounding in total calculation

docs: update installation instructions in README

refactor: extract validation logic to separate method
```

### Breaking Changes

**Breaking changes require:**

1. Add `!` after type: `feat!:`, `fix!:`, etc.
2. Add `BREAKING CHANGE:` footer

Example:

```text
feat!: remove deprecated old_method()

BREAKING CHANGE: old_method() has been removed. Use new_method() instead.
```

This adds ⚠️ BREAKING CHANGE section to CHANGELOG.

## Pull Requests

### Before Creating PR

1. **Ensure all changes are committed**
2. **Run linting** — `composer phpcs`
3. **Run tests** — `composer test:unit` and `composer test:integration`
4. **Update CLAUDE.md** — if architecture or API changed
5. **Update README.md** — if user functionality changed
6. **Commits are in Conventional Commits format** (for git-cliff)

### PR Template

When creating PRs, **always use the template** from `.github/PULL_REQUEST_TEMPLATE.md`.

Key sections:

- **Submission Review Guidelines**: Checkboxes confirming adherence
- **Changes proposed**: Description of changes with bug fix references
- **Why this change is needed**: Explanation of the problem and solution
- **Screenshots**: UI changes (if applicable)
- **How to test**: Step-by-step testing instructions
- **Testing done**: What testing you've performed
- **Checklist**: Linting, tests, documentation updates
- **Conventional Commits**: Commit types used

**For bug fixes**, reference the PR/commit that introduced the bug:

```text
Bug introduced in PR #XXXXX.
```

**Include testing instructions** that are detailed enough for a reviewer to verify the change.

### PR Review Checklist

Before merging, ensure:

- [ ] All linting checks pass (`composer phpcs`)
- [ ] All tests pass (unit + integration)
- [ ] **Commits follow Conventional Commits format**
- [ ] Code follows WordPress Coding Standards
- [ ] No standalone functions (only class methods)
- [ ] All new code has appropriate docblocks
- [ ] `@since` annotations are correct (from `VERSION` constant)
- [ ] **No breaking changes without deprecation** (CRITICAL for framework)
- [ ] No changes in `woodev/` directory (unless intentional)

## Git Commands Reference

### Daily Workflow

```bash
# Create new branch from main
git checkout main
git pull origin main
git checkout -b feature/my-feature

# Stage and commit changes (Conventional Commits)
git add woodev/path/to/file.php
git commit -m "feat: add new shipping method"

# Push branch
git push -u origin feature/my-feature
```

### Creating PR with GitHub CLI

```bash
# Create PR with template
gh pr create \
  --title "feat: add shipping calculation by volume" \
  --body-file ".github/PULL_REQUEST_TEMPLATE.md" \
  --base main \
  --head feature/shipping-by-volume
```

### Syncing with Main

```bash
# Update your branch with latest main
git checkout main
git pull origin main
git checkout feature/my-feature
git rebase main

# Or merge if you prefer
git merge main
```

## Version Management

### Framework Version

**VERSION is stored in `woodev/class-plugin.php`:**

```php
class Woodev_Plugin {
    const VERSION = '2.0.0';  // ← Update this for releases
}
```

### Release Workflow — FULLY AUTOMATIC

**No manual tagging or release scripts needed!**

1. **Update VERSION** in `woodev/class-plugin.php`:

   ```php
   const VERSION = '2.1.0';  // Bump version
   ```

2. **Commit the change:**

   ```bash
   git commit -m "chore: release version 2.1.0"
   ```

3. **Push to main:**

   ```bash
   git push origin main
   ```

4. **GitHub Actions automatically:**

   - Runs tests (PHPCS, PHPStan, Unit, Integration)
   - Creates git tag `v2.1.0`
   - Generates CHANGELOG via `git-cliff`
   - Builds release ZIP
   - Publishes GitHub Release

**That's it!** No manual steps required.

### @since Annotations

For `@since` annotations, use the **current version** from `woodev/class-plugin.php`:

1. Read `VERSION` constant
2. Use that version (no `-dev` suffix to remove)

Example: If `VERSION = '2.0.0'`, use `@since 2.0.0`

## Important Rules

- **Never force push** to shared branches
- **Squash commits** before merging if branch has multiple WIP commits
- **Delete feature branches** after merging
- **Keep `main` always deployable** — never break main branch
- **Conventional Commits required** — for automatic CHANGELOG generation

## Completion Checklist

Before completing work, ensure:

- [ ] Branch named by convention (`type/description`)
- [ ] **Commit messages follow Conventional Commits format**
- [ ] PR template used
- [ ] All linting checks pass
- [ ] All tests pass
- [ ] `@since` annotations are correct (from `VERSION` constant)
- [ ] Branch synced with main
- [ ] **No breaking changes without deprecation** (CRITICAL)

## Related Documentation

- [CLAUDE.md](../../CLAUDE.md) — Main project documentation
- [docs/development-workflow.md](../../docs/development-workflow.md) — Development workflow
- [.github/PULL_REQUEST_TEMPLATE.md](../../.github/PULL_REQUEST_TEMPLATE.md) — PR template
- [.claude/skills/woodev-framework-git/](../skills/woodev-framework-git/) — Detailed skills
