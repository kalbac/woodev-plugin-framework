# Woodev Framework: Git Workflow Agent

**Role:** Git Operations, Branching, and Release Management
**Version:** 2.0
**Scope:** Woodev Plugin Framework (`woodev/plugin-framework`)

## When to Use

- Creating branches and managing Git workflow
- Creating pull requests
- Performing releases and version tagging
- Resolving merge conflicts

## Branch Naming

```
<type>/<short-description>
```

| Type        | Purpose                        | Example                           |
|-------------|--------------------------------|-----------------------------------|
| `feat/`     | New feature                    | `feat/async-api-cache`            |
| `fix/`      | Bug fix                        | `fix/gateway-token-refresh`       |
| `refactor/` | Code restructuring             | `refactor/bootstrap-loading`      |
| `chore/`    | Maintenance, deps, tooling     | `chore/update-phpstan-baseline`   |
| `docs/`     | Documentation                  | `docs/api-layer-guide`            |
| `test/`     | Test additions/fixes           | `test/lifecycle-upgrade-paths`    |
| `release/`  | Release preparation            | `release/2.5.0`                   |
| `hotfix/`   | Urgent production fix          | `hotfix/critical-payment-error`   |

**Rules:**
- Use lowercase, hyphens for separators
- Keep descriptions under 40 characters
- Base feature branches off `main`

## Pull Request Creation

Use `gh` CLI to create PRs:

```bash
gh pr create --title "feat(gateway): add token refresh support" --body "$(cat <<'EOF'
## Summary
- Added automatic token refresh for payment gateways
- Tokens are refreshed 5 minutes before expiry

## Test plan
- [ ] Unit tests pass: `composer test:unit`
- [ ] Integration tests pass: `composer test:integration`
- [ ] Manual test with wp-env
EOF
)"
```

**PR title:** Must follow Conventional Commits format (same as commit message first line).
**PR body:** Include Summary (bullet points) and Test plan (checklist).

## Release Workflow

### 1. Prepare Release Branch

```bash
git checkout main && git pull
git checkout -b release/X.Y.Z
```

### 2. Update Version Numbers

Update the version constant in `woodev/bootstrap.php` and any other version references.

### 3. Generate Changelog

CHANGELOG.md is auto-generated from Conventional Commits. Do NOT edit it manually.

### 4. Create Release PR

```bash
gh pr create --title "release: vX.Y.Z" --body "Release version X.Y.Z"
```

### 5. After Merge: Tag and Release

```bash
git checkout main && git pull
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin vX.Y.Z
gh release create vX.Y.Z --generate-notes
```

### Version Bumping Rules

| Change type     | Version bump | Example         |
|-----------------|-------------|-----------------|
| Breaking change | Major       | 2.0.0 -> 3.0.0 |
| New feature     | Minor       | 2.4.0 -> 2.5.0 |
| Bug fix         | Patch       | 2.4.1 -> 2.4.2 |

## Commit Messages

See `woodev-framework-dev-workflow-agent.md` for Conventional Commits format and type reference.

## References

- See `woodev-framework-dev-workflow-agent.md` for commit message format
- See `woodev-framework-code-review-agent.md` for PR review checklist
- See `CLAUDE.md` for project architecture
