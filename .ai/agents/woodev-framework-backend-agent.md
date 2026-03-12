# Woodev Framework: Backend Development Agent

**Role:** Backend PHP Development
**Version:** 2.0
**Scope:** Woodev Plugin Framework (`woodev/plugin-framework`)

## When to Use

- Writing new PHP classes, methods, or subsystems
- Refactoring existing framework code
- Adding new API integrations or payment gateways
- Working with the bootstrap or lifecycle systems

## DO NOT Use For

- Writing commit messages (use dev-workflow-agent)
- Code review (use code-review-agent)
- Git operations (use git-agent)
- Documentation updates (use docs-agent)

## Project Structure

```
woodev/                     # Framework source (all prefixed Woodev_)
  api/                      # API base classes and interfaces
  payment-gateway/          # Payment gateway plugin variant
  shipping-method/          # Shipping method plugin variant (PSR-4 namespaced)
  settings-api/             # WooCommerce-style settings
  utilities/                # Async requests, background jobs, box packer
tests/                      # unit/ and integration/ with _fixtures/
```

See `CLAUDE.md > Architecture` for full subsystem documentation.

## Namespace Rules

### Legacy Classes (majority of codebase)

- Prefix: `Woodev_` (e.g., `Woodev_Plugin`, `Woodev_Payment_Gateway`)
- File naming: `class-{name-with-hyphens}.php` (e.g., `class-payment-gateway.php`)
- No PHP namespace declarations

### New PSR-4 Code (shipping module, new subsystems)

- Namespace: `Woodev\Framework\{Module}` (e.g., `Woodev\Framework\Shipping`)
- File naming: `class-{ClassName}.php` in matching directory structure
- Autoloaded via Composer PSR-4

### Rules

- NEVER mix legacy prefix and PSR-4 namespace in the same class
- New subsystems SHOULD use PSR-4 namespaces
- Existing legacy classes MUST NOT be converted without a deprecation plan

## Version Management

- Framework version is defined in `woodev/bootstrap.php` as a constant
- Each plugin declares a minimum framework version in its bootstrap registration
- The bootstrap loads the highest available framework version across all active plugins

## Coding Conventions

- Platform target: PHP 8.1 (compatible with PHP 7.4+)
- WordPress Coding Standards (`WordPress-Core`, `WordPress-Extra`, `WordPress-Docs`)
- Short array syntax `[]` is allowed
- Line length limit: 120 characters
- All public methods MUST have PHPDoc blocks
- i18n: Use Russian for UI-facing strings, text domain `woodev-plugin-framework`

See `skills/woodev-framework-backend-dev/` for detailed patterns and examples.
See `CLAUDE.md > Code Style` for PHPCS/PHPStan configuration.

## Backward Compatibility

- Public/protected methods and properties MUST NOT be removed without deprecation
- Deprecated items: add `@deprecated X.Y.Z` PHPDoc tag and call `_deprecated_function()`
- Maintain backward compatibility for at least 2 minor versions
- Hook names (`woodev_{plugin_id}_*`) are part of the public API

See `CLAUDE.md > Architecture` for hook and API contracts.

## References

- See `CLAUDE.md` for full architecture, bootstrap flow, and subsystem details
- See `skills/woodev-framework-backend-dev/` for coding patterns and examples
- See `woodev-framework-code-review-agent.md` for review checklist
