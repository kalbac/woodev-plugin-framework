# The local PHP is four minor versions above the CI floor, so a green local suite says nothing about 7.4

**Namespace:** `[testing/*]`
**Found:** s98 (27.08.2026), on PR #563.

## The trap

`composer check` on this machine runs **PHP 8.5.1**. The CI matrix runs **7.4, 8.0, 8.1, 8.2,
8.3** — the floor is 7.4, and the platform target in `composer.json` is 8.1. Any API whose
BEHAVIOUR changed across that span is invisible locally in exactly one direction: the newest
interpreter is the most permissive, so a test that depends on a newer relaxation passes here and
fails there.

The instance that cost a CI round: `ReflectionProperty::setValue()` on a **private** property.

| PHP | Behaviour without `setAccessible( true )` |
|---|---|
| 7.4, 8.0 | `ReflectionException: Cannot access non-public member …` |
| 8.1 – 8.4 | works; `setAccessible()` is a no-op |
| 8.5 | works; **`setAccessible()` itself is deprecated** |

So the naive fix is wrong in both directions: omitting the call fails the two lowest jobs, and
adding it unconditionally raises a deprecation on the interpreter you actually develop on.

This is not specific to Reflection. The same shape applies to anything the language relaxed:
implicit nullable parameters, `readonly`, enum behaviour, string-to-number comparison,
`ReflectionProperty::getValue()` on uninitialised typed properties.

## ❌ Wrong

```php
// Passes locally on 8.5. Fails `Unit Tests (PHP 7.4)` and `(PHP 8.0)`.
$reflection->getProperty( 'id' )->setValue( $section, 'tools' );
```

## ✅ Correct

```php
$handle = $reflection->getProperty( 'id' );

// Required below 8.1, a deprecated no-op from 8.5.
if ( PHP_VERSION_ID < 80100 ) {
	$handle->setAccessible( true );
}

$handle->setValue( $section, 'tools' );
```

## How to notice before CI does

- **A green `composer check` is evidence about 8.5 and nothing else.** When a change touches
  Reflection, typed properties, or any API with a documented version note, say so out loud rather
  than treating the local run as the gate.
- The CI failure is loud and fast (~30 s per job), so pushing and reading the matrix is a
  legitimate check — but only if you actually read it. `mergeStateStatus: UNSTABLE` with
  `SUCCESS=16 FAILURE=2` is not "mostly green".
- `php -r 'echo PHP_VERSION;'` costs nothing and settles what you are actually testing on.

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other "the gate you ran is not the gate CI runs"
- [phpunit-result-cache-makes-a-run-unreproducible](phpunit-result-cache-makes-a-run-unreproducible.md) — same family: the local run disagreeing with itself
- [php84-implicit-nullable-payment-handlers](php84-implicit-nullable-payment-handlers.md) — a version-drift defect in the other direction (newer PHP deprecating existing code)
