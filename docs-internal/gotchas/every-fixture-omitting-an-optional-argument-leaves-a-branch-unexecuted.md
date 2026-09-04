# Gotcha: [testing/coverage] — Every fixture omitting the same optional argument leaves a whole output branch unexecuted
> Tags: testing, coverage, fixtures, shipping | Session: s116

## What happens

A value object looks well covered — several fixtures build it, a test file exercises the code that
consumes it, the suite is green — and yet one branch of its output has **never run once**. The
branch is guarded by nothing; it is simply reached only when an optional constructor argument is
supplied, and no fixture ever supplies it.

Found on `Shipping_Rate` (#764). `to_array()` wrapped the rate meta in an extra level keyed by the
method id:

```php
'meta_data' => [ $this->method_id => $this->meta_data ],
```

Four shipping fixtures (`woodev-test-shipping-method`, `woodev-realistic-shipping-plugin`,
`woodev-edostavka-pilot-plugin`, `woodev-yandex-pilot-plugin`) and every unit test built the object
with **four of its six arguments** — `method_id`, `id`, `label`, `cost` — so `$meta_data` was always
`[]` and the wrapper always produced `[ '<method-id>' => [] ]`, which nothing asserted. The first
real plugin to migrate (`woocommerce-edostavka`) writes a flat `edostavka_rate` key and reads it
back off the shipping order item, so the wrapper silently moved a **release-blocking installed-site
contract** one level down (ADR-005).

## Root cause

Coverage tools report the LINE as executed — `to_array()` ran thousands of times. What was never
executed is the line's *meaningful* case. An optional parameter with a harmless default (`[]`) makes
the difference invisible: the wrapper around an empty array is itself nearly empty, so the wrong
shape looks like the right one in every existing test.

Fixtures amplify this. They are written to be minimal, they get copied from each other, and so they
all omit the *same* optional arguments. Four fixtures agreeing is not four samples — it is one
sample copied four times.

## Fix

Do not ask "is this class covered". Ask **which constructor arguments has anything ever passed**.

```bash
# ❌ Reassuring and useless — the class is "used in 9 places"
grep -rn "Shipping_Rate" tests/

# ✅ The question that finds it — how many arguments does each call site actually pass?
grep -rn -A8 "new Shipping_Rate(" tests/ | grep -c "meta"   # → 0
```

When a value object's output shape is a contract, pin the shape itself, including the empty case —
the empty case is where a wrapper hides:

```php
// ❌ Was possible for months: no test named the output shape at all.

// ✅ Pins both, so the wrapper cannot come back unnoticed
$this->assertSame( [ 'edostavka_rate' => [ 'period_min' => 2 ] ], $rate->to_array()['meta_data'] );
$this->assertSame( [], ( new Shipping_Rate( 'edostavka', 'r', 'L', '1' ) )->to_array()['meta_data'] );
```

The framework side of the fix: `to_array()` now emits meta flat, because that is what
`WC_Shipping_Rate::add_meta_data()` means by `meta_data` — one order-item meta row per pair — and
because the shipping order item already carries its own `method_id`, so naming the meta key after
the method said nothing new.

## Related

- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — the same failure one layer up: green against our own double, wrong against the real contract
- [a-stricter-base-class-fatals-on-signatures](a-stricter-base-class-fatals-on-signatures.md) — the pilot's other find, also invisible to a green unit suite (248/248 with the plugin dead)
- [migration/edostavka-data-preservation-checklist.md](../migration/edostavka-data-preservation-checklist.md) — the contract class this defect belonged to
- [adr/005-platform-v2-clean-break-policy.md](../adr/005-platform-v2-clean-break-policy.md) — why an installed-site meta key is release-blocking while an internal API is not
