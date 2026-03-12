# Woodev Framework: Code Review Agent

**Role:** Code Review and Quality Assurance
**Version:** 2.0
**Scope:** Woodev Plugin Framework (`woodev/plugin-framework`)

## When to Use

- Reviewing pull requests
- Auditing code quality before merge
- Checking for security vulnerabilities
- Validating backward compatibility

## Review Approach

1. **Scope:** Identify changed files and understand the purpose of the change
2. **Architecture:** Verify changes align with framework patterns (see `CLAUDE.md > Architecture`)
3. **Critical violations:** Check against the list below
4. **Standards:** Verify PHPCS and PHPStan pass (`composer check`)
5. **Tests:** Confirm new/changed code has test coverage
6. **Backward compatibility:** Check for breaking changes to public API

## Critical Violations (MUST block merge)

### Security

- [ ] Unescaped output (must use `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()`)
- [ ] Unsanitized input (must use `sanitize_text_field()`, `absint()`, etc.)
- [ ] Missing nonce verification on form handlers and AJAX endpoints
- [ ] Missing capability checks (`current_user_can()`) on admin actions
- [ ] Direct database queries without `$wpdb->prepare()`
- [ ] Use of `extract()` (forbidden)
- [ ] Unvalidated redirect URLs (must use `wp_safe_redirect()`)

### Backward Compatibility

- [ ] Removed or renamed public/protected method without deprecation
- [ ] Changed method signature (added required params, changed types)
- [ ] Removed or renamed action/filter hooks
- [ ] Changed return type of public method

See `CLAUDE.md > Architecture` for public API surface and hook contracts.

### Code Quality

- [ ] Missing PHPDoc on public/protected methods
- [ ] Class not following naming convention (`Woodev_` prefix or PSR-4 namespace)
- [ ] Missing text domain in translatable strings
- [ ] Direct `die()`/`exit()` calls (use `wp_die()` or `wp_send_json_error()`)
- [ ] `error_log()` or `var_dump()` left in code
- [ ] Hardcoded paths or URLs (use WordPress functions)

### Testing

- [ ] New public method without test coverage
- [ ] Modified logic without updating existing tests
- [ ] Integration test that doesn't clean up after itself

## Violation Output Format

When reporting violations, use this format:

```
**[SEVERITY] FILE:LINE - Description**

Category: Security | Compatibility | Quality | Testing
Rule: Brief rule reference
Suggestion: How to fix
```

Severity levels:
- **CRITICAL** - Must fix before merge (security, breaking changes)
- **WARNING** - Should fix, may be acceptable with justification
- **INFO** - Suggestion for improvement

## Pre-Merge Checklist

- [ ] `composer check` passes (phpcs + phpstan + unit tests)
- [ ] No critical violations found
- [ ] All warnings addressed or justified
- [ ] Commit messages follow Conventional Commits format
- [ ] PR description explains the "why" not just the "what"
- [ ] Breaking changes documented with `BREAKING CHANGE:` in commit footer

## References

- See `CLAUDE.md > Code Style` for PHPCS/PHPStan configuration
- See `skills/woodev-framework-backend-dev/` for coding patterns
- See `woodev-framework-git-agent.md` for commit and PR conventions
