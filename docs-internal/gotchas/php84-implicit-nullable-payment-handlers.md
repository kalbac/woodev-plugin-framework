# Gotcha: [php/php84-implicit-nullable-payment-handlers] — Legacy payment handler files use implicit-nullable parameters; PHP 8.4+ deprecates them
> Tags: php, php84, deprecation, payment-gateway, handler-files
> Discovered: 2026-06-01 (independent audit)
> Resolved: 2026-06-02 (commit `ef3d067` — H1)

## What happens
`RealisticPaymentFixtureTest.php:88-94` wraps `$resolver->load_plugins()` in an `error_reporting( error_reporting() & ~E_DEPRECATED )` block to mask PHP 8.4+ deprecation notices. The inline comment admits this is to suppress "pre-existing PHP 8.4+ implicit-nullable deprecations in legacy payment handler files" loaded by `Woodev_Payment_Gateway_Plugin::__construct()` via its `includes()` chain.

The legacy files still use the PHP 7.4-era implicit-nullable parameter syntax:

```php
// ❌ Implicit-nullable — deprecated in PHP 8.4, fatal in PHP 9.0
public function do_credit_card_capture_failed( $order, $exception = null ) {
    // ...
}

public function mark_order_as_failed( $order, $message = null ) {
    // ...
}
```

```php
// ✅ Explicit nullable — required for PHP 8.4+
public function do_credit_card_capture_failed( $order, ?\Exception $exception = null ): void {
    // ...
}
```

### Where the test masks it
- `tests/unit/RealisticPaymentFixtureTest.php:88-94` — `error_reporting` mask around `load_plugins()`
- This is **a workaround, not a fix**. Any production plugin that loads `payment-gateway/class-payment-gateway-plugin.php` on PHP 8.4+ will see the same deprecations in real logs.

### Affected files (to be audited)
The `includes()` chain in `Woodev_Payment_Gateway_Plugin::__construct()` loads these files (per `class-payment-gateway-plugin.php` near the top of the constructor):
- `payment-gateway/handlers/abstract-payment-handler.php`
- `payment-gateway/handlers/abstract-hosted-payment-handler.php`
- `payment-gateway/handlers/capture.php`
- `payment-gateway/admin/class-payment-gateway-admin-*.php` (multiple)
- Possibly more

A targeted audit is needed: `grep -nE 'function [a-z_]+\(.*= ?null\)' woodev/payment-gateway/handlers/` and `woodev/payment-gateway/admin/`.

## Root cause
The framework targets PHP 7.4+ (per `composer.json` `"php": ">=7.4 <9.0"`) and uses PHP 7.4-era idioms throughout. Implicit-nullable parameter syntax (`$arg = null` without `?Type`) was a long-standing soft deprecation in PHPStan and IDEs and became a hard `E_DEPRECATED` notice in PHP 8.4 (November 2024). The CI matrix (per `.github/workflows/`) and the local dev environment must be checked to see whether they run PHP 8.4+. If they do, every `composer check` run is generating deprecation noise that the test silently suppresses.

## Fix
**Short-term (do now, ~30 minutes):**
1. Find every implicit-nullable parameter in the affected files (`grep -nE 'function [a-z_]+\(.*\$\w+\s*=\s*null\)' woodev/payment-gateway/`).
2. Add `?Type` annotations or use `mixed` (only when the original was truly untyped).
3. After the fix, remove the `error_reporting` mask in `RealisticPaymentFixtureTest.php` and add a `assertNoDeprecationsInTest()` style assertion (Brain Monkey `Tests\AssertThrows` or a custom strict-output listener).

**Medium-term (do in a follow-up):**
- Bump CI matrix to include PHP 8.4 explicitly (if not already).
- Add a project-wide audit script (or PHPStan rule) that flags implicit-nullable parameters as a CI gate.
- Document the PHP-version policy in `composer.json` and `AGENTS.md` (currently says "PHP 7.4–8.x, platform 8.1" — confirm whether 8.4 is supported for `composer check`).

**Do NOT do:** remove the test mask without fixing the underlying code. The mask is hiding a real production warning that will surface in customer sites running PHP 8.4+.

## Related
- [php/gateway-type-methods-required](gotchas/gateway-type-methods-required.md) — adjacent base-class contract concerns
- [../CURRENT-STATE.md](../CURRENT-STATE.md) — known bugs section should track this
- [../AGENT-RULES.md](../AGENT-RULES.md) — coding rules that may need an explicit PHP 8.4+ constraint
- [PHP RFC: Deprecate implicit-nullable parameter types](https://wiki.php.net/rfc/deprecate-implicit-nullable-types) — upstream rationale

## Resolution
H1 fix (commit `ef3d067`): 13 implicit-nullable sites annotated with explicit `?Type`, the
`error_reporting` test mask removed, and `reportUnmatchedIgnoredErrors: true` enabled in
`phpstan.neon` to catch future dead ignore patterns.

Sites fixed (13 total, 4 files):
- `woodev/payment-gateway/class-payment-gateway.php:2699, 2727` — `perform_credit_card_charge`, `perform_credit_card_authorization`
- `woodev/payment-gateway/class-payment-gateway-my-payment-methods.php:668` — `get_payment_method_default_html`
- `woodev/payment-gateway/handlers/abstract-hosted-payment-handler.php:161, 175, 198, 224` — 4 do_transaction_response_* methods
- `woodev/payment-gateway/handlers/abstract-payment-handler.php:262, 273, 307, 322, 386, 411` — 6 mark_order_as_* methods

The audit estimated 46 sites, but most untyped `$arg = null` parameters do NOT trigger
the PHP 8.4 deprecation (the deprecation only applies to TYPED parameters, not bare
untyped ones). The actual count was 13. Tested by `tests/unit/PaymentGatewayImplicitNullableTest.php`
which uses Reflection to enumerate the sites and asserts zero.

Side effect: enabling `reportUnmatchedIgnoredErrors: true` surfaced a dead PHPStan
ignore pattern for `Woodev_Payment_Gateway_Payment_Token::get_check_number()` — the
eCheck API was removed in s3 but the ignore pattern was still in `phpstan.neon`. Removed
in the same commit.
