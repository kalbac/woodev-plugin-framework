# Gotcha: [bootstrap/multiversion-early-class-guards] — Guard and source early support classes
> Tags: bootstrap, multi-version, vendored-loading | Session: s4

## What happens
Multiple active plugins can include their own vendored copy of `woodev/bootstrap.php`. If bootstrap support classes are required before a `class_exists()` guard, the second framework copy can fatal with a class redeclaration before resolver arbitration runs. If early capability classes are loaded from the current plugin registration instead of the selected framework copy, the site can also mix base classes from different vendored framework versions.

## Root cause
The bootstrap file is intentionally the compatibility entry path across all vendored framework copies. Any globally named class loaded from that path participates in the same multi-version collision surface as `Woodev_Plugin_Bootstrap` itself. Resolver-selected runtime classes must also come from the selected framework path; otherwise a lower-version WooCommerce/payment/shipping plugin can load stale specialized bases after a higher-version pure WordPress plugin wins arbitration.

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

✅ Correct for selected framework loading:

```php
$this->load_early_capability_classes( $plugin, $loaded_framework ?? $plugin );
```

❌ Wrong:

```php
$this->load_early_capability_classes( $plugin ); // Uses the current registration path.
```

## Related
- [../GOTCHAS.md](../GOTCHAS.md) — gotcha index for bootstrap and multi-version loading rules
- [../adr/003-platform-v2-minimal-framework-resolver.md](../adr/003-platform-v2-minimal-framework-resolver.md) — resolver boundary that keeps `bootstrap.php` as installed compatibility entry path
- [../platform-v2-implementation-spec.md](../platform-v2-implementation-spec.md) — active Platform v2 implementation source
