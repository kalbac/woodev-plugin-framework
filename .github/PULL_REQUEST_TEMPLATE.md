<!-- markdownlint-disable MD041 MD036 -->

### Submission Review Guidelines

- I have followed the [Woodev Contributing Guidelines](.github/CONTRIBUTING.md) and the [WordPress Coding Standards](https://make.wordpress.org/core/handbook/best-practices/coding-standards/).
- I have checked to ensure there aren't other open [Pull Requests](../../pulls) for the same update/change.
- I have reviewed my code for [security best practices](https://developer.wordpress.org/apis/security/).
- I have applied the **clean-break policy** ([ADR-005](../docs-internal/adr/005-platform-v2-clean-break-policy.md)): internal APIs may break cleanly with no shims; installed-site data contracts — option keys, hook names, IDs, slugs, meta keys — never break.
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

### Areas for human testing

*What the machine could NOT verify — name it here, so the reviewer knows exactly where to look.*
*Write "N/A" only if a machine really did prove everything.*

-

### Areas for documentation updates

*User-facing behaviour this PR changes, and which doc it lands in. "N/A" if none.*

-

### Checklist

- [ ] I have run linting: `composer phpcs`
- [ ] I have run tests (if applicable): `composer test:unit`, `composer test:integration`
- [ ] I have updated `docs-internal/` where the change belongs (`wiki/architecture.md` for a seam, `adr/` for a decision, a gotcha for a trap)
- [ ] I have updated README.md (if user-facing changes)
- [ ] **All commits follow Conventional Commits format** (for automatic CHANGELOG generation)
- [ ] **No installed-site data contract broken** (option keys, hook names, cron, REST namespaces, AJAX actions, admin slugs, meta keys)

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

- [ ] This PR breaks **no** installed-site data contract
- [ ] This PR breaks an internal API only — allowed on the v2 line, no shim added
- [ ] This PR breaks an installed-site data contract (**release-blocking** — say why here)

**If breaking changes:** Add `!` to commit type (e.g., `feat!:`, `fix!:`) and include `BREAKING CHANGE:` footer in commit message.

---

### Notes for Reviewers

*(Add any additional context, concerns, or questions for reviewers)*

**Special attention needed for:**

- [ ] Installed-site data contracts (the release-blocking list)
- [ ] Changes in `woodev/` directory (framework code)
- [ ] A hook whose name, payload shape or firing order changed
