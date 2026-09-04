# Gotcha: [php/inheritance] — a stricter base class fatals on signatures, and a green unit suite cannot see it
> Tags: php, inheritance, migration, mocks, testing | Session: s115

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

Fix that one, reload, and the next one appears. There were **seven**.

## Root cause

Two separate things, and both have to be true for the trap to bite.

**1. PHP checks override compatibility at CLASS DECLARATION time**, not at call time. A parent that
declares `: string` where the child declares nothing is a fatal the moment the file is parsed — no
call required, no test needed. Older framework bases had no return types; the v2 ones do.

**2. Unit tests with mocks never build the real hierarchy.** Brain Monkey / Mockery construct a
*double*, not a subclass of the real parent, so the incompatible override is never declared and
never checked. The suite is green because it is not testing what broke.

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

✅ Correct — enumerate every conflicting override BEFORE switching, by comparing signatures across
the whole ancestor chain. Cheap, and it finds all of them at once instead of one fatal per reload:

```python
sig = re.compile(r'^\s*(?:abstract\s+|final\s+)?(public|protected|private)\s+'
                 r'(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)\s*(?::\s*([^\s{;]+))?', re.M)
# build {name: (visibility, args, return)} for the child and for EVERY ancestor file,
# nearest ancestor wins, then report names whose return type or visibility differ
```

✅ And load it once on a real stand. A `wp eval` that just prints `get_parent_class()` is enough —
the fatal happens at declaration, so merely loading WordPress surfaces it.

## Related

- [mockery-mock-new-method-full-suite](mockery-mock-new-method-full-suite.md) — the neighbouring mock trap: renaming a method that a mock names as a STRING (`shouldReceive( 'get_api' )`) is invisible to a grep for `->get_api()`
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — the same shape one level up: green tests that pin the double rather than the real thing
- [gateway-type-methods-required](gateway-type-methods-required.md) — the other half of this, on the call side
