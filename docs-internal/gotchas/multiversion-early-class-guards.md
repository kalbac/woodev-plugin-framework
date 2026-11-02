# Gotcha: [bootstrap/multiversion-early-class-guards] — Guard and source early support classes
> Tags: bootstrap, multi-version, vendored-loading | Session: s4

## What happens
Multiple active plugins can include their own vendored copy of `woodev/bootstrap.php`. If bootstrap support classes are required before a `class_exists()` guard, the second framework copy can fatal with a class redeclaration before resolver arbitration runs. If early capability classes are loaded from the current plugin registration instead of the selected framework copy, the site can also mix base classes from different vendored framework versions.

## Root cause
The bootstrap file is intentionally the compatibility entry path across all vendored framework copies. Any globally named class loaded from that path participates in the same multi-version collision surface as `Woodev_Plugin_Bootstrap` itself. Resolver-selected runtime classes must also come from the selected framework path; otherwise a lower-version WooCommerce/payment/shipping plugin can load stale specialized bases after a higher-version pure WordPress plugin wins arbitration.

## Fix

> **Mechanism, not names.** This gotcha was written in s4 against the pre-namespace resolver. Today the resolver is
> `Woodev\Framework\Framework_Resolver` (`woodev/class-framework-resolver.php`), and the early-capability loader
> method this note used to quote no longer exists. The two rules below still hold; the snippets use the current guard.

❌ Wrong — a globally named class declared with no guard in a file every vendored copy loads:

```php
class Framework_Resolver {}
```

✅ Correct — the guard that `class-framework-resolver.php` uses today:

```php
if ( ! class_exists( Framework_Resolver::class, false ) ) :
	class Framework_Resolver {}
endif;
```

Second rule: any runtime class that is not guarded this way must be sourced from the **selected** framework
copy (the resolver's winner), never from the path of the plugin that happens to be registering. See
[ADR-003](../adr/003-platform-v2-minimal-framework-resolver.md) for the resolver boundary.

## Related
- [../GOTCHAS.md](../GOTCHAS.md) — gotcha index for bootstrap and multi-version loading rules
- [../adr/003-platform-v2-minimal-framework-resolver.md](../adr/003-platform-v2-minimal-framework-resolver.md) — resolver boundary that keeps `bootstrap.php` as installed compatibility entry path
- [../platform-v2-implementation-spec.md](../platform-v2-implementation-spec.md) — historical Platform v2 implementation spec; current design is [ADR-003](../adr/003-platform-v2-minimal-framework-resolver.md) and [wiki/architecture.md](../wiki/architecture.md)
