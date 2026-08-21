# gotcha: a `class_exists`-guarded global stub is won by whichever test file loads first — and it wins for the whole suite

**Namespace:** `[testing/*]`
**Discovered:** s84 (2026-08-21)

## What happens

A new test file adds the usual guarded stub:

```php
if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {
        public function get_id() { return 0; }   // ← harmless for MY test
        // …
    }
}
```

Every one of that file's own tests passes, because each order double it actually uses overrides
`get_id()` anyway. And then a completely unrelated file starts failing:

```text
tests/unit/Shipping/Pickup/PickupHandlerTest.php:4726
- Array (0 => 'cdek_full_point', 1 => 'yandex_delivery_point_data')
+ Array &0 ()
```

`$captured` is empty — the pickup handler wrote no meta at all.

## Root cause

The whole unit suite runs in ONE process. The `class_exists()` guard means the FIRST file to load
defines `WC_Order` for everybody, and PHPUnit loads test files in directory order. In s84 two new
`PaymentGateway*` files sorted ahead of the established `PaymentGatewayDirectXssTest.php`, whose
stub returns `123`, so the new `0` won.

`PickupHandlerTest` then constructs a bare `new WC_Order()`, gets `get_id() === 0`, fails
`Woodev_Order_Compatibility::update_order_meta()`'s `$order_id > 0` guard, and the meta write is
silently skipped. No error, no warning — just an assertion about an empty array in a file nobody
touched.

## ✅ Correct

- **Match the existing stub exactly.** Before adding a guarded global stub, grep for it:
  `grep -rn "class_exists( 'WC_Order' )" tests/` and copy the values the established one returns.
  A different value is a behavior change for every test in the suite, not a local detail.
- **Give the stub a value that cannot mean "absent".** `0`, `''` and `null` are the values guards
  test for. `123` is deliberate.
- **Suspect this shape whenever an untouched test file starts failing.** The `--filter` run makes
  it unmistakable: if `--testsuite=Unit --filter ThatOtherFile` fails on your branch and passes on
  `main`, the coupling is through shared process state, not through test order.

## How it was caught

The worker classified the two failures as "pre-existing, unrelated — reproduce identically in
total isolation". They were not. What settled it:

```text
main,       --testsuite=Unit --filter PickupHandlerTest  →  OK (206 tests, 745 assertions)
its branch, --testsuite=Unit --filter PickupHandlerTest  →  FAILURES, 2 (206 tests, 741 assertions)
```

"Pre-existing" is a claim about `main`, and only a run on `main` settles it.

## Related

- [phpunit-result-cache-makes-a-run-unreproducible](phpunit-result-cache-makes-a-run-unreproducible.md) — the other reason a suite disagrees with itself, and what made the "reproduces in isolation" claim feel true
- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the third way a test count misleads
