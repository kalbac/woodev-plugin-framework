# Sweeping for a boundary by one spelling of its call finds only that spelling

**Namespace:** `[framework/contracts]`
**Found:** s101 (28.08.2026), on card #594 — by the critic, after two sweeps had already declared
the job finished.

## The trap

Card #594 asked for every place that logs a caught exception's message to route it through
`Woodev_API_Base::redact_secret_log_text()` first. It took **four** sweeps, and each one was
confident it had finished:

| Sweep | Found | What its grep could not see |
|---|---|---|
| #585 | 4 | — |
| #594's own card | 11 | — (also keyed on `error_log`) |
| my re-sweep | 3 | they write `$plugin->log(`, invisible to a grep for `error_log` |
| the critic | 2 | they write `add_debug_message(`, and the logger is **two frames further down** |

The last one is the instructive one. `Woodev_Payment_Gateway::add_debug_message()` is not named
like a log call and does not contain one at the call site:

```php
$this->get_gateway()->add_debug_message( $e->getMessage(), 'error' );   // looks like a UI concern
```

Two frames later it is `$this->get_plugin()->log( $message, … )` → `WC_Logger::add()`. A grep for
`error_log(` misses it. A grep for `->log(` misses it too.

And the sharpest miss was inside a catch block **I had already fixed**:
`Woodev_Payment_Gateway::mark_order_as_failed()` calls `add_debug_message( $error_message )`, and
the hosted gateway's catch calls `mark_order_as_failed( $order, $e->getMessage(), … )` — so the
same message still reached the same logger, two lines below the line I had just redacted.

## Root cause

A grep matches a **spelling**. A boundary is a **destination**. They coincide only when nobody has
wrapped the destination — and wrapping it is exactly what a framework does.

The failure is silent in both directions: nothing errors when a sink is missed, and the sweep
reports a tidy count that looks like completeness.

## Fix

❌ Wrong — sweep for the call you have in mind, then write down that the list is complete:

```bash
grep -rn "error_log(" --include='*.php' woodev/ | grep -i getmessage
```

✅ Correct — find the DESTINATION first, then find everything that reaches it. For a log sink in
this codebase that means all of `error_log()`, `$plugin->log()`, `WC_Logger`, `trigger_error()`,
`_doing_it_wrong()`, and any framework method that ends in one of them:

```bash
# 1. what are the destinations?
grep -rn "function log(\|error_log(\|wc_get_logger\|trigger_error(" --include='*.php' woodev/
# 2. for each wrapper found, who calls IT?
#    (Serena: find_referencing_symbols on the wrapper, not a text grep)
```

✅ Correct — **redact at the SINK, not at the call sites.** One edit inside
`add_debug_message()` covers all ten of its callers and every future one; ten edits at the call
sites cover ten, and the eleventh author forgets. This is the general remedy for the whole class:
a wrapper that hides a boundary should own the boundary's rule.

✅ Correct — write the residual honestly. `redact_secret_log_text()`'s docblock now lists all four
sweeps **with what each one could not see**, rather than asserting a complete list. A claim of
completeness in a docblock closes the question for the next reader; four sweeps in a row proved
that claim would have been wrong each time it was made.

## Related

- [an-action-beside-a-filter-must-carry-the-filters-result](an-action-beside-a-filter-must-carry-the-filters-result.md) — the other "the seam is not where you think it is"
- [a-stale-composer-classmap-only-breaks-isolated-test-runs](a-stale-composer-classmap-only-breaks-isolated-test-runs.md) — same family: a gap that is silent because the common path masks it
