---
name: woodev-framework-code-review
description: Review Woodev Framework code changes for coding standards compliance. Use when reviewing code locally, performing automated PR reviews, or checking code quality. **CRITICAL: Check backward compatibility.**
---

# Woodev Framework Code Review

## When to Use This Skill

**ALWAYS invoke this skill when:**

- Reviewing a pull request
- Performing code review before merging
- Checking code for compliance with standards
- Setting up automated code review workflows
- **Validating backward compatibility** (CRITICAL)

**DO NOT use this skill for:**

- Writing new code (use `woodev-framework-backend-dev`)
- Running linting (use `woodev-framework-dev-cycle`)
- Git operations (use `woodev-framework-git`)

---

## Описание

Review code changes against Woodev Framework coding standards and conventions.

---

## ⚠️ Критические нарушения для флага (Framework Specific)

### Backward Compatibility (CRITICAL)

These violations **require mandatory fixes** before merging. Breaking changes affect 10+ dependent plugins.

- ❌ **Public method/class deleted without deprecation** — Must have deprecation cycle
- ❌ **Public method/class renamed without deprecation** — Must have deprecation cycle
- ❌ **Missing `@deprecated` annotation** — Deprecated code must be marked
- ❌ **Missing `_deprecated_function()` call** — Deprecated methods must call it
- ❌ **Breaking change without major version bump** — Semver violation
- ❌ **Changes in `woodev/` without enhanced review** — Framework code needs extra scrutiny

### Backend PHP Code

Consult the `woodev-framework-backend-dev` skill for detailed standards. Using these standards as guidance, flag these violations and other similar ones:

**Architecture & Structure:**

- ❌ **Standalone functions** — Must use class methods
- ❌ **Classes outside proper namespace** — Must use `Woodev\Framework\*` for new code
- ❌ **Modifications in `woodev/` directory** — Never change framework code unless explicitly requested

**Naming & Conventions:**

- ❌ **camelCase naming** — Must use `snake_case` for methods/variables/hooks
- ❌ **PascalCase for functions** — Must use `snake_case` for function names
- ❌ **Yoda condition violations** — Must follow WordPress Coding Standards

**Documentation:**

- ❌ **Missing `@since` annotations** — Required for public/protected methods and hooks
- ❌ **Missing docblocks** — Required for all hooks, methods, and classes
- ❌ **Verbose docblocks** — Keep concise, one line is ideal
- ❌ **Missing `@param`/`@return` types** — Required for all functions/methods

**Type Safety:**

- ❌ **Missing type declarations** — Must use type hints for parameters and return types (PHP 7.4+)
- ❌ **Missing DTOs for data transfer** — Use contracts or DTOs when passing data between layers

**Data Integrity:**

- ❌ **Missing validation** — Must verify state before deletion/modification
- ❌ **Direct SQL queries** — Use `$wpdb` methods or WC CRUD classes

**Testing:**

- ❌ **Missing tests for new functionality** — All new features must have tests
- ❌ **Tests without assertions** — Every test must verify expected behavior

### JS Code

**Naming & Conventions:**

- ❌ **camelCase for properties/methods** — Must use `snake_case` for properties, methods, and functions
- ❌ **snake_case for class names** — Must use `PascalCase` for class names
- ❌ **Semicolons at end of lines** — Do not use `;` at end of lines

**Script Loading:**

- ❌ **Direct script tags** — Always enqueue scripts via `wp_enqueue_script`
- ❌ **Missing dependency check** — Check dependency availability via `wp_script_is`
- ❌ **Third-party scripts** — Prefer built-in WordPress/WooCommerce scripts

### Documentation

**Markdown Files:**

- ❌ **Not following `woodev-framework-markdown` skill** — All `.md` files must follow the skill guidelines
- ❌ **Wrong language** — User docs in Russian, developer docs in English

---

## Review Approach

1. **Check backward compatibility first** (CRITICAL for framework)
2. **Scan for critical violations** listed above
3. **Cite specific skill files** when flagging issues
4. **Provide correct examples** from the skill documentation
5. **Group related issues** for clarity
6. **Be constructive** — explain why the standard exists when relevant

---

## Output Format

For each violation found:

```text
❌ [Issue Type]: [Specific problem]
Location: [File path and line number]
Standard: [Link to relevant skill file]
Fix: [Brief explanation or example]
```

**Example:**

```text
❌ Backward Compatibility: Public method deleted without deprecation
Location: woodev/class-plugin.php:123
Standard: woodev-framework-backend-dev/code-entities.md
Fix: Add @deprecated annotation and keep method for at least one version. Call _deprecated_function() inside.

Example fix:
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
- [ ] **Backward compatibility verified** (CRITICAL for framework)
- [ ] All linting checks pass (`composer phpcs`)
- [ ] All tests pass (unit + integration)
- [ ] Commits follow Conventional Commits format
- [ ] `@since` annotations are correct (from `VERSION` constant)
- [ ] No changes in `woodev/` directory (unless intentional)
- [ ] CLAUDE.md updated (if architecture changed)
- [ ] README.md updated (if user-facing changes)

---

## Notes

- All detailed standards are in the `woodev-framework-backend-dev` and `woodev-framework-dev-cycle` skills
- Consult those skills for complete context and examples
- When in doubt, refer to the specific skill documentation
- **Never approve PRs with critical violations**
- **Never approve breaking changes without deprecation cycle**
