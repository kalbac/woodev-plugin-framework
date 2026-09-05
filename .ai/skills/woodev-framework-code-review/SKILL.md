---
name: woodev-framework-code-review
description: Review Woodev Framework code changes for coding standards compliance. Use when reviewing code locally, performing automated PR reviews, or checking code quality.
---

# Woodev Framework Code Review

## When to Use This Skill

**ALWAYS invoke this skill when:**

- Reviewing a pull request
- Performing code review before merging
- Checking code for compliance with standards
- Validating backward compatibility

**DO NOT use this skill for:**

- Writing new code (use `woodev-framework-backend-dev`)
- Running linting (use `woodev-framework-dev-cycle`)
- Git operations (use `woodev-framework-git`)

---

## Review Approach

1. **Check the DATA CONTRACTS first** — option keys, hook names, IDs, slugs, meta keys. An internal class or method rename is legitimate under ADR-005 and must not be flagged
2. **Scan for critical violations** listed below
3. **Cite specific skill files** when flagging issues
4. **Provide correct examples** from the skill documentation
5. **Group related issues** for clarity

---

## Critical Violations

### Backward Compatibility — data contracts only (ADR-005)

These violations **require mandatory fixes** before merging. ⚠ An internal class or method rename is
NOT one of them: the clean-break policy makes internal APIs free to break on the v2 line, and this
list said the opposite until 2026-09-05. Architecture context:
`docs-internal/wiki/architecture.md`.

- An action/filter **hook** deleted or renamed (route it through `Woodev_Hook_Deprecator` instead)
- An **option key**, meta key, cron hook or payload, REST namespace, AJAX action, admin page slug,
  log source, background-job ID, gateway or shipping-method ID changed
- A custom DB table or schema changed with no migration
- An `@deprecated` shim, `class_alias` or `_deprecated_function()` wrapper **added** for a moved
  internal API — the policy forbids these, so their presence is the defect

### PHP Code

Consult `woodev-framework-backend-dev` skill for detailed standards. Key violations to flag:

**Architecture:**

- Standalone functions instead of class methods
- Classes outside proper namespace (new code must use `Woodev\Framework\*`)

**Naming:**

- camelCase naming instead of `snake_case` for methods/variables/hooks
- Yoda condition violations (WordPress Coding Standards)

**Documentation:**

- Missing `@since` annotations on public/protected methods and hooks
- Missing docblocks on hooks, methods, and classes
- Missing `@param`/`@return` types

**Type Safety:**

- Missing type declarations for parameters and return types
- Missing DTOs/contracts when passing data between layers

**Data Integrity:**

- Missing validation before deletion/modification
- Direct SQL queries instead of `$wpdb` methods or WC CRUD classes

**Testing:**

- Missing tests for new functionality
- Tests without assertions

### Security Checklist

- User input is sanitized (`sanitize_text_field()`, `absint()`, etc.)
- Output is escaped (`esc_html()`, `esc_attr()`, `wp_kses()`, etc.)
- Nonce verification on form submissions and AJAX handlers
- Capability checks before privileged operations
- No raw SQL without `$wpdb->prepare()`
- No `eval()`, `extract()`, or `$$variable`

### Documentation

- Markdown files follow `woodev-framework-markdown` skill
- User-facing docs in Russian, developer docs in English

---

## Output Format

For each violation found:

```text
[Issue Type]: [Specific problem]
Location: [File path and line number]
Standard: [Link to relevant skill file]
Fix: [Brief explanation or example]
```

**Example:**

```text
Backward Compatibility: Public method deleted without deprecation
Location: woodev/class-plugin.php:123
Standard: woodev-framework-backend-dev/code-entities.md
Fix: Add @deprecated annotation and keep method for at least one version.

/**
 * @deprecated 2.0.0 Use new_method() instead.
 */
public function old_method(): void {
    _deprecated_function( __METHOD__, '2.0.0', __CLASS__ . '::new_method()' );
    $this->new_method();
}
```

---

## Pre-Merge Checklist

Before approving a PR, ensure:

- [ ] No critical violations remain
- [ ] Backward compatibility verified
- [ ] All linting checks pass (`composer phpcs`)
- [ ] All tests pass (unit + integration)
- [ ] Commits follow Conventional Commits format
- [ ] `@since` annotations are correct (from `VERSION` constant)
- [ ] CLAUDE.md updated (if architecture changed)

---

## Notes

- Detailed coding standards are in `woodev-framework-backend-dev` skill
- Development workflow is in `woodev-framework-dev-cycle` skill
- **Never approve PRs with critical violations**
- **Never approve a change that breaks an installed-site data contract.** An internal rename is fine and must not be blocked
