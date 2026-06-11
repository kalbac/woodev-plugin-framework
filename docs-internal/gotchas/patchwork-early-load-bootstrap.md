# [testing/unit] Patchwork redefinable internals need an EARLY load in bootstrap — Brain Monkey's lazy load misses suite-build-time source files

> Namespace: `testing/*` — added session 9 (2026-06-11)

## The trap

`patchwork.json` lists internals (`function_exists`, `error_log`) as redefinable so
Brain Monkey `Functions\expect()` can stub them. But Brain Monkey loads Patchwork
**lazily at the first `Monkey\setUp()`** — while PHPUnit loads every test file (and
every source file they `require_once`) at suite-BUILD time, before any `setUp()`
runs. Call sites compiled before Patchwork loads are never instrumented: the stub
"works" in one isolated file and silently does nothing in the full suite (or vice
versa), an order-dependent heisenbug.

## Correct

Force-load Patchwork at the top of the unit branch of `tests/bootstrap.php`,
before any framework source can be required:

```php
require_once dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';
```

(See the comment block in `tests/bootstrap.php`. Patchwork's own docs: "load it as
early as possible".)

## Incorrect

- Adding an internal to `patchwork.json` and assuming the stub now works everywhere.
- Diagnosing the resulting failure as a Brain Monkey bug — it is a load-order issue.

## Related

- [[brain-monkey-function-pollution]] — the sibling order-dependence trap.
