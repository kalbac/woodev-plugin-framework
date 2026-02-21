---
name: woodev-framework-backend-dev
description: Add or modify Woodev Framework backend PHP code following project conventions. Use when creating new classes, methods, hooks, or modifying existing backend code. **CRITICAL: Maintain backward compatibility.**
---

# Woodev Framework Backend Development

This skill provides guidance for developing Woodev Framework backend PHP code according to project standards and conventions.

## ⚠️ CRITICAL: Backward Compatibility

This is a **framework** used by 10+ dependent plugins. Breaking changes affect all of them.

**Rules:**

- **NEVER** delete or rename public methods/classes without a deprecation cycle
- **ALWAYS** use `@deprecated` annotation for deprecated code
- **ALWAYS** use `_deprecated_function()` for deprecated functions/methods
- Deprecation cycle: minimum **one full version** before removal
- Breaking changes require **major version bump** (semver)

**Example deprecation:**

```php
/**
 * Old method name.
 *
 * @deprecated 2.0.0 Use new_method_name() instead.
 * @see self::new_method_name()
 */
public function old_method_name(): void {
    _deprecated_function( __METHOD__, '2.0.0', __CLASS__ . '::new_method_name()' );
    $this->new_method_name();
}
```

## When to Use This Skill

**ALWAYS invoke this skill before:**

- Creating new PHP classes
- Modifying existing backend PHP code
- Adding hooks or filters
- **Writing PHP unit tests** (invoke before creating `*Test.php` files)
- **Adding deprecation notices**

**DO NOT use this skill for:**

- Running tests (use `woodev-framework-dev-cycle`)
- Git operations (use `woodev-framework-git`)
- Code review (use `woodev-framework-code-review`)

## Instructions

Follow Woodev Framework project conventions when adding or modifying backend PHP code:

1. **Creating new code structures**: See [file-entities.md](file-entities.md) for conventions on creating classes and organizing files.
2. **Naming conventions**: See [code-entities.md](code-entities.md) for naming methods, variables, and parameters
3. **Coding style**: See [coding-conventions.md](coding-conventions.md) for general coding standards and best practices
4. **Working with hooks**: See [hooks.md](hooks.md) for hook callback conventions and documentation
5. **Dependency injection**: See [dependency-injection.md](dependency-injection.md) for DI container usage
6. **Data integrity**: See [data-integrity.md](data-integrity.md) for ensuring data integrity when performing CRUD operations

## Key Principles

- Always follow WordPress Coding Standards
- Use class methods instead of standalone functions
- Use PSR-4 autoloading with `Woodev\Framework` namespace for new code
- Legacy code remains without namespace (`Woodev_Plugin`, `Woodev_Plugin_Bootstrap`)
- Write comprehensive unit tests for new functionality
- Run linting and tests before committing changes
- **Maintain backward compatibility** (or use proper deprecation cycle)

## Version Information

To determine the current framework version number for `@since` annotations:

- Read the `VERSION` constant in `woodev/class-plugin.php`
- Example: If `const VERSION = '2.0.0'`, use `@since 2.0.0`
