# Reflection `setAccessible()` — required on PHP < 8.1, deprecated on 8.5; guard it

> [testing/unit] — discovered 2026-06-08 fixing PR #20 Unit tests (7.4/8.0).

## The trap (two-sided)

Tests that read/invoke **private** members via reflection without `setAccessible(true)`:

```php
$prop = ( new \ReflectionClass( $obj ) )->getProperty( 'items' );
$prop->getValue( $obj );   // ← needs setAccessible(true) on PHP < 8.1
```

- **PHP < 8.1:** `setAccessible(true)` is **required**, else
  `ReflectionException: Cannot access non-public member` / `Trying to invoke private method`.
- **PHP 8.1+:** reflected members are accessible by default → `setAccessible()` is a no-op.
- **PHP 8.5+:** `ReflectionProperty/Method::setAccessible()` is **deprecated**; an *unconditional*
  call emits a deprecation notice, which PHPUnit reports as "Test code printed unexpected output"
  → test error.

So neither "never call it" (breaks 7.4/8.0) nor "always call it" (breaks 8.5) is correct. The
CI matrix is 7.4–8.3 but `composer.json` allows `php <9.0`, so 8.5 matters.

This surfaced 26 errors only after the Unit job was unblocked (it had never run on CI — see
[[ci-failing-gate-skips-dependent-jobs]]). It passed on 8.1–8.3, failed on 7.4/8.0.

## Fix — version-guard every call

```php
if ( PHP_VERSION_ID < 80100 ) {
    $prop->setAccessible( true );
}
$prop->getValue( $obj );
```

Calls it where required (7.4/8.0), skips it where it's a no-op/deprecated (8.1+, incl. 8.5).

## Why it matters

`passes on 8.1-8.3 / fails on 7.4/8.0` for private-member reflection = missing `setAccessible`.
`passes on ≤8.4 / fails on 8.5` for an *unconditional* `setAccessible` = the deprecation. You
need the guard to satisfy both ends of the supported range. Local dev on PHP 8.5 will catch the
deprecation side; only the 7.4/8.0 CI jobs catch the required side.

## How to apply

- Any new reflected private `getValue()`/`setValue()`/`invoke()` in a test must precede the
  access with the `PHP_VERSION_ID < 80100` guarded `setAccessible(true)`.
- Inspection-only reflection (`getMethods`, `getParameters`, `getName`, `getDeclaringClass`,
  `newInstanceWithoutConstructor`) does **not** need `setAccessible`.

## Addendum — s68 (2026-08-12): recurrence, and where the rule actually has to live

Hit again in PR-A of the location-provider layer: `LocationProviderRegistryTest` reflected a private
static (`bundled_provider_classes()`) with no guard — green on local PHP 8.5, `ReflectionException`
on the 7.4/8.0 CI jobs, 1798 other tests passing around it. Same shape as 2026-06-08, same fix.

Two things worth keeping from the recurrence:

- **The rule was written down and still did not travel.** The work was subagent-driven; the
  implementer never read `GOTCHAS.md` because nothing in its brief pointed here. A gotcha only
  prevents anything if it reaches whoever writes the line — for delegated work that means the
  brief, not the index. Practical lever: any brief that will produce test code touching private
  members must carry this rule inline.
- **Prefer not to reflect at all.** The assertion pinned a private *literal* — an implementation
  detail. Registering a fake class under the expected FQCN and asserting the registry picks it up
  proves the same seam through observable behaviour and is immune to both ends of the hazard.
  Reflection is the right tool only when the behaviour has no observable surface yet.

## Related

- [[brain-monkey-function-pollution]], [[ci-failing-gate-skips-dependent-jobs]] — the other masked Unit failures
- [[php84-implicit-nullable-payment-handlers]] — another PHP-version-specific test trap
