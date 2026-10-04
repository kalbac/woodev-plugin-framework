# gotcha: below PHP 8.1 a reflective write needs `setAccessible()`, and an anonymous subclass is named `class@anonymous`

**Namespace:** `[testing/*]`
**Discovered:** s150 (2026-10-04), PR #1083 (#130) — CI red on PHP 7.4 and 8.0 only

## What happened

Every local gate was green on PHP 8.5; CI failed on 7.4 and 8.0 with two test-only differences:

1. `new ReflectionProperty( Exception::class, 'code' )` then `setValue()` on a protected property →
   `ReflectionException: Cannot access non-public member Exception::$code`. Before 8.1 reflection needs
   `setAccessible( true )`; since 8.1 it is a no-op, and **8.5 deprecates it** — called unconditionally, the
   deprecation makes the test «risky» locally.
2. `get_class( new class( 'x' ) extends RuntimeException {} )` starts with `RuntimeException@anonymous` on newer
   runtimes but is `class@anonymous` on older ones — a test pinning the new form fails there.

## ❌ Wrong

```php
$prop->setAccessible( true );                                        // deprecated on 8.5
$this->assertSame( 'RuntimeException@anonymous', $type );            // fails on 7.4 / 8.0
```

## ✅ Correct

```php
if ( PHP_VERSION_ID < 80100 ) {
	$prop->setAccessible( true );
}
$this->assertMatchesRegularExpression( '/^(RuntimeException|class)@anonymous$/', $type );
```

## Related

- [the-local-php-is-four-versions-above-the-ci-floor](the-local-php-is-four-versions-above-the-ci-floor.md) — why only CI sees this
- `../gotcha-index/testing.md`
