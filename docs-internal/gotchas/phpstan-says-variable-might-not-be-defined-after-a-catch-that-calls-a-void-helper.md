# Gotcha: [tooling/phpstan] "Variable might not be defined" after a try/catch whose catch calls a terminating helper typed `void`

**Discovered:** s229 (12.11.2026), F11 #1690 / PR #1739.

## What happens

PHPStan reports `Variable $x might not be defined` on code after a `try { $x = …; } catch ( Throwable $e ) { fail(); }`
although `fail()` always exits.

## Root cause

The helper is documented `@return void`, so PHPStan assumes the catch branch can fall through with `$x` unset.

## Fix

Document a helper that always terminates (`wp_die`, `exit`, always throws) as `@return never`.

```php
// ❌
/** @return void */
private function fail( string $message ) { wp_send_json_error( $message, 502 ); }

// ✅
/** @return never */
private function fail( string $message ) { wp_send_json_error( $message, 502 ); }
```

## Related

- [class-alias-phpstan-resolution](class-alias-phpstan-resolution.md) — another PHPStan resolution trap
