# Gotcha: [bootstrap/multiversion-early-class-guards] — Guard early bootstrap support classes
> Tags: bootstrap, multi-version, vendored-loading | Session: s4

## What happens
Multiple active plugins can include their own vendored copy of `woodev/bootstrap.php`. If bootstrap support classes are required before a `class_exists()` guard, the second framework copy can fatal with a class redeclaration before resolver arbitration runs.

## Root cause
The bootstrap file is intentionally the compatibility entry path across all vendored framework copies. Any globally named class loaded from that path participates in the same multi-version collision surface as `Woodev_Plugin_Bootstrap` itself.

## Fix

❌ Wrong:

```php
require_once __DIR__ . '/class-framework-resolver.php';

class Woodev_Framework_Resolver {}
```

✅ Correct:

```php
require_once __DIR__ . '/class-framework-resolver.php';

if ( ! class_exists( 'Woodev_Framework_Resolver', false ) ) :
	class Woodev_Framework_Resolver {}
endif;
```

## Related
- [../GOTCHAS.md](../GOTCHAS.md) — gotcha index for bootstrap and multi-version loading rules
- [../adr/003-platform-v2-minimal-framework-resolver.md](../adr/003-platform-v2-minimal-framework-resolver.md) — resolver boundary that keeps `bootstrap.php` as installed compatibility entry path
- [../platform-v2-implementation-spec.md](../platform-v2-implementation-spec.md) — active Platform v2 implementation source
