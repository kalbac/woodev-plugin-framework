# Future Backlog — Woodev Plugin Framework

> Features and improvements deferred for later versions.
> Format: what's done | what's missing | why deferred | when to implement

## Technical Debt

### PHPStan Baseline Cleanup
- **What's done:** 50+ errors baselined in phpstan-baseline.neon
- **What's missing:** Fix or properly type-annotate each ignored error
- **Why deferred:** Non-blocking; baseline errors are existing patterns, not regressions
- **When:** Gradually, during normal development

### Deprecated Methods Removal (v2.0.0)
- **What's done:** 11 methods deprecated in Woodev_Plugin, `_deprecated_function()` called
- **What's missing:** Remove the deprecated methods after deprecation cycle
- **Why deferred:** Backward compatibility — 10+ dependent plugins need migration time
- **When:** v2.0.0 (next major version)

### Payment Gateway Trait Extraction
- **What's done:** class-payment-gateway.php works correctly
- **What's missing:** File is ~3900 lines — extract logical groups into traits
- **Why deferred:** Functional priority over code organization
- **When:** During payment-gateway refactoring cycle

## Deferred Features

<!-- Add deferred feature requests here -->
