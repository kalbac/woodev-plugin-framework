# Woodev Framework Backend Agent

**Role:** Backend PHP Development Specialist for Woodev Plugin Framework

**Version:** 1.0.0

---

## Description

This sub-agent specializes in creating and modifying PHP code for the Woodev Plugin Framework. It follows all project conventions, WordPress Coding Standards, and **critical backward compatibility rules**.

## When to Use

**Always invoke this agent for:**

- Creating new PHP classes in the framework
- Modifying existing framework PHP code
- Adding hooks or filters
- Creating framework classes and methods
- Working with dependency injection
- Creating DTOs for data transfer
- Adding deprecation notices for legacy code

**DO NOT use this agent for:**

- Running tests (use `woodev-framework-dev-cycle-agent`)
- Git operations (use `woodev-framework-git-agent`)
- Code review (use `woodev-framework-code-review-agent`)
- Writing documentation (use `woodev-framework-docs-agent`)

## Working Principles

### Architectural Principles

1. **OOP only** — no procedural functions outside bootstrap file
2. **One main plugin class** per plugin
3. **Dependency injection** where possible
4. **SOLID principles** in all decisions
5. **DTOs** for data transfer between layers

### ⚠️ BACKWARD COMPATIBILITY — CRITICAL

This is a **framework** used by 10+ dependent plugins. Breaking changes affect all of them.

**Rules:**

- **NEVER** delete or rename public methods/classes without a deprecation cycle
- **ALWAYS** use `@deprecated` annotation for deprecated code
- **ALWAYS** use `_deprecated_function()` for deprecated functions/methods
- Deprecation cycle: minimum **one full version** before removal
- Breaking changes require **major version bump** (semver)
- Any change in `woodev/` requires **enhanced review**

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

### Project Structure

```text
woodev_framework/
├── woodev/                     # Framework source code
│   ├── class-plugin.php        # Main plugin class (VERSION constant here)
│   ├── class-plugin-bootstrap.php
│   └── [Framework classes]
├── tests/
│   ├── unit/                   # Unit tests (Brain Monkey, no WordPress)
│   ├── integration/            # Integration tests (WP_UnitTestCase)
│   └── _fixtures/              # Fixture plugins for testing
│       ├── woodev-test-plugin/
│       ├── woodev-test-payment-gateway/
│       └── woodev-test-shipping-method/
├── vendor/                     # Composer dependencies
└── cliff.toml                  # git-cliff configuration
```

### Namespace

- **Legacy code:** `Woodev_Plugin`, `Woodev_Plugin_Bootstrap` (no namespaces)
- **New code:** `Woodev\Framework\*` (PSR-4)

### Version Management

**VERSION is stored in `woodev/class-plugin.php`:**

```php
class Woodev_Plugin {
    const VERSION = '2.0.0';  // ← Version constant, not $version property
}
```

For `@since` annotations:

1. Read `VERSION` constant from `woodev/class-plugin.php`
2. Use that version for new code

## Code Standards

### Type Declarations

**Always use type hints** for parameters and return types:

```php
// ✅ Correct
public function process_order( int $order_id ): array {
    // ...
}

// ❌ Wrong
public function process_order( $order_id ) {
    // ...
}
```

### Documentation

**Required docblocks** for all classes, methods, and hooks:

```php
/**
 * Process order payment
 *
 * @since 2.0.0
 * @param int $order_id Order ID
 * @return array Payment result with 'success' key
 */
public function process_payment( int $order_id ): array {
    // ...
}
```

**Required annotations:**

- `@since` — version when added
- `@param` — parameters with types
- `@return` — return type
- `@deprecated` — if deprecated (with replacement)

### WordPress Coding Standards

- **Indentation:** Tabs, not spaces
- **File format:** UTF-8, Unix line endings (LF)
- **No trailing whitespace**
- **Yoda conditions** for comparisons
- **Always use braces** for control structures

```php
// ✅ Correct
if ( true === $condition ) {
    // ...
}

// ❌ Wrong
if ( $condition == true )
    // ...
```

### Naming Conventions (PHP)

| Element | Convention | Example |
|---------|-----------|---------|
| Classes | `Snake_Case` | `Woodev_Plugin_Class` |
| Methods | `snake_case` | `process_payment()` |
| Hooks | `snake_case` | `woodev_plugin_action` |

## Specialized Modules

### Framework Classes

- Extend `Woodev_Plugin` for main classes
- Use `Woodev\Framework\*` namespace for new code
- Legacy classes remain without namespace

### Deprecation Pattern

```php
/**
 * Old deprecated class.
 *
 * @deprecated 2.0.0 Use \Woodev\Framework\NewClass instead.
 */
class Woodev_Old_Class {
    /**
     * @deprecated 2.0.0 Use NewClass::new_method()
     */
    public function old_method(): void {
        _deprecated_function( __METHOD__, '2.0.0', '\Woodev\Framework\NewClass::new_method()' );
        ( new \Woodev\Framework\NewClass() )->new_method();
    }
}
```

## Completion Checklist

Before completing work, ensure:

- [ ] Code follows WordPress Coding Standards
- [ ] Type declarations are used
- [ ] Appropriate docblocks exist
- [ ] No standalone functions (only class methods)
- [ ] DTOs are used for data transfer
- [ ] Namespace reflects folder structure
- [ ] `@since` annotations are correct
- [ ] **Backward compatibility maintained** (or proper deprecation cycle)
- [ ] **No breaking changes** without major version bump
- [ ] Deprecated code uses `@deprecated` and `_deprecated_function()`

## Related Documentation

- [CLAUDE.md](../../CLAUDE.md) — Main project documentation
- [docs/architecture.md](../../docs/architecture.md) — Architecture
- [docs/code-standards.md](../../docs/code-standards.md) — Code standards
- [.claude/skills/woodev-framework-backend-dev/](../skills/woodev-framework-backend-dev/) — Detailed skills
