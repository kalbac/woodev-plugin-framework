# Gotcha: [php/gateway-type-methods-required] — Never blanket-ignore `Call to an undefined method` on a class hierarchy

> Tags: phpstan, static-analysis, blank-ignores, gateway, payment, shipping, box-packer
> Discovered: 2026-05-10 (s3); recurred + widened: 2026-05-31; audit of remaining ignores: 2026-06-01

## 2026-06-01 audit — 3 remaining blanket-ignores with the same fatal risk

A second-model independent audit of `phpstan.neon` (after the a7da0ea fix) found
that **the same class of bug still exists in 3 places**, plus 1 dead ignore and
1 docblock-precision ignore. Running PHPStan with the suspect ignores removed
revealed **30 masked errors across 5 patterns**.

The pattern, in every case, is the same as the a7da0ea bug:

> A blanket ignore of the form `#Call to an undefined method <Class>::#`
> hides exactly the static-analysis errors that would have caught a real
> `Error: Call to undefined method` at runtime. When the assumed "runtime
> implementation" never appears (or the parent class is used where the child
> was assumed), the site fatals on every install that triggers that path.

### Surviving suspect blanket-ignores (audit of `phpstan.neon` lines 86-156)

| # | Line | Regex | Risk | Where it actually exists | Notes |
|---|------|-------|------|--------------------------|-------|
| 1 | 117 | `Woodev_Plugin::get_gateways()` | MEDIUM | Only in `Woodev_Payment_Gateway_Plugin:833` | 2 unguarded calls: `payment-gateway/admin/abstract-payment-gateway-plugin-admin-setup-wizard.php:29`, `payment-gateway/rest-api/class-payment-gateway-plugin-rest-api.php:28` — fatals only if used by a non-gateway plugin (very narrow). The other 23 call sites are inside `Woodev_Payment_Gateway_Plugin` itself or guarded by `instanceof`. |
| 2 | 120 | `Woodev_Payment_Gateway_API_Payment_Notification_Response::#` (CLASS-WIDE) | **HIGH** | Base interface has 4 methods; sub-interfaces add `get_exp_month`/`get_exp_year`/`get_card_type`/`get_loan_type`/`get_credit_amount`/`get_first_payment` | 6 UNGUARDED calls in `payment-gateway/class-payment-gateway-hosted.php:440-452` — `$response->get_exp_month()` etc. dispatched by `switch($response->get_payment_type())`, not by `instanceof`. If a hosted gateway returns `PAYMENT_TYPE_CREDIT_CARD` from a custom response that does NOT implement `Woodev_Payment_Gateway_API_Payment_Notification_Credit_Card_Response`, the call at line 442 **fatals on checkout**. The sibling handler (`abstract-hosted-payment-handler.php:286-296`) uses `instanceof` guards correctly — copy that pattern. |
| 3 | 123 | `Woodev_Payment_Gateway_Payment_Token::get_check_number()` | **DEAD** (cleanup) | Nowhere — eCheck removed in s3 | Verified with `reportUnmatchedIgnoredErrors: true`: 0 matches. **Safe to delete.** |
| 4 | 124 | `Invalid array key type Woodev_Payment_Gateway_Payment_Token` | LOW (docblock) | Runtime works; only docblock is imprecise | `@return array\|Woodev_Payment_Gateway_Payment_Token[]` should be `@return array<string, Woodev_Payment_Gateway_Payment_Token>`. |
| 5 | 127 | `Woodev_Box_Packer_Item::get_product()` | **HIGH** | Only in `Woodev_Packer_Item_Implementation` — not in the interface contract | `box-packer/class-packer-separatly.php:38` calls `$item->get_product()` on `Woodev_Box_Packer_Item[]`. If a plugin implements `Woodev_Box_Packer_Item` with their own class (no `get_product()`), `pack()` fatals. Other packers (`Single_Box`, `Virtual_Box`, `Boxes`) do NOT call `get_product()`. |
| 6 | 130-131 | `(Woodev_Shipping\|Woodev_Exportable\|not subtype of Throwable)` (scoped to `interface-shipping-api.php`) | **HIGH** (broken contract) | The 6 types in this interface **do not exist** in the framework | `Woodev_Shipping_API_Rate_Response`, `Woodev_Shipping_API_Order_Response`, `Woodev_Shipping_API_Tracking_Response`, `Woodev_Shipping_API_Pickup_Points_Response`, `Woodev_Exportable_Order`, `Woodev_Shipping_Exception` — see [gotcha: shipping-api-broken-contract](gotchas/shipping/shipping-api-broken-contract.md). 20 errors revealed when ignore removed. |

### Required fixes (release-blocking for v2.0)

**Pattern 2 (`Payment_Notification_Response` class-wide) — copy pattern from sibling handler:**

```php
// ❌ Current (class-payment-gateway-hosted.php:440-452) — value-based dispatch
switch ( $response->get_payment_type() ) {
    case self::PAYMENT_TYPE_CREDIT_CARD:
        $order->payment->exp_month = $response->get_exp_month();
        $order->payment->exp_year  = $response->get_exp_year();
        $order->payment->card_type = $response->get_card_type();
        break;
    case self::PAYMENT_TYPE_LOANS:
        $order->payment->loan_type     = $response->get_loan_type();
        // ...
}

// ✅ Correct — type-based dispatch (matches abstract-hosted-payment-handler.php:286-296)
if ( $response instanceof Woodev_Payment_Gateway_API_Payment_Notification_Credit_Card_Response ) {
    $order->payment->exp_month = $response->get_exp_month();
    $order->payment->exp_year  = $response->get_exp_year();
    $order->payment->card_type = $response->get_card_type();
} elseif ( $response instanceof Woodev_Payment_Gateway_API_Payment_Notification_Loans_Response ) {
    $order->payment->loan_type     = $response->get_loan_type();
    $order->payment->credit_amount = $response->get_credit_amount();
    $order->payment->first_payment = $response->get_first_payment();
}
```

After fix: remove class-wide ignore, replace with no entry (or narrow per-method ignores if PHPStan still flags false positives).

**Pattern 3 (dead `get_check_number`) — one-line removal:**

```neon
# DELETE this line from phpstan.neon:123
- '#Call to an undefined method Woodev_Payment_Gateway_Payment_Token::get_check_number\(\)#'
```

**Pattern 5 (`Box_Packer_Item::get_product`) — split interface or add to base:**

Either (a) split into `Woodev_Box_Packer_Item_With_Product extends Woodev_Box_Packer_Item` and have `Woodev_Packer_Separately` require the extended type, or (b) add `public function get_product();` to the base interface with a default no-op or nullable return.

**Pattern 6 (Shipping_API broken contract) — fix the interface, not the ignore.** See dedicated gotcha.

### Cross-cutting enforcement (prevents recurrence)

Enable dead-ignore detection permanently in `phpstan.neon:78`:

```neon
reportUnmatchedIgnoredErrors: true   # was: false
```

With this on, the next dead ignore (like `get_check_number` above) is reported at
every `composer check` instead of silently rotting forever.

---

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
