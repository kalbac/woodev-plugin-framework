<!-- markdownlint-disable MD041 MD036 -->

### Submission Review Guidelines

- I have followed the [Woodev Contributing Guidelines](.github/CONTRIBUTING.md) and the [WordPress Coding Standards](https://make.wordpress.org/core/handbook/best-practices/coding-standards/).
- I have checked to ensure there aren't other open [Pull Requests](../../pulls) for the same update/change.
- I have reviewed my code for [security best practices](https://developer.wordpress.org/apis/security/).
- I have followed **backward compatibility rules** (critical for framework used by 10+ plugins).
- Following the above guidelines will result in quick merges and clear and detailed feedback when appropriate.

### Changes proposed in this Pull Request

Closes # . (For Bug Fixes)

Bug introduced in PR # . (If applicable)

### Why this change is needed

### Screenshots or screen recordings

| Before | After |
| ------ | ----- |
|        |       |

*(Include screenshots for UI changes, or write "N/A" if not applicable)*

### How to test the steps in this Pull Request

1.
2.
3.

### Testing that has already taken place

### Checklist

- [ ] I have run linting: `composer phpcs`
- [ ] I have run tests (if applicable): `composer test:unit`, `composer test:integration`
- [ ] I have updated CLAUDE.md (if architecture or API changed)
- [ ] I have updated README.md (if user-facing changes)
- [ ] **All commits follow Conventional Commits format** (for automatic CHANGELOG generation)
- [ ] **No breaking changes without deprecation cycle** (or major version bump)

### Conventional Commits

**All commits must follow Conventional Commits format** for automatic CHANGELOG generation via git-cliff.

#### Commit Types Used in This PR

- [ ] `feat:` — New feature
- [ ] `fix:` — Bug fix
- [ ] `docs:` — Documentation
- [ ] `refactor:` — Code refactoring (no functionality change)
- [ ] `test:` — Tests
- [ ] `chore:` — Auxiliary tasks (CI/CD, config)
- [ ] `ci:` — CI/CD changes

#### Breaking Changes

- [ ] This PR contains **NO breaking changes**
- [ ] This PR contains breaking changes (requires major version bump)

**If breaking changes:** Add `!` to commit type (e.g., `feat!:`, `fix!:`) and include `BREAKING CHANGE:` footer in commit message.

---

### Notes for Reviewers

*(Add any additional context, concerns, or questions for reviewers)*

**Special attention needed for:**

- [ ] Backward compatibility check (critical for framework)
- [ ] Changes in `woodev/` directory (framework code)
- [ ] Deprecation cycle implementation
