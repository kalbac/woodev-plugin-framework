# Gateway Type Methods Required

> `[php/gateway-type-methods-required]` | discovered: 2026-05-10 (s3); recurred + widened: 2026-05-31

## 2026-05-31 recurrence (much larger) + why static analysis missed it

A second, far larger instance of this exact root cause was found during a branch
review. Commits `728c6f9` ("remove 47 deprecated methods") and `d85a1f9` removed
**57 methods** from `Woodev_Payment_Gateway` (1045 lines). **28 of them were still
called** by surviving framework code, including core infrastructure on the hot
checkout path: `is_available()` calls `$this->get_plugin()` and
`$this->currency_is_accepted()`; capture/refund call `$this->get_order_meta()` /
`$this->add_order_meta()` / `$this->update_order_meta()`; plus `get_id`,
`get_id_dasherized`, `get_environment(s)`, `is_test/production_environment`,
`csc_*`, `add_support`/`set_supports`, `inherit_settings`, `get_accepted_currencies`,
`add_debug_message`/`debug_log`, `add_api_request_logging`, etc. On any installed
gateway plugin this is a guaranteed `Error: Call to undefined method` at checkout.

**Why `composer check` stayed green (the real trap):** `phpstan.neon` contained a
blanket ignore block suppressing **all** undefined-method errors on the gateway
hierarchy:

```
- '#Call to an undefined method Woodev_Payment_Gateway_Direct::#'
- '#Call to an undefined method Woodev_Payment_Gateway_Hosted::#'
- '#Call to an undefined method Woodev_Payment_Gateway::#'
```

with a comment claiming "The methods exist at runtime." After the deletion that was
no longer true, and the ignore hid exactly the errors that would have caught the
regression. Unit tests also missed it because none instantiated a concrete gateway.

**Fix:** restored the still-called infrastructure methods from the pre-cleanup
version (excluding WC-inherited `get_method_title()`, eCheck-only `supports_check_field()`,
and deprecated capture wrappers that the capture handler resolves itself), then
**removed the blanket PHPStan ignore**. With all methods present PHPStan resolves the
whole hierarchy and reports 0 errors — so this class of regression is now caught.

**Lesson:** never use a blanket `Call to an undefined method <Class>::` ignore — it
permanently blinds static analysis to real fatals. Prefer narrow, per-method ignores
only for genuinely-unresolvable stub gaps.

---

> `[php/gateway-type-methods-required]` | discovered: 2026-05-10 (s3)

## Root cause

`is_credit_card_gateway()`, `is_echeck_gateway()`, and `get_payment_type()` are **not** optional helper methods — they are part of the core gateway API called 32+ times across the codebase. In s2 legacy cleanup, these methods were accidentally deleted from `Woodev_Payment_Gateway` along with other deprecated code because they appeared unused at first glance.

The methods were never found by grep because they use standard naming patterns that overlap with simple usage searches. When methods are called via `$this->is_credit_card_gateway()` at call sites and also defined as `public function is_credit_card_gateway()` in the class, a grep for `function is_credit_card` should find both. If it doesn't, the definition is missing.

## ❌ Wrong (s2 state — missing definitions)

```php
abstract class Woodev_Payment_Gateway extends WC_Payment_Gateway {
    // ... no is_credit_card_gateway() definition ...
    // ... no is_echeck_gateway() definition ...
    // ... no get_payment_type() definition ...

    // line 207: $this->is_credit_card_gateway()
    // line 828: $this->is_echeck_gateway()
    // All 32 call sites would produce PHP fatal errors at runtime
}
```

## ✅ Correct

```php
abstract class Woodev_Payment_Gateway extends WC_Payment_Gateway {
    public function get_payment_type() {
        return $this->payment_type;
    }

    public function is_credit_card_gateway() {
        return self::PAYMENT_TYPE_CREDIT_CARD === $this->get_payment_type();
    }

    /** @deprecated v2.0.0 eCheck payment type removed */
    public function is_echeck_gateway() {
        return false;
    }
}
```

## Detection rule

When removing deprecated code, **always verify** that methods being deleted have zero call sites remaining. A method may be called deep in the class hierarchy (parent or child classes) via `$this->` which won't appear in a simple `findstr` for the **definition** — only for the **usage**.

Before deleting any method:
1. `grep "method_name" woodev/` — find ALL references (both definition and call sites)
2. If references remain → do NOT delete, mark `@deprecated` instead
3. If references remain but method is obsolete → replace body with `return false/true/null` + `@deprecated`

## Related
- [deprecation/deprecated-which-function](gotchas/deprecated-which-function.md) — when to use `_deprecated_function()` vs WC helpers
- [php/namespace-migration-legacy-psr4](gotchas/namespace-migration-legacy-psr4.md) — legacy naming conventions
