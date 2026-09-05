# Gotcha: [php/inheritance] — a stricter base class fatals on signatures, and a green unit suite cannot see it
> Tags: php, inheritance, migration, mocks, testing | Session: s115, corrected s117

## What happens

Repointing a plugin class at a newer, more strongly typed framework base:

```php
- class WC_Edostavka_Shipping extends Woodev_Plugin {
+ class WC_Edostavka_Shipping extends \Woodev\Framework\Shipping\Shipping_Plugin {
```

`php -l` is clean. The **full unit suite stays green — 248/248** — and the plugin then dies on its
first real request:

```
PHP Fatal error: Declaration of WC_Edostavka_Shipping::get_settings_url($plugin_id = null)
must be compatible with Shipping_Plugin::get_settings_url($plugin_id = null): string
```

Fix that one, reload, and the next one appears.

**How many there are is the part a manual pass gets wrong.** The hand-built list said seven. A
mechanical probe over the same pair found **eleven fatals and eight unimplemented abstracts**
across the three classes, and three of the hand-listed seven were not fatals at all. Do not carry a
hand-counted figure forward; run `npm run probe:signature` (card #767).

## Root cause

Two separate things, and both have to be true for the trap to bite.

**1. PHP checks override compatibility at CLASS DECLARATION time**, not at call time. A parent that
declares `: string` where the child declares nothing is a fatal the moment the file is parsed — no
call required, no test needed. Older framework bases had no return types; the v2 ones do.

**2. Unit tests with mocks never build the real hierarchy.** Brain Monkey / Mockery construct a
*double*, not a subclass of the real parent, so the incompatible override is never declared and
never checked. The suite is green because it is not testing what broke.

⚠ **An OMITTED return type is a fatal, and it is the one a manual review argues itself out of**
(measured s117). Where the base declares `: ?Checkout_Handler` and the override declares nothing,
s116's own notes waved it through as *"returns `?null`, the base allows that"*. That reasoning
answers a different question. PHP checks the **declared** type, at declaration, regardless of what
either method ever returns:

```php
class A { public function foo(): void {} }
class B extends A { public function foo() {} }

PHP Fatal error: Declaration of B::foo() must be compatible with A::foo(): void
```

⚠ **A `private` base method is NOT a conflict — and that is worse, not better.** Private methods
are not inherited, so a same-named public method on the plugin declares cleanly and then never
runs: the base calls `$this->name()`, which PHP resolves to the declaring class's own private
method. `Shipping_Method::should_send_cart_api_request()` and `Shipping_Plugin::includes()` are both
like this. The second is why the manual count said seven — `includes()` looks like a conflict in any
plain name-and-type diff and is not one.

The three that matter most are not type declarations at all, they are CLASSES: `get_integration_handler`,
`get_checkout_handler` and `get_webhook_handler` demand `?Shipping_Integration`,
`?Checkout_Handler` and `?Abstract_Webhook_Handler` respectively — so the base drags three more
subsystems in with it, and the migration is not divisible into "the plugin first, its parts later".

## Fix

❌ Wrong — switch the parent, run the suite, believe the green:

```bash
# suite passes; the plugin is dead on the stand
php vendor/bin/phpunit --testsuite=unit
```

✅ Correct — enumerate every conflicting override BEFORE switching, with the probe. It reads source
only, so it works before the plugin can load at all, and it finds them all at once instead of one
fatal per reload:

```bash
npm run probe:signature   # the edostavka triple, by default
node scripts/signature-probe.mjs --pair "path/Subject.php:Subject=woodev/base.php:Base"
```

It reports fatals (final override, narrowed visibility, static mismatch, incompatible or omitted
return type), unimplemented abstracts, parameter divergence, and — separately, because it is not a
fatal — the shadowed-base-private case above. Usage and the counting argument:
[`docs-internal/migration/signature-probe.md`](../migration/signature-probe.md).

✅ And load it once on a real stand. A `wp eval` that just prints `get_parent_class()` is enough —
the fatal happens at declaration, so merely loading WordPress surfaces it.

## Related

- [mockery-mock-new-method-full-suite](mockery-mock-new-method-full-suite.md) — the neighbouring mock trap: renaming a method that a mock names as a STRING (`shouldReceive( 'get_api' )`) is invisible to a grep for `->get_api()`
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — the same shape one level up: green tests that pin the double rather than the real thing
- [gateway-type-methods-required](gateway-type-methods-required.md) — the other half of this, on the call side
- [`migration/signature-probe.md`](../migration/signature-probe.md) — the probe, and why the count is 11/8 rather than the 13/8 first recorded
