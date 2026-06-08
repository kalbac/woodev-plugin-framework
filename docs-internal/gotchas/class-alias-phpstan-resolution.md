# class_alias() and PHPStan

PHPStan does not follow `class_alias()` calls when the alias is declared inside
a conditional `if ( ! class_exists( ..., false ) )` block in a file that lives
outside the classmap. Code that references the alias by short name
(`AliasClass::method()`) will produce
`Call to static method ... on an unknown class AliasClass` errors.

## Why this happens

Two conditions are required:

1. The alias file uses `class_alias( \Real\Class::class, 'AliasClass' )` instead
   of declaring a real subclass (`class AliasClass extends \Real\Class {}`).
2. The alias file is loaded conditionally (e.g., by the framework resolver only
   when WC is required), so it is NOT in the composer classmap.

The existing `class-woocommerce-plugin-alias.php` uses `class_alias()` and
relies on the B-2 shim's internal code using the FQCN
`\Woodev\Framework\Woocommerce_Plugin::class` — never the alias. PHPStan is
happy because the FQCN resolves through the classmap.

## Fix patterns (pick one)

**Option A — FQCN in internal code, alias for users (recommended for shims):**

```php
// In the shim (internal code, parsed by PHPStan):
_deprecated_function( __METHOD__, '2.0.0', 'AliasClass::method()' ); // alias in deprecation message
\Real\Class::method();                                                  // FQCN in actual call
```

**Option B — Real subclass in the alias file (for user-facing code that
PHPStan also parses):**

```php
// class-foo-alias.php
if ( ! class_exists( 'Foo', false ) ) {
    class Foo extends \Real\Class {}   // empty subclass, not class_alias
}
```

This adds a classmap entry (`Foo => class-foo-alias.php`) and PHPStan sees
`Foo` as a real class.

## When to apply

- Any time you add a new helper/plugin/license/etc. class and give it a
  global-namespace alias for backward compat, decide which option to use
  before committing.
- If the alias is only referenced in deprecation messages and docblocks (which
  PHPStan does not analyze), Option A is fine.
- If user code or framework code calls the alias by short name, use Option B
  so PHPStan resolves the static call.

## Related

- [B-2 audit 2026-06-01 — get_woocommerce_uploads_path() shim FQCN fix]
  (../audit-2026-06-01.md#b-2) — Option A applied to the B-2 shim.
- [M-1 + L-4 helper-class split (2026-06-02 polish session)]
  (../../CURRENT-STATE.md#next-actions-priority-order) — Woodev_Woocommerce_Helper
  uses Option A; the alias is for users and the deprecation message only.
