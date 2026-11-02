---
name: woodev-framework-git
description: Guidelines for git and GitHub operations in Woodev Framework projects. **CRITICAL: All commits MUST follow Conventional Commits format for automatic CHANGELOG generation.**
---

# Woodev Framework Git Guidelines

## When to Use This Skill

**ALWAYS invoke this skill when:**

- Creating a new branch for a feature/fix
- Writing commit messages (**Conventional Commits format required**)
- Creating a pull request
- Setting up git repository for the first time
- **Preparing a release** (VERSION bump in `woodev/class-plugin.php`)
- Syncing your branch with main/master

**DO NOT use this skill for:**

- Day-to-day code changes (use `woodev-framework-dev-cycle`)
- Code review (use `woodev-framework-code-review`)
- Writing code (use `woodev-framework-backend-dev`)

## Branch Strategy

### Branch Naming

Use descriptive branch names following this pattern (branch-naming rule: `AGENTS.md` → Git workflow):

```text
{type}/{description}
```

Worktrees created by Orca use `kalbac/<slug>` (for example `kalbac/s210-docs-audit`); keep that form for agent work.

**Types:**

- `feat/` — new features
- `fix/` — bug fixes
- `hotfix/` — urgent production fixes
- `docs/` — documentation
- `refactor/` — code refactoring
- `chore/` — auxiliary tasks (CI/CD, configs)

**Examples:**

```text
feat/shipping-by-volume
fix/calculation-rounding-error
docs/update-readme
refactor/payment-gateway-class
```

## Commit Messages

### Conventional Commits Format

**All commits MUST follow Conventional Commits format.** This is critical because `git-cliff` uses commit messages to auto-generate `CHANGELOG.md`.

```
{type}: {description}

[optional body]

[optional footer]
```

**Types** (the CHANGELOG sections are exactly what `cliff.toml` emits; `filter_unconventional = true`):

| Type | Description | CHANGELOG Section |
|------|-------------|-------------------|
| `feat:` | New feature | Features |
| `fix:` | Bug fix | Bug Fixes |
| `refactor:` | Refactoring, no behaviour change | Refactoring |
| `perf:` | Performance improvement | Performance |
| `docs:` | Documentation | Documentation |
| `test:` | Tests | Tests |
| `ci:` | CI/CD changes | CI/CD |
| `chore:` | Auxiliary tasks | — (skipped) |
| `style:` | Formatting, whitespace | — (no parser; dropped) |

**Examples:**

```
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

```
feat!: remove deprecated old_method()

BREAKING CHANGE: old_method() has been removed. Use new_method() instead.
```

This adds ⚠️ BREAKING CHANGE section to CHANGELOG.

## Pull Requests

### Before Creating PR

1. **Ensure all changes are committed**
2. **Run linting** — `composer phpcs`
3. **Run tests** — `composer test:unit`; integration runs only in the wp-env `tests-cli` container (coordinator, see `woodev-framework-dev-cycle/running-tests.md`)
4. **Update the gateways** (`AGENTS.md` / `CLAUDE.md`) — if the architecture or API changed
5. **Update README.md** — if user-facing behaviour changed
6. **Commits are in Conventional Commits format** (for git-cliff)

### PR Template

When creating PRs, **always use the template** from `.github/PULL_REQUEST_TEMPLATE.md`.

Key sections:

- **Submission Review Guidelines**: Checkboxes confirming adherence to guidelines
- **Changes proposed**: Description of changes with bug fix references
- **Why this change is needed**: Explanation of the problem and solution
- **Screenshots**: UI changes (if applicable)
- **How to test**: Step-by-step testing instructions
- **Testing done**: What testing you've performed
- **Checklist**: Linting, tests, documentation updates
- **Conventional Commits**: Commit types used

**For bug fixes**, reference the PR/commit that introduced the bug:

```
Bug introduced in PR #XXXXX.
```

**Include testing instructions** that are detailed enough for a reviewer to verify the change.

### PR Review Checklist

Before merging, ensure:

- [ ] All linting checks pass
- [ ] All tests pass
- [ ] **Commits follow Conventional Commits format**
- [ ] Code follows WordPress Coding Standards
- [ ] No standalone functions (only class methods)
- [ ] All new code has appropriate docblocks
- [ ] `@since` annotations are correct
- [ ] **No installed-site data contract broken** (option keys, hook names, IDs, slugs, meta keys — release-blocking); internal API breaks cleanly, no `@deprecated` shims (ADR-005)
- [ ] No changes in `woodev/` directory (unless intentional)

## Git Commands Reference

### Daily Workflow

```bash
# Create new branch from main
git checkout main
git pull origin main
git checkout -b feat/my-feature

# Stage and commit changes (Conventional Commits)
git add woodev/path/to/file.php
git commit -m "feat: add new shipping method"

# Push branch
git push -u origin feat/my-feature
```

### Creating PR with GitHub CLI

```bash
# Create PR with template
gh pr create \
  --title "feat: add shipping calculation by volume" \
  --body-file ".github/PULL_REQUEST_TEMPLATE.md" \
  --base main \
  --head feat/shipping-by-volume
```

### Syncing with Main

```bash
# Update your branch with latest main
git checkout main
git pull origin main
git checkout feat/my-feature
git rebase main

# Or merge if you prefer
git merge main
```

## Version Management

### Framework Version

`VERSION` is the released version, stored in `woodev/class-plugin.php`. `@since` and the next release are tracked separately in `composer.json` → `extra.woodev.planned-release` (see below).

### Release Workflow

A release is a **pull request**, not a direct push. Raising `VERSION` on `main` is what publishes a release (#285).

1. In a PR, bump `VERSION` in `woodev/class-plugin.php`, and bump `extra.woodev.planned-release` in `composer.json` to the next planned release. `SinceTagCeilingTest` goes red if `planned-release` is below `VERSION`.
2. Update the single hand-written "currently …" sentence in `AGENTS.md` (the `@since` row). No other doc may carry the release number; if you find one, point it at `composer.json` instead.
3. Merge the PR once CI is green.
4. On the push to `main`, the `release` job in `.github/workflows/ci.yml` runs when the `version` job reports `changed == 'true'`: it tags `vX.Y.Z`, generates the CHANGELOG with `git-cliff`, builds the release ZIP (`git archive HEAD woodev/`) and publishes the GitHub Release.

### @since Annotations

⚠ **NOT the `VERSION` constant.** This section said to read `VERSION` from
`woodev/class-plugin.php` until 2026-09-05; operator decision #409 settled the opposite, and the two
are deliberately separate concepts.

- `@since` = the **planned release** the change ships in (read it from `composer.json`). Plain semver:
  `2.0.x` = fixes only; the first feature PR raises it to the next minor; `3.0.0` = breaking.
- `VERSION` = the **released** version. It lags on purpose, because raising it on `main`
  publishes a release.
- The machine-readable authority is `composer.json` → `extra.woodev.planned-release`, and
  `tests/unit/SinceTagCeilingTest.php` gates every `@since` in `woodev/**` against it.
- **Inherited code carries `1.0.0`**, never an upstream number.

Full rule: `docs-internal/AGENT-RULES.md` → Rule 5.

## Notes

- **Never force push** to shared branches
- **Squash commits** before merging if branch has multiple WIP commits
- **Delete feature branches** after merging
- **Keep `main` always deployable** — never break main branch
- **Conventional Commits required** — for automatic CHANGELOG generation
- **Breaking an installed-site data contract is release-blocking**; an internal API break is allowed on the v2 line (ADR-005)
