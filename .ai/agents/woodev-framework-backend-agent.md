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

## Backward Compatibility — clean-break policy (ADR-005), two rules

- **Internal code is FREE TO BREAK on the v2 line.** Class names, method signatures, visibility,
  namespacing, file layout. Do **NOT** add `@deprecated` shims, `class_alias` or
  `_deprecated_function()` wrappers for a moved or renamed internal API — delete the ones you find.
  (This section said the opposite until 2026-09-05; ADR-005 superseded that on 2026-06-03.)
- **Installed-site data contracts NEVER break.** Option keys, license and instance IDs, updater
  identity, gateway and shipping-method IDs plus instance setting keys, **hook names**
  (`woodev_{plugin_id}_*`), cron hooks and payloads, DB tables, REST namespaces, AJAX actions,
  admin slugs, log sources, background-job IDs, order and session meta keys.
- The surviving `_deprecated_function()` / `_doing_it_wrong()` calls are misuse markers and
  clone/wakeup guards, not internal-API move shims. `Woodev_Hook_Deprecator` is how a genuinely
  deprecated HOOK is handled — hooks are data contracts, so they get a deprecator, not a deletion.

Full policy: `docs-internal/adr/005-platform-v2-clean-break-policy.md` and
`docs-internal/AGENT-RULES.md` → Rule 0. Architecture reference:
`docs-internal/wiki/architecture.md`.

## References

- See `CLAUDE.md` for full architecture, bootstrap flow, and subsystem details
- See `skills/woodev-framework-backend-dev/` for coding patterns and examples
- See `woodev-framework-code-review-agent.md` for review checklist
